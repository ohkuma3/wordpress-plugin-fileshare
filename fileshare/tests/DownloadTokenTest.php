<?php
/**
 * ダウンロード認証トークン（HMAC）の単体テスト。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class DownloadTokenTest extends TestCase {

	/**
	 * private static メソッドを呼び出すヘルパ。
	 */
	private function invoke( string $method, array $args ): mixed {
		$ref = new ReflectionMethod( FileShare_Download::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	public function test_valid_token_passes_verification(): void {
		$token = $this->invoke( 'issue_token', array( 'abc123' ) );
		$this->assertIsString( $token );
		$this->assertTrue( $this->invoke( 'verify_token', array( 'abc123', $token ) ) );
	}

	public function test_token_rejected_for_other_download_id(): void {
		$token = $this->invoke( 'issue_token', array( 'abc123' ) );
		$this->assertFalse( $this->invoke( 'verify_token', array( 'different', $token ) ) );
	}

	public function test_tampered_token_is_rejected(): void {
		$token   = $this->invoke( 'issue_token', array( 'abc123' ) );
		$decoded = base64_decode( rawurldecode( $token ), true );
		// 署名末尾を改ざん。
		$tampered = rawurlencode( base64_encode( $decoded . 'x' ) );
		$this->assertFalse( $this->invoke( 'verify_token', array( 'abc123', $tampered ) ) );
	}

	public function test_garbage_token_is_rejected(): void {
		$this->assertFalse( $this->invoke( 'verify_token', array( 'abc123', 'not-a-valid-token' ) ) );
	}
}
