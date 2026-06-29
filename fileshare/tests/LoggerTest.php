<?php
/**
 * SQLite ロガー（ログ・ログイン試行・IPブロック・レート制限）の単体テスト。
 *
 * @package FileShare
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase {

	/**
	 * テストごとに衝突しない一意な IP を生成する。
	 */
	private function unique_ip(): string {
		static $n = 0;
		$n++;
		return '198.51.100.' . ( $n % 250 + 1 ) . '.' . $n;
	}

	public function test_log_is_recorded_and_retrieved(): void {
		$ip = '203.0.113.1';
		FileShare_Logger::log( 'unittest', 'success', $ip, 'did-xyz', 'detail-A' );
		$logs = FileShare_Logger::recent_logs( 50 );

		$found = array_filter(
			$logs,
			static fn ( $row ) => 'detail-A' === $row['detail'] && 'unittest' === $row['action']
		);
		$this->assertNotEmpty( $found );
	}

	public function test_recent_failures_counts_only_failures(): void {
		$ip = $this->unique_ip();
		FileShare_Logger::record_attempt( $ip, 'd1', false );
		FileShare_Logger::record_attempt( $ip, 'd1', false );
		FileShare_Logger::record_attempt( $ip, 'd1', true ); // 成功はカウントしない。

		$this->assertSame( 2, FileShare_Logger::recent_failures( $ip, 900 ) );
	}

	public function test_block_and_clear(): void {
		$ip = $this->unique_ip();
		$this->assertSame( 0, FileShare_Logger::blocked_until( $ip ) );

		FileShare_Logger::block_ip( $ip, 100 );
		$this->assertGreaterThan( time(), FileShare_Logger::blocked_until( $ip ) );

		FileShare_Logger::clear_block( $ip );
		$this->assertSame( 0, FileShare_Logger::blocked_until( $ip ) );
	}

	public function test_rate_hit_increments_within_window(): void {
		$ip     = $this->unique_ip();
		$bucket = 'bucket-' . $ip;
		$this->assertSame( 1, FileShare_Logger::rate_hit( $bucket, $ip, 3600 ) );
		$this->assertSame( 2, FileShare_Logger::rate_hit( $bucket, $ip, 3600 ) );
		$this->assertSame( 3, FileShare_Logger::rate_hit( $bucket, $ip, 3600 ) );
	}
}
