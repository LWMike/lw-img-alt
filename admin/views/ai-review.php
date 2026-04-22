<?php
/**
 * AI Review screen — review batch suggestions, inline-edit, apply selected.
 *
 * Variables provided by LWIA_Admin::render_ai_review():
 *   $job          array|null       Batch job record.
 *   $results      LWIA_AI_Result[]|false  All results (false if expired/not ready).
 *   $page_results LWIA_AI_Result[]        Results for current page.
 *   $job_id       string           Local job UUID.
 *   $total        int              Total result rows.
 *   $total_pages  int
 *   $paged        int
 *   $per_page     int
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$applied = isset( $_GET['applied'] ) ? absint( $_GET['applied'] ) : null;
$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : null;
$errors  = isset( $_GET['errors'] )  ? absint( $_GET['errors'] )  : null;
$error   = isset( $_GET['error'] )   ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Image Alt — AI Review', 'lw-img-alt' ); ?></h1>

	<?php if ( null !== $applied ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %d: number applied */
				esc_html__( '%d image(s) updated.', 'lw-img-alt' ),
				$applied
			);
			if ( $skipped > 0 ) {
				printf( ' ' . esc_html__( '%d skipped.', 'lw-img-alt' ), $skipped );
			}
			if ( $errors > 0 ) {
				printf( ' ' . esc_html__( '%d failed.', 'lw-img-alt' ), $errors );
			}
			?>
			&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-undo' ) ); ?>"><?php esc_html_e( 'Undo this batch', 'lw-img-alt' ); ?></a>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( 'expired' === $error ) : ?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'These results have expired (kept for 7 days). Please start a new batch.', 'lw-img-alt' ); ?></p>
	</div>
	<?php elseif ( 'no_selection' === $error ) : ?>
	<div class="notice notice-warning is-dismissible">
		<p><?php esc_html_e( 'No rows were selected. Tick the checkboxes for the suggestions you want to apply.', 'lw-img-alt' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( ! $job ) : ?>
		<p><?php esc_html_e( 'Job not found. It may have been cleared.', 'lw-img-alt' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-ai-suggest' ) ); ?>" class="button">
			<?php esc_html_e( '← Back to AI Suggest', 'lw-img-alt' ); ?>
		</a></p>
	<?php elseif ( 'processing' === ( $job['status'] ?? '' ) ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'This batch is still processing. You\'ll receive an admin notice when it\'s ready.', 'lw-img-alt' ); ?></p>
		</div>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-ai-suggest' ) ); ?>" class="button">
			<?php esc_html_e( '← Back to AI Suggest', 'lw-img-alt' ); ?>
		</a></p>
	<?php elseif ( false === $results ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Results have expired. Results are kept for 7 days.', 'lw-img-alt' ); ?></p>
		</div>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-ai-suggest' ) ); ?>" class="button">
			<?php esc_html_e( '← Start a new batch', 'lw-img-alt' ); ?>
		</a></p>
	<?php else : ?>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of suggestions */
				esc_html__( '%d suggestions ready. Review and edit below, then click Apply selected.', 'lw-img-alt' ),
				(int) $total
			);
			?>
		</p>

		<?php
		$pagination = paginate_links( array(
			'base'      => esc_url_raw( add_query_arg( array( 'paged' => '%#%', 'job_id' => urlencode( $job_id ) ), admin_url( 'admin.php?page=lwia-ai-review' ) ) ),
			'format'    => '',
			'current'   => $paged,
			'total'     => $total_pages,
			'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'lw-img-alt' ),
			'next_text' => esc_html__( 'Next', 'lw-img-alt' ) . ' &raquo;',
			'type'      => 'plain',
		) );
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="lwia-review-form">
			<?php wp_nonce_field( 'lwia_ai_batch_apply' ); ?>
			<input type="hidden" name="action"  value="lwia_ai_batch_apply">
			<input type="hidden" name="job_id"  value="<?php echo esc_attr( $job_id ); ?>">
			<input type="hidden" name="paged"   value="<?php echo esc_attr( (string) $paged ); ?>">

			<div class="tablenav top">
				<div class="alignleft actions">
					<button type="button" id="lwia-select-all" class="button">
						<?php esc_html_e( 'Select all', 'lw-img-alt' ); ?>
					</button>
					<button type="button" id="lwia-select-none" class="button">
						<?php esc_html_e( 'Select none', 'lw-img-alt' ); ?>
					</button>
					<button type="submit" id="lwia-apply-btn" class="button button-primary">
						<?php esc_html_e( 'Apply selected', 'lw-img-alt' ); ?>
					</button>
				</div>
				<?php if ( $pagination ) : ?>
				<div class="tablenav-pages">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $pagination;
					?>
				</div>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped lwia-review-table">
				<thead>
					<tr>
						<th style="width:30px;"><input type="checkbox" id="lwia-check-all"></th>
						<th style="width:60px;"><?php esc_html_e( 'Preview', 'lw-img-alt' ); ?></th>
						<th style="width:200px;"><?php esc_html_e( 'Filename', 'lw-img-alt' ); ?></th>
						<th><?php esc_html_e( 'AI suggestion', 'lw-img-alt' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Confidence', 'lw-img-alt' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $page_results as $result ) :
						$attachment_id = (int) $result->custom_id;
						$post          = get_post( $attachment_id );
						$filename      = $post ? basename( (string) $post->guid ) : '#' . $attachment_id;
						$edit_url      = $post ? get_edit_post_link( $attachment_id ) : '';
						$thumb         = wp_get_attachment_image( $attachment_id, array( 50, 50 ), false, array( 'class' => 'lwia-thumb-img' ) );
						$conf_pct      = round( $result->confidence * 100 );
						$conf_class    = $result->confidence < 0.5 ? 'lwia-quality-poor' : ( $result->confidence < 0.8 ? 'lwia-quality-warn' : 'lwia-quality-good' );
					?>
					<tr>
						<td>
							<?php if ( $result->success ) : ?>
							<input type="checkbox" name="selected[]" value="<?php echo esc_attr( (string) $attachment_id ); ?>" checked>
							<?php else : ?>
							<span title="<?php echo esc_attr( $result->error ); ?>">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image is safe ?></td>
						<td>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $filename ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $filename ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $result->success ) : ?>
							<input
								type="text"
								name="alt_<?php echo esc_attr( (string) $attachment_id ); ?>"
								class="large-text lwia-review-alt"
								value="<?php echo esc_attr( $result->alt ); ?>"
								maxlength="125"
							>
							<?php else : ?>
							<span class="lwia-muted"><?php echo esc_html( $result->error ?: __( 'Generation failed', 'lw-img-alt' ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $result->success ) : ?>
							<span class="lwia-status-badge <?php echo esc_attr( $conf_class ); ?>"><?php echo esc_html( $conf_pct . '%' ); ?></span>
							<?php if ( $result->confidence < 0.5 ) : ?>
								<span title="<?php esc_attr_e( 'Low confidence — review carefully before applying.', 'lw-img-alt' ); ?>">⚠️</span>
							<?php endif; ?>
							<?php else : ?>
							—
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="alignleft actions">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Apply selected', 'lw-img-alt' ); ?>
					</button>
				</div>
				<?php if ( $pagination ) : ?>
				<div class="tablenav-pages">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $pagination;
					?>
				</div>
				<?php endif; ?>
			</div>
		</form>

	<?php endif; ?>

</div><!-- .wrap -->
