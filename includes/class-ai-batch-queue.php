<?php
/**
 * AI batch job lifecycle: create, store, poll via WP-Cron, retrieve results.
 *
 * Jobs are persisted in the 'lwia_ai_jobs' option (array keyed by local UUID).
 * Results are stored in transients: 'lwia_ai_results_{local_id}'.
 * WP-Cron fires 'lwia_poll_ai_batch' every minute while jobs are processing.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_Batch_Queue
 */
class LWIA_AI_Batch_Queue {

	const OPTION_KEY  = 'lwia_ai_jobs';
	const CRON_HOOK   = 'lwia_poll_ai_batch';
	const CRON_PERIOD = 'lwia_every_minute';
	const RESULTS_TTL = 7 * DAY_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Register the cron schedule and action hook.
	 * Called once from LWIA_Plugin::init_hooks().
	 */
	public static function register_hooks(): void {
		add_filter( 'cron_schedules',  array( __CLASS__, 'add_cron_schedule' ) );
		add_action( self::CRON_HOOK,   array( __CLASS__, 'poll_pending_jobs' ) );
	}

	/**
	 * Register a 60-second cron interval so WP-Cron can poll batch jobs.
	 */
	public static function add_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_PERIOD ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Image Alt batch polling)', 'lw-img-alt' ),
		);
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Job creation
	// -------------------------------------------------------------------------

	/**
	 * Create a new batch job and submit it to the OpenAI Batch API.
	 *
	 * @param array  $images Array of { attachment_id, image_url, existing_alt }.
	 * @param string $mode   'missing' | 'rewrite' | 'both'.
	 * @return string  Local job UUID on success, empty string on failure.
	 */
	public static function create( array $images, string $mode = 'missing' ): string {
		$provider = self::get_provider();
		if ( ! $provider ) {
			return '';
		}

		if ( LWIA_AI_Settings::is_cap_reached() ) {
			return '';
		}

		// Build jobs array for the provider.
		$jobs = array_map( static fn( $img ) => array(
			'custom_id' => (string) $img['attachment_id'],
			'image_url' => (string) $img['image_url'],
			'context'   => array( 'existing_alt' => (string) ( $img['existing_alt'] ?? '' ) ),
		), $images );

		$vendor_batch_id = $provider->create_batch( $jobs );
		if ( ! $vendor_batch_id ) {
			if ( $provider instanceof LWIA_AI_OpenAI && '' !== LWIA_AI_OpenAI::$last_create_error ) {
				set_transient(
					'lwia_batch_err_' . get_current_user_id(),
					LWIA_AI_OpenAI::$last_create_error,
					2 * MINUTE_IN_SECONDS
				);
			}
			return '';
		}

		$local_id  = wp_generate_uuid4();
		$all_jobs  = (array) get_option( self::OPTION_KEY, array() );

		$all_jobs[ $local_id ] = array(
			'id'              => $local_id,
			'vendor_batch_id' => $vendor_batch_id,
			'status'          => 'processing',
			'mode'            => sanitize_key( $mode ),
			'count'           => count( $jobs ),
			'created_at'      => current_time( 'mysql', true ),
			'completed_at'    => null,
			'user_id'         => get_current_user_id(),
		);

		update_option( self::OPTION_KEY, $all_jobs, false );

		// Schedule the cron poller if not already running.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_PERIOD, self::CRON_HOOK );
		}

		return $local_id;
	}

	// -------------------------------------------------------------------------
	// Polling (runs via WP-Cron)
	// -------------------------------------------------------------------------

	/**
	 * Poll all processing jobs.  Called by WP-Cron.
	 */
	public static function poll_pending_jobs(): void {
		$provider = self::get_provider();
		if ( ! $provider ) {
			return;
		}

		$all_jobs = (array) get_option( self::OPTION_KEY, array() );
		$changed  = false;

		foreach ( $all_jobs as $local_id => $job ) {
			if ( 'processing' !== ( $job['status'] ?? '' ) ) {
				continue;
			}

			$vendor_batch_id = (string) ( $job['vendor_batch_id'] ?? '' );
			$status          = $provider->poll_batch( $vendor_batch_id );

			if ( ! $status->is_ended ) {
				continue;
			}

			// For OpenAI, results_url holds the output_file_id needed to download results.
			$retrieve_id = $status->results_url ?: $vendor_batch_id;
			$results     = $provider->retrieve_batch( $retrieve_id );
			set_transient( 'lwia_ai_results_' . $local_id, $results, self::RESULTS_TTL );

			$all_jobs[ $local_id ]['status']       = 'complete';
			$all_jobs[ $local_id ]['completed_at'] = current_time( 'mysql', true );
			$all_jobs[ $local_id ]['succeeded']    = $status->succeeded;
			$all_jobs[ $local_id ]['errored']       = $status->errored;
			$changed = true;

			// Queue an admin notice for the job owner.
			self::queue_completion_notice( $local_id, $all_jobs[ $local_id ] );
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $all_jobs, false );
		}

		// Unschedule the cron if no jobs are still processing.
		$pending = array_filter( $all_jobs, static fn( $j ) => 'processing' === ( $j['status'] ?? '' ) );
		if ( empty( $pending ) ) {
			$ts = wp_next_scheduled( self::CRON_HOOK );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::CRON_HOOK );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	/**
	 * Return all jobs, newest first.
	 *
	 * @return array[]
	 */
	public static function get_jobs(): array {
		$all = (array) get_option( self::OPTION_KEY, array() );
		usort( $all, static fn( $a, $b ) => strcmp(
			(string) ( $b['created_at'] ?? '' ),
			(string) ( $a['created_at'] ?? '' )
		) );
		return $all;
	}

	/**
	 * Return a single job by local UUID, or null if not found.
	 *
	 * @param string $local_id
	 * @return array|null
	 */
	public static function get_job( string $local_id ): ?array {
		$all = (array) get_option( self::OPTION_KEY, array() );
		return $all[ $local_id ] ?? null;
	}

	/**
	 * Return true if any job is currently processing (for single-job-at-a-time guard).
	 */
	public static function has_active_job(): bool {
		$all = (array) get_option( self::OPTION_KEY, array() );
		foreach ( $all as $job ) {
			if ( 'processing' === ( $job['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return cached results for a completed job.
	 *
	 * @param string $local_id
	 * @return LWIA_AI_Result[]|false  False if results have expired or job not complete.
	 */
	public static function get_results( string $local_id ) {
		return get_transient( 'lwia_ai_results_' . $local_id );
	}

	// -------------------------------------------------------------------------
	// Provider factory
	// -------------------------------------------------------------------------

	/**
	 * Return an instantiated provider, or null if AI is disabled / no API key.
	 */
	public static function get_provider(): ?LWIA_AI_Provider {
		if ( ! LWIA_AI_Settings::is_enabled() ) {
			return null;
		}
		$api_key = LWIA_AI_Settings::get_api_key();
		if ( ! $api_key ) {
			return null;
		}
		return new LWIA_AI_OpenAI( $api_key );
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Store a completion notice transient for the job owner so they see a banner
	 * next time they visit the admin.
	 */
	private static function queue_completion_notice( string $local_id, array $job ): void {
		$user_id    = (int) ( $job['user_id'] ?? 0 );
		$notice_key = 'lwia_ai_complete_' . $user_id;
		set_transient( $notice_key, array(
			'job_id'    => $local_id,
			'count'     => (int) ( $job['count'] ?? 0 ),
			'succeeded' => (int) ( $job['succeeded'] ?? 0 ),
		), HOUR_IN_SECONDS );
	}
}
