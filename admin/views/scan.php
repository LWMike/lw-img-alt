<?php
/**
 * Scan screen — lists image attachments missing alt text.
 *
 * Variables provided by LWIA_Admin::render_scan():
 *   $rows          object[]  Scanner result rows for current page.
 *   $total         int       Total missing-alt attachments matching filters.
 *   $lib_total     int       Total image attachments in the library.
 *   $total_pages   int       Total pages for pagination.
 *   $current_page  int       Current page number.
 *   $per_page      int       Results per page.
 *   $base_url      string    Filter-preserving base URL (no 'paged' param).
 *   $filter_attach string    Current attachment filter value.
 *   $filter_date_f string    Current date_from filter value.
 *   $filter_date_t string    Current date_to filter value.
 *   $filter_mime   string    Current mime_type filter value.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Image Alt — Scan', 'lw-img-alt' ); ?></h1>
	<hr class="wp-header-end">

	<div id="lwia-toasts" class="lwia-toast-container" aria-live="polite" aria-atomic="false"></div>

	<?php
	// -------------------------------------------------------------------------
	// Filter form
	// -------------------------------------------------------------------------
	?>
	<form method="get" class="lwia-filter-row">
		<input type="hidden" name="page" value="lw-img-alt">

		<span class="lwia-filter-prefix"><?php esc_html_e( 'Filter:', 'lw-img-alt' ); ?></span>

		<div class="lwia-filter-field">
			<label for="lwia-filter-attachment"><?php esc_html_e( 'STATUS', 'lw-img-alt' ); ?></label>
			<select id="lwia-filter-attachment" name="attachment">
				<option value="all"        <?php selected( $filter_attach, 'all' ); ?>><?php esc_html_e( 'All images', 'lw-img-alt' ); ?></option>
				<option value="attached"   <?php selected( $filter_attach, 'attached' ); ?>><?php esc_html_e( 'Attached', 'lw-img-alt' ); ?></option>
				<option value="unattached" <?php selected( $filter_attach, 'unattached' ); ?>><?php esc_html_e( 'Unattached', 'lw-img-alt' ); ?></option>
			</select>
		</div>

		<div class="lwia-filter-field">
			<label for="lwia-filter-mime"><?php esc_html_e( 'TYPE', 'lw-img-alt' ); ?></label>
			<select id="lwia-filter-mime" name="mime_type">
				<option value="all"        <?php selected( $filter_mime, 'all' ); ?>><?php esc_html_e( 'All types', 'lw-img-alt' ); ?></option>
				<option value="image/jpeg" <?php selected( $filter_mime, 'image/jpeg' ); ?>>JPEG</option>
				<option value="image/png"  <?php selected( $filter_mime, 'image/png' ); ?>>PNG</option>
				<option value="image/webp" <?php selected( $filter_mime, 'image/webp' ); ?>>WebP</option>
				<option value="image/gif"  <?php selected( $filter_mime, 'image/gif' ); ?>>GIF</option>
				<option value="image/avif" <?php selected( $filter_mime, 'image/avif' ); ?>>AVIF</option>
			</select>
		</div>

		<div class="lwia-filter-field">
			<label for="lwia-filter-date-from"><?php esc_html_e( 'FROM', 'lw-img-alt' ); ?></label>
			<input
				type="date"
				id="lwia-filter-date-from"
				name="date_from"
				value="<?php echo esc_attr( $filter_date_f ); ?>"
			>
		</div>

		<div class="lwia-filter-field">
			<label for="lwia-filter-date-to"><?php esc_html_e( 'TO', 'lw-img-alt' ); ?></label>
			<input
				type="date"
				id="lwia-filter-date-to"
				name="date_to"
				value="<?php echo esc_attr( $filter_date_t ); ?>"
			>
		</div>

		<div class="lwia-filter-actions">
			<button
				type="submit"
				name="lwia-filter-submit"
				id="lwia-filter-submit"
				class="button"
				title="<?php esc_attr_e( 'Results update automatically when images are added or edited. Click to force a refresh.', 'lw-img-alt' ); ?>"
			>
				<?php esc_html_e( 'Refresh results', 'lw-img-alt' ); ?>
			</button>

			<?php if ( $filter_attach !== 'all' || $filter_date_f || $filter_date_t || $filter_mime !== 'all' ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lw-img-alt' ) ); ?>" class="button">
					<?php esc_html_e( 'Clear filters', 'lw-img-alt' ); ?>
				</a>
			<?php endif; ?>

			<?php
			$export_url = wp_nonce_url(
				add_query_arg(
					array_filter(
						array(
							'page'       => 'lw-img-alt',
							'action'     => 'lwia_export_csv',
							'attachment' => ( 'all' !== $filter_attach ) ? $filter_attach : false,
							'date_from'  => $filter_date_f ?: false,
							'date_to'    => $filter_date_t ?: false,
							'mime_type'  => ( 'all' !== $filter_mime ) ? $filter_mime : false,
						)
					),
					admin_url( 'admin.php' )
				),
				'lwia_export_csv'
			);
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Export CSV', 'lw-img-alt' ); ?>
			</a>
		</div>
	</form>

	<?php
	// -------------------------------------------------------------------------
	// Summary
	// -------------------------------------------------------------------------
	?>
	<p
		class="lwia-summary"
		id="lwia-total-count"
		data-count="<?php echo esc_attr( (string) $total ); ?>"
	>
		<?php
		if ( 0 === $total ) {
			echo esc_html__( 'No images missing alt text — great work!', 'lw-img-alt' );
		} elseif ( 1 === $total ) {
			printf(
				/* translators: %d: total image attachments in library */
				esc_html__( '1 image missing alt text (of %d total in library)', 'lw-img-alt' ),
				(int) $lib_total
			);
		} else {
			printf(
				/* translators: 1: count missing alt, 2: total images in library */
				esc_html__( '%1$d images missing alt text (of %2$d total in library)', 'lw-img-alt' ),
				(int) $total,
				(int) $lib_total
			);
		}
		?>
	</p>

	<?php if ( $total > 0 ) : ?>
	<p class="lwia-helper-text">
		<?php
		printf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag */
			esc_html__( 'Edit alt text and click Save, or press Enter. Changes can be rolled back from the %1$sUndo screen%2$s.', 'lw-img-alt' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=lwia-undo' ) ) . '">',
			'</a>'
		);
		?>
	</p>
	<?php endif; ?>

	<?php if ( 0 === $total ) : ?>

		<div class="lwia-empty-state notice notice-success">
			<p><?php esc_html_e( 'All images in this library have alt text. Nothing to do here.', 'lw-img-alt' ); ?></p>
		</div>

	<?php elseif ( empty( $rows ) ) : ?>

		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'No results on this page.', 'lw-img-alt' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lw-img-alt' ) ); ?>">
					<?php esc_html_e( 'Back to page 1', 'lw-img-alt' ); ?>
				</a>
			</p>
		</div>

	<?php else : ?>

		<?php
		// -------------------------------------------------------------------------
		// Top pagination
		// -------------------------------------------------------------------------
		$pagination = paginate_links(
			array(
				'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $base_url ) ),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'lw-img-alt' ),
				'next_text' => esc_html__( 'Next', 'lw-img-alt' ) . ' &raquo;',
				'type'      => 'plain',
			)
		);
		?>

		<?php if ( $pagination ) : ?>
		<div class="tablenav top">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %d: total items */
						esc_html( _n( '%d item', '%d items', $total, 'lw-img-alt' ) ),
						(int) $total
					);
					?>
				</span>
				<?php
				// paginate_links() returns pre-escaped HTML with only safe markup (<a>, <span>).
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $pagination;
				?>
			</div>
		</div>
		<?php endif; ?>

		<?php
		// -------------------------------------------------------------------------
		// Results table — columns: Preview | Filename | Alt text | Attached to | Uploaded | Details
		// -------------------------------------------------------------------------
		?>
		<table class="wp-list-table widefat fixed striped lwia-scan-table">
			<thead>
				<tr>
					<th scope="col" class="lwia-col-thumb column-thumb"><?php esc_html_e( 'Preview', 'lw-img-alt' ); ?></th>
					<th scope="col" class="lwia-col-filename column-primary"><?php esc_html_e( 'Filename', 'lw-img-alt' ); ?></th>
					<th scope="col" class="lwia-col-alt"><?php esc_html_e( 'Alt text', 'lw-img-alt' ); ?></th>
					<th scope="col" class="lwia-col-attached"><?php esc_html_e( 'Attached to', 'lw-img-alt' ); ?></th>
					<th scope="col" class="lwia-col-date"><?php esc_html_e( 'Uploaded', 'lw-img-alt' ); ?></th>
					<th scope="col" class="lwia-col-details"><?php esc_html_e( 'Details', 'lw-img-alt' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$attachment_id = (int) $row->ID;
					$meta          = maybe_unserialize( get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) );
					$width         = is_array( $meta ) ? ( $meta['width']  ?? '' ) : '';
					$height        = is_array( $meta ) ? ( $meta['height'] ?? '' ) : '';
					$filesize      = is_array( $meta ) ? ( $meta['filesize'] ?? 0 ) : 0;
					$edit_url      = get_edit_post_link( $attachment_id );
					$filename      = basename( $row->guid );
					$date          = mysql2date( get_option( 'date_format' ), $row->post_date );

					// Thumbnail with mime-type icon fallback for SVGs, broken files, etc.
					$thumb = wp_get_attachment_image( $attachment_id, array( 50, 50 ), false, array( 'class' => 'lwia-thumb-img' ) );
					if ( ! $thumb ) {
						$icon_url = wp_mime_type_icon( $row->post_mime_type );
						if ( $icon_url ) {
							$thumb = '<img src="' . esc_url( $icon_url ) . '" width="48" height="48" class="lwia-thumb-img lwia-thumb-icon" alt="">';
						}
					}
				?>
				<tr data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>" data-filename="<?php echo esc_attr( $filename ); ?>">

					<td class="lwia-col-thumb column-thumb">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
						echo $thumb;
						?>
					</td>

					<td class="lwia-col-filename column-primary">
						<strong>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>">
									<?php echo esc_html( $filename ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $filename ); ?>
							<?php endif; ?>
						</strong>
					</td>

					<td class="lwia-col-alt">
						<div class="lwia-inline-edit-wrap">
							<input
								type="text"
								class="lwia-alt-input"
								value=""
								data-original-alt=""
								placeholder="<?php echo esc_attr__( 'Enter alt text…', 'lw-img-alt' ); ?>"
								maxlength="125"
								aria-label="<?php echo esc_attr__( 'Alt text for this image', 'lw-img-alt' ); ?>"
							>
							<button
								type="button"
								class="button lwia-row-save"
								disabled
								aria-label="<?php echo esc_attr__( 'Save alt text', 'lw-img-alt' ); ?>"
							><?php esc_html_e( 'Save', 'lw-img-alt' ); ?></button>
							<span class="lwia-spinner spinner" aria-hidden="true"></span>
							<span class="lwia-save-indicator" aria-hidden="true"></span>
							<span class="lwia-status screen-reader-text" aria-live="polite"></span>
						</div>
					</td>

					<td class="lwia-col-attached">
						<?php if ( $row->post_parent ) :
							$parent = get_post( $row->post_parent );
							if ( $parent ) :
								$parent_edit = get_edit_post_link( $parent->ID );
						?>
							<?php if ( $parent_edit ) : ?>
								<a href="<?php echo esc_url( $parent_edit ); ?>">
									<?php echo esc_html( get_the_title( $parent ) ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( get_the_title( $parent ) ); ?>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
						<?php else : ?>
							<span class="lwia-unattached"><?php esc_html_e( 'Unattached', 'lw-img-alt' ); ?></span>
						<?php endif; ?>
					</td>

					<td class="lwia-col-date">
						<?php echo esc_html( (string) $date ); ?>
					</td>

					<td class="lwia-col-details">
						<span class="lwia-details-type"><?php echo esc_html( $row->post_mime_type ); ?></span>
						<?php if ( $width && $height ) : ?>
							<span class="lwia-details-sep">&bull;</span>
							<span class="lwia-details-dims"><?php echo esc_html( $width ) . '&times;' . esc_html( $height ); ?></span>
						<?php endif; ?>
						<?php if ( $filesize ) : ?>
							<span class="lwia-details-sep">&bull;</span>
							<span class="lwia-details-size"><?php echo esc_html( size_format( (int) $filesize ) ); ?></span>
						<?php endif; ?>
					</td>

				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		// -------------------------------------------------------------------------
		// Bottom pagination
		// -------------------------------------------------------------------------
		?>
		<?php if ( $pagination ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $pagination;
				?>
			</div>
		</div>
		<?php endif; ?>

	<?php endif; ?>

</div><!-- .wrap -->
