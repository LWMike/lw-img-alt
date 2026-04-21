<?php
/**
 * CSV generation — streams a UTF-8 CSV file of attachments missing alt text.
 *
 * Must be called before any output is sent (headers will be set here).
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_CSV_Export
 */
class LWIA_CSV_Export {

	/**
	 * Column header row written to every export file.
	 */
	const COLUMNS = array( 'attachment_id', 'filename', 'url', 'current_alt', 'new_alt', 'title', 'caption' );

	/**
	 * Fetch all missing-alt attachments matching $filter_args and stream as CSV.
	 *
	 * Sets Content-Type / Content-Disposition headers and echoes directly to
	 * the output buffer. Callers must call exit after this returns.
	 *
	 * @param array $filter_args Same filter args accepted by LWIA_Scanner::get_missing()
	 *                           (attachment, date_from, date_to, mime_type).
	 *                           Pagination args are overridden to export all rows.
	 */
	public static function generate( array $filter_args = array() ): void {
		// Override pagination to fetch all rows.
		$args    = array_merge( $filter_args, array( 'per_page' => 50000, 'paged' => 1 ) );
		$scanner = new LWIA_Scanner();
		$rows    = $scanner->get_missing( $args );

		// Prime the post cache so get_post_field( 'post_excerpt' ) calls are free.
		if ( ! empty( $rows ) ) {
			$ids = array_map( static fn( $row ) => (int) $row->ID, $rows );
			_prime_post_caches( $ids, false, false );
		}

		$filename = 'lwia-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// Open the output stream.
		$handle = fopen( 'php://output', 'w' );

		// UTF-8 BOM — required for Excel to open the file without encoding errors.
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv( $handle, self::COLUMNS );

		// Data rows — current_alt and new_alt are always empty because these are
		// all missing-alt images; new_alt is left blank for the user to fill in.
		foreach ( $rows as $row ) {
			$url     = wp_get_attachment_url( (int) $row->ID );
			$caption = get_post_field( 'post_excerpt', (int) $row->ID );

			fputcsv(
				$handle,
				array(
					(int) $row->ID,
					basename( $row->guid ),
					$url ? $url : $row->guid,
					'', // current_alt
					'', // new_alt
					$row->post_title,
					$caption,
				)
			);
		}

		fclose( $handle );
	}
}
