<?php
/**
 * Change Log screen.
 *
 * Variables provided by LWIA_Admin::render_log():
 *   $entries        object[]  Log entries for the current page.
 *   $total          int       Total matching entries.
 *   $total_pages    int       Total pages.
 *   $current_page   int       Current page number.
 *   $per_page       int       Entries per page.
 *   $filter_source  string    Active source filter value.
 *   $filter_user_id int       Active user filter value (0 = all).
 *   $filter_date_f  string    Active date_from filter (Y-m-d).
 *   $filter_date_t  string    Active date_to filter (Y-m-d).
 *   $log_users      array     Distinct users who have log entries: [{id, name}].
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

$base_log_url    = admin_url( 'admin.php?page=lwia-log' );
$filters_active  = $filter_source || $filter_user_id || $filter_date_f || $filter_date_t;

/**
 * Format a MySQL UTC datetime as relative (< 7 days) or absolute.
 */
$lwia_format_date = function( string $mysql_dt ) use ( &$lwia_format_date ): string {
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

	<h1><?php esc_html_e( 'Image Alt — Change Log', 'lw-img-alt' ); ?></h1>

	<?php
	// ---- Filter form — auto-applies on dropdown/date change via JS ----
	?>
	<form method="get" class="lwia-filters" id="lwia-log-filters">
		<input type="hidden" name="page" value="lwia-log">

		<label for="lwia-log-source" class="screen-reader-text">
			<?php esc_html_e( 'Filter by source', 'lw-img-alt' ); ?>
		</label>
		<select id="lwia-log-source" name="source">
			<option value="" <?php selected( $filter_source, '' ); ?>><?php esc_html_e( 'All sources', 'lw-img-alt' ); ?></option>
			<option value="manual" <?php selected( $filter_source, 'manual' ); ?>><?php esc_html_e( 'Manual (inline edit)', 'lw-img-alt' ); ?></option>
			<option value="csv"    <?php selected( $filter_source, 'csv' ); ?>><?php esc_html_e( 'CSV import', 'lw-img-alt' ); ?></option>
			<option value="undo"   <?php selected( $filter_source, 'undo' ); ?>><?php esc_html_e( 'Undo / rollback', 'lw-img-alt' ); ?></option>
		</select>

		<?php if ( ! empty( $log_users ) ) : ?>
		<label for="lwia-log-user" class="screen-reader-text">
			<?php esc_html_e( 'Filter by user', 'lw-img-alt' ); ?>
		</label>
		<select id="lwia-log-user" name="user_id">
			<option value="0" <?php selected( $filter_user_id, 0 ); ?>><?php esc_html_e( 'All users', 'lw-img-alt' ); ?></option>
			<?php foreach ( $log_users as $lu ) : ?>
			<option value="<?php echo esc_attr( (string) $lu['id'] ); ?>" <?php selected( $filter_user_id, $lu['id'] ); ?>>
				<?php echo esc_html( $lu['name'] ); ?>
			</option>
			<?php endforeach; ?>
		</select>
		<?php endif; ?>

		<label for="lwia-log-date-from" class="lwia-label-inline">
			<?php esc_html_e( 'From', 'lw-img-alt' ); ?>
		</label>
		<input type="date" id="lwia-log-date-from" name="date_from" value="<?php echo esc_attr( $filter_date_f ); ?>">

		<label for="lwia-log-date-to" class="lwia-label-inline">
			<?php esc_html_e( 'To', 'lw-img-alt' ); ?>
		</label>
		<input type="date" id="lwia-log-date-to" name="date_to" value="<?php echo esc_attr( $filter_date_t ); ?>">

		<?php if ( $filters_active ) : ?>
			<a href="<?php echo esc_url( $base_log_url ); ?>" class="button">
				<?php esc_html_e( 'Clear filters', 'lw-img-alt' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( 0 === $total ) : ?>

	<p><?php esc_html_e( 'No log entries found.', 'lw-img-alt' ); ?></p>

	<?php else : ?>

	<?php
	// ---- Pagination ----
	$log_base_url = add_query_arg(
		array_filter( array(
			'page'      => 'lwia-log',
			'source'    => $filter_source ?: false,
			'user_id'   => $filter_user_id ?: false,
			'date_from' => $filter_date_f  ?: false,
			'date_to'   => $filter_date_t  ?: false,
		) ),
		admin_url( 'admin.php' )
	);

	$pagination = paginate_links( array(
		'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $log_base_url ) ),
		'format'    => '',
		'current'   => $current_page,
		'total'     => $total_pages,
		'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'lw-img-alt' ),
		'next_text' => esc_html__( 'Next', 'lw-img-alt' ) . ' &raquo;',
		'type'      => 'plain',
	) );
	?>

	<?php if ( $pagination ) : ?>
	<div class="tablenav top">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php printf(
					/* translators: %d: entry count */
					esc_html( _n( '%d entry', '%d entries', $total, 'lw-img-alt' ) ),
					(int) $total
				); ?>
			</span>
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $pagination; ?>
		</div>
	</div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped lwia-log-table">
		<thead>
			<tr>
				<th scope="col" class="lwia-log-col-attach"><?php esc_html_e( 'Attachment', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-date"><?php esc_html_e( 'Date', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-alt"><?php esc_html_e( 'Old alt', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-alt"><?php esc_html_e( 'New alt', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-source"><?php esc_html_e( 'Source', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-user"><?php esc_html_e( 'User', 'lw-img-alt' ); ?></th>
				<th scope="col" class="lwia-log-col-batch"><?php esc_html_e( 'Batch ID', 'lw-img-alt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $entry ) :
				$attachment_id = (int) $entry->attachment_id;
				$edit_url      = get_edit_post_link( $attachment_id );
				$user          = get_userdata( (int) $entry->user_id );
				$batch_full    = (string) $entry->batch_id;
				$batch_short   = substr( $batch_full, 0, 8 );
				$old_alt       = (string) $entry->old_alt;
				$new_alt       = (string) $entry->new_alt;

				// Thumbnail for log attachment column.
				$log_thumb = wp_get_attachment_image( $attachment_id, array( 40, 40 ), false, array( 'class' => 'lwia-log-thumb' ) );
				if ( ! $log_thumb ) {
					$mime      = get_post_mime_type( $attachment_id );
					$icon_url  = wp_mime_type_icon( $mime ?: '' );
					if ( $icon_url ) {
						$log_thumb = '<img src="' . esc_url( $icon_url ) . '" width="40" height="40" class="lwia-log-thumb lwia-thumb-icon" alt="">';
					}
				}

				// Link to Change Log filtered to this batch.
				$batch_filter_url = add_query_arg(
					array( 'page' => 'lwia-log', 'batch_id' => urlencode( $batch_full ) ),
					admin_url( 'admin.php' )
				);
			?>
			<tr>
				<td class="lwia-log-col-attach">
					<div class="lwia-log-attach-wrap">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $log_thumb;
						?>
						<span>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo esc_html( (string) $attachment_id ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( (string) $attachment_id ); ?>
							<?php endif; ?>
						</span>
					</div>
				</td>
				<td class="lwia-log-col-date">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside closure
					echo $lwia_format_date( (string) $entry->created_at );
					?>
				</td>
				<td class="lwia-log-alt" title="<?php echo esc_attr( $old_alt ); ?>">
					<?php if ( '' === $old_alt ) : ?>
						<span class="lwia-muted"><?php esc_html_e( '(empty)', 'lw-img-alt' ); ?></span>
					<?php else : ?>
						<?php echo esc_html( $old_alt ); ?>
					<?php endif; ?>
				</td>
				<td class="lwia-log-alt" title="<?php echo esc_attr( $new_alt ); ?>">
					<?php if ( '' === $new_alt ) : ?>
						<span class="lwia-muted"><?php esc_html_e( '(empty)', 'lw-img-alt' ); ?></span>
					<?php else : ?>
						<?php echo esc_html( $new_alt ); ?>
					<?php endif; ?>
				</td>
				<td class="lwia-log-col-source">
					<span class="lwia-source-badge lwia-source-<?php echo esc_attr( (string) $entry->source ); ?>">
						<?php echo esc_html( (string) $entry->source ); ?>
					</span>
				</td>
				<td class="lwia-log-col-user">
					<?php echo $user ? esc_html( $user->display_name ) : esc_html( '#' . $entry->user_id ); ?>
				</td>
				<td class="lwia-log-col-batch">
					<button
						type="button"
						class="button-link lwia-batch-copy"
						data-batch-id="<?php echo esc_attr( $batch_full ); ?>"
						title="<?php esc_attr_e( 'Click to copy full UUID', 'lw-img-alt' ); ?>"
					><code><?php echo esc_html( $batch_short ); ?>&hellip;</code></button>
					<a
						href="<?php echo esc_url( $batch_filter_url ); ?>"
						class="lwia-batch-filter-link"
						title="<?php esc_attr_e( 'Filter log to this batch', 'lw-img-alt' ); ?>"
					>&#x1F50D;</a>
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
