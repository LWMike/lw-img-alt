<?php
/**
 * WP-CLI command registrations.
 *
 * Thin wrappers — all business logic lives in the core classes.
 * This file is only loaded when WP_CLI is defined.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage image alt text in the WordPress Media Library.
 *
 * ## EXAMPLES
 *
 *   wp lwia scan
 *   wp lwia scan --format=csv > missing.csv
 *   wp lwia export ./missing-alt.csv
 *   wp lwia import ./missing-alt.csv
 *   wp lwia import ./missing-alt.csv --dry-run
 *   wp lwia undo a1b2c3d4-e5f6-7890-abcd-ef1234567890
 *   wp lwia log --limit=50
 *   wp lwia log --batch=a1b2c3d4-e5f6-7890-abcd-ef1234567890
 *
 * @package LW_Image_Alt
 */
class LWIA_CLI {

	// =========================================================================
	// wp lwia scan
	// =========================================================================

	/**
	 * Print a summary of images missing alt text, or stream them as structured data.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, csv, json, yaml. Default: table (summary only).
	 *
	 * ## EXAMPLES
	 *
	 *   # Print human-readable summary
	 *   wp lwia scan
	 *
	 *   # Pipe missing list as CSV to stdout
	 *   wp lwia scan --format=csv
	 *   wp lwia scan --format=csv > missing.csv
	 *
	 * @when after_wp_load
	 */
	public function scan( array $args, array $assoc_args ): void {
		$format  = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$scanner = new LWIA_Scanner();

		if ( 'table' === $format ) {
			$missing = $scanner->get_total();
			$total   = $scanner->get_library_total();

			WP_CLI::line( sprintf(
				/* translators: 1: count of missing-alt images, 2: total images in library */
				'%1$d image%3$s missing alt text (of %2$d total in library)',
				$missing,
				$total,
				1 === $missing ? '' : 's'
			) );

			return;
		}

		// For non-table formats, page through all results and format them.
		$all_rows = $this->get_all_missing( $scanner );

		$items = array_map( static function ( $row ) {
			return array(
				'attachment_id' => $row->ID,
				'filename'      => basename( $row->guid ),
				'url'           => wp_get_attachment_url( (int) $row->ID ) ?: $row->guid,
				'title'         => $row->post_title,
				'uploaded'      => $row->post_date,
			);
		}, $all_rows );

		WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'attachment_id', 'filename', 'url', 'title', 'uploaded' )
		);
	}

	// =========================================================================
	// wp lwia export
	// =========================================================================

	/**
	 * Export the missing-alt list to a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Absolute or relative path to write the CSV file.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lwia export ./missing-alt.csv
	 *
	 * @when after_wp_load
	 */
	public function export( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Provide a file path. Usage: wp lwia export <file>' );
		}

		$file_path = $args[0];
		$dir       = dirname( $file_path );

		if ( ! is_dir( $dir ) ) {
			WP_CLI::error( sprintf( 'Directory "%s" does not exist.', $dir ) );
		}

		if ( ! is_writable( $dir ) ) {
			WP_CLI::error( sprintf( 'Directory "%s" is not writable.', $dir ) );
		}

		WP_CLI::log( 'Scanning for images missing alt text...' );

		$scanner  = new LWIA_Scanner();
		$all_rows = $this->get_all_missing( $scanner );

		if ( empty( $all_rows ) ) {
			WP_CLI::success( 'No images missing alt text. Nothing to export.' );
			return;
		}

		$handle = fopen( $file_path, 'w' );
		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Could not open "%s" for writing.', $file_path ) );
		}

		fwrite( $handle, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $handle, array( 'attachment_id', 'filename', 'url', 'current_alt', 'new_alt', 'title', 'caption' ) );

		foreach ( $all_rows as $row ) {
			fputcsv( $handle, array(
				(int) $row->ID,
				basename( $row->guid ),
				wp_get_attachment_url( (int) $row->ID ) ?: $row->guid,
				'',
				'',
				$row->post_title,
				get_post_field( 'post_excerpt', (int) $row->ID ),
			) );
		}

		fclose( $handle );

		WP_CLI::success( sprintf( 'Exported %d row(s) to %s', count( $all_rows ), $file_path ) );
	}

	// =========================================================================
	// wp lwia import
	// =========================================================================

	/**
	 * Import alt text from a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file to import.
	 *
	 * [--dry-run]
	 * : Preview changes without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lwia import ./missing-alt.csv
	 *   wp lwia import ./missing-alt.csv --dry-run
	 *
	 * @when after_wp_load
	 */
	public function import( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Provide a file path. Usage: wp lwia import <file>' );
		}

		$file_path = $args[0];
		$dry_run   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( ! file_exists( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		WP_CLI::log( 'Parsing CSV...' );

		$importer = new LWIA_CSV_Import();
		$parsed   = $importer->parse( $file_path );

		if ( $parsed['errors'] ) {
			foreach ( $parsed['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
			if ( empty( $parsed['rows'] ) ) {
				WP_CLI::error( 'Could not parse the CSV file. Aborting.' );
			}
		}

		WP_CLI::log( sprintf( 'Validating %d rows...', count( $parsed['rows'] ) ) );

		$validated = $importer->validate( $parsed['rows'] );

		$ok_count   = count( array_filter( $validated, static fn( $r ) => in_array( $r['status'], array( 'ok', 'warn' ), true ) ) );
		$skip_count = count( array_filter( $validated, static fn( $r ) => 'skip' === $r['status'] ) );
		$err_count  = count( array_filter( $validated, static fn( $r ) => 'error' === $r['status'] ) );

		WP_CLI::log( sprintf( 'Will update: %d  |  Will skip: %d  |  Errors: %d', $ok_count, $skip_count, $err_count ) );

		if ( $dry_run ) {
			$preview = array_slice( $validated, 0, 50 );
			$items   = array_map( static function ( $r ) {
				return array(
					'id'     => $r['attachment_id'],
					'status' => $r['status'],
					'alt'    => $r['new_alt'],
					'note'   => implode( '; ', $r['messages'] ),
				);
			}, $preview );

			WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'status', 'alt', 'note' ) );

			if ( count( $validated ) > 50 ) {
				WP_CLI::log( sprintf( '...and %d more rows (first 50 shown above).', count( $validated ) - 50 ) );
			}

			WP_CLI::success( 'Dry run complete. No changes were written.' );
			return;
		}

		if ( 0 === $ok_count ) {
			WP_CLI::success( 'Nothing to import (all rows are empty, skipped, or in error).' );
			return;
		}

		$batch_id = wp_generate_uuid4();
		$progress = WP_CLI\Utils\make_progress_bar( 'Importing', count( $validated ) );
		$applied  = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;

		do {
			$result   = $importer->apply_chunk( $validated, $batch_id, $offset, LWIA_CSV_Import::CHUNK_SIZE );
			$applied += $result['applied'];
			$skipped += $result['skipped'];
			$errors  += $result['errors'];
			$offset   = $result['next_offset'];
			$progress->tick( min( LWIA_CSV_Import::CHUNK_SIZE, $result['total'] - ( $offset - LWIA_CSV_Import::CHUNK_SIZE ) ) );
		} while ( ! $result['done'] );

		$progress->finish();

		WP_CLI::success( sprintf(
			'Import complete — Updated: %d | Skipped: %d | Errors: %d | Batch ID: %s',
			$applied,
			$skipped,
			$errors,
			$batch_id
		) );

		if ( $errors > 0 ) {
			WP_CLI::warning( sprintf( 'Check the log for details: wp lwia log --batch=%s', $batch_id ) );
		}
	}

	// =========================================================================
	// wp lwia undo
	// =========================================================================

	/**
	 * Roll back all alt text changes in a batch.
	 *
	 * ## OPTIONS
	 *
	 * <batch-id>
	 * : The batch UUID to roll back (shown at the end of `wp lwia import`).
	 *
	 * ## EXAMPLES
	 *
	 *   wp lwia undo a1b2c3d4-e5f6-7890-abcd-ef1234567890
	 *
	 * @when after_wp_load
	 */
	public function undo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Provide a batch ID. Usage: wp lwia undo <batch-id>' );
		}

		$batch_id = sanitize_text_field( $args[0] );

		WP_CLI::log( sprintf( 'Rolling back batch %s...', $batch_id ) );

		$undo   = new LWIA_Undo();
		$result = $undo->rollback( $batch_id );

		WP_CLI::success( sprintf(
			'Rollback complete — Restored: %d | Skipped: %d | Errors: %d',
			$result['rolled_back'],
			$result['skipped'],
			$result['errors']
		) );
	}

	// =========================================================================
	// wp lwia log
	// =========================================================================

	/**
	 * View the alt text change log.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of entries to show. Default 20. Max 1000.
	 *
	 * [--batch=<batch-id>]
	 * : Filter log to a specific batch UUID.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, csv, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lwia log --limit=50
	 *   wp lwia log --batch=a1b2c3d4-e5f6-7890-abcd-ef1234567890
	 *   wp lwia log --format=json
	 *
	 * @when after_wp_load
	 */
	public function log( array $args, array $assoc_args ): void {
		$limit    = max( 1, min( 1000, absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 ) ) ) );
		$batch_id = sanitize_text_field( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', '' ) );
		$format   = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$query_args = array(
			'per_page' => $limit,
			'paged'    => 1,
		);

		if ( $batch_id ) {
			$query_args['batch_id'] = $batch_id;
		}

		$entries = LWIA_Logger::get_entries( $query_args );

		if ( empty( $entries ) ) {
			WP_CLI::log( 'No log entries found.' );
			return;
		}

		$items = array_map( static function ( $e ) {
			return array(
				'id'            => $e->id,
				'created_at'    => $e->created_at,
				'attachment_id' => $e->attachment_id,
				'old_alt'       => $e->old_alt,
				'new_alt'       => $e->new_alt,
				'source'        => $e->source,
				'user_id'       => $e->user_id,
				'batch_id'      => $e->batch_id,
			);
		}, $entries );

		WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'id', 'created_at', 'attachment_id', 'old_alt', 'new_alt', 'source', 'user_id', 'batch_id' )
		);
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Fetch all missing-alt attachments across all pages of the scanner.
	 *
	 * @param LWIA_Scanner $scanner
	 * @return object[]
	 */
	private function get_all_missing( LWIA_Scanner $scanner ): array {
		// The scanner supports up to 50 000 rows per page — sufficient for most sites.
		return $scanner->get_missing( array( 'per_page' => 50000, 'paged' => 1 ) );
	}
}

WP_CLI::add_command( 'lwia', 'LWIA_CLI' );
