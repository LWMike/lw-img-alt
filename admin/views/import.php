<?php
/**
 * Import screen — CSV upload, preview, and apply.
 *
 * Variables provided by LWIA_Admin::render_import():
 *   $step         string       'upload' or 'preview'.
 *   $import_id    string       Transient key (preview step only).
 *   $import_data  array|null   Transient contents (preview step only):
 *                              { rows[], batch_id, stats{ok,warn,skip,error,total}, errors[] }
 *   $error        string       Error code from a previous failed step, or ''.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

// Error message map.
$error_messages = array(
	'no_file'      => __( 'No file was uploaded. Please choose a CSV file and try again.', 'lw-img-alt' ),
	'invalid_type' => __( 'The uploaded file does not appear to be a CSV. Please upload a .csv file.', 'lw-img-alt' ),
	'upload_failed' => __( 'The file could not be saved on the server. Please try again.', 'lw-img-alt' ),
	'parse_failed' => __( 'The CSV file could not be parsed. Check the format and required columns (attachment_id, new_alt).', 'lw-img-alt' ),
	'expired'      => __( 'The import session expired. Please re-upload the CSV file.', 'lw-img-alt' ),
);
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Import Alt Text', 'lw-img-alt' ); ?></h1>

	<?php if ( $error && isset( $error_messages[ $error ] ) ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo esc_html( $error_messages[ $error ] ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( 'upload' === $step ) : ?>
	<?php // ================================================================= ?>
	<?php // Step 1 — Upload form                                               ?>
	<?php // ================================================================= ?>

	<p>
		<?php esc_html_e( 'Upload a CSV file to bulk-update alt text across your Media Library.', 'lw-img-alt' ); ?>
		<?php printf(
			/* translators: %s: link to download a sample CSV */
			esc_html__( 'Not sure of the format? %s from the Scan screen first.', 'lw-img-alt' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=lw-img-alt' ) ) . '">'
			. esc_html__( 'Export a CSV', 'lw-img-alt' )
			. '</a>'
		); ?>
	</p>

	<p class="description">
		<?php esc_html_e( 'Required columns: attachment_id, new_alt. Optional: filename, url, current_alt, title, caption.', 'lw-img-alt' ); ?>
	</p>

	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		enctype="multipart/form-data"
		class="lwia-upload-form"
	>
		<?php wp_nonce_field( 'lwia_import_upload' ); ?>
		<input type="hidden" name="action" value="lwia_import_upload">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="lwia_csv"><?php esc_html_e( 'CSV file', 'lw-img-alt' ); ?></label>
				</th>
				<td>
					<input
						type="file"
						id="lwia_csv"
						name="lwia_csv"
						accept=".csv,text/csv"
						required
					>
					<p class="description">
						<?php printf(
							/* translators: %d: row limit */
							esc_html__( 'Maximum %d rows. UTF-8 encoding. BOM optional.', 'lw-img-alt' ),
							LWIA_CSV_Import::ROW_LIMIT
						); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Upload &amp; Preview', 'lw-img-alt' ), 'primary', 'lwia-upload-submit' ); ?>
	</form>

	<?php elseif ( 'preview' === $step && $import_data ) : ?>
	<?php // ================================================================= ?>
	<?php // Step 2 — Preview                                                   ?>
	<?php // ================================================================= ?>

	<?php
	$stats    = $import_data['stats'];
	$rows     = $import_data['rows'];
	$ok_count = $stats['ok'] + $stats['warn'];
	?>

	<?php if ( ! empty( $import_data['errors'] ) ) : ?>
	<div class="notice notice-warning is-dismissible">
		<p><strong><?php esc_html_e( 'Parse warnings:', 'lw-img-alt' ); ?></strong></p>
		<ul>
			<?php foreach ( $import_data['errors'] as $err ) : ?>
				<li><?php echo esc_html( $err ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<div class="lwia-import-stats notice notice-info inline">
		<p>
			<strong><?php echo esc_html( (string) $stats['total'] ); ?></strong>
			<?php esc_html_e( 'rows parsed —', 'lw-img-alt' ); ?>
			<span class="lwia-stat-ok">
				<?php printf(
					/* translators: %d: count */
					esc_html__( '%d will be updated', 'lw-img-alt' ),
					(int) $ok_count
				); ?>
			</span>
			&bull;
			<span class="lwia-stat-skip">
				<?php printf(
					/* translators: %d: count */
					esc_html__( '%d will be skipped (no alt text)', 'lw-img-alt' ),
					(int) $stats['skip']
				); ?>
			</span>
			<?php if ( $stats['error'] > 0 ) : ?>
			&bull;
			<span class="lwia-stat-error">
				<?php printf(
					/* translators: %d: count */
					esc_html__( '%d errors (attachment not found)', 'lw-img-alt' ),
					(int) $stats['error']
				); ?>
			</span>
			<?php endif; ?>
		</p>
	</div>

	<?php if ( $ok_count > 0 ) : ?>

	<p>
		<button
			type="button"
			class="button button-primary lwia-apply-import-btn"
			data-import-id="<?php echo esc_attr( $import_id ); ?>"
			data-total="<?php echo esc_attr( (string) count( $rows ) ); ?>"
		>
			<?php printf(
				/* translators: %d: number of changes */
				esc_html__( 'Apply %d Changes', 'lw-img-alt' ),
				(int) $ok_count
			); ?>
		</button>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-import' ) ); ?>"
			class="button"
		><?php esc_html_e( 'Cancel', 'lw-img-alt' ); ?></a>
	</p>

	<?php else : ?>
	<p>
		<?php esc_html_e( 'There are no rows to apply (all rows are empty or in error).', 'lw-img-alt' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=lwia-import' ) ); ?>" class="button">
			<?php esc_html_e( '&larr; Upload a different file', 'lw-img-alt' ); ?>
		</a>
	</p>
	<?php endif; ?>

	<?php // Progress bar — shown by JS during apply, hidden initially ?>
	<div id="lwia-import-progress" style="display:none;" aria-live="polite" aria-label="<?php esc_attr_e( 'Import progress', 'lw-img-alt' ); ?>">
		<div class="lwia-progress-bar-wrap">
			<div class="lwia-progress-bar" id="lwia-progress-bar-fill" style="width:0%"></div>
		</div>
		<p id="lwia-progress-label"><?php esc_html_e( 'Starting\u2026', 'lw-img-alt' ); ?></p>
	</div>

	<?php // Results panel — populated by JS when apply is done, hidden initially ?>
	<div id="lwia-import-results" style="display:none;"></div>

	<?php // Preview table (first 200 rows) ?>
	<h2><?php esc_html_e( 'Row preview', 'lw-img-alt' ); ?></h2>

	<?php if ( count( $rows ) > 200 ) : ?>
	<p class="description">
		<?php printf(
			/* translators: 1: shown count, 2: total count */
			esc_html__( 'Showing first %1$d of %2$d rows.', 'lw-img-alt' ),
			200,
			count( $rows )
		); ?>
	</p>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped lwia-preview-table">
		<thead>
			<tr>
				<th scope="col" style="width:70px;"><?php esc_html_e( 'ID', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Filename', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'New alt text', 'lw-img-alt' ); ?></th>
				<th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'lw-img-alt' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Notes', 'lw-img-alt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_slice( $rows, 0, 200 ) as $row ) : ?>
			<tr class="lwia-row-status-<?php echo esc_attr( $row['status'] ); ?>">
				<td><?php echo esc_html( (string) $row['attachment_id'] ); ?></td>
				<td><?php echo esc_html( $row['filename'] ); ?></td>
				<td><?php echo esc_html( $row['new_alt'] ); ?></td>
				<td><span class="lwia-status-badge lwia-status-<?php echo esc_attr( $row['status'] ); ?>">
					<?php echo esc_html( $row['status'] ); ?>
				</span></td>
				<td><?php echo esc_html( implode( ' ', $row['messages'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php endif; ?>

</div><!-- .wrap -->
