<?php
/**
 * セキュリティ（クライアントIP取得・レート制限・ブルートフォースブロック）の単体テスト。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

	private function unique_ip(): string {
		static $n = 0;
		$n++;
		return '192.0.2.' . ( $n % 250 + 1 ) . '.' . $n;
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_client_ip_from_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
		$this->assertSame( '203.0.113.55', FileShare_Security::client_ip() );
	}

	public function test_invalid_remote_addr_falls_back(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( '0.0.0.0', FileShare_Security::client_ip() );
	}

	public function test_forwarded_header_ignored_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.9';
		// 既定では転送ヘッダを信頼しない。
		$this->assertSame( '203.0.113.10', FileShare_Security::client_ip() );
	}

	public function test_rate_limit_triggers_after_limit(): void {
		$ip                     = $this->unique_ip();
		$_SERVER['REMOTE_ADDR'] = $ip;
		$bucket                 = 'ep-' . $ip;

		$this->assertFalse( FileShare_Security::is_rate_limited( $bucket, 2, 3600 ) ); // 1
		$this->assertFalse( FileShare_Security::is_rate_limited( $bucket, 2, 3600 ) ); // 2
		$this->assertTrue( FileShare_Security::is_rate_limited( $bucket, 2, 3600 ) );  // 3 超過
	}

	public function test_bruteforce_blocks_after_threshold(): void {
		$ip = $this->unique_ip();

		$blocked = false;
		for ( $i = 0; $i < 5; $i++ ) {
			$blocked = FileShare_Security::register_failure( $ip, 'dl-1' );
		}
		// 5 回目の失敗でブロックされる。
		$this->assertTrue( $blocked );
		$this->assertGreaterThan( 0, FileShare_Security::block_remaining( $ip ) );

		// 成功でブロック解除。
		FileShare_Security::register_success( $ip, 'dl-1' );
		$this->assertSame( 0, FileShare_Security::block_remaining( $ip ) );
	}
}
