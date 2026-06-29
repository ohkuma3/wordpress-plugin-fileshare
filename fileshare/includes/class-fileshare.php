<?php
/**
 * メインローダークラス。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグインの各コンポーネントを束ねるシングルトン。
 */
final class FileShare {

	private static ?FileShare $instance = null;

	private FileShare_Admin $admin;
	private FileShare_Public $public;
	private FileShare_Front_Upload $front_upload;
	private FileShare_Cleanup $cleanup;

	/**
	 * シングルトンインスタンスを返す。
	 */
	public static function instance(): FileShare {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * フックを登録する。
	 */
	private function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->admin        = new FileShare_Admin();
		$this->public       = new FileShare_Public();
		$this->front_upload = new FileShare_Front_Upload();
		$this->cleanup      = new FileShare_Cleanup();

		if ( is_admin() ) {
			$this->admin->register();
		}
		$this->public->register();
		$this->front_upload->register();
		$this->cleanup->register();
	}

	/**
	 * 翻訳ファイルを読み込む。
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'fileshare', false, dirname( FILESHARE_PLUGIN_BASENAME ) . '/languages' );
	}
}
