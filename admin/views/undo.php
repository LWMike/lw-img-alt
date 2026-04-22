<?php
/**
 * Undo screen — list recent batches and allow one-click rollback.
 *
 * Variables provided by LWIA_Admin::render_undo():
 *   $batches      object[]     Recent batch rows: batch_id, source, user_id, row_count, created_at.
 *   $total        int          Total undoable batches (for pagination).
 *   $total_pages  int
 *   $current_page int
 *   $per_page     int
 *   $undo_result  array|false  Result of the last rollback, if just redirected here.
 *                              Keys: rolled_back, skipped, errors.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

$log_base_url = admin_url( 'admin.php?page=lwia-log' );

/**
 * Format a MySQL UTC datetime as relative (< 7 days) or absolute.
 */
$lwia_format_date = function( string $mysql_dt ): string {
	$ts      = strtotime( $mysql_dt );
	$now_utc = current_time( 'timestamp', true );
	$age     = $now_utc - $ts;

	$abs = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql_dt );

	if ( $age >= 0 && $age < 7 * DAY_IN_SECONDS ) {
		/* translators: %s: human-readable time difference, e.g. "3 hours" */
		$rel = sprintf( esc_html__( '%s ago', 'lw-img-alt' ), human_time_diff( $ts, $now_utc ) );
		return '<span title="' . esc_attr( $abs ) . '">' . esc_html( $rel ) . '</span>';
	}

	return esc_html( $abs );
};
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Image Alt — Undo', 'lw-img-alt' ); ?></h1>

	<?php if ( $undo_result ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php printf(
				/* translators: %d: count of restored changes */
				esc_html__( 'Rollback complete. %d change(s) restored.', 'lw-img-alt' ),
				(int) $undo_result['rolled_back']
			); ?>
			<?php if ( $undo_result['skipped'] > 0 ) : ?>
				<?php printf(
					/* translators: %d: skipped count */
					esc_html__( '%d skipped (attachment deleted or had no previous value).', 'lw-img-alt' ),
					(int) $undo_result['skipped']
				); ?>
			<?php endif; ?>
			<?php if ( $undo_result['errors'] > 0 ) : ?>
				<?php printf(
					/* translators: %d: error count */
					esc_html__( '%d error(s). Check the Change Log for details.', 'lw-img-alt' ),
					(int) $undo_result['errors']
				); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Each row represents a group of alt text changes that can be rolled back together. Undo operations restore the previous value for every image in the batch and are themselves logged.', 'lw-img-alt' ); ?>
	</p>

	<?php if ( empty( $batches ) ) : ?>

	<p><?php esc_html_e( 'No undoable batches found. Changes will appear here after your first import or inline edit.', 'lw-img-alt' ); ?></p>

	<?php else : ?>

	<?php
	// ---- Pagination ----
	$pagination = paginate_links( array(
		'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=lwia-undo' ) ) ),
		'format'    => '',
		'current'   => $current_page,
		'total'     => $total_pages,
		'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'lw-img-alt' ),
		'next_text' => esc_html__( 'Next', 'lw-img-alt' ) . ' &raquo;',
		'type'      => 'plain',
	) );
	?>

	<table class="wp-list-table widefat fixed striped lwia-undo-table">
		<thead>
			<tr>
				<th scope="col" style="width:140px;"><?php esc_html_e( 'Date', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:100px;"><?php esc_html_e( 'Source', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:140px;"><?php esc_html_e( 'User', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:90px;"><?php esc_html_e( 'Changes', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Batch ID', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:160px;"><?php esc_html_e( 'Actions', 'lw-img-alt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $batches as $batch ) :
				$user       = get_userdata( (int) $batch->user_id );
				$user_name  = $user ? $user->display_name : '#' . $batch->user_id;
				$row_count  = (int) $batch->row_count;
				$batch_full = (string) $batch->batch_id;
				$batch_short = substr( $batch_full, 0, 8 );

				$batch_log_url = add_query_arg(
					array( 'page' => 'lwia-log', 'batch_id' => urlencode( $batch_full ) ),
					admin_url( 'admin.php' )
				);
			?>
			<tr>
				<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $lwia_format_date( (string) $batch->created_at );
					?>
				</td>
				<td>
					<span class="lwia-source-badge lwia-source-<?php echo esc_attr( (string) $batch->source ); ?>">
						<?php echo esc_html( (string) $batch->source ); ?>
					</span>
				</td>
				<td><?php echo esc_html( $user_name ); ?></td>
				<td>
					<?php echo esc_html(
						sprintf(
							/* translators: %d: number of images */
							_n( '%d image', '%d images', $row_count, 'lw-img-alt' ),
							$row_count
						)
					); ?>
				</td>
				<td>
					<code title="<?php echo esc_attr( $batch_full ); ?>"><?php echo esc_html( $batch_short ); ?>&hellip;</code>
				</td>
				<td>
					<form
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						class="lwia-undo-form"
						data-row-count="<?php echo esc_attr( (string) $row_count ); ?>"
						data-batch-short="<?php echo esc_attr( $batch_short ); ?>"
					>
						<?php wp_nonce_field( 'lwia_undo_batch' ); ?>
						<input type="hidden" name="action"   value="lwia_undo_batch">
						<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch_full ); ?>">
						<button type="submit" class="button button-small button-danger">
							<?php esc_html_e( 'Undo', 'lw-img-alt' ); ?>
						</button>
					</form>
					<a
						href="<?php echo esc_url( $batch_log_url ); ?>"
						class="button button-small lwia-view-details-btn"
					><?php esc_html_e( 'View details', 'lw-img-alt' ); ?></a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pagination ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $pagination; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php endif; ?>

</div><!-- .wrap -->
