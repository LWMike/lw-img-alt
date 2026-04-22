<?php
/**
 * Shared write path for alt text updates.
 *
 * All writes to _wp_attachment_image_alt go through this class.
 * Never call update_post_meta() for alt text from any other class.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Updater
 */
class LWIA_Updater {

	/**
	 * Update the alt text for a single image attachment.
	 *
	 * Validates the attachment, writes the meta, logs the change, and flushes
	 * the scanner cache. This is the single write path used by the admin UI,
	 * AJAX handler, CSV importer, WP-CLI commands, and AI generator.
	 *
	 * @param int    $attachment_id WordPress attachment post ID.
	 * @param string $new_alt       New alt text (already sanitised by the caller).
	 * @param string $source        Change source: 'manual' | 'csv' | 'batch' | 'undo'.
	 * @param string $batch_id      UUID grouping related updates (generate one per operation set).
	 * @param array  $ai_data       Optional AI metadata: {
	 *                                  ai_model         string  e.g. 'claude-haiku-4-5-20251001'
	 *                                  ai_prompt_version string  e.g. 'v2.0'
	 *                                  ai_confidence     float   0.0–1.0
	 *                              }
	 * @return bool True if the meta was written, false on failure.
	 */
	public static function update(
		int $attachment_id,
		string $new_alt,
		string $source,
		string $batch_id,
		array $ai_data = array()
	): bool {
		// Read the previous value before overwriting — needed for the log and for undo.
		$old_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		// update_post_meta returns false only on a DB error.
		// It returns the new meta ID (int) on insert, or true on update, or '' (falsy)
		// when the new value equals the old value. We treat the "no change" case as success.
		$result = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_alt );

		if ( false === $result ) {
			return false;
		}

		LWIA_Logger::insert(
			array_merge(
				array(
					'attachment_id' => $attachment_id,
					'old_alt'       => $old_alt,
					'new_alt'       => $new_alt,
					'user_id'       => get_current_user_id(),
					'source'        => $source,
					'batch_id'      => $batch_id,
				),
				$ai_data
			)
		);

		// Invalidate scanner cache so the next scan reflects the change.
		LWIA_Scanner::flush_cache();

		return true;
	}
}
