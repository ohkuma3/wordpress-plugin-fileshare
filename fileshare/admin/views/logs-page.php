<?php
/**
 * 管理画面: アクセスログページ。
 *
 * @package FileShare
 *
 * @var array<int,array<string,mixed>> $logs ログ一覧。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap fileshare-wrap">
	<h1 class="fileshare-title">
		<span class="dashicons dashicons-list-view"></span>
		<?php esc_html_e( 'FileShare アクセスログ', 'fileshare' ); ?>
	</h1>

	<div class="fileshare-card">
		<?php if ( empty( $logs ) ) : ?>
			<p class="fileshare-empty"><?php esc_html_e( 'ログはまだありません。', 'fileshare' ); ?></p>
		<?php else : ?>
			<table class="fileshare-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '日時 (UTC)', 'fileshare' ); ?></th>
						<th><?php esc_html_e( 'IP', 'fileshare' ); ?></th>
						<th><?php esc_html_e( 'アクション', 'fileshare' ); ?></th>
						<th><?php esc_html_e( 'ステータス', 'fileshare' ); ?></th>
						<th><?php esc_html_e( 'ダウンロード ID', 'fileshare' ); ?></th>
						<th><?php esc_html_e( '詳細', 'fileshare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $log['created_at'] ); ?></td>
							<td><code><?php echo esc_html( (string) $log['ip'] ); ?></code></td>
							<td><?php echo esc_html( (string) $log['action'] ); ?></td>
							<td><span class="fileshare-status fileshare-status--<?php echo esc_attr( (string) $log['status'] ); ?>"><?php echo esc_html( (string) $log['status'] ); ?></span></td>
							<td><?php echo $log['download_id'] ? '<code>' . esc_html( (string) $log['download_id'] ) . '</code>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( (string) ( $log['detail'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
