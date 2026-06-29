<?php
/**
 * ファイルアップロード処理（複数ファイルの自動 ZIP 圧縮を含む）。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * アップロードされたファイルを検証・保存し、メタデータを登録する。
 */
final class FileShare_Uploader {

	/**
	 * アップロードを処理する。
	 *
	 * @param array<string,mixed> $files         $_FILES['fileshare_files'] 相当の配列。
	 * @param array<string,mixed> $options       max_downloads, expires_days, password を含むオプション。
	 * @return array{download_id:string,password:string,file_name:string}
	 *
	 * @throws RuntimeException 検証・保存に失敗した場合。
	 */
	public static function handle( array $files, array $options ): array {
		$entries = self::normalize( $files );
		if ( empty( $entries ) ) {
			throw new RuntimeException( __( 'アップロードされたファイルがありません。', 'fileshare' ) );
		}

		self::validate( $entries );

		$storage_dir = FileShare_DB::storage_dir();
		if ( ! is_dir( $storage_dir ) ) {
			wp_mkdir_p( $storage_dir );
		}

		$is_zip = count( $entries ) > 1;

		if ( $is_zip ) {
			[ $stored_name, $display_name, $size ] = self::store_as_zip( $entries, $storage_dir );
		} else {
			[ $stored_name, $display_name, $size ] = self::store_single( $entries[0], $storage_dir );
		}

		$download_id = self::generate_download_id();
		$password    = ! empty( $options['password'] )
			? (string) $options['password']
			: wp_generate_password( 12, false );

		$expires_at = null;
		$days       = (int) ( $options['expires_days'] ?? 0 );
		if ( $days > 0 ) {
			// WordPress のローカル時刻で保存期限を記録する。
			$expires_at = wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		}

		$id = FileShare_DB::insert(
			array(
				'download_id'   => $download_id,
				'password_hash' => password_hash( $password, PASSWORD_DEFAULT ),
				'file_name'     => $display_name,
				'stored_name'   => $stored_name,
				'file_size'     => $size,
				'is_zip'        => $is_zip,
				'max_downloads' => max( 0, (int) ( $options['max_downloads'] ?? 0 ) ),
				'uploaded_by'   => get_current_user_id(),
				'expires_at'    => $expires_at,
			)
		);

		if ( 0 === $id ) {
			@unlink( $storage_dir . $stored_name );
			throw new RuntimeException( __( 'メタデータの保存に失敗しました。', 'fileshare' ) );
		}

		FileShare_Logger::log(
			'upload',
			'success',
			FileShare_Security::client_ip(),
			$download_id,
			$display_name
		);

		return array(
			'download_id' => $download_id,
			'password'    => $password,
			'file_name'   => $display_name,
		);
	}

	/**
	 * $_FILES 形式を {name,tmp_name,size,error} のリストへ正規化する。
	 *
	 * @param array<string,mixed> $files $_FILES エントリ。
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize( array $files ): array {
		$out = array();
		if ( ! isset( $files['name'] ) ) {
			return $out;
		}

		if ( is_array( $files['name'] ) ) {
			$count = count( $files['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
					continue;
				}
				$out[] = array(
					'name'     => (string) $files['name'][ $i ],
					'tmp_name' => (string) $files['tmp_name'][ $i ],
					'size'     => (int) $files['size'][ $i ],
					'error'    => (int) $files['error'][ $i ],
				);
			}
		} elseif ( UPLOAD_ERR_NO_FILE !== (int) $files['error'] ) {
			$out[] = array(
				'name'     => (string) $files['name'],
				'tmp_name' => (string) $files['tmp_name'],
				'size'     => (int) $files['size'],
				'error'    => (int) $files['error'],
			);
		}
		return $out;
	}

	/**
	 * 各エントリの妥当性を検証する。
	 *
	 * @param array<int,array<string,mixed>> $entries 正規化済みエントリ。
	 *
	 * @throws RuntimeException 不正な場合。
	 */
	private static function validate( array $entries ): void {
		foreach ( $entries as $e ) {
			if ( UPLOAD_ERR_OK !== (int) $e['error'] ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: file name */
						__( '「%s」のアップロードに失敗しました。', 'fileshare' ),
						$e['name']
					)
				);
			}
			if ( ! is_uploaded_file( $e['tmp_name'] ) ) {
				throw new RuntimeException( __( '不正なアップロードを検出しました。', 'fileshare' ) );
			}

			// 拡張子が WordPress の許可リストにない場合は拒否。
			$check = wp_check_filetype( $e['name'] );
			if ( empty( $check['ext'] ) || ! array_key_exists( $check['ext'], self::flatten_allowed() ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: file name */
						__( '「%s」は許可されていないファイル形式です。', 'fileshare' ),
						$e['name']
					)
				);
			}
		}
	}

	/**
	 * 許可された拡張子を ext => mime のフラットな連想配列で返す。
	 *
	 * @return array<string,string>
	 */
	private static function flatten_allowed(): array {
		$flat = array();
		foreach ( get_allowed_mime_types() as $exts => $mime ) {
			foreach ( explode( '|', $exts ) as $ext ) {
				$flat[ $ext ] = $mime;
			}
		}
		return $flat;
	}

	/**
	 * 単一ファイルを保存する。
	 *
	 * @param array<string,mixed> $entry       エントリ。
	 * @param string              $storage_dir 保存先。
	 * @return array{0:string,1:string,2:int} stored_name, display_name, size。
	 *
	 * @throws RuntimeException 保存に失敗した場合。
	 */
	private static function store_single( array $entry, string $storage_dir ): array {
		$display     = sanitize_file_name( $entry['name'] );
		$ext         = pathinfo( $display, PATHINFO_EXTENSION );
		$stored_name = self::random_stored_name( $ext );
		$dest        = $storage_dir . $stored_name;

		if ( ! @move_uploaded_file( $entry['tmp_name'], $dest ) ) {
			throw new RuntimeException( __( 'ファイルの保存に失敗しました。', 'fileshare' ) );
		}
		return array( $stored_name, $display, (int) filesize( $dest ) );
	}

	/**
	 * 複数ファイルを ZIP にまとめて保存する。
	 *
	 * @param array<int,array<string,mixed>> $entries     エントリ群。
	 * @param string                         $storage_dir 保存先。
	 * @return array{0:string,1:string,2:int} stored_name, display_name, size。
	 *
	 * @throws RuntimeException 圧縮に失敗した場合。
	 */
	private static function store_as_zip( array $entries, string $storage_dir ): array {
		$stored_name = self::random_stored_name( 'zip' );
		$dest        = $storage_dir . $stored_name;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( __( 'ZIP アーカイブを作成できませんでした。', 'fileshare' ) );
		}

		$used = array();
		foreach ( $entries as $entry ) {
			$name = sanitize_file_name( $entry['name'] );
			// 同名衝突を回避。
			$base    = pathinfo( $name, PATHINFO_FILENAME );
			$ext     = pathinfo( $name, PATHINFO_EXTENSION );
			$counter = 1;
			while ( isset( $used[ $name ] ) ) {
				$name = $base . '-' . $counter . ( $ext ? '.' . $ext : '' );
				$counter++;
			}
			$used[ $name ] = true;
			$zip->addFile( $entry['tmp_name'], $name );
		}
		$zip->close();

		// addFile は close 時に tmp を参照するため、ここで明示的にクリーンアップ。
		foreach ( $entries as $entry ) {
			if ( is_uploaded_file( $entry['tmp_name'] ) && file_exists( $entry['tmp_name'] ) ) {
				@unlink( $entry['tmp_name'] );
			}
		}

		$display = 'fileshare-' . gmdate( 'Ymd-His' ) . '.zip';
		return array( $stored_name, $display, (int) filesize( $dest ) );
	}

	/**
	 * 衝突しにくいランダムな保存名を生成する。
	 *
	 * @param string $ext 拡張子。
	 */
	private static function random_stored_name( string $ext ): string {
		$ext = preg_replace( '/[^a-zA-Z0-9]/', '', $ext );
		$name = bin2hex( random_bytes( 16 ) );
		return $ext ? "{$name}.{$ext}" : $name;
	}

	/**
	 * 一意なダウンロード ID を生成する。
	 */
	private static function generate_download_id(): string {
		do {
			$id = strtolower( bin2hex( random_bytes( 6 ) ) );
		} while ( null !== FileShare_DB::find_by_download_id( $id ) );
		return $id;
	}
}
