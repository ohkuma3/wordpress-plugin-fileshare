<?php
/**
 * セキュリティ: IP ブルートフォース対策とレート制限。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * クライアント IP の取得、レート制限、ブルートフォースブロックを提供する。
 */
final class FileShare_Security {

	/** 失敗回数の判定ウィンドウ（秒）。 */
	private const BRUTEFORCE_WINDOW = 900;
	/** ブロック発動の失敗回数しきい値。 */
	private const BRUTEFORCE_THRESHOLD = 5;
	/** ブロック継続時間（秒）。 */
	private const BLOCK_DURATION = 1800;

	/**
	 * クライアント IP を取得する。
	 *
	 * リバースプロキシ配下を考慮しつつ、信頼できる場合のみ転送ヘッダを参照する。
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

		/**
		 * 転送ヘッダを信頼するか。デフォルトは false（なりすまし防止）。
		 *
		 * @param bool $trust 信頼する場合 true。
		 */
		if ( apply_filters( 'fileshare_trust_forwarded_for', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts     = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$candidate = trim( $parts[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * 指定 IP が現在ブロック中か判定する。
	 *
	 * @param string $ip クライアント IP。
	 * @return int 残りブロック秒数（0 ならブロックなし）。
	 */
	public static function block_remaining( string $ip ): int {
		$until = FileShare_Logger::blocked_until( $ip );
		$diff  = $until - time();
		return $diff > 0 ? $diff : 0;
	}

	/**
	 * ログイン失敗を登録し、しきい値超過なら IP をブロックする。
	 *
	 * @param string $ip          クライアント IP。
	 * @param string $download_id ダウンロード ID。
	 * @return bool 今回の失敗でブロックされたら true。
	 */
	public static function register_failure( string $ip, string $download_id ): bool {
		FileShare_Logger::record_attempt( $ip, $download_id, false );
		$failures = FileShare_Logger::recent_failures( $ip, self::BRUTEFORCE_WINDOW );

		if ( $failures >= self::BRUTEFORCE_THRESHOLD ) {
			FileShare_Logger::block_ip( $ip, self::BLOCK_DURATION );
			FileShare_Logger::log( 'security', 'blocked', $ip, $download_id, "failures={$failures}" );
			return true;
		}
		return false;
	}

	/**
	 * ログイン成功を登録し、ブロック状態を解除する。
	 *
	 * @param string $ip          クライアント IP。
	 * @param string $download_id ダウンロード ID。
	 */
	public static function register_success( string $ip, string $download_id ): void {
		FileShare_Logger::record_attempt( $ip, $download_id, true );
		FileShare_Logger::clear_block( $ip );
	}

	/**
	 * レート制限を判定する（flask-limiter 相当）。
	 *
	 * @param string $bucket エンドポイント識別子。
	 * @param int    $limit  ウィンドウ内の許容回数。
	 * @param int    $window ウィンドウ秒数。
	 * @return bool 制限超過なら true（リクエストを拒否すべき）。
	 */
	public static function is_rate_limited( string $bucket, int $limit, int $window ): bool {
		$ip   = self::client_ip();
		$hits = FileShare_Logger::rate_hit( $bucket, $ip, $window );
		if ( $hits > $limit ) {
			FileShare_Logger::log( 'ratelimit', 'blocked', $ip, null, "{$bucket}:{$hits}/{$limit}" );
			return true;
		}
		return false;
	}
}
