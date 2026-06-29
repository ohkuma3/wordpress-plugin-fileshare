<?php
/**
 * 専用アップロードURL生成の単体テスト。
 *
 * 固定ページ/ショートコードに依存せず、専用URLが正しく組み立てられることを確認する。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class FrontUploadUrlTest extends TestCase {

	public function test_upload_url_uses_home_url_root(): void {
		$url = FileShare_Front_Upload::upload_url();
		$this->assertSame( 'https://example.test/?fileshare_upload=1', $url );
	}

	public function test_upload_url_contains_query_var(): void {
		$url = FileShare_Front_Upload::upload_url();
		$this->assertStringContainsString( FileShare_Front_Upload::QUERY_VAR . '=1', $url );
	}

	public function test_query_var_is_stable_identifier(): void {
		// クエリ変数名が変わると既存の共有URLが壊れるため、固定値であることを保証する。
		$this->assertSame( 'fileshare_upload', FileShare_Front_Upload::QUERY_VAR );
	}
}
