<?php
/**
 * AI Suggest screen — batch generation start + job history.
 *
 * Variables provided by LWIA_Admin::render_ai_suggest():
 *   $jobs          array   All past batch jobs, newest first.
 *   $has_active    bool    True if a job is currently processing.
 *   $image_count   int     Images in scope given current filters.
 *   $estimated_gbp float   Estimated batch cost.
 *   $spend         array   Current month spend record.
 *   $cap           float   Monthly spend cap.
 *   $filter_mode   string  'missing' | 'rewrite' | 'both'.
 *   $filter_attach string  Attachment filter.
 *   $filter_date_f string  Date from.
 *   $filter_date_t string  Date to.
 *   $filter_mime   string  MIME type filter.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Image Alt — AI Suggest', 'lw-img-alt' ); ?></h1>

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$error   = isset( $_GET['error'] )   ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
	$started = isset( $_GET['started'] ) ? (bool) $_GET['started']                       : false;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( $started ) :
	?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'Batch job started. You\'ll receive an admin notice here when it\'s complete (usually 5–15 minutes).', 'lw-img-alt' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( 'job_active' === $error ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'A batch job is already running. Please wait for it to complete before starting another.', 'lw-img-alt' ); ?></p>
	</div>
	<?php elseif ( 'cap_reached' === $error ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Monthly spend cap reached. Raise the cap in AI Settings to continue.', 'lw-img-alt' ); ?></p>
	</div>
	<?php elseif ( 'no_images' === $error ) : ?>
	<div class="notice notice-warning is-dismissible">
		<p><?php esc_html_e( 'No images found matching those filters.', 'lw-img-alt' ); ?></p>
	</div>
	<?php elseif ( 'batch_failed' === $error ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Failed to submit the batch to OpenAI. Check your API key and try again.', 'lw-img-alt' ); ?></p>
		<?php if ( ! empty( $batch_api_error ) ) : ?>
		<p><strong><?php esc_html_e( 'API error:', 'lw-img-alt' ); ?></strong> <code><?php echo esc_html( $batch_api_error ); ?></code></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Generate alt text suggestions for multiple images at once using the OpenAI Batch API (50% cheaper than real-time). You review all suggestions before anything is saved.', 'lw-img-alt' ); ?>
	</p>

	<!-- Batch configuration form -->
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'lwia_ai_batch_start' ); ?>
		<input type="hidden" name="action" value="lwia_ai_batch_start">

		<div class="lwia-filter-row">
			<span class="lwia-filter-prefix"><?php esc_html_e( 'Filter:', 'lw-img-alt' ); ?></span>

			<div class="lwia-filter-field">
				<label for="lwia-ai-mode"><?php esc_html_e( 'MODE', 'lw-img-alt' ); ?></label>
				<select id="lwia-ai-mode" name="mode">
					<option value="missing" <?php selected( $filter_mode, 'missing' ); ?>><?php esc_html_e( 'Missing alt only', 'lw-img-alt' ); ?></option>
					<option value="rewrite" <?php selected( $filter_mode, 'rewrite' ); ?>><?php esc_html_e( 'Existing alt — flag for rewrite', 'lw-img-alt' ); ?></option>
					<option value="both"    <?php selected( $filter_mode, 'both' ); ?>><?php esc_html_e( 'Both', 'lw-img-alt' ); ?></option>
				</select>
			</div>

			<div class="lwia-filter-field">
				<label for="lwia-ai-attachment"><?php esc_html_e( 'STATUS', 'lw-img-alt' ); ?></label>
				<select id="lwia-ai-attachment" name="attachment">
					<option value="all"        <?php selected( $filter_attach, 'all' ); ?>><?php esc_html_e( 'All images', 'lw-img-alt' ); ?></option>
					<option value="attached"   <?php selected( $filter_attach, 'attached' ); ?>><?php esc_html_e( 'Attached', 'lw-img-alt' ); ?></option>
					<option value="unattached" <?php selected( $filter_attach, 'unattached' ); ?>><?php esc_html_e( 'Unattached', 'lw-img-alt' ); ?></option>
				</select>
			</div>

			<div class="lwia-filter-field">
				<label for="lwia-ai-mime"><?php esc_html_e( 'TYPE', 'lw-img-alt' ); ?></label>
				<select id="lwia-ai-mime" name="mime_type">
					<option value="all"        <?php selected( $filter_mime, 'all' ); ?>><?php esc_html_e( 'All types', 'lw-img-alt' ); ?></option>
					<option value="image/jpeg" <?php selected( $filter_mime, 'image/jpeg' ); ?>>JPEG</option>
					<option value="image/png"  <?php selected( $filter_mime, 'image/png' ); ?>>PNG</option>
					<option value="image/webp" <?php selected( $filter_mime, 'image/webp' ); ?>>WebP</option>
					<option value="image/gif"  <?php selected( $filter_mime, 'image/gif' ); ?>>GIF</option>
					<option value="image/avif" <?php selected( $filter_mime, 'image/avif' ); ?>>AVIF</option>
				</select>
			</div>

			<div class="lwia-filter-field">
				<label for="lwia-ai-date-from"><?php esc_html_e( 'FROM', 'lw-img-alt' ); ?></label>
				<input type="date" id="lwia-ai-date-from" name="date_from" value="<?php echo esc_attr( $filter_date_f ); ?>">
			</div>

			<div class="lwia-filter-field">
				<label for="lwia-ai-date-to"><?php esc_html_e( 'TO', 'lw-img-alt' ); ?></label>
				<input type="date" id="lwia-ai-date-to" name="date_to" value="<?php echo esc_attr( $filter_date_t ); ?>">
			</div>
		</div>

		<!-- Cost preview -->
		<div class="lwia-ai-cost-preview notice notice-info inline">
			<p>
				<?php if ( $image_count > 0 ) : ?>
					<?php
					printf(
						/* translators: 1: number of images, 2: estimated cost */
						esc_html__( 'This will generate suggestions for %1$d image(s). Estimated cost: £%2$s. Estimated time: 5–15 minutes.', 'lw-img-alt' ),
						(int) $image_count,
						esc_html( number_format( $estimated_gbp, 2 ) )
					);
					?>
					<?php if ( $cap > 0 ) : ?>
						<br>
						<?php
						printf(
							/* translators: 1: current spend, 2: cap */
							esc_html__( 'Current spend this month: £%1$s / £%2$s cap.', 'lw-img-alt' ),
							esc_html( number_format( (float) $spend['estimated_gbp'], 2 ) ),
							esc_html( number_format( $cap, 2 ) )
						);
						?>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'No images match the current filters.', 'lw-img-alt' ); ?>
				<?php endif; ?>
			</p>
		</div>

		<?php
		$submit_disabled = $has_active || $image_count < 1 || LWIA_AI_Settings::is_cap_reached();
		?>
		<p>
			<button
				type="submit"
				class="button button-primary"
				<?php disabled( $submit_disabled ); ?>
			>
				<?php esc_html_e( 'Start batch', 'lw-img-alt' ); ?>
			</button>
			<?php if ( $has_active ) : ?>
				<span class="description" style="margin-left:8px;">
					<?php esc_html_e( 'A job is already running — wait for it to complete first.', 'lw-img-alt' ); ?>
				</span>
			<?php endif; ?>
		</p>
	</form>

	<!-- Job history -->
	<?php if ( ! empty( $jobs ) ) : ?>
	<hr>
	<h2><?php esc_html_e( 'Recent jobs', 'lw-img-alt' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:140px;"><?php esc_html_e( 'Started', 'lw-img-alt' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Mode', 'lw-img-alt' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Count', 'lw-img-alt' ); ?></th>
				<th style="width:100px;"><?php esc_html_e( 'Status', 'lw-img-alt' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'lw-img-alt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $jobs as $job ) :
				$status     = (string) ( $job['status']     ?? '' );
				$created_at = (string) ( $job['created_at'] ?? '' );
				$ts         = $created_at ? strtotime( $created_at ) : 0;
				$date_str   = $ts ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_at ) : '—';
			?>
			<tr>
				<td><?php echo esc_html( $date_str ); ?></td>
				<td><?php echo esc_html( (string) ( $job['mode'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $job['count'] ?? '—' ) ); ?></td>
				<td>
					<?php
					$status_classes = array(
						'processing' => 'lwia-source-manual',
						'complete'   => 'lwia-status-ok',
						'failed'     => 'lwia-status-error',
					);
					$badge_class = $status_classes[ $status ] ?? '';
					?>
					<span class="lwia-status-badge <?php echo esc_attr( $badge_class ); ?>">
						<?php echo esc_html( ucfirst( $status ) ); ?>
					</span>
				</td>
				<td>
					<?php if ( 'complete' === $status ) :
						$review_url = add_query_arg(
							array( 'page' => 'lwia-ai-review', 'job_id' => urlencode( (string) $job['id'] ) ),
							admin_url( 'admin.php' )
						);
					?>
					<a href="<?php echo esc_url( $review_url ); ?>" class="button button-small button-primary">
						<?php esc_html_e( 'Review suggestions', 'lw-img-alt' ); ?>
					</a>
					<?php elseif ( 'processing' === $status ) : ?>
					<span class="description"><?php esc_html_e( 'Processing — check back in a few minutes.', 'lw-img-alt' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

</div><!-- .wrap -->
