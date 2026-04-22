<?php
/**
 * Anthropic API provider — synchronous single-image and Batch API support.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_Anthropic
 */
class LWIA_AI_Anthropic extends LWIA_AI_Provider {

	const MODEL       = 'claude-haiku-4-5-20251001';
	const API_BASE    = 'https://api.anthropic.com/v1';
	const API_VERSION = '2023-06-01';
	const BATCH_BETA  = 'message-batches-2024-09-24';
	const MAX_TOKENS  = 150;
	const TIMEOUT     = 30;  // seconds — sync requests
	const BATCH_LIMIT = 10000; // max requests per Anthropic batch

	/** Populated on create_batch() failure so callers can surface the reason. */
	public static string $last_create_error = '';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	// -------------------------------------------------------------------------
	// Synchronous generation
	// -------------------------------------------------------------------------

	/**
	 * Generate alt text for a single image (synchronous API call).
	 *
	 * @param string $image_url Publicly accessible image URL.
	 * @param array  $context   Optional: 'existing_alt' (string), 'style_guide' (string).
	 * @return LWIA_AI_Result
	 */
	public function generate_single( string $image_url, array $context = array() ): LWIA_AI_Result {
		$result       = new LWIA_AI_Result();
		$style_guide  = (string) ( $context['style_guide']  ?? LWIA_AI_Settings::get_style_guide() );
		$existing_alt = (string) ( $context['existing_alt'] ?? '' );

		$body = wp_json_encode( array(
			'model'      => self::MODEL,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => LWIA_AI_Prompt::build_system( $style_guide ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => LWIA_AI_Prompt::build_user_content( $image_url, $existing_alt ),
				),
			),
		) );

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result->error = $response->get_error_message();
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$result->error = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: "HTTP {$code}";
			return $result;
		}

		$text   = (string) ( $data['content'][0]['text'] ?? '' );
		$parsed = LWIA_AI_Prompt::parse_response( $text );

		$result->success    = $parsed['valid'];
		$result->alt        = $parsed['alt'];
		$result->confidence = $parsed['confidence'];

		if ( ! $parsed['valid'] ) {
			$result->error = __( 'AI returned an unusable response. Please try again or enter alt text manually.', 'lw-img-alt' );
		}

		// Record token usage for spend tracking.
		$tokens_in  = (int) ( $data['usage']['input_tokens']  ?? 0 );
		$tokens_out = (int) ( $data['usage']['output_tokens'] ?? 0 );
		if ( $tokens_in + $tokens_out > 0 ) {
			LWIA_AI_Settings::record_usage( $tokens_in, $tokens_out, 1, false );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Batch API
	// -------------------------------------------------------------------------

	/**
	 * Submit a batch of images to the Anthropic Message Batches API.
	 *
	 * @param array[] $jobs Each: { custom_id, image_url, context }.
	 * @return string  Anthropic batch ID on success, empty string on failure.
	 */
	public function create_batch( array $jobs ): string {
		self::$last_create_error = '';

		$style_guide = LWIA_AI_Settings::get_style_guide();
		$requests    = array();

		foreach ( array_slice( $jobs, 0, self::BATCH_LIMIT ) as $job ) {
			$image_url    = (string) ( $job['image_url']              ?? '' );
			$custom_id    = (string) ( $job['custom_id']              ?? '' );
			$existing_alt = (string) ( $job['context']['existing_alt'] ?? '' );

			if ( ! $image_url || ! $custom_id ) {
				continue;
			}

			$requests[] = array(
				'custom_id' => $custom_id,
				'params'    => array(
					'model'      => self::MODEL,
					'max_tokens' => self::MAX_TOKENS,
					'system'     => LWIA_AI_Prompt::build_system( $style_guide ),
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => LWIA_AI_Prompt::build_user_content( $image_url, $existing_alt ),
						),
					),
				),
			);
		}

		if ( empty( $requests ) ) {
			return '';
		}

		$response = wp_remote_post(
			self::API_BASE . '/messages/batches',
			array(
				'timeout' => 60,
				'headers' => $this->headers( true ),
				'body'    => wp_json_encode( array( 'requests' => $requests ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_create_error = $response->get_error_message();
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ( 200 !== $code && 201 !== $code ) || empty( $data['id'] ) ) {
			$api_msg = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'HTTP %d', $code );
			self::$last_create_error = $api_msg;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LWIA: Anthropic batch create failed — ' . $api_msg );
			return '';
		}

		return (string) $data['id'];
	}

	/**
	 * Poll the status of an Anthropic batch job.
	 */
	public function poll_batch( string $batch_id ): LWIA_Batch_Status {
		$status           = new LWIA_Batch_Status();
		$status->batch_id = $batch_id;

		$response = wp_remote_get(
			self::API_BASE . '/messages/batches/' . rawurlencode( $batch_id ),
			array(
				'timeout' => 15,
				'headers' => $this->headers( true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $status;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $status;
		}

		$status->processing_status = (string) ( $data['processing_status']           ?? '' );
		$status->is_ended          = 'ended' === $status->processing_status;
		$status->succeeded         = (int) ( $data['request_counts']['succeeded']  ?? 0 );
		$status->errored           = (int) ( $data['request_counts']['errored']    ?? 0 );
		$status->processing        = (int) ( $data['request_counts']['processing'] ?? 0 );
		$status->results_url       = (string) ( $data['results_url'] ?? '' );

		return $status;
	}

	/**
	 * Retrieve and parse results from a completed batch job.
	 *
	 * The Batch API returns JSONL (one JSON object per line).
	 *
	 * @return LWIA_AI_Result[]  Keyed by custom_id (attachment ID as string).
	 */
	public function retrieve_batch( string $batch_id ): array {
		$results = array();

		$response = wp_remote_get(
			self::API_BASE . '/messages/batches/' . rawurlencode( $batch_id ) . '/results',
			array(
				'timeout' => 120,
				'headers' => $this->headers( true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $results;
		}

		$body  = wp_remote_retrieve_body( $response );
		$lines = explode( "\n", trim( $body ) );

		$total_in  = 0;
		$total_out = 0;
		$count     = 0;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$item = json_decode( $line, true );
			if ( ! is_array( $item ) ) {
				continue;
			}

			$custom_id          = (string) ( $item['custom_id'] ?? '' );
			$result             = new LWIA_AI_Result();
			$result->custom_id  = $custom_id;

			if ( 'succeeded' === ( $item['result']['type'] ?? '' ) ) {
				$text               = (string) ( $item['result']['message']['content'][0]['text'] ?? '' );
				$parsed             = LWIA_AI_Prompt::parse_response( $text );
				$result->success    = $parsed['valid'];
				$result->alt        = $parsed['alt'];
				$result->confidence = $parsed['confidence'];

				if ( ! $parsed['valid'] ) {
					$result->error = __( 'AI returned an unusable response.', 'lw-img-alt' );
				}

				$total_in  += (int) ( $item['result']['message']['usage']['input_tokens']  ?? 0 );
				$total_out += (int) ( $item['result']['message']['usage']['output_tokens'] ?? 0 );
				$count++;
			} else {
				$result->error = (string) ( $item['result']['error']['message'] ?? __( 'Unknown error', 'lw-img-alt' ) );
			}

			$results[ $custom_id ] = $result;
		}

		if ( $count > 0 ) {
			LWIA_AI_Settings::record_usage( $total_in, $total_out, $count, true );
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build request headers for the Anthropic API.
	 *
	 * @param bool $batch Include the message-batches beta header.
	 */
	private function headers( bool $batch = false ): array {
		$headers = array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);

		if ( $batch ) {
			$headers['anthropic-beta'] = self::BATCH_BETA;
		}

		return $headers;
	}
}
