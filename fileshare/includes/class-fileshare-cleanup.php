<?php
/**
 * 期限切れファイルの自動クリーンアップ（WP-Cron）。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * cron で期限切れ・回数超過のファイルとログを削除する。
 */
final class FileShare_Cleanup {

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( FileShare_Activator::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * クリーンアップを実行する。
	 *
	 * @return int 削除したファイル数。
	 */
	public function run(): int {
		$expired = FileShare_DB::expired();
		$deleted = 0;

		foreach ( $expired as $record ) {
			if ( $this->delete_record( (int) $record['id'] ) ) {
				$deleted++;
			}
		}

		// 古いログ・期限切れブロックの掃除。
		FileShare_Logger::prune( 30 );

		if ( $deleted > 0 ) {
			FileShare_Logger::log( 'cleanup', 'success', 'system', null, "deleted={$deleted}" );
		}

		return $deleted;
	}

	/**
	 * レコードと実ファイルを削除する。
	 *
	 * @param int $id 行 ID。
	 * @return bool 削除に成功したら true。
	 */
	public function delete_record( int $id ): bool {
		$record = FileShare_DB::find( $id );
		if ( null === $record ) {
			return false;
		}

		$path = FileShare_DB::storage_dir() . $record['stored_name'];
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
		FileShare_DB::delete( $id );
		return true;
	}
}
