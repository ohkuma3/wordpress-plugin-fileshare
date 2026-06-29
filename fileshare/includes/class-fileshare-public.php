<?php
/**
 * フロント側のダウンロードページ（専用URL + 配信エンドポイント）。
 *
 * 固定ページやショートコードを用意しなくても、専用URL（例: /?fileshare_download=1）に
 * アクセスするとダウンロード ID/パスワードの認証フォームを表示する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 専用URL（?fileshare_download=1）のダウンロードページと認証付き配信を提供する。
 */
final class FileShare_Public {

	private const NONCE = 'fileshare_download_action';

	/** 専用ダウンロードページを示すクエリ変数。 */
	public const QUERY_VAR = 'fileshare_download';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle_endpoints' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_register_assets' ) );
	}

	/**
	 * ダウンロードページの専用URLを返す。
	 *
	 * 固定ページやショートコードを設置せずに利用できるエンドポイント。
	 * 任意でダウンロード ID をクエリに付与する。
	 *
	 * @param string $download_id 付与するダウンロード ID（省略可）。
	 * @return string ダウンロードページ URL。
	 */
	public static function download_url( string $download_id = '' ): string {
		$base = home_url( '/?' . self::QUERY_VAR . '=1' );
		if ( '' !== $download_id ) {
			return add_query_arg( 'fsid', rawurlencode( $download_id ), $base );
		}
		return $base;
	}

	/**
	 * フロント用スタイルを登録する（読み込みはページ描画時）。
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
	 * POST(認証) と GET(配信) のエンドポイントを処理する。
	 */
	public function handle_endpoints(): void {
		// 認証 POST。
		if ( isset( $_POST['fileshare_action'] ) && 'authenticate' === $_POST['fileshare_action'] ) {
			$this->process_authentication();
			return;
		}

		// 配信 GET。
		if ( isset( $_GET['fileshare_serve'], $_GET['fsid'], $_GET['token'] ) ) {
			$download_id = sanitize_text_field( wp_unslash( (string) $_GET['fsid'] ) );
			$token       = (string) wp_unslash( $_GET['token'] );
			FileShare_Download::serve( $download_id, $token );
		}
	}

	/**
	 * 専用 URL（?fileshare_download=1）へのアクセス時にスタンドアロンページを出力する。
	 *
	 * 固定ページに依存せず、テーマのヘッダー/フッターを使わない独立した
	 * HTML ドキュメントとして認証フォームを表示する。
	 */
	public function maybe_render_standalone(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示のみ。GET 値は描画前にサニタイズする。
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_standalone_page();
		exit;
	}

	/**
	 * スタンドアロンの完全な HTML ドキュメントを出力する。
	 */
	private function render_standalone_page(): void {
		$this->maybe_register_assets();
		wp_enqueue_style( 'fileshare-public' );

		$download_id = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示用 ID。サニタイズ済みで認証は別途必要。
		if ( isset( $_GET['fsid'] ) ) {
			$download_id = sanitize_text_field( wp_unslash( (string) $_GET['fsid'] ) );
		}

		$state = $this->resolve_state( $download_id );

		ob_start();
		require FILESHARE_PLUGIN_DIR . 'public/views/download-page.php';
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
	<title><?php echo esc_html( __( 'ファイルのダウンロード', 'fileshare' ) . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="fileshare-standalone">
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- download-page.php 内で個別にエスケープ済み。 ?>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * 認証 POST を処理し、成否を transient に保存してリダイレクトする。
	 */
	private function process_authentication(): void {
		$download_id = isset( $_POST['fsid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fsid'] ) ) : '';
		$password    = isset( $_POST['fspw'] ) ? (string) wp_unslash( $_POST['fspw'] ) : '';

		// 送信先と表示ページが同一の専用URLのため、リファラー依存ではなく固定URLへ戻す。
		$redirect = self::download_url( $download_id );

		$nonce_ok = isset( $_POST['fileshare_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fileshare_nonce'] ) ), self::NONCE );

		if ( ! $nonce_ok ) {
			$this->flash( $download_id, array( 'ok' => false, 'message' => __( 'セッションが無効です。再読み込みしてください。', 'fileshare' ) ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$result = FileShare_Download::authenticate( $download_id, $password );
		$this->flash( $download_id, $result );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * 表示用の状態を組み立てる。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @return array<string,mixed>
	 */
	private function resolve_state( string $download_id ): array {
		$state = array(
			'download_id' => $download_id,
			'error'       => '',
			'serve_url'   => '',
			'file_name'   => '',
			'nonce'       => wp_create_nonce( self::NONCE ),
		);

		if ( '' === $download_id ) {
			return $state;
		}

		$flash = $this->pull_flash( $download_id );
		if ( null !== $flash ) {
			if ( ! empty( $flash['ok'] ) && ! empty( $flash['token'] ) ) {
				$state['serve_url'] = add_query_arg(
					array(
						'fileshare_serve' => '1',
						'fsid'            => rawurlencode( $download_id ),
						'token'           => $flash['token'],
					),
					home_url( '/' )
				);
				$record = FileShare_DB::find_by_download_id( $download_id );
				if ( $record ) {
					$state['file_name'] = (string) $record['file_name'];
				}
			} else {
				$state['error'] = (string) ( $flash['message'] ?? '' );
			}
		}

		return $state;
	}

	/**
	 * 認証結果を一時保存する（IP 単位）。
	 *
	 * @param string              $download_id ダウンロード ID。
	 * @param array<string,mixed> $result      認証結果。
	 */
	private function flash( string $download_id, array $result ): void {
		set_transient( $this->flash_key( $download_id ), $result, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * 一時保存した認証結果を取り出す。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @return array<string,mixed>|null
	 */
	private function pull_flash( string $download_id ): ?array {
		$key  = $this->flash_key( $download_id );
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
			return $data;
		}
		return null;
	}

	/**
	 * flash 用のキーを生成する（IP + ダウンロード ID）。
	 *
	 * @param string $download_id ダウンロード ID。
	 */
	private function flash_key( string $download_id ): string {
		return 'fileshare_flash_' . md5( FileShare_Security::client_ip() . '|' . $download_id );
	}
}
