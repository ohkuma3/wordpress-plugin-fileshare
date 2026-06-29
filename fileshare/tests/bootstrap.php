<?php
/**
 * PHPUnit ブートストラップ。
 *
 * WordPress 非依存で単体テストを行うため、必要最小限の WP 関数・定数をスタブ化する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

// テスト用 ABSPATH（各クラスの直接アクセスガードを通すため）。
define( 'ABSPATH', __DIR__ . '/' );

// 時間定数。
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS   = 3600;
const DAY_IN_SECONDS    = 86400;

// 一時アップロードディレクトリ（SQLite ログ DB の置き場所）。
$fs_base = sys_get_temp_dir() . '/fileshare-tests-' . getmypid();
if ( ! is_dir( $fs_base . '/fileshare-files' ) ) {
	mkdir( $fs_base . '/fileshare-files', 0777, true );
}
$GLOBALS['__fs_upload_base'] = $fs_base;

/* ------------------------------------------------------------------ *
 *  WordPress 関数スタブ
 * ------------------------------------------------------------------ */

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return array( 'basedir' => $GLOBALS['__fs_upload_base'] );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $s ): string {
		return rtrim( $s, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'unit-test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ): bool {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

/* ------------------------------------------------------------------ *
 *  ダウンロードページ検出用スタブ
 * ------------------------------------------------------------------ */

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( string $key, string $value, string $url ): string {
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . $key . '=' . $value;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( string $content, string $tag ): bool {
		return false !== strpos( $content, '[' . $tag );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ): string {
		return is_object( $post ) && isset( $post->permalink ) ? (string) $post->permalink : '';
	}
}

if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['__fs_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['__fs_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['__fs_transients'][ $key ] );
		return true;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * 最小限の WP_Query スタブ。$GLOBALS['__fs_test_posts'] を返す。
	 */
	class WP_Query {
		/** @var array<int,object> */
		public array $posts;

		public function __construct( array $args = array() ) {
			$this->posts = $GLOBALS['__fs_test_posts'] ?? array();
		}
	}
}

/* ------------------------------------------------------------------ *
 *  テスト対象クラスの読み込み
 * ------------------------------------------------------------------ */

$inc = dirname( __DIR__ ) . '/includes/';
require_once $inc . 'class-fileshare-logger.php';
require_once $inc . 'class-fileshare-security.php';
require_once $inc . 'class-fileshare-download.php';
require_once $inc . 'class-fileshare-uploader.php';
require_once $inc . 'class-fileshare-settings.php';
require_once $inc . 'class-fileshare-front-upload.php';
require_once $inc . 'class-fileshare-public.php';

// ログ用テーブルを初期化。
FileShare_Logger::install();
