<?php
/**
 * OpenAI API provider — synchronous single-image and Batch API support.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_OpenAI
 */
class LWIA_AI_OpenAI extends LWIA_AI_Provider {

	const MODEL       = 'gpt-4o-mini';
	const API_BASE    = 'https://api.openai.com/v1';
	const MAX_TOKENS  = 150;
	const TIMEOUT     = 30;
	const BATCH_LIMIT = 50000;

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

		$messages = $this->build_messages( $image_url, $existing_alt, $style_guide );

		$body = wp_json_encode( array(
			'model'      => self::MODEL,
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => $messages,
		) );

		$response = wp_remote_post(
			self::API_BASE . '/chat/completions',
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

		$text   = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		$parsed = LWIA_AI_Prompt::parse_response( $text );

		$result->success    = $parsed['valid'];
		$result->alt        = $parsed['alt'];
		$result->confidence = $parsed['confidence'];

		if ( ! $parsed['valid'] ) {
			$result->error = __( 'AI returned an unusable response. Please try again or enter alt text manually.', 'lw-img-alt' );
		}

		$tokens_in  = (int) ( $data['usage']['prompt_tokens']    ?? 0 );
		$tokens_out = (int) ( $data['usage']['completion_tokens'] ?? 0 );
		if ( $tokens_in + $tokens_out > 0 ) {
			LWIA_AI_Settings::record_usage( $tokens_in, $tokens_out, 1, false );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Batch API
	// -------------------------------------------------------------------------

	/**
	 * Submit a batch of images to the OpenAI Batch API.
	 *
	 * Flow: build JSONL → upload file → create batch.
	 *
	 * @param array[] $jobs Each: { custom_id, image_url, context }.
	 * @return string  OpenAI batch ID on success, empty string on failure.
	 */
	public function create_batch( array $jobs ): string {
		self::$last_create_error = '';

		$style_guide = LWIA_AI_Settings::get_style_guide();
		$lines       = array();

		foreach ( array_slice( $jobs, 0, self::BATCH_LIMIT ) as $job ) {
			$image_url    = (string) ( $job['image_url']               ?? '' );
			$custom_id    = (string) ( $job['custom_id']               ?? '' );
			$existing_alt = (string) ( $job['context']['existing_alt'] ?? '' );

			if ( ! $image_url || ! $custom_id ) {
				continue;
			}

			$messages = $this->build_messages( $image_url, $existing_alt, $style_guide );

			$lines[] = wp_json_encode( array(
				'custom_id' => $custom_id,
				'method'    => 'POST',
				'url'       => '/v1/chat/completions',
				'body'      => array(
					'model'      => self::MODEL,
					'max_tokens' => self::MAX_TOKENS,
					'messages'   => $messages,
				),
			) );
		}

		if ( empty( $lines ) ) {
			self::$last_create_error = 'No valid jobs to submit.';
			return '';
		}

		// Step 1: Upload the JSONL file.
		$file_id = $this->upload_batch_file( implode( "\n", $lines ) );
		if ( ! $file_id ) {
			return '';
		}

		// Step 2: Create the batch.
		$response = wp_remote_post(
			self::API_BASE . '/batches',
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( array(
					'input_file_id'     => $file_id,
					'endpoint'          => '/v1/chat/completions',
					'completion_window' => '24h',
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_create_error = $response->get_error_message();
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ( 200 !== $code && 201 !== $code ) || empty( $data['id'] ) ) {
			$api_msg = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'HTTP %d', $code );
			self::$last_create_error = $api_msg;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LWIA: OpenAI batch create failed — ' . $api_msg );
			return '';
		}

		return (string) $data['id'];
	}

	/**
	 * Poll the status of an OpenAI batch job.
	 *
	 * Sets results_url to the output_file_id when the job is complete,
	 * so poll_pending_jobs() can pass it straight to retrieve_batch().
	 */
	public function poll_batch( string $batch_id ): LWIA_Batch_Status {
		$status           = new LWIA_Batch_Status();
		$status->batch_id = $batch_id;

		$response = wp_remote_get(
			self::API_BASE . '/batches/' . rawurlencode( $batch_id ),
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $status;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $status;
		}

		$oa_status                = (string) ( $data['status'] ?? '' );
		$ended_statuses           = array( 'completed', 'failed', 'expired', 'cancelled' );
		$status->processing_status = $oa_status;
		$status->is_ended          = in_array( $oa_status, $ended_statuses, true );
		$status->succeeded         = (int) ( $data['request_counts']['completed']   ?? 0 );
		$status->errored           = (int) ( $data['request_counts']['failed']      ?? 0 );
		$status->processing        = (int) ( $data['request_counts']['in_progress'] ?? 0 );
		$status->results_url       = (string) ( $data['output_file_id']             ?? '' );

		return $status;
	}

	/**
	 * Retrieve and parse results from a completed batch job.
	 *
	 * @param string $output_file_id  The output_file_id from the completed batch object.
	 * @return LWIA_AI_Result[]  Keyed by custom_id (attachment ID as string).
	 */
	public function retrieve_batch( string $output_file_id ): array {
		$results = array();

		if ( ! $output_file_id ) {
			return $results;
		}

		$response = wp_remote_get(
			self::API_BASE . '/files/' . rawurlencode( $output_file_id ) . '/content',
			array(
				'timeout' => 120,
				'headers' => $this->headers(),
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

			$custom_id         = (string) ( $item['custom_id'] ?? '' );
			$result            = new LWIA_AI_Result();
			$result->custom_id = $custom_id;

			$status_code = (int) ( $item['response']['status_code'] ?? 0 );

			if ( 200 === $status_code ) {
				$text               = (string) ( $item['response']['body']['choices'][0]['message']['content'] ?? '' );
				$parsed             = LWIA_AI_Prompt::parse_response( $text );
				$result->success    = $parsed['valid'];
				$result->alt        = $parsed['alt'];
				$result->confidence = $parsed['confidence'];

				if ( ! $parsed['valid'] ) {
					$result->error = __( 'AI returned an unusable response.', 'lw-img-alt' );
				}

				$total_in  += (int) ( $item['response']['body']['usage']['prompt_tokens']    ?? 0 );
				$total_out += (int) ( $item['response']['body']['usage']['completion_tokens'] ?? 0 );
				$count++;
			} else {
				$result->error = (string) ( $item['error']['message'] ?? __( 'Unknown error', 'lw-img-alt' ) );
			}

			if ( $custom_id ) {
				$results[ $custom_id ] = $result;
			}
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
	 * Build the messages array for a Chat Completions request.
	 */
	private function build_messages( string $image_url, string $existing_alt, string $style_guide ): array {
		$messages = array();

		$system = LWIA_AI_Prompt::build_system( $style_guide );
		if ( $system ) {
			$messages[] = array( 'role' => 'system', 'content' => $system );
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => LWIA_AI_Prompt::build_user_content( $image_url, $existing_alt ),
		);

		return $messages;
	}

	/**
	 * Upload a JSONL string as a batch input file.  Returns the file ID or ''.
	 */
	private function upload_batch_file( string $jsonl ): string {
		$boundary = wp_generate_password( 24, false );
		$crlf     = "\r\n";

		$body  = "--{$boundary}{$crlf}";
		$body .= "Content-Disposition: form-data; name=\"purpose\"{$crlf}{$crlf}";
		$body .= "batch{$crlf}";
		$body .= "--{$boundary}{$crlf}";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"batch.jsonl\"{$crlf}";
		$body .= "Content-Type: application/x-jsonlines{$crlf}{$crlf}";
		$body .= $jsonl . "{$crlf}";
		$body .= "--{$boundary}--{$crlf}";

		$response = wp_remote_post(
			self::API_BASE . '/files',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_create_error = $response->get_error_message();
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ( 200 !== $code && 201 !== $code ) || empty( $data['id'] ) ) {
			$api_msg = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'File upload HTTP %d', $code );
			self::$last_create_error = $api_msg;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LWIA: OpenAI file upload failed — ' . $api_msg );
			return '';
		}

		return (string) $data['id'];
	}

	/**
	 * Standard JSON request headers.
	 */
	private function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);
	}
}
