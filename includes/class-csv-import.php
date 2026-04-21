<?php
/**
 * CSV parsing, validation, and chunked apply.
 *
 * parse()      — reads the file, maps headers, returns raw rows
 * validate()   — bulk-checks attachments, determines per-row status
 * apply_chunk() — writes a slice of validated rows via LWIA_Updater
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_CSV_Import
 */
class LWIA_CSV_Import {

	/** Maximum rows accepted per import to guard against memory blow-up. */
	const ROW_LIMIT = 50000;

	/** Rows applied per AJAX round-trip. */
	const CHUNK_SIZE = 250;

	/** Character length above which alt text triggers a warning (not a block). */
	const ALT_MAX_LEN = 125;

	/**
	 * Parse a CSV file and return its rows as associative arrays.
	 *
	 * Does not validate row data — that is done in validate().
	 *
	 * @param string $file_path Absolute path to the uploaded CSV file.
	 * @return array{rows: array[], errors: string[]}
	 */
	public function parse( string $file_path ): array {
		$result = array( 'rows' => array(), 'errors' => array() );

		if ( ! is_readable( $file_path ) ) {
			$result['errors'][] = __( 'File is not readable.', 'lw-img-alt' );
			return $result;
		}

		try {
			$file = new SplFileObject( $file_path, 'r' );
			$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD );
		} catch ( RuntimeException $e ) {
			$result['errors'][] = __( 'Could not open the CSV file.', 'lw-img-alt' );
			return $result;
		}

		// Read header row.
		$header_row = $file->current();
		$file->next();

		if ( ! is_array( $header_row ) || empty( $header_row ) ) {
			$result['errors'][] = __( 'The CSV file is empty or has no header row.', 'lw-img-alt' );
			return $result;
		}

		// Strip UTF-8 BOM from the first column name (Excel adds one on export).
		$header_row[0] = ltrim( $header_row[0], "\xEF\xBB\xBF" );

		// Normalise column names — lowercase, trimmed.
		$headers = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header_row );
		$col_map = array_flip( $headers );

		// Verify required columns.
		$required = array( 'attachment_id', 'new_alt' );
		$missing  = array_diff( $required, array_keys( $col_map ) );

		if ( $missing ) {
			$result['errors'][] = sprintf(
				/* translators: %s: comma-separated list of missing column names */
				__( 'Required column(s) missing from CSV header: %s', 'lw-img-alt' ),
				implode( ', ', $missing )
			);
			return $result;
		}

		// Read data rows.
		$count = 0;

		while ( ! $file->eof() ) {
			$raw = $file->current();
			$file->next();

			// Skip empty/whitespace-only rows.
			if ( ! is_array( $raw ) || ( 1 === count( $raw ) && '' === trim( (string) ( $raw[0] ?? '' ) ) ) ) {
				continue;
			}

			if ( $count >= self::ROW_LIMIT ) {
				$result['errors'][] = sprintf(
					/* translators: %d: row limit */
					__( 'CSV exceeds the %d-row limit. Remaining rows were ignored.', 'lw-img-alt' ),
					self::ROW_LIMIT
				);
				break;
			}

			// Build associative row from column map.
			$row = array();
			foreach ( $col_map as $col_name => $pos ) {
				$row[ $col_name ] = isset( $raw[ $pos ] ) ? trim( (string) $raw[ $pos ] ) : '';
			}

			$result['rows'][] = $row;
			$count++;
		}

		return $result;
	}

	/**
	 * Validate parsed rows and annotate each with a status and messages.
	 *
	 * Status values:
	 *   'ok'    — will be applied
	 *   'warn'  — will be applied with a warning (e.g. alt text > 125 chars)
	 *   'skip'  — new_alt is empty, will be skipped
	 *   'error' — attachment not found / not an image, will be skipped
	 *
	 * @param array[] $rows Raw rows from parse().
	 * @return array[] Rows with added 'status', 'messages', and sanitised 'new_alt'.
	 */
	public function validate( array $rows ): array {
		if ( empty( $rows ) ) {
			return array();
		}

		// Bulk-check all attachment IDs in one query to avoid N+1 DB hits.
		$all_ids   = array_unique( array_filter( array_map( 'absint', array_column( $rows, 'attachment_id' ) ) ) );
		$found_map = $this->fetch_image_attachments( $all_ids );

		$validated = array();

		foreach ( $rows as $row ) {
			$attachment_id = absint( $row['attachment_id'] ?? 0 );
			$new_alt       = sanitize_text_field( wp_unslash( $row['new_alt'] ?? '' ) );
			$filename      = sanitize_text_field( $row['filename'] ?? '' );
			$status        = 'ok';
			$messages      = array();

			if ( $attachment_id < 1 ) {
				$status     = 'error';
				$messages[] = __( 'Invalid or missing attachment_id.', 'lw-img-alt' );

			} elseif ( ! isset( $found_map[ $attachment_id ] ) ) {
				$status     = 'error';
				$messages[] = __( 'Attachment not found or is not an image.', 'lw-img-alt' );

			} else {
				// Optional filename sanity-check — warn but do not block.
				if ( $filename && $filename !== $found_map[ $attachment_id ] ) {
					$messages[] = sprintf(
						/* translators: 1: expected filename, 2: filename from CSV */
						__( 'Filename mismatch (expected "%1$s", CSV has "%2$s"). Matched by ID.', 'lw-img-alt' ),
						$found_map[ $attachment_id ],
						$filename
					);
				}

				if ( '' === $new_alt ) {
					$status     = 'skip';
					$messages[] = __( 'new_alt is empty — row will be skipped.', 'lw-img-alt' );

				} elseif ( mb_strlen( $new_alt ) > self::ALT_MAX_LEN ) {
					$status     = 'warn';
					$messages[] = sprintf(
						/* translators: 1: character count, 2: recommended maximum */
						__( 'Alt text is %1$d characters (recommended max: %2$d). Will be applied as-is.', 'lw-img-alt' ),
						mb_strlen( $new_alt ),
						self::ALT_MAX_LEN
					);
				}
			}

			$validated[] = array(
				'attachment_id' => $attachment_id,
				'new_alt'       => $new_alt,
				'filename'      => $filename,
				'status'        => $status,
				'messages'      => $messages,
			);
		}

		return $validated;
	}

	/**
	 * Apply a slice of validated rows via LWIA_Updater.
	 *
	 * Designed for chunked AJAX delivery: the caller sends offset + chunk_size,
	 * receives result counts + next_offset, and repeats until done === true.
	 *
	 * @param array[]  $rows       Validated rows from validate().
	 * @param string   $batch_id   UUID grouping this entire import.
	 * @param int      $offset     Index of the first row in this chunk.
	 * @param int      $chunk_size Maximum rows to process in one call.
	 * @return array{applied: int, skipped: int, errors: int, next_offset: int, done: bool, total: int}
	 */
	public function apply_chunk( array $rows, string $batch_id, int $offset, int $chunk_size ): array {
		$applied  = 0;
		$skipped  = 0;
		$errors   = 0;
		$total    = count( $rows );
		$end      = min( $offset + $chunk_size, $total );

		for ( $i = $offset; $i < $end; $i++ ) {
			$row = $rows[ $i ];

			if ( 'skip' === $row['status'] || 'error' === $row['status'] ) {
				$skipped++;
				continue;
			}

			$ok = LWIA_Updater::update(
				(int) $row['attachment_id'],
				$row['new_alt'],
				'csv',
				$batch_id
			);

			if ( $ok ) {
				$applied++;
			} else {
				$errors++;
			}
		}

		$next_offset = $end;
		$done        = $next_offset >= $total;

		return compact( 'applied', 'skipped', 'errors', 'next_offset', 'done', 'total' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a map of attachment_id => filename for the given IDs,
	 * restricted to image attachments only.
	 *
	 * Processes IDs in chunks of 1 000 to keep IN() clause sizes reasonable.
	 *
	 * @param int[] $ids
	 * @return array<int, string>  attachment_id => basename of guid
	 */
	private function fetch_image_attachments( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;

		$map = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// Each element of $chunk is a prepared %d value; the table name is a
			// trusted constant. phpcs:ignore comments acknowledge the pattern is safe.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT ID, guid FROM {$wpdb->posts}
					 WHERE ID IN ({$placeholders})
					   AND post_type = 'attachment'
					   AND post_mime_type LIKE 'image/%'",
					...$chunk
				)
			);

			foreach ( $rows as $row ) {
				$map[ (int) $row->ID ] = basename( $row->guid );
			}
		}

		return $map;
	}
}
