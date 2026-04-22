<?php
/**
 * Abstract AI provider interface and shared result/status value objects.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by a single-image generation call.
 */
class LWIA_AI_Result {
	public string $custom_id  = '';
	public string $alt        = '';
	public float  $confidence = 0.0;
	public bool   $success    = false;
	public string $error      = '';
}

/**
 * Value object returned by a batch status poll.
 */
class LWIA_Batch_Status {
	public string $batch_id          = '';
	public string $processing_status = ''; // 'in_progress' | 'ended' | 'canceling' | 'canceled'
	public int    $succeeded         = 0;
	public int    $errored           = 0;
	public int    $processing        = 0;
	public bool   $is_ended          = false;
	public string $results_url       = '';
}

/**
 * Abstract base class for AI providers (Anthropic, and future providers).
 *
 * Concrete implementations must supply:
 *   - generate_single() — synchronous single-image call
 *   - create_batch()    — submit a batch job, return vendor batch ID
 *   - poll_batch()      — check status of a running job
 *   - retrieve_batch()  — fetch results from a completed job
 */
abstract class LWIA_AI_Provider {

	/**
	 * Generate alt text for a single image synchronously.
	 *
	 * @param string $image_url Publicly accessible URL to the image.
	 * @param array  $context   Optional context: 'existing_alt' (string), 'style_guide' (string).
	 * @return LWIA_AI_Result
	 */
	abstract public function generate_single( string $image_url, array $context = array() ): LWIA_AI_Result;

	/**
	 * Submit a batch of images for asynchronous processing.
	 *
	 * @param array[] $jobs Each element: { custom_id: string, image_url: string, context: array }.
	 * @return string  Vendor-specific batch job ID, or empty string on failure.
	 */
	abstract public function create_batch( array $jobs ): string;

	/**
	 * Poll the status of a running batch job.
	 *
	 * @param string $batch_id Vendor-specific batch job ID.
	 * @return LWIA_Batch_Status
	 */
	abstract public function poll_batch( string $batch_id ): LWIA_Batch_Status;

	/**
	 * Retrieve results from a completed batch job.
	 *
	 * @param string $batch_id Vendor-specific batch job ID.
	 * @return LWIA_AI_Result[]  Associative array keyed by custom_id.
	 */
	abstract public function retrieve_batch( string $batch_id ): array;
}
