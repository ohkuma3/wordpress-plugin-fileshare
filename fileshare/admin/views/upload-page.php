<?php
/**
 * 管理画面: アップロード / ファイル管理ページ。
 *
 * @package FileShare
 *
 * @var array<int,array<string,mixed>>     $files  ファイル一覧。
 * @var array{type:string,message:string}|null $notice 通知。
 * @var array{download_id:string,password:string,file_name:string}|false $result 直近のアップロード結果。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$action_url = admin_url( 'admin-post.php' );
$nonce      = FileShare_Admin::nonce_action();
?>
<div class="wrap fileshare-wrap">
	<h1 class="fileshare-title">
		<span class="dashicons dashicons-share-alt"></span>
		<?php esc_html_e( 'FileShare', 'fileshare' ); ?>
	</h1>

	<?php if ( $notice ) : ?>
		<div class="fileshare-notice fileshare-notice--<?php echo esc_attr( $notice['type'] ); ?>">
			<?php echo esc_html( $notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $result && is_array( $result ) ) : ?>
		<div class="fileshare-card fileshare-result">
			<h2><?php esc_html_e( 'アップロード結果', 'fileshare' ); ?></h2>
			<p><?php esc_html_e( '以下の情報を相手に安全に共有してください。パスワードはこの画面でのみ表示されます。', 'fileshare' ); ?></p>
			<?php
			$page_url = FileShare_Public::download_url( $result['download_id'] );
			?>
			<div class="fileshare-field">
				<label><?php esc_html_e( 'ダウンロード ID', 'fileshare' ); ?></label>
				<div class="fileshare-copy">
					<input type="text" readonly value="<?php echo esc_attr( $result['download_id'] ); ?>">
					<button type="button" class="button fileshare-copy-btn" data-copy="<?php echo esc_attr( $result['download_id'] ); ?>"><?php esc_html_e( 'コピー', 'fileshare' ); ?></button>
				</div>
			</div>
			<div class="fileshare-field">
				<label><?php esc_html_e( 'パスワード', 'fileshare' ); ?></label>
				<div class="fileshare-copy">
					<input type="text" readonly value="<?php echo esc_attr( $result['password'] ); ?>">
					<button type="button" class="button fileshare-copy-btn" data-copy="<?php echo esc_attr( $result['password'] ); ?>"><?php esc_html_e( 'コピー', 'fileshare' ); ?></button>
				</div>
			</div>
			<div class="fileshare-field">
				<label><?php esc_html_e( 'ダウンロードページ URL（固定ページ不要・このまま相手に共有できます）', 'fileshare' ); ?></label>
				<div class="fileshare-copy">
					<input type="text" readonly value="<?php echo esc_url( $page_url ); ?>">
					<button type="button" class="button fileshare-copy-btn" data-copy="<?php echo esc_attr( $page_url ); ?>"><?php esc_html_e( 'コピー', 'fileshare' ); ?></button>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="fileshare-grid">
		<div class="fileshare-card">
			<h2><?php esc_html_e( 'ファイルをアップロード', 'fileshare' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="fileshare-form">
				<input type="hidden" name="action" value="fileshare_upload">
				<?php wp_nonce_field( $nonce ); ?>

				<div class="fileshare-dropzone" id="fileshare-dropzone">
					<input type="file" name="fileshare_files[]" id="fileshare-input" multiple>
					<p class="fileshare-drop-hint"><?php esc_html_e( 'ここにファイルをドラッグ&ドロップ、またはクリックして選択', 'fileshare' ); ?></p>
					<ul class="fileshare-filelist" id="fileshare-filelist"></ul>
					<p class="fileshare-drop-note"><?php esc_html_e( '複数ファイルは自動的に ZIP に圧縮されます。', 'fileshare' ); ?></p>
				</div>

				<div class="fileshare-options">
					<div class="fileshare-field">
						<label for="fileshare-pw"><?php esc_html_e( 'パスワード（空欄なら自動生成）', 'fileshare' ); ?></label>
						<input type="text" name="password" id="fileshare-pw" autocomplete="off" placeholder="<?php esc_attr_e( '自動生成', 'fileshare' ); ?>">
					</div>
					<div class="fileshare-field">
						<label for="fileshare-max"><?php esc_html_e( '最大ダウンロード回数（0 = 無制限）', 'fileshare' ); ?></label>
						<input type="number" name="max_downloads" id="fileshare-max" min="0" value="0">
					</div>
					<div class="fileshare-field">
						<label for="fileshare-exp"><?php esc_html_e( '保存期間（日数、0 = 無期限）', 'fileshare' ); ?></label>
						<input type="number" name="expires_days" id="fileshare-exp" min="0" value="7">
					</div>
				</div>

				<button type="submit" class="button button-primary fileshare-submit"><?php esc_html_e( 'アップロード', 'fileshare' ); ?></button>
			</form>
		</div>

		<div class="fileshare-card">
			<h2><?php esc_html_e( '保存済みファイル', 'fileshare' ); ?></h2>
			<?php if ( empty( $files ) ) : ?>
				<p class="fileshare-empty"><?php esc_html_e( 'まだファイルはありません。', 'fileshare' ); ?></p>
			<?php else : ?>
				<table class="fileshare-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ファイル名', 'fileshare' ); ?></th>
							<th><?php esc_html_e( 'ID', 'fileshare' ); ?></th>
							<th><?php esc_html_e( 'サイズ', 'fileshare' ); ?></th>
							<th><?php esc_html_e( 'DL', 'fileshare' ); ?></th>
							<th><?php esc_html_e( '期限', 'fileshare' ); ?></th>
							<th><?php esc_html_e( '操作', 'fileshare' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $f ) : ?>
							<?php
							$max   = (int) $f['max_downloads'];
							$count = (int) $f['download_count'];
							$dl    = $max > 0 ? "{$count} / {$max}" : (string) $count;
							$exp   = empty( $f['expires_at'] ) ? esc_html__( '無期限', 'fileshare' ) : esc_html( $f['expires_at'] );
							?>
							<tr>
								<td>
									<?php if ( (int) $f['is_zip'] ) : ?><span class="dashicons dashicons-media-archive"></span><?php endif; ?>
									<?php echo esc_html( $f['file_name'] ); ?>
								</td>
								<td><code><?php echo esc_html( $f['download_id'] ); ?></code></td>
								<td><?php echo esc_html( size_format( (int) $f['file_size'] ) ); ?></td>
								<td><?php echo esc_html( $dl ); ?></td>
								<td><?php echo $exp; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'このファイルを削除しますか？', 'fileshare' ) ); ?>');">
										<input type="hidden" name="action" value="fileshare_delete">
										<input type="hidden" name="file_id" value="<?php echo esc_attr( (string) $f['id'] ); ?>">
										<?php wp_nonce_field( $nonce ); ?>
										<button type="submit" class="button-link fileshare-delete"><?php esc_html_e( '削除', 'fileshare' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
