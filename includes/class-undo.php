<?php
/**
 * Batch rollback logic.
 *
 * get_batches()      — lists import batches that can be undone
 * get_batches_total() — count for pagination
 * rollback()         — restores previous alt values for every row in a batch
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Undo
 */
class LWIA_Undo {

	/**
	 * Return a paginated list of batches that can be undone.
	 *
	 * Batches are grouped from the log table. Undo operations themselves
	 * (source = 'undo') are excluded to prevent undo-of-undo loops.
	 *
	 * @param array $args { paged, per_page }
	 * @return object[] Each row: batch_id, source, user_id, row_count, created_at.
	 */
	public function get_batches( array $args = array() ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'lwia_log';
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$paged    = max( 1, absint( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );

		// GROUP BY batch_id, source, user_id so each row also carries who made it.
		// A given batch_id will always share one source and one user_id.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT
			    batch_id,
			    source,
			    user_id,
			    COUNT(*)        AS row_count,
			    MAX(created_at) AS created_at
			 FROM {$table}
			 WHERE source != 'undo'
			   AND batch_id != ''
			 GROUP BY batch_id, source, user_id
			 ORDER BY MAX(created_at) DESC
			 {$limit_sql}"
		);

		return $results ?? array();
	}

	/**
	 * Return the total count of distinct undoable batches.
	 *
	 * @return int
	 */
	public function get_batches_total(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'lwia_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT batch_id)
			 FROM {$table}
			 WHERE source != 'undo'
			   AND batch_id != ''"
		);
	}

	/**
	 * Roll back all alt text changes in a batch.
	 *
	 * Fetches every log entry for the batch_id and re-applies old_alt as the
	 * new value. The rollback is itself logged with source = 'undo' under a
	 * fresh UUID so it is auditable.
	 *
	 * @param string $batch_id UUID of the batch to roll back.
	 * @return array{rolled_back: int, skipped: int, errors: int}
	 */
	public function rollback( string $batch_id ): array {
		global $wpdb;

		$batch_id = sanitize_text_field( $batch_id );

		if ( ! $batch_id ) {
			return array( 'rolled_back' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$table = $wpdb->prefix . 'lwia_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, old_alt FROM {$table} WHERE batch_id = %s ORDER BY id ASC",
				$batch_id
			)
		);

		if ( ! $entries ) {
			return array( 'rolled_back' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$undo_batch_id = wp_generate_uuid4();
		$rolled_back   = 0;
		$skipped       = 0;
		$errors        = 0;

		foreach ( $entries as $entry ) {
			$attachment_id = (int) $entry->attachment_id;
			$restore_alt   = (string) $entry->old_alt;

			// Skip if the attachment has been deleted since the original change.
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$skipped++;
				continue;
			}

			$ok = LWIA_Updater::update( $attachment_id, $restore_alt, 'undo', $undo_batch_id );

			if ( $ok ) {
				$rolled_back++;
			} else {
				$errors++;
			}
		}

		return compact( 'rolled_back', 'skipped', 'errors' );
	}
}
