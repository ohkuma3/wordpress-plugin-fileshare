<?php
/**
 * ダウンロード認証とファイル配信。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ダウンロード ID + パスワード認証、回数制限の判定、ファイル送出を担う。
 */
final class FileShare_Download {

	/** 認証セッショントークンの有効秒数。 */
	private const TOKEN_TTL = 600;

	/**
	 * 認証を試行する。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @param string $password    入力パスワード。
	 * @return array{ok:bool,message:string,token?:string}
	 */
	public static function authenticate( string $download_id, string $password ): array {
		$ip = FileShare_Security::client_ip();

		// IP ブロック判定。
		$remaining = FileShare_Security::block_remaining( $ip );
		if ( $remaining > 0 ) {
			FileShare_Logger::log( 'login', 'blocked', $ip, $download_id );
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %d: minutes */
					__( '試行回数が多すぎます。約 %d 分後に再度お試しください。', 'fileshare' ),
					(int) ceil( $remaining / 60 )
				),
			);
		}

		// レート制限（flask-limiter 相当: 1 分あたり 10 回）。
		if ( FileShare_Security::is_rate_limited( 'download_login', 10, MINUTE_IN_SECONDS ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'アクセスが集中しています。しばらくしてから再度お試しください。', 'fileshare' ),
			);
		}

		$record = FileShare_DB::find_by_download_id( $download_id );
		if ( null === $record ) {
			FileShare_Security::register_failure( $ip, $download_id );
			return array(
				'ok'      => false,
				'message' => __( 'ダウンロード ID またはパスワードが正しくありません。', 'fileshare' ),
			);
		}

		// 期限・回数チェック。
		$availability = self::check_availability( $record );
		if ( true !== $availability ) {
			FileShare_Logger::log( 'login', 'unavailable', $ip, $download_id, (string) $availability );
			return array( 'ok' => false, 'message' => (string) $availability );
		}

		// パスワード検証。
		if ( ! password_verify( $password, (string) $record['password_hash'] ) ) {
			$blocked = FileShare_Security::register_failure( $ip, $download_id );
			FileShare_Logger::log( 'login', 'fail', $ip, $download_id );
			$msg = __( 'ダウンロード ID またはパスワードが正しくありません。', 'fileshare' );
			if ( $blocked ) {
				$msg = __( '試行回数が上限に達したため、一時的にアクセスをブロックしました。', 'fileshare' );
			}
			return array( 'ok' => false, 'message' => $msg );
		}

		// 認証成功。短命トークンを発行。
		FileShare_Security::register_success( $ip, $download_id );
		FileShare_Logger::log( 'login', 'success', $ip, $download_id );

		return array(
			'ok'    => true,
			'token' => self::issue_token( $download_id ),
			'message' => '',
		);
	}

	/**
	 * トークンを検証し、ファイルを送出する。スクリプトを終了する。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @param string $token       認証トークン。
	 */
	public static function serve( string $download_id, string $token ): void {
		$ip = FileShare_Security::client_ip();

		if ( ! self::verify_token( $download_id, $token ) ) {
			FileShare_Logger::log( 'download', 'denied', $ip, $download_id, 'bad token' );
			wp_die( esc_html__( '認証情報が無効か期限切れです。再度ログインしてください。', 'fileshare' ), '', array( 'response' => 403 ) );
		}

		$record = FileShare_DB::find_by_download_id( $download_id );
		if ( null === $record ) {
			wp_die( esc_html__( 'ファイルが見つかりません。', 'fileshare' ), '', array( 'response' => 404 ) );
		}

		$availability = self::check_availability( $record );
		if ( true !== $availability ) {
			wp_die( esc_html( (string) $availability ), '', array( 'response' => 410 ) );
		}

		$path = FileShare_DB::storage_dir() . $record['stored_name'];
		if ( ! is_readable( $path ) ) {
			FileShare_Logger::log( 'download', 'error', $ip, $download_id, 'missing file' );
			wp_die( esc_html__( 'ファイル本体が見つかりません。', 'fileshare' ), '', array( 'response' => 404 ) );
		}

		// 回数を加算してから送出（競合を避けるためアトミック更新）。
		FileShare_DB::increment_download_count( (int) $record['id'] );
		FileShare_Logger::log( 'download', 'success', $ip, $download_id, (string) $record['file_name'] );

		self::stream( $path, (string) $record['file_name'] );
		exit;
	}

	/**
	 * 期限切れ・回数超過を判定する。
	 *
	 * @param array<string,mixed> $record レコード。
	 * @return true|string 利用可能なら true、不可なら理由メッセージ。
	 */
	private static function check_availability( array $record ): bool|string {
		if ( ! empty( $record['expires_at'] ) ) {
			// expires_at は WordPress ローカル時刻で保存されているため、同じタイムゾーンで比較する。
			$expires = DateTimeImmutable::createFromFormat(
				'Y-m-d H:i:s',
				(string) $record['expires_at'],
				wp_timezone()
			);
			if ( $expires instanceof DateTimeImmutable && $expires->getTimestamp() < time() ) {
				return __( 'このファイルは保存期限が切れています。', 'fileshare' );
			}
		}
		$max = (int) $record['max_downloads'];
		if ( $max > 0 && (int) $record['download_count'] >= $max ) {
			return __( 'このファイルはダウンロード回数の上限に達しています。', 'fileshare' );
		}
		return true;
	}

	/**
	 * 認証トークンを発行する（HMAC ベース、ステートレス）。
	 *
	 * @param string $download_id ダウンロード ID。
	 */
	private static function issue_token( string $download_id ): string {
		$expiry  = time() + self::TOKEN_TTL;
		$payload = $download_id . '|' . $expiry;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rawurlencode( base64_encode( $payload . '|' . $sig ) );
	}

	/**
	 * 認証トークンを検証する。
	 *
	 * @param string $download_id ダウンロード ID。
	 * @param string $token       トークン。
	 */
	private static function verify_token( string $download_id, string $token ): bool {
		$decoded = base64_decode( rawurldecode( $token ), true );
		if ( false === $decoded ) {
			return false;
		}
		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		[ $did, $expiry, $sig ] = $parts;

		if ( ! hash_equals( $download_id, $did ) ) {
			return false;
		}
		if ( (int) $expiry < time() ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $did . '|' . $expiry, wp_salt( 'auth' ) );
		return hash_equals( $expected, $sig );
	}

	/**
	 * ファイルを HTTP レスポンスとしてストリーミングする。
	 *
	 * @param string $path         実ファイルパス。
	 * @param string $display_name ダウンロード時のファイル名。
	 */
	private static function stream( string $path, string $display_name ): void {
		// 出力バッファをすべて破棄。
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $display_name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $display_name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		$fp = fopen( $path, 'rb' );
		if ( false === $fp ) {
			wp_die( esc_html__( 'ファイルを開けませんでした。', 'fileshare' ), '', array( 'response' => 500 ) );
		}
		while ( ! feof( $fp ) ) {
			echo fread( $fp, 1024 * 64 ); // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		fclose( $fp );
	}
}
