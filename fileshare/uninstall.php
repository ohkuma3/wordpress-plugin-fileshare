<?php
/**
 * アンインストール処理。
 *
 * プラグイン削除時にテーブル・保存ファイル・SQLite ログ・オプションを除去する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// メタデータテーブルを削除。
$table = $wpdb->prefix . 'fileshare_files';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

// 保存ディレクトリ（ファイル本体・SQLite・.htaccess）を削除。
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'fileshare-files/';

if ( is_dir( $dir ) ) {
	$items = glob( $dir . '*' );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( is_file( $item ) ) {
				@unlink( $item );
			}
		}
	}
	// 隠しファイル(.htaccess)も削除。
	foreach ( array( '.htaccess', '.htaccess-wal', 'fileshare-logs.sqlite-wal', 'fileshare-logs.sqlite-shm' ) as $hidden ) {
		if ( file_exists( $dir . $hidden ) ) {
			@unlink( $dir . $hidden );
		}
	}
	@rmdir( $dir );
}

// オプション・transient を削除。
delete_option( 'fileshare_db_version' );

// 念のため transient を掃除。
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fileshare_%' OR option_name LIKE '_transient_timeout_fileshare_%'" ); // phpcs:ignore WordPress.DB
