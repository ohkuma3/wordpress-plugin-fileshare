<?php
/**
 * 管理画面（アップロード・ファイル管理・ログ）。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理メニュー、アップロード処理、ファイル一覧/削除を担う。
 */
final class FileShare_Admin {

	private const CAPABILITY     = 'upload_files';
	private const MENU_SLUG      = 'fileshare';
	private const NONCE          = 'fileshare_admin_action';
	private const SETTINGS_NONCE = 'fileshare_settings_action';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_fileshare_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_fileshare_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_fileshare_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * 管理メニューを追加する。
	 */
	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'FileShare', 'fileshare' ),
			__( 'FileShare', 'fileshare' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' ),
			'dashicons-share-alt',
			81
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'アップロード / 管理', 'fileshare' ),
			__( 'アップロード / 管理', 'fileshare' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'フロント設定', 'fileshare' ),
			__( 'フロント設定', 'fileshare' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'アクセスログ', 'fileshare' ),
			__( 'アクセスログ', 'fileshare' ),
			'manage_options',
			self::MENU_SLUG . '-logs',
			array( $this, 'render_logs_page' )
		);
		unset( $hook );
	}

	/**
	 * 管理画面用のアセットを読み込む。
	 *
	 * @param string $hook 現在の管理画面フック。
	 */
	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'fileshare-admin',
			FILESHARE_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			FILESHARE_VERSION
		);
		wp_enqueue_script(
			'fileshare-admin',
			FILESHARE_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			FILESHARE_VERSION,
			true
		);
		wp_localize_script(
			'fileshare-admin',
			'FileShareAdmin',
			array(
				'i18n' => array(
					'dropHint'  => __( 'ここにファイルをドラッグ&ドロップ', 'fileshare' ),
					'selected'  => __( '選択中:', 'fileshare' ),
					'copyDone'  => __( 'コピーしました', 'fileshare' ),
				),
			)
		);
	}

	/**
	 * アップロード/管理ページを描画する。
	 */
	public function render_upload_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		$files  = FileShare_DB::all();
		$notice = $this->pull_notice();
		$result = get_transient( 'fileshare_result_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'fileshare_result_' . get_current_user_id() );
		}
		require FILESHARE_PLUGIN_DIR . 'admin/views/upload-page.php';
	}

	/**
	 * ログページを描画する。
	 */
	public function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		$logs = FileShare_Logger::recent_logs( 200 );
		require FILESHARE_PLUGIN_DIR . 'admin/views/logs-page.php';
	}

	/**
	 * フロント設定ページを描画する。
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		$settings = FileShare_Settings::get();
		$notice   = $this->pull_notice();
		require FILESHARE_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * フロント設定の保存を処理する。
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$enabled  = ! empty( $_POST['enabled'] );
		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( '' === $username ) {
			$this->set_notice( 'error', __( 'ユーザー名を入力してください。', 'fileshare' ) );
			$this->redirect_settings();
		}

		$current = FileShare_Settings::get();
		if ( '' === $password && '' === $current['password_hash'] ) {
			$this->set_notice( 'error', __( '初回はパスワードを設定してください。', 'fileshare' ) );
			$this->redirect_settings();
		}

		FileShare_Settings::update( $enabled, $username, $password );
		$this->set_notice( 'success', __( 'フロント設定を保存しました。', 'fileshare' ) );
		$this->redirect_settings();
	}

	/**
	 * 設定ページへリダイレクトする。
	 */
	private function redirect_settings(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) );
		exit;
	}

	/**
	 * 設定フォーム用の nonce アクションを返す。
	 */
	public static function settings_nonce_action(): string {
		return self::SETTINGS_NONCE;
	}

	/**
	 * アップロード送信を処理する。
	 */
	public function handle_upload(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		check_admin_referer( self::NONCE );

		try {
			if ( empty( $_FILES['fileshare_files'] ) ) {
				throw new RuntimeException( __( 'ファイルが選択されていません。', 'fileshare' ) );
			}
			$options = array(
				'max_downloads' => isset( $_POST['max_downloads'] ) ? absint( $_POST['max_downloads'] ) : 0,
				'expires_days'  => isset( $_POST['expires_days'] ) ? absint( $_POST['expires_days'] ) : 0,
				'password'      => isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '',
			);

			// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			$result = FileShare_Uploader::handle( $_FILES['fileshare_files'], $options );

			set_transient( 'fileshare_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
			$this->set_notice( 'success', __( 'アップロードが完了しました。', 'fileshare' ) );
		} catch ( Throwable $e ) {
			$this->set_notice( 'error', $e->getMessage() );
		}

		$this->redirect_back();
	}

	/**
	 * ファイル削除を処理する。
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '権限がありません。', 'fileshare' ) );
		}
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		if ( $id > 0 ) {
			$cleanup = new FileShare_Cleanup();
			if ( $cleanup->delete_record( $id ) ) {
				FileShare_Logger::log( 'delete', 'success', FileShare_Security::client_ip(), null, "id={$id}" );
				$this->set_notice( 'success', __( 'ファイルを削除しました。', 'fileshare' ) );
			} else {
				$this->set_notice( 'error', __( '削除対象が見つかりませんでした。', 'fileshare' ) );
			}
		}
		$this->redirect_back();
	}

	/**
	 * 一覧ページへリダイレクトする。
	 */
	private function redirect_back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * 通知を保存する。
	 *
	 * @param string $type    success/error。
	 * @param string $message メッセージ。
	 */
	private function set_notice( string $type, string $message ): void {
		set_transient(
			'fileshare_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * 通知を取り出す（1 回限り）。
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'fileshare_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}

	/**
	 * フォーム用の nonce フィールドを出力するためのキーを返す。
	 */
	public static function nonce_action(): string {
		return self::NONCE;
	}
}
