<?php
/**
 * Plugin Name:       FileShare
 * Plugin URI:        https://example.com/fileshare
 * Description:        ドラッグ&ドロップ対応の安全なファイル共有プラグイン。ダウンロードID+パスワード認証、回数制限、自動クリーンアップ、IPブルートフォース対策、レート制限を備えます。
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            kuma-tech.com
 * Author URI:        https://kuma-tech.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fileshare
 * Domain Path:       /languages
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセス禁止。
}

define( 'FILESHARE_VERSION', '1.0.0' );
define( 'FILESHARE_PLUGIN_FILE', __FILE__ );
define( 'FILESHARE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILESHARE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FILESHARE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * クラスのオートローダー。
 *
 * includes/class-fileshare-{name}.php を読み込む。
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'FileShare_' ) && 'FileShare' !== $class ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', $class ) );
		$path = FILESHARE_PLUGIN_DIR . 'includes/class-' . $slug . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// 有効化・無効化フック。
register_activation_hook( __FILE__, array( 'FileShare_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FileShare_Activator', 'deactivate' ) );

/**
 * プラグイン本体を起動する。
 */
function fileshare(): FileShare {
	return FileShare::instance();
}

add_action( 'plugins_loaded', 'fileshare' );
