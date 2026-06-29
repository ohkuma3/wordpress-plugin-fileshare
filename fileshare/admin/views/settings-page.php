<?php
/**
 * 管理画面: フロントアップロード設定ページ。
 *
 * @package FileShare
 *
 * @var array{enabled:bool,username:string,password_hash:string} $settings 現在の設定。
 * @var array{type:string,message:string}|null                   $notice   通知。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$action_url   = admin_url( 'admin-post.php' );
$nonce        = FileShare_Admin::settings_nonce_action();
$has_password = '' !== $settings['password_hash'];
$upload_url   = FileShare_Front_Upload::upload_url();
$is_active    = FileShare_Settings::is_enabled();
?>
<div class="wrap fileshare-wrap">
	<h1 class="fileshare-title">
		<span class="dashicons dashicons-share-alt"></span>
		<?php esc_html_e( 'FileShare — フロント設定', 'fileshare' ); ?>
	</h1>

	<?php if ( $notice ) : ?>
		<div class="fileshare-notice fileshare-notice--<?php echo esc_attr( $notice['type'] ); ?>">
			<?php echo esc_html( $notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<div class="fileshare-card">
		<h2><?php esc_html_e( 'フロントからのアップロード', 'fileshare' ); ?></h2>
		<p>
			<?php esc_html_e( '下記の専用URLにアクセスすると、ここで設定した専用 ID/パスワードでログインしてファイルをアップロードできます。固定ページやショートコードは不要です。WordPress のログインとは別の認証です。', 'fileshare' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="fileshare-form" autocomplete="off">
			<input type="hidden" name="action" value="fileshare_save_settings">
			<?php wp_nonce_field( $nonce ); ?>

			<div class="fileshare-field">
				<label>
					<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
					<?php esc_html_e( 'フロントアップロードを有効にする', 'fileshare' ); ?>
				</label>
			</div>

			<div class="fileshare-field">
				<label for="fs-set-user"><?php esc_html_e( 'ユーザー名', 'fileshare' ); ?></label>
				<input type="text" name="username" id="fs-set-user" value="<?php echo esc_attr( $settings['username'] ); ?>" autocomplete="off" required>
			</div>

			<div class="fileshare-field">
				<label for="fs-set-pass">
					<?php
					echo $has_password
						? esc_html__( 'パスワード（変更する場合のみ入力／空欄なら現在のまま）', 'fileshare' )
						: esc_html__( 'パスワード', 'fileshare' );
					?>
				</label>
				<input type="password" name="password" id="fs-set-pass" value="" autocomplete="new-password" <?php echo $has_password ? '' : 'required'; ?>>
				<?php if ( $has_password ) : ?>
					<p class="description"><?php esc_html_e( 'パスワードは設定済みです（ハッシュ化して保存されており、表示はできません）。', 'fileshare' ); ?></p>
				<?php endif; ?>
			</div>

			<button type="submit" class="button button-primary fileshare-submit"><?php esc_html_e( '設定を保存', 'fileshare' ); ?></button>
		</form>
	</div>

	<div class="fileshare-card">
		<h2><?php esc_html_e( '専用アップロードURL', 'fileshare' ); ?></h2>
		<?php if ( $is_active ) : ?>
			<p><?php esc_html_e( 'このURLを担当者に共有してください。アクセスすると専用ログイン画面が表示されます。', 'fileshare' ); ?></p>
			<div class="fileshare-field">
				<input type="text" readonly value="<?php echo esc_url( $upload_url ); ?>" onfocus="this.select();" style="width:100%;max-width:640px;">
			</div>
			<p>
				<a href="<?php echo esc_url( $upload_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'アップロードページを開く', 'fileshare' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( '「フロントアップロードを有効にする」をオンにして保存すると、ここに専用URLが表示されます。', 'fileshare' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<div class="fileshare-card">
		<h2><?php esc_html_e( '使い方', 'fileshare' ); ?></h2>
		<ol>
			<li><?php esc_html_e( '上のフォームでユーザー名とパスワードを設定し、「フロントアップロードを有効にする」をオンにして保存します。', 'fileshare' ); ?></li>
			<li><?php esc_html_e( '「専用アップロードURL」に表示されたURLを、アップロード担当者に共有します。', 'fileshare' ); ?></li>
			<li><?php esc_html_e( '担当者は、そのURLにアクセスして上記の ID/パスワードでログインし、ファイルをアップロードします。', 'fileshare' ); ?></li>
		</ol>
	</div>
</div>
