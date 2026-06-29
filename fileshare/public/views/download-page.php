<?php
/**
 * フロント: ダウンロードページ（ショートコード出力）。
 *
 * @package FileShare
 *
 * @var array<string,mixed> $state 表示状態。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$download_id = (string) $state['download_id'];
$error       = (string) $state['error'];
$serve_url   = (string) $state['serve_url'];
$file_name   = (string) $state['file_name'];
$nonce       = (string) $state['nonce'];
$form_action = esc_url( FileShare_Public::download_url() );
?>
<div class="fileshare-public">
	<div class="fileshare-public__card">
		<h2 class="fileshare-public__title">
			<?php esc_html_e( 'ファイルのダウンロード', 'fileshare' ); ?>
		</h2>

		<?php if ( '' !== $serve_url ) : ?>
			<div class="fileshare-public__success">
				<p><?php esc_html_e( '認証に成功しました。下のボタンからダウンロードできます。', 'fileshare' ); ?></p>
				<?php if ( '' !== $file_name ) : ?>
					<p class="fileshare-public__filename"><span class="dashicons dashicons-media-default"></span> <?php echo esc_html( $file_name ); ?></p>
				<?php endif; ?>
				<a class="fileshare-public__btn" href="<?php echo esc_url( $serve_url ); ?>">
					<?php esc_html_e( 'ダウンロード', 'fileshare' ); ?>
				</a>
			</div>
		<?php else : ?>
			<?php if ( '' !== $error ) : ?>
				<div class="fileshare-public__error"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<form method="post" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="fileshare-public__form">
				<input type="hidden" name="fileshare_action" value="authenticate">
				<input type="hidden" name="fileshare_nonce" value="<?php echo esc_attr( $nonce ); ?>">

				<label class="fileshare-public__label" for="fsid"><?php esc_html_e( 'ダウンロード ID', 'fileshare' ); ?></label>
				<input type="text" id="fsid" name="fsid" value="<?php echo esc_attr( $download_id ); ?>" required autocomplete="off">

				<label class="fileshare-public__label" for="fspw"><?php esc_html_e( 'パスワード', 'fileshare' ); ?></label>
				<input type="password" id="fspw" name="fspw" required autocomplete="off">

				<button type="submit" class="fileshare-public__btn"><?php esc_html_e( '認証してダウンロード', 'fileshare' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
