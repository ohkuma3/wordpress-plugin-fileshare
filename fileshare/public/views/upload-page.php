<?php
/**
 * フロント: 専用ログイン + アップロードページ（ショートコード出力）。
 *
 * @package FileShare
 *
 * @var array<string,mixed> $state 表示状態。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$enabled       = (bool) $state['enabled'];
$configured    = (bool) $state['configured'];
$authenticated = (bool) $state['authenticated'];
$username      = (string) $state['username'];
$login_nonce   = (string) $state['login_nonce'];
$upload_nonce  = (string) $state['upload_nonce'];
$logout_nonce  = (string) $state['logout_nonce'];
$flash         = is_array( $state['flash'] ) ? $state['flash'] : null;
$can_manage    = (bool) $state['can_manage'];
$form_action   = esc_url( (string) $state['form_action'] );

$flash_type    = $flash ? (string) ( $flash['type'] ?? 'error' ) : '';
$flash_message = $flash ? (string) ( $flash['message'] ?? '' ) : '';
$result        = ( $flash && isset( $flash['result'] ) && is_array( $flash['result'] ) ) ? $flash['result'] : null;
?>
<div class="fileshare-public">
	<div class="fileshare-public__card">
		<h2 class="fileshare-public__title">
			<?php esc_html_e( 'ファイルのアップロード', 'fileshare' ); ?>
		</h2>

		<?php if ( ! $enabled ) : ?>
			<?php if ( $can_manage ) : ?>
				<div class="fileshare-public__error">
					<?php esc_html_e( 'フロントアップロードが無効、または認証情報が未設定です。管理画面の「FileShare → フロント設定」で有効化してください。', 'fileshare' ); ?>
				</div>
			<?php else : ?>
				<p class="fileshare-public__filename"><?php esc_html_e( '現在この機能は利用できません。', 'fileshare' ); ?></p>
			<?php endif; ?>

		<?php else : ?>

			<?php if ( '' !== $flash_message ) : ?>
				<div class="fileshare-public__<?php echo 'success' === $flash_type ? 'note' : 'error'; ?>">
					<?php echo esc_html( $flash_message ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $authenticated ) : ?>
				<?php // ログインフォーム。 ?>
				<form method="post" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="fileshare-public__form">
					<input type="hidden" name="fileshare_action" value="front_login">
					<input type="hidden" name="fileshare_nonce" value="<?php echo esc_attr( $login_nonce ); ?>">

					<label class="fileshare-public__label" for="fs_user"><?php esc_html_e( 'ユーザー名', 'fileshare' ); ?></label>
					<input type="text" id="fs_user" name="fs_user" required autocomplete="username">

					<label class="fileshare-public__label" for="fs_pass"><?php esc_html_e( 'パスワード', 'fileshare' ); ?></label>
					<input type="password" id="fs_pass" name="fs_pass" required autocomplete="current-password">

					<button type="submit" class="fileshare-public__btn"><?php esc_html_e( 'ログイン', 'fileshare' ); ?></button>
				</form>

			<?php else : ?>

				<?php if ( $result ) : ?>
					<?php
					$page_url = FileShare_Public::download_url( (string) $result['download_id'] );
					?>
					<div class="fileshare-public__result">
						<p class="fileshare-public__label"><?php esc_html_e( 'アップロード結果（この画面でのみ表示されます）', 'fileshare' ); ?></p>

						<label class="fileshare-public__label" for="fs_res_id"><?php esc_html_e( 'ダウンロード ID', 'fileshare' ); ?></label>
						<input type="text" id="fs_res_id" readonly value="<?php echo esc_attr( (string) $result['download_id'] ); ?>">

						<label class="fileshare-public__label" for="fs_res_pw"><?php esc_html_e( 'パスワード', 'fileshare' ); ?></label>
						<input type="text" id="fs_res_pw" readonly value="<?php echo esc_attr( (string) $result['password'] ); ?>">

						<label class="fileshare-public__label" for="fs_res_url"><?php esc_html_e( 'ダウンロードページ URL', 'fileshare' ); ?></label>
						<input type="text" id="fs_res_url" readonly value="<?php echo esc_url( $page_url ); ?>">
					</div>
				<?php endif; ?>

				<div class="fileshare-public__userbar">
					<span class="fileshare-public__user">
						<span class="dashicons dashicons-admin-users"></span> <?php echo esc_html( $username ); ?>
					</span>
					<form method="post" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="fileshare-public__logout">
						<input type="hidden" name="fileshare_action" value="front_logout">
						<input type="hidden" name="fileshare_nonce" value="<?php echo esc_attr( $logout_nonce ); ?>">
						<button type="submit" class="fileshare-public__link"><?php esc_html_e( 'ログアウト', 'fileshare' ); ?></button>
					</form>
				</div>

				<form method="post" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" enctype="multipart/form-data" class="fileshare-public__form">
					<input type="hidden" name="fileshare_action" value="front_upload">
					<input type="hidden" name="fileshare_nonce" value="<?php echo esc_attr( $upload_nonce ); ?>">

					<label class="fileshare-public__label" for="fs_files"><?php esc_html_e( 'ファイル（複数選択時は自動的に ZIP に圧縮されます）', 'fileshare' ); ?></label>
					<input type="file" id="fs_files" name="fileshare_files[]" multiple required>

					<label class="fileshare-public__label" for="fs_up_pw"><?php esc_html_e( 'パスワード（空欄なら自動生成）', 'fileshare' ); ?></label>
					<input type="text" id="fs_up_pw" name="password" autocomplete="off" placeholder="<?php esc_attr_e( '自動生成', 'fileshare' ); ?>">

					<label class="fileshare-public__label" for="fs_up_max"><?php esc_html_e( '最大ダウンロード回数（0 = 無制限）', 'fileshare' ); ?></label>
					<input type="number" id="fs_up_max" name="max_downloads" min="0" value="0">

					<label class="fileshare-public__label" for="fs_up_exp"><?php esc_html_e( '保存期間（日数、0 = 無期限）', 'fileshare' ); ?></label>
					<input type="number" id="fs_up_exp" name="expires_days" min="0" value="7">

					<button type="submit" class="fileshare-public__btn"><?php esc_html_e( 'アップロード', 'fileshare' ); ?></button>
				</form>

			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
