<?php
/**
 * SQLite を用いたログ・セキュリティ状態の永続化。
 *
 * 要件: PHP の sqlite3 拡張機能（ログ機能で使用）。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * アクセスログ、ログイン試行、IP ブロック、レート制限を SQLite で管理する。
 */
final class FileShare_Logger {

	private static ?SQLite3 $db = null;

	/**
	 * SQLite データベースファイルの絶対パス。
	 */
	public static function db_path(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'fileshare-files/';
		return $dir . 'fileshare-logs.sqlite';
	}

	/**
	 * 接続を取得（必要なら生成）する。
	 */
	private static function db(): SQLite3 {
		if ( null === self::$db ) {
			self::$db = new SQLite3( self::db_path() );
			self::$db->busyTimeout( 5000 );
			self::$db->exec( 'PRAGMA journal_mode = WAL;' );
		}
		return self::$db;
	}

	/**
	 * テーブルを作成する（有効化時に呼ばれる）。
	 */
	public static function install(): void {
		$db = self::db();
		$db->exec(
			'CREATE TABLE IF NOT EXISTS access_log (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				created_at TEXT NOT NULL,
				ip TEXT NOT NULL,
				action TEXT NOT NULL,
				download_id TEXT,
				status TEXT NOT NULL,
				detail TEXT
			);'
		);
		$db->exec(
			'CREATE TABLE IF NOT EXISTS login_attempts (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				ip TEXT NOT NULL,
				download_id TEXT,
				success INTEGER NOT NULL DEFAULT 0,
				created_at INTEGER NOT NULL
			);'
		);
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_login_ip_time ON login_attempts (ip, created_at);' );
		$db->exec(
			'CREATE TABLE IF NOT EXISTS ip_blocks (
				ip TEXT PRIMARY KEY,
				fail_count INTEGER NOT NULL DEFAULT 0,
				blocked_until INTEGER NOT NULL DEFAULT 0
			);'
		);
		$db->exec(
			'CREATE TABLE IF NOT EXISTS rate_limits (
				bucket TEXT NOT NULL,
				ip TEXT NOT NULL,
				window_start INTEGER NOT NULL,
				hits INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (bucket, ip)
			);'
		);
	}

	/**
	 * アクセスログを記録する。
	 *
	 * @param string      $action      アクション名（upload/download/login など）。
	 * @param string      $status      success/fail/blocked など。
	 * @param string      $ip          クライアント IP。
	 * @param string|null $download_id 関連ダウンロード ID。
	 * @param string      $detail      補足情報。
	 */
	public static function log( string $action, string $status, string $ip, ?string $download_id = null, string $detail = '' ): void {
		$db   = self::db();
		$stmt = $db->prepare(
			'INSERT INTO access_log (created_at, ip, action, download_id, status, detail)
			 VALUES (:t, :ip, :action, :did, :status, :detail)'
		);
		$stmt->bindValue( ':t', gmdate( 'c' ), SQLITE3_TEXT );
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$stmt->bindValue( ':action', $action, SQLITE3_TEXT );
		$stmt->bindValue( ':did', $download_id, SQLITE3_TEXT );
		$stmt->bindValue( ':status', $status, SQLITE3_TEXT );
		$stmt->bindValue( ':detail', $detail, SQLITE3_TEXT );
		$stmt->execute();
	}

	/**
	 * ログイン試行を記録する。
	 *
	 * @param string $ip          クライアント IP。
	 * @param string $download_id ダウンロード ID。
	 * @param bool   $success     成功なら true。
	 */
	public static function record_attempt( string $ip, string $download_id, bool $success ): void {
		$db   = self::db();
		$stmt = $db->prepare(
			'INSERT INTO login_attempts (ip, download_id, success, created_at)
			 VALUES (:ip, :did, :s, :t)'
		);
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$stmt->bindValue( ':did', $download_id, SQLITE3_TEXT );
		$stmt->bindValue( ':s', $success ? 1 : 0, SQLITE3_INTEGER );
		$stmt->bindValue( ':t', time(), SQLITE3_INTEGER );
		$stmt->execute();
	}

	/**
	 * 指定 IP の直近 $window 秒間の失敗回数を数える。
	 *
	 * @param string $ip     クライアント IP。
	 * @param int    $window 秒数。
	 */
	public static function recent_failures( string $ip, int $window ): int {
		$db   = self::db();
		$stmt = $db->prepare(
			'SELECT COUNT(*) AS c FROM login_attempts
			 WHERE ip = :ip AND success = 0 AND created_at >= :since'
		);
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$stmt->bindValue( ':since', time() - $window, SQLITE3_INTEGER );
		$res = $stmt->execute();
		$row = $res->fetchArray( SQLITE3_ASSOC );
		return (int) ( $row['c'] ?? 0 );
	}

	/**
	 * IP のブロック期限（UNIX 秒）を取得する。未ブロックなら 0。
	 *
	 * @param string $ip クライアント IP。
	 */
	public static function blocked_until( string $ip ): int {
		$db   = self::db();
		$stmt = $db->prepare( 'SELECT blocked_until FROM ip_blocks WHERE ip = :ip' );
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$res = $stmt->execute();
		$row = $res->fetchArray( SQLITE3_ASSOC );
		return (int) ( $row['blocked_until'] ?? 0 );
	}

	/**
	 * IP を指定秒数だけブロックする。
	 *
	 * @param string $ip       クライアント IP。
	 * @param int    $duration ブロック秒数。
	 */
	public static function block_ip( string $ip, int $duration ): void {
		$db   = self::db();
		$stmt = $db->prepare(
			'INSERT INTO ip_blocks (ip, fail_count, blocked_until)
			 VALUES (:ip, 1, :until)
			 ON CONFLICT(ip) DO UPDATE SET
			   fail_count = fail_count + 1,
			   blocked_until = :until'
		);
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$stmt->bindValue( ':until', time() + $duration, SQLITE3_INTEGER );
		$stmt->execute();
	}

	/**
	 * ログイン成功時に IP のブロック状態を解除する。
	 *
	 * @param string $ip クライアント IP。
	 */
	public static function clear_block( string $ip ): void {
		$db   = self::db();
		$stmt = $db->prepare( 'DELETE FROM ip_blocks WHERE ip = :ip' );
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$stmt->execute();
	}

	/**
	 * レート制限のヒット数を加算し、現在のウィンドウ内ヒット数を返す。
	 *
	 * @param string $bucket 制限バケット名（エンドポイント単位）。
	 * @param string $ip     クライアント IP。
	 * @param int    $window ウィンドウ秒数。
	 * @return int 現ウィンドウ内の累積ヒット数。
	 */
	public static function rate_hit( string $bucket, string $ip, int $window ): int {
		$db    = self::db();
		$now   = time();
		$start = $now - ( $now % $window );

		$stmt = $db->prepare( 'SELECT window_start, hits FROM rate_limits WHERE bucket = :b AND ip = :ip' );
		$stmt->bindValue( ':b', $bucket, SQLITE3_TEXT );
		$stmt->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$row = $stmt->execute()->fetchArray( SQLITE3_ASSOC );

		if ( ! $row || (int) $row['window_start'] !== $start ) {
			$up = $db->prepare(
				'INSERT INTO rate_limits (bucket, ip, window_start, hits)
				 VALUES (:b, :ip, :ws, 1)
				 ON CONFLICT(bucket, ip) DO UPDATE SET window_start = :ws, hits = 1'
			);
			$up->bindValue( ':b', $bucket, SQLITE3_TEXT );
			$up->bindValue( ':ip', $ip, SQLITE3_TEXT );
			$up->bindValue( ':ws', $start, SQLITE3_INTEGER );
			$up->execute();
			return 1;
		}

		$hits = (int) $row['hits'] + 1;
		$up   = $db->prepare( 'UPDATE rate_limits SET hits = :h WHERE bucket = :b AND ip = :ip' );
		$up->bindValue( ':h', $hits, SQLITE3_INTEGER );
		$up->bindValue( ':b', $bucket, SQLITE3_TEXT );
		$up->bindValue( ':ip', $ip, SQLITE3_TEXT );
		$up->execute();
		return $hits;
	}

	/**
	 * 直近のアクセスログを取得する（管理画面表示用）。
	 *
	 * @param int $limit 取得件数。
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_logs( int $limit = 100 ): array {
		$db   = self::db();
		$stmt = $db->prepare( 'SELECT * FROM access_log ORDER BY id DESC LIMIT :l' );
		$stmt->bindValue( ':l', $limit, SQLITE3_INTEGER );
		$res  = $stmt->execute();
		$rows = array();
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * 古いログ・期限切れの制限レコードを削除する。
	 *
	 * @param int $retention_days ログ保持日数。
	 */
	public static function prune( int $retention_days = 30 ): void {
		$db     = self::db();
		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

		$db->exec( "DELETE FROM access_log WHERE created_at < '" . gmdate( 'c', $cutoff ) . "'" );

		$stmt = $db->prepare( 'DELETE FROM login_attempts WHERE created_at < :c' );
		$stmt->bindValue( ':c', $cutoff, SQLITE3_INTEGER );
		$stmt->execute();

		$stmt = $db->prepare( 'DELETE FROM ip_blocks WHERE blocked_until < :now' );
		$stmt->bindValue( ':now', time(), SQLITE3_INTEGER );
		$stmt->execute();
	}
}
