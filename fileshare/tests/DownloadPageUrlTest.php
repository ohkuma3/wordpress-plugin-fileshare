<?php
/**
 * ダウンロードページ専用URL生成の単体テスト。
 *
 * 「ダウンロードページ URL を開いても表示されない」不具合の回帰防止。
 * 生成 URL がサイトのトップページではなく、固定ページ不要の専用URL
 * （?fileshare_download=1）を指し、ダウンロード ID を付与できることを検証する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class DownloadPageUrlTest extends TestCase {

	public function test_returns_standalone_url_without_id(): void {
		$url = FileShare_Public::download_url();

		$this->assertSame( 'https://example.test/?fileshare_download=1', $url );
	}

	public function test_appends_download_id(): void {
		$url = FileShare_Public::download_url( 'abc123' );

		$this->assertSame( 'https://example.test/?fileshare_download=1&fsid=abc123', $url );
	}

	public function test_does_not_depend_on_a_published_page(): void {
		// 固定ページ/ショートコードの有無に関わらず常に専用URLを返す。
		$GLOBALS['__fs_test_posts'] = array();

		$this->assertSame( 'https://example.test/?fileshare_download=1', FileShare_Public::download_url() );
	}

	public function test_query_var_constant_is_stable(): void {
		$this->assertSame( 'fileshare_download', FileShare_Public::QUERY_VAR );
	}
}
