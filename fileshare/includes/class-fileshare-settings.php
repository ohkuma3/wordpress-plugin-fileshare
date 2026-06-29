<?php
/**
 * フロントアップロード用の設定とログインセッションを管理する。
 *
 * WordPress のユーザーアカウントとは独立した、専用 ID/パスワード（1 組）を
 * ダッシュボードで設定し、フロントの固定ページからログインさせるための仕組み。
 *
 * @package FileShare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 認証情報の保存・検証と、HMAC ベースのセッショントークンを提供する。
 */
final class FileShare_Settings {

	/** 設定を保存する option キー。 */
	private const OPTION = 'fileshare_front_upload';

	/** ログインセッションの有効秒数（1 時間）。 */
	private const SESSION_TTL = 3600;

	/** セッショントークンを保持する Cookie 名。 */
	public const SESSION_COOKIE = 'fileshare_front_session';

	/**
	 * 設定の初期値。
	 *
	 * @return array{enabled:bool,username:string,password_hash:string}
	 */
	private static function defaults(): array {
		return array(
			'enabled'       => false,
			'username'      => '',
			'password_hash' => '',
		);
	}

	/**
	 * 現在の設定を取得する（欠損キーは初期値で補完）。
	 *
	 * @return array{enabled:bool,username:string,password_hash:string}
	 */
	public static function get(): array {
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$merged                  = array_merge( self::defaults(), $opt );
		$merged['enabled']       = ! empty( $merged['enabled'] );
		$merged['username']      = (string) $merged['username'];
		$merged['password_hash'] = (string) $merged['password_hash'];
		return $merged;
	}

	/**
	 * 認証情報（ユーザー名・パスワード）が設定済みか。
	 */
	public static function is_configured(): bool {
		$s = self::get();
		return '' !== $s['username'] && '' !== $s['password_hash'];
	}

	/**
	 * フロントアップロードが有効か（有効フラグ + 認証情報設定済み）。
	 */
	public static function is_enabled(): bool {
		return self::get()['enabled'] && self::is_configured();
	}

	/**
	 * 設定されたユーザー名を返す。
	 */
	public static function username(): string {
		return self::get()['username'];
	}

	/**
	 * 設定の有効秒数を返す。
	 */
	public static function session_ttl(): int {
		return self::SESSION_TTL;
	}

	/**
	 * 設定を更新する。パスワードが空の場合は既存のハッシュを維持する。
	 *
	 * @param bool   $enabled  有効/無効。
	 * @param string $username ユーザー名。
	 * @param string $password 新しいパスワード（空なら変更しない）。
	 */
	public static function update( bool $enabled, string $username, string $password ): void {
		$current = self::get();
		$hash    = $current['password_hash'];
		if ( '' !== $password ) {
			$hash = password_hash( $password, PASSWORD_DEFAULT );
		}
		update_option(
			self::OPTION,
			array(
				'enabled'       => $enabled,
				'username'      => $username,
				'password_hash' => $hash,
			)
		);
	}

	/**
	 * 入力された認証情報を検証する。
	 *
	 * @param string $username 入力ユーザー名。
	 * @param string $password 入力パスワード。
	 */
	public static function verify_credentials( string $username, string $password ): bool {
		$s = self::get();
		if ( ! $s['enabled'] || '' === $s['username'] || '' === $s['password_hash'] ) {
			return false;
		}
		$user_ok = hash_equals( $s['username'], $username );
		$pass_ok = password_verify( $password, $s['password_hash'] );
		return $user_ok && $pass_ok;
	}

	/* ------------------------------------------------------------------ *
	 *  セッショントークン（HMAC ベース・ステートレス）
	 * ------------------------------------------------------------------ */

	/**
	 * 現在のユーザー向けにセッショントークンを発行する。
	 */
	public static function issue_session_token(): string {
		return self::sign( self::username(), time() + self::SESSION_TTL );
	}

	/**
	 * username + expiry に署名したトークンを生成する。
	 *
	 * @param string $username ユーザー名。
	 * @param int    $expiry   失効 UNIX 時刻。
	 */
	private static function sign( string $username, int $expiry ): string {
		$payload = $username . '|' . $expiry;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rawurlencode( base64_encode( $payload . '|' . $sig ) );
	}

	/**
	 * トークンの署名と有効期限を検証し、ペイロードを返す。
	 *
	 * @param string $token トークン。
	 * @return array{username:string,expiry:int}|null 無効なら null。
	 */
	public static function parse_session_token( string $token ): ?array {
		$decoded = base64_decode( rawurldecode( $token ), true );
		if ( false === $decoded ) {
			return null;
		}
		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		[ $username, $expiry, $sig ] = $parts;

		$expected = hash_hmac( 'sha256', $username . '|' . $expiry, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}
		if ( (int) $expiry < time() ) {
			return null;
		}
		return array(
			'username' => $username,
			'expiry'   => (int) $expiry,
		);
	}

	/**
	 * 現在のリクエストがフロントログイン済みか（Cookie のトークンを検証）。
	 */
	public static function is_front_authenticated(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		if ( empty( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return false;
		}
		$token  = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::SESSION_COOKIE ] ) );
		$parsed = self::parse_session_token( $token );
		if ( null === $parsed ) {
			return false;
		}
		// ユーザー名が変更されたら既存セッションを無効化する。
		return hash_equals( self::username(), $parsed['username'] );
	}
}
