<?php
/**
 * アップロードファイルの $_FILES 正規化ロジックの単体テスト。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class UploaderNormalizeTest extends TestCase {

	/**
	 * private static normalize() を呼び出す。
	 *
	 * @param array<string,mixed> $files $_FILES エントリ。
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( array $files ): array {
		$ref = new ReflectionMethod( FileShare_Uploader::class, 'normalize' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $files );
	}

	public function test_single_file_is_normalized(): void {
		$files = array(
			'name'     => 'doc.txt',
			'tmp_name' => '/tmp/php123',
			'size'     => 100,
			'error'    => UPLOAD_ERR_OK,
		);
		$out = $this->normalize( $files );
		$this->assertCount( 1, $out );
		$this->assertSame( 'doc.txt', $out[0]['name'] );
		$this->assertSame( 100, $out[0]['size'] );
	}

	public function test_multiple_files_are_normalized(): void {
		$files = array(
			'name'     => array( 'a.txt', 'b.png' ),
			'tmp_name' => array( '/tmp/a', '/tmp/b' ),
			'size'     => array( 10, 20 ),
			'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_OK ),
		);
		$out = $this->normalize( $files );
		$this->assertCount( 2, $out );
		$this->assertSame( 'b.png', $out[1]['name'] );
	}

	public function test_empty_entries_are_skipped(): void {
		$files = array(
			'name'     => array( 'a.txt', '' ),
			'tmp_name' => array( '/tmp/a', '' ),
			'size'     => array( 10, 0 ),
			'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ),
		);
		$out = $this->normalize( $files );
		$this->assertCount( 1, $out );
		$this->assertSame( 'a.txt', $out[0]['name'] );
	}

	public function test_no_file_returns_empty(): void {
		$files = array(
			'name'     => '',
			'tmp_name' => '',
			'size'     => 0,
			'error'    => UPLOAD_ERR_NO_FILE,
		);
		$this->assertCount( 0, $this->normalize( $files ) );
	}
}
