<?php
/**
 * Log table schema, inserts, and queries.
 *
 * All writes to {prefix}lwia_log go through this class.
 * Never write to the log table directly from any other class.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Logger
 */
class LWIA_Logger {

	/**
	 * Create (or upgrade) the {prefix}lwia_log table using dbDelta.
	 *
	 * Called on plugin activation and can be called again safely on schema upgrades.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'lwia_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires two spaces before field definitions and PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
		  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		  old_alt TEXT DEFAULT NULL,
		  new_alt TEXT NOT NULL,
		  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		  source VARCHAR(16) NOT NULL DEFAULT '',
		  batch_id VARCHAR(36) NOT NULL DEFAULT '',
		  created_at DATETIME NOT NULL,
		  ai_model VARCHAR(64) DEFAULT NULL,
		  ai_prompt_version VARCHAR(16) DEFAULT NULL,
		  ai_confidence DECIMAL(3,2) DEFAULT NULL,
		  PRIMARY KEY  (id),
		  KEY attachment_id (attachment_id),
		  KEY batch_id (batch_id),
		  KEY source (source),
		  KEY created_at (created_at),
		  KEY ai_model (ai_model)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param array $data {
	 *     Required. Log row data.
	 *     @type int         $attachment_id     Attachment post ID.
	 *     @type string      $old_alt           Previous alt text value (empty string if none).
	 *     @type string      $new_alt           New alt text value.
	 *     @type int         $user_id           WordPress user ID who made the change.
	 *     @type string      $source            'manual' | 'csv' | 'batch' | 'undo'.
	 *     @type string      $batch_id          UUID grouping related updates.
	 *     @type string|null $ai_model          Model ID e.g. 'claude-haiku-4-5-20251001'. Omit/null for manual edits.
	 *     @type string|null $ai_prompt_version Prompt version e.g. 'v2.0'. Omit/null for manual edits.
	 *     @type float|null  $ai_confidence     0.0–1.0 confidence score. Omit/null for manual edits.
	 * }
	 * @return bool True on success, false on DB error.
	 */
	public static function insert( array $data ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'lwia_log';

		// Base columns always written.
		$row     = array(
			'attachment_id' => (int) ( $data['attachment_id'] ?? 0 ),
			'old_alt'       => isset( $data['old_alt'] ) ? (string) $data['old_alt'] : '',
			'new_alt'       => (string) ( $data['new_alt'] ?? '' ),
			'user_id'       => (int) ( $data['user_id'] ?? 0 ),
			'source'        => sanitize_key( $data['source'] ?? '' ),
			'batch_id'      => sanitize_text_field( $data['batch_id'] ?? '' ),
			'created_at'    => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

		// Optional AI columns — omitted entirely when null so DB DEFAULT NULL applies.
		if ( isset( $data['ai_model'] ) && '' !== $data['ai_model'] ) {
			$row['ai_model']          = sanitize_text_field( $data['ai_model'] );
			$row['ai_prompt_version'] = sanitize_text_field( $data['ai_prompt_version'] ?? '' );
			$row['ai_confidence']     = round( (float) ( $data['ai_confidence'] ?? 0.0 ), 2 );
			$formats[]                = '%s';
			$formats[]                = '%s';
			$formats[]                = '%f';
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			$row,
			$formats
		);

		return false !== $result;
	}

	/**
	 * Return a paginated list of log entries.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @type int    $paged    Current page. Default 1.
	 *     @type int    $per_page Results per page. Max 100. Default 20.
	 *     @type string $source   Filter by source: 'manual'|'csv'|'batch'|'undo'.
	 *     @type int    $user_id  Filter by WordPress user ID.
	 *     @type string $batch_id Filter by batch UUID.
	 * }
	 * @return object[]
	 */
	public static function get_entries( array $args = array() ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'lwia_log';
		$per_page = max( 1, min( 1000, absint( $args['per_page'] ?? 20 ) ) );
		$paged    = max( 1, absint( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$conditions = self::build_conditions( $args );
		$where      = 'WHERE ' . implode( ' AND ', $conditions );
		$limit_sql  = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );

		// Each dynamic condition was individually run through $wpdb->prepare() in build_conditions().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC {$limit_sql}" );

		return $results ?? array();
	}

	/**
	 * Return the total count of log entries matching the given filters.
	 *
	 * @param array $args Same filter args as get_entries().
	 * @return int
	 */
	public static function get_total( array $args = array() ): int {
		global $wpdb;

		$table      = $wpdb->prefix . 'lwia_log';
		$conditions = self::build_conditions( $args );
		$where      = 'WHERE ' . implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build an array of pre-escaped WHERE conditions from the query args.
	 *
	 * Each dynamic condition is individually run through $wpdb->prepare() so
	 * the resulting strings can be safely concatenated into a larger query.
	 *
	 * @param array $args Filter args (source, user_id, batch_id).
	 * @return string[]
	 */
	private static function build_conditions( array $args ): array {
		global $wpdb;

		$conditions = array( '1=1' );

		$allowed_sources = array( 'manual', 'csv', 'batch', 'undo' );

		if ( ! empty( $args['source'] ) && in_array( $args['source'], $allowed_sources, true ) ) {
			$conditions[] = $wpdb->prepare( 'source = %s', $args['source'] );
		}

		if ( ! empty( $args['user_id'] ) && absint( $args['user_id'] ) > 0 ) {
			$conditions[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
		}

		if ( ! empty( $args['batch_id'] ) ) {
			$conditions[] = $wpdb->prepare( 'batch_id = %s', sanitize_text_field( $args['batch_id'] ) );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$conditions[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$conditions[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) . ' 23:59:59' );
		}

		return $conditions;
	}

	/**
	 * Return distinct users who have at least one log entry.
	 *
	 * @return array[] Each element: { id: int, name: string }.
	 */
	public static function get_distinct_users(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'lwia_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} ORDER BY user_id ASC" );

		$users = array();
		foreach ( (array) $user_ids as $uid ) {
			$user    = get_userdata( (int) $uid );
			$users[] = array(
				'id'   => (int) $uid,
				'name' => $user ? $user->display_name : '#' . $uid,
			);
		}

		return $users;
	}
}
