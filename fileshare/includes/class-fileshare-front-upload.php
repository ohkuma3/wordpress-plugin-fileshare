<?php
/**
 * フロントからの専用ログイン + ファイルアップロード。
 *
 * 固定ページを用意しなくても、専用URL（例: /?fileshare_upload=1）にアクセスすると
 * 専用 ID/パスワードによるログインフォームとアップロードフォームを表示する。
 * WordPress ユーザーとは独立して動作する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 専用URL（?fileshare_upload=1）のアップロードページとフロント側エンドポイントを担う。
 */
final class FileShare_Front_Upload {

	private const NONCE_LOGIN  = 'fileshare_front_login';
	private const NONCE_UPLOAD = 'fileshare_front_upload';
	private const NONCE_LOGOUT = 'fileshare_front_logout';

	/** ブルートフォース計測用の擬似 ID。 */
	private const BUCKET = 'front_upload';

	/** 専用アップロードページを示すクエリ変数。 */
	public const QUERY_VAR = 'fileshare_upload';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle_endpoints' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_register_assets' ) );
	}

	/**
	 * 専用アップロードページの URL を返す。
	 *
	 * 固定ページやショートコードを設置せずに利用できるエンドポイント。
	 */
	public static function upload_url(): string {
		return home_url( '/?' . self::QUERY_VAR . '=1' );
	}

	/**
	 * フロント用スタイルを登録する（読み込みはショートコード描画時）。
	 */
	public function maybe_register_assets(): void {
		if ( ! wp_style_is( 'fileshare-public', 'registered' ) ) {
			wp_register_style(
				'fileshare-public',
				FILESHARE_PLUGIN_URL . 'public/css/public.css',
				array(),
				FILESHARE_VERSION
			);
		}
	}

	/**
	 * ログイン / ログアウト / アップロードの POST を処理する。
	 */
	public function handle_endpoints(): void {
		$action = '';
		if ( isset( $_POST['fileshare_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( (string) $_POST['fileshare_action'] ) );
		}

		switch ( $action ) {
			case 'front_login':
				$this->process_login();
				break;
			case 'front_logout':
				$this->process_logout();
				break;
			case 'front_upload':
				$this->process_upload();
				break;
		}
	}

	/**
	 * 専用 URL（?fileshare_upload=1）へのアクセス時にスタンドアロンページを出力する。
	 *
	 * 固定ページに依存せず、テーマのヘッダー/フッターを使わない独立した
	 * HTML ドキュメントとしてログイン/アップロードフォームを表示する。
	 */
	public function maybe_render_standalone(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示のみ。GET 値は使用しない。
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_standalone_page();
		exit;
	}

	/**
	 * 表示用の状態を組み立てる。
	 *
	 * @return array<string,mixed>
	 */
	private function build_state(): array {
		return array(
			'enabled'       => FileShare_Settings::is_enabled(),
			'configured'    => FileShare_Settings::is_configured(),
			'authenticated' => FileShare_Settings::is_front_authenticated(),
			'username'      => FileShare_Settings::username(),
			'login_nonce'   => wp_create_nonce( self::NONCE_LOGIN ),
			'upload_nonce'  => wp_create_nonce( self::NONCE_UPLOAD ),
			'logout_nonce'  => wp_create_nonce( self::NONCE_LOGOUT ),
			'flash'         => $this->pull_flash(),
			'form_action'   => self::upload_url(),
			'can_manage'    => current_user_can( 'manage_options' ),
		);
	}

	/**
	 * スタンドアロンの完全な HTML ドキュメントを出力する。
	 */
	private function render_standalone_page(): void {
		$this->maybe_register_assets();
		wp_enqueue_style( 'fileshare-public' );

		$state = $this->build_state();

		ob_start();
		require FILESHARE_PLUGIN_DIR . 'public/views/upload-page.php';
		$body = (string) ob_get_clean();

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( __( 'ファイルのアップロード', 'fileshare' ) . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="fileshare-standalone">
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- upload-page.php 内で個別にエスケープ済み。 ?>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * ログイン POST を処理する。
	 */
	private function process_login(): void {
		// 専用URL（?fileshare_upload=1）へ戻す。リファラーは送信先URLと一致して
		// wp_get_referer() が false になり TOP へ落ちるため使用しない。
		$redirect = self::upload_url();

		if ( ! FileShare_Settings::is_enabled() ) {
			$this->redirect( $redirect );
		}

		$nonce_ok = isset( $_POST['fileshare_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fileshare_nonce'] ) ), self::NONCE_LOGIN );
		if ( ! $nonce_ok ) {
			$this->flash( array( 'type' => 'error', 'message' => __( 'セッションが無効です。再読み込みしてください。', 'fileshare' ) ) );
			$this->redirect( $redirect );
		}

		$ip = FileShare_Security::client_ip();

		$remaining = FileShare_Security::block_remaining( $ip );
		if ( $remaining > 0 ) {
			FileShare_Logger::log( 'front_login', 'blocked', $ip, null );
			$this->flash(
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %d: minutes */
						__( '試行回数が多すぎます。約 %d 分後に再度お試しください。', 'fileshare' ),
						(int) ceil( $remaining / 60 )
					),
				)
			);
			$this->redirect( $redirect );
		}

		if ( FileShare_Security::is_rate_limited( 'front_login', 10, MINUTE_IN_SECONDS ) ) {
			$this->flash( array( 'type' => 'error', 'message' => __( 'アクセスが集中しています。しばらくしてから再度お試しください。', 'fileshare' ) ) );
			$this->redirect( $redirect );
		}

		$username = isset( $_POST['fs_user'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fs_user'] ) ) : '';
		$password = isset( $_POST['fs_pass'] ) ? (string) wp_unslash( $_POST['fs_pass'] ) : '';

		if ( FileShare_Settings::verify_credentials( $username, $password ) ) {
			FileShare_Security::register_success( $ip, self::BUCKET );
			FileShare_Logger::log( 'front_login', 'success', $ip, null, $username );
			$this->set_session_cookie( FileShare_Settings::issue_session_token() );
			$this->redirect( $redirect );
		}

		$blocked = FileShare_Security::register_failure( $ip, self::BUCKET );
		FileShare_Logger::log( 'front_login', 'fail', $ip, null, $username );
		$msg = __( 'ユーザー名またはパスワードが正しくありません。', 'fileshare' );
		if ( $blocked ) {
			$msg = __( '試行回数が上限に達したため、一時的にアクセスをブロックしました。', 'fileshare' );
		}
		$this->flash( array( 'type' => 'error', 'message' => $msg ) );
		$this->redirect( $redirect );
	}

	/**
	 * ログアウト POST を処理する。
	 */
	private function process_logout(): void {
		$redirect = self::upload_url();
		$nonce_ok = isset( $_POST['fileshare_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fileshare_nonce'] ) ), self::NONCE_LOGOUT );
		if ( $nonce_ok ) {
			$this->clear_session_cookie();
		}
		$this->redirect( $redirect );
	}

	/**
	 * アップロード POST を処理する。
	 */
	private function process_upload(): void {
		$redirect = self::upload_url();

		if ( ! FileShare_Settings::is_front_authenticated() ) {
			$this->flash( array( 'type' => 'error', 'message' => __( 'ログインが必要です。再度ログインしてください。', 'fileshare' ) ) );
			$this->redirect( $redirect );
		}

		$nonce_ok = isset( $_POST['fileshare_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fileshare_nonce'] ) ), self::NONCE_UPLOAD );
		if ( ! $nonce_ok ) {
			$this->flash( array( 'type' => 'error', 'message' => __( 'セッションが無効です。再読み込みしてください。', 'fileshare' ) ) );
			$this->redirect( $redirect );
		}

		if ( FileShare_Security::is_rate_limited( 'front_upload', 20, MINUTE_IN_SECONDS ) ) {
			$this->flash( array( 'type' => 'error', 'message' => __( 'アップロードが集中しています。しばらくしてから再度お試しください。', 'fileshare' ) ) );
			$this->redirect( $redirect );
		}

		try {
			if ( empty( $_FILES['fileshare_files'] ) ) {
				throw new RuntimeException( __( 'ファイルが選択されていません。', 'fileshare' ) );
			}
			$options = array(
				'max_downloads' => isset( $_POST['max_downloads'] ) ? absint( $_POST['max_downloads'] ) : 0,
				'expires_days'  => isset( $_POST['expires_days'] ) ? absint( $_POST['expires_days'] ) : 0,
				'password'      => isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '',
			);

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$result = FileShare_Uploader::handle( $_FILES['fileshare_files'], $options );

			$this->flash(
				array(
					'type'    => 'success',
					'message' => __( 'アップロードが完了しました。', 'fileshare' ),
					'result'  => $result,
				)
			);
		} catch ( Throwable $e ) {
			$this->flash( array( 'type' => 'error', 'message' => $e->getMessage() ) );
		}

		$this->redirect( $redirect );
	}

	/* ------------------------------------------------------------------ *
	 *  Cookie / フラッシュ / リダイレクト
	 * ------------------------------------------------------------------ */

	/**
	 * セッション Cookie を発行する。
	 *
	 * @param string $token セッショントークン。
	 */
	private function set_session_cookie( string $token ): void {
		$this->write_cookie( $token, time() + FileShare_Settings::session_ttl() );
	}

	/**
	 * セッション Cookie を破棄する。
	 */
	private function clear_session_cookie(): void {
		$this->write_cookie( '', time() - DAY_IN_SECONDS );
	}

	/**
	 * Cookie を書き込む（HttpOnly / SameSite=Lax / SSL 時は Secure）。
	 *
	 * @param string $value   値。
	 * @param int    $expires 失効 UNIX 時刻。
	 */
	private function write_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			FileShare_Settings::SESSION_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// 同一リクエスト内の後続処理にも反映する。
		if ( '' === $value ) {
			unset( $_COOKIE[ FileShare_Settings::SESSION_COOKIE ] );
		} else {
			$_COOKIE[ FileShare_Settings::SESSION_COOKIE ] = $value;
		}
	}

	/**
	 * フラッシュメッセージを一時保存する（IP 単位）。
	 *
	 * @param array<string,mixed> $data メッセージデータ。
	 */
	private function flash( array $data ): void {
		set_transient( $this->flash_key(), $data, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * フラッシュメッセージを取り出す（1 回限り）。
	 *
	 * @return array<string,mixed>|null
	 */
	private function pull_flash(): ?array {
		$key  = $this->flash_key();
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	/**
	 * フラッシュ用キー（IP 単位）。
	 */
	private function flash_key(): string {
		return 'fileshare_front_flash_' . md5( FileShare_Security::client_ip() );
	}

	/**
	 * 安全にリダイレクトして終了する。
	 *
	 * @param string $url 遷移先。
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
