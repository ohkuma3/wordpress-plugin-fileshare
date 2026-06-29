<?php
/**
 * ファイルメタデータの永続化（MySQL / $wpdb）。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * fileshare ファイルレコードの CRUD を担う。
 */
final class FileShare_DB {

	/**
	 * メタデータテーブル名（プレフィックス込み）。
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'fileshare_files';
	}

	/**
	 * ファイル保存ディレクトリの絶対パス（末尾スラッシュ付き）。
	 */
	public static function storage_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'fileshare-files/';
	}

	/**
	 * ファイルレコードを追加する。
	 *
	 * @param array<string,mixed> $data 列データ。
	 * @return int 追加された行 ID（失敗時 0）。
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'download_id'    => $data['download_id'],
				'password_hash'  => $data['password_hash'],
				'file_name'      => $data['file_name'],
				'stored_name'    => $data['stored_name'],
				'file_size'      => $data['file_size'],
				'is_zip'         => $data['is_zip'] ? 1 : 0,
				'max_downloads'  => $data['max_downloads'],
				'download_count' => 0,
				'uploaded_by'    => $data['uploaded_by'],
				'expires_at'     => $data['expires_at'],
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * download_id でレコードを取得する。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @return array<string,mixed>|null
	 */
	public static function find_by_download_id( string $download_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE download_id = %s',
				$download_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * 主キーでレコードを取得する。
	 *
	 * @param int $id 行 ID。
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * 全レコードを新しい順で取得する。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC',
			ARRAY_A
		);
	}

	/**
	 * 期限切れ・回数超過のレコードを取得する。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function expired(): array {
		global $wpdb;
		$now = current_time( 'mysql' );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . '
				 WHERE ( expires_at IS NOT NULL AND expires_at < %s )
				    OR ( max_downloads > 0 AND download_count >= max_downloads )',
				$now
			),
			ARRAY_A
		);
	}

	/**
	 * ダウンロード回数をアトミックに +1 する。
	 *
	 * @param int $id 行 ID。
	 */
	public static function increment_download_count( int $id ): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET download_count = download_count + 1 WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * レコードを削除する。
	 *
	 * @param int $id 行 ID。
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}
}
