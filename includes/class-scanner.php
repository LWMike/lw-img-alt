<?php
/**
 * Finds image attachments missing alt text.
 *
 * Uses direct $wpdb queries for performance on large media libraries (10,000+ images).
 * Results are cached in the 'lwia_scanner' object cache group. Cache is flushed when
 * a new attachment is uploaded or when an alt text is updated via LWIA_Updater.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Scanner
 */
class LWIA_Scanner {

	const CACHE_GROUP = 'lwia_scanner';
	const CACHE_TTL   = HOUR_IN_SECONDS;

	/**
	 * Return a paginated list of image attachments missing alt text.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @type int    $per_page   Results per page. Default 50. Max 500.
	 *     @type int    $paged      Current page number. Default 1.
	 *     @type string $attachment Filter: 'all' | 'attached' | 'unattached'. Default 'all'.
	 *     @type string $date_from  Y-m-d. Only include uploads on or after this date.
	 *     @type string $date_to    Y-m-d. Only include uploads on or before this date.
	 *     @type string $mime_type  e.g. 'image/jpeg'. 'all' means no filter. Default 'all'.
	 * }
	 * @return object[] Array of stdClass rows: ID, post_title, post_date, post_parent,
	 *                  post_mime_type, guid.
	 */
	public function get_missing( array $args = array() ): array {
		global $wpdb;

		$args     = $this->normalise_args( $args );
		$ckey     = $this->cache_key( 'rows', $args );
		$cached   = wp_cache_get( $ckey, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$this->prime_meta_cache( $cached );
			return $cached;
		}

		$where     = $this->build_where( $args );
		$offset    = ( $args['paged'] - 1 ) * $args['per_page'];
		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], $offset );

		// Direct query for performance. Every dynamic condition was run through
		// $wpdb->prepare() individually inside build_where().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT p.ID,
			        p.post_title,
			        p.post_date,
			        p.post_parent,
			        p.post_mime_type,
			        p.guid
			 FROM {$wpdb->posts} p
			 WHERE {$where}
			 ORDER BY p.post_date DESC
			 {$limit_sql}"
		);

		$results = $results ?? array();

		$this->prime_meta_cache( $results );

		wp_cache_set( $ckey, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Return the total count of image attachments missing alt text, respecting filters.
	 *
	 * @param array $args Same filter args as get_missing(). page/per_page are ignored.
	 * @return int
	 */
	public function get_total( array $args = array() ): int {
		global $wpdb;

		$args   = $this->normalise_args( $args );
		$ckey   = $this->cache_key( 'total', $args );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$where = $this->build_where( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 WHERE {$where}"
		);

		wp_cache_set( $ckey, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return the total number of image attachments in the library, regardless of alt status.
	 *
	 * Used for the summary line: "X missing of Y total".
	 *
	 * @return int
	 */
	public function get_library_total(): int {
		global $wpdb;

		$ckey   = 'library_total';
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts}
			 WHERE post_type   = 'attachment'
			   AND post_status = 'inherit'
			   AND post_mime_type LIKE 'image/%'"
		);

		wp_cache_set( $ckey, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Flush all scanner cache entries.
	 *
	 * Called after any alt text update (via LWIA_Updater) or new upload (via add_attachment hook).
	 */
	public static function flush_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise and sanitise query arguments.
	 */
	private function normalise_args( array $args ): array {
		$defaults = array(
			'per_page'   => 50,
			'paged'      => 1,
			'attachment' => 'all',
			'date_from'  => '',
			'date_to'    => '',
			'mime_type'  => 'all',
		);

		$args = array_merge( $defaults, $args );

		// Numeric args.
		$args['per_page'] = max( 1, min( 500, absint( $args['per_page'] ) ) );
		$args['paged']    = max( 1, absint( $args['paged'] ) );

		// Attachment filter — whitelist.
		$args['attachment'] = in_array( $args['attachment'], array( 'all', 'attached', 'unattached' ), true )
			? $args['attachment']
			: 'all';

		// Date strings — validate format; blank if invalid.
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$val          = sanitize_text_field( $args[ $key ] );
			$dt           = ( '' !== $val ) ? \DateTime::createFromFormat( 'Y-m-d', $val ) : false;
			$args[ $key ] = $dt ? $val : '';
		}

		// Mime type — whitelist.
		$allowed_mimes  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' );
		$args['mime_type'] = ( 'all' === $args['mime_type'] || in_array( $args['mime_type'], $allowed_mimes, true ) )
			? $args['mime_type']
			: 'all';

		return $args;
	}

	/**
	 * Build a ready-to-embed WHERE clause string.
	 *
	 * All static conditions are literal SQL with no user input.
	 * All dynamic conditions are individually run through $wpdb->prepare() before
	 * being concatenated here, so the final string is fully escaped.
	 *
	 * @return string SQL fragment beginning with the first condition (no leading WHERE keyword).
	 */
	private function build_where( array $args ): string {
		global $wpdb;

		$conditions = array(
			"p.post_type = 'attachment'",
			"p.post_status = 'inherit'",
			"p.post_mime_type LIKE 'image/%'",
			// NOT EXISTS is more correct than LEFT JOIN IS NULL — avoids row
			// multiplication if the meta key has been duplicated (data corruption edge case).
			"NOT EXISTS (
			    SELECT 1 FROM {$wpdb->postmeta} pm_alt
			    WHERE pm_alt.post_id  = p.ID
			      AND pm_alt.meta_key = '_wp_attachment_image_alt'
			      AND pm_alt.meta_value != ''
			)",
		);

		if ( 'attached' === $args['attachment'] ) {
			$conditions[] = 'p.post_parent > 0';
		} elseif ( 'unattached' === $args['attachment'] ) {
			$conditions[] = 'p.post_parent = 0';
		}

		if ( '' !== $args['date_from'] ) {
			$conditions[] = $wpdb->prepare( 'p.post_date >= %s', $args['date_from'] . ' 00:00:00' );
		}

		if ( '' !== $args['date_to'] ) {
			$conditions[] = $wpdb->prepare( 'p.post_date <= %s', $args['date_to'] . ' 23:59:59' );
		}

		if ( 'all' !== $args['mime_type'] ) {
			$conditions[] = $wpdb->prepare( 'p.post_mime_type = %s', $args['mime_type'] );
		}

		return implode( ' AND ', $conditions );
	}

	/**
	 * Generate a deterministic cache key for a query type and its arguments.
	 */
	private function cache_key( string $type, array $args ): string {
		return $type . '_' . md5( (string) wp_json_encode( $args ) );
	}

	/**
	 * Prime WordPress's post-meta cache for a set of result rows.
	 *
	 * After this call, get_post_meta() for any of these IDs within the same
	 * request will read from memory rather than hitting the database.
	 *
	 * @param object[] $rows Scanner result rows.
	 */
	private function prime_meta_cache( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$ids = array_map( static fn( $row ) => (int) $row->ID, $rows );
		update_meta_cache( 'post', $ids );
	}
}
