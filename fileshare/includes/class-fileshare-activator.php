<?php
/**
 * 有効化・無効化処理。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化時のテーブル作成・保存ディレクトリ準備・cron 登録などを担う。
 */
final class FileShare_Activator {

	public const CRON_HOOK = 'fileshare_cleanup_event';

	/**
	 * プラグイン有効化時の処理。
	 */
	public static function activate(): void {
		self::check_requirements();
		self::create_tables();
		self::prepare_storage();
		FileShare_Logger::install();
		self::schedule_cron();
		add_option( 'fileshare_db_version', FILESHARE_VERSION );
	}

	/**
	 * プラグイン無効化時の処理。
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * 動作環境要件を確認する。要件未満なら有効化を中断。
	 */
	private static function check_requirements(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			self::bail( __( 'FileShare には PHP 8.1 以上が必要です。', 'fileshare' ) );
		}
		global $wp_version;
		if ( version_compare( $wp_version, '6.2', '<' ) ) {
			self::bail( __( 'FileShare には WordPress 6.2 以上が必要です。', 'fileshare' ) );
		}
		if ( ! class_exists( 'SQLite3' ) ) {
			self::bail( __( 'FileShare にはログ機能のため PHP の sqlite3 拡張機能が必要です。', 'fileshare' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::bail( __( 'FileShare には複数ファイルの圧縮のため PHP の zip 拡張機能が必要です。', 'fileshare' ) );
		}
	}

	/**
	 * 有効化を中断してエラー表示する。
	 *
	 * @param string $message 表示メッセージ。
	 */
	private static function bail( string $message ): void {
		deactivate_plugins( FILESHARE_PLUGIN_BASENAME );
		wp_die(
			esc_html( $message ),
			esc_html__( 'FileShare 有効化エラー', 'fileshare' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * ファイルメタデータ用テーブルを作成する。
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = FileShare_DB::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			download_id VARCHAR(64) NOT NULL,
			password_hash VARCHAR(255) NOT NULL,
			file_name VARCHAR(255) NOT NULL,
			stored_name VARCHAR(255) NOT NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_zip TINYINT(1) NOT NULL DEFAULT 0,
			max_downloads INT UNSIGNED NOT NULL DEFAULT 0,
			download_count INT UNSIGNED NOT NULL DEFAULT 0,
			uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY download_id (download_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * ファイル保存ディレクトリを作成し、直接アクセスを遮断する。
	 */
	private static function prepare_storage(): void {
		$dir = FileShare_DB::storage_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Apache 向け直アクセス遮断。
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		// ディレクトリリスティング抑止。
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * クリーンアップ cron を登録する。
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}
}
