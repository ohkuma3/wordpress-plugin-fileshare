<?php
/**
 * フロントログインのセッショントークン（HMAC）単体テスト。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class FrontSessionTest extends TestCase {

	/**
	 * private static メソッド sign() を呼び出すヘルパ。
	 */
	private function sign( string $username, int $expiry ): string {
		$ref = new ReflectionMethod( FileShare_Settings::class, 'sign' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $username, $expiry );
	}

	public function test_valid_token_parses_back_to_payload(): void {
		$expiry = time() + 3600;
		$token  = $this->sign( 'taro', $expiry );

		$parsed = FileShare_Settings::parse_session_token( $token );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'taro', $parsed['username'] );
		$this->assertSame( $expiry, $parsed['expiry'] );
	}

	public function test_expired_token_is_rejected(): void {
		$token = $this->sign( 'taro', time() - 10 );
		$this->assertNull( FileShare_Settings::parse_session_token( $token ) );
	}

	public function test_tampered_token_is_rejected(): void {
		$token   = $this->sign( 'taro', time() + 3600 );
		$decoded = base64_decode( rawurldecode( $token ), true );
		// 署名末尾を改ざん。
		$tampered = rawurlencode( base64_encode( $decoded . 'x' ) );
		$this->assertNull( FileShare_Settings::parse_session_token( $tampered ) );
	}

	public function test_username_change_in_payload_breaks_signature(): void {
		$token   = $this->sign( 'taro', time() + 3600 );
		$decoded = base64_decode( rawurldecode( $token ), true );
		// username 部分のみ別名に差し替えると署名が一致しなくなる。
		$parts     = explode( '|', $decoded );
		$forged    = 'jiro|' . $parts[1] . '|' . $parts[2];
		$bad_token = rawurlencode( base64_encode( $forged ) );
		$this->assertNull( FileShare_Settings::parse_session_token( $bad_token ) );
	}

	public function test_garbage_token_is_rejected(): void {
		$this->assertNull( FileShare_Settings::parse_session_token( 'not-a-valid-token' ) );
	}
}
