<?php
/**
 * Change Log screen.
 *
 * Variables provided by LWIA_Admin::render_log():
 *   $entries       object[]  Log entries for the current page.
 *   $total         int       Total matching entries.
 *   $total_pages   int       Total pages.
 *   $current_page  int       Current page number.
 *   $per_page      int       Entries per page.
 *   $filter_source string    Active source filter value.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Alt Text Change Log', 'lw-img-alt' ); ?></h1>

	<?php
	// ---- Filter form ----
	$base_log_url = admin_url( 'admin.php?page=lwia-log' );
	?>
	<form method="get" class="lwia-filters">
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

		<?php submit_button( __( 'Filter', 'lw-img-alt' ), 'secondary', 'lwia-log-filter-submit', false ); ?>

		<?php if ( $filter_source ) : ?>
			<a href="<?php echo esc_url( $base_log_url ); ?>" class="button">
				<?php esc_html_e( 'Clear', 'lw-img-alt' ); ?>
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
			'page'   => 'lwia-log',
			'source' => $filter_source ?: false,
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
				<th scope="col" style="width:50px;"><?php esc_html_e( 'ID', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:140px;"><?php esc_html_e( 'Date', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:120px;"><?php esc_html_e( 'Attachment', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Old alt', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'New alt', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:80px;"><?php esc_html_e( 'Source', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:120px;"><?php esc_html_e( 'User', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:100px;"><?php esc_html_e( 'Batch ID', 'lw-img-alt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $entry ) :
				$attachment_id = (int) $entry->attachment_id;
				$edit_url      = get_edit_post_link( $attachment_id );
				$user          = get_userdata( (int) $entry->user_id );
				$batch_short   = substr( (string) $entry->batch_id, 0, 8 );
			?>
			<tr>
				<td><?php echo esc_html( (string) $entry->id ); ?></td>
				<td>
					<?php echo esc_html(
						mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at )
					); ?>
				</td>
				<td>
					<?php if ( $edit_url ) : ?>
						<a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo esc_html( (string) $attachment_id ); ?></a>
					<?php else : ?>
						#<?php echo esc_html( (string) $attachment_id ); ?>
					<?php endif; ?>
				</td>
				<td class="lwia-log-alt"><?php echo esc_html( (string) $entry->old_alt ); ?></td>
				<td class="lwia-log-alt"><?php echo esc_html( (string) $entry->new_alt ); ?></td>
				<td>
					<span class="lwia-source-badge lwia-source-<?php echo esc_attr( (string) $entry->source ); ?>">
						<?php echo esc_html( (string) $entry->source ); ?>
					</span>
				</td>
				<td>
					<?php echo $user ? esc_html( $user->display_name ) : esc_html( '#' . $entry->user_id ); ?>
				</td>
				<td>
					<code title="<?php echo esc_attr( (string) $entry->batch_id ); ?>">
						<?php echo esc_html( $batch_short ); ?>&hellip;
					</code>
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
