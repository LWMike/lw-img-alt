<?php
/**
 * Per-site AI settings: toggle, API key, style guide, spend cap, spend tracking.
 *
 * All option reads/writes go through this class.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_Settings
 */
class LWIA_AI_Settings {

	// -------------------------------------------------------------------------
	// Getters
	// -------------------------------------------------------------------------

	/**
	 * Return true if AI features are enabled for this site.
	 * Default: on (opt-out model).
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( 'lwia_ai_enabled', true );
	}

	/**
	 * Return the OpenAI API key, or empty string if not set.
	 */
	public static function get_api_key(): string {
		return (string) get_option( 'lwia_ai_api_key', '' );
	}

	/**
	 * Return the per-site style guide text.
	 */
	public static function get_style_guide(): string {
		return (string) get_option( 'lwia_ai_style_guide', '' );
	}

	/**
	 * Return the monthly spend cap in GBP (0 = no cap).
	 */
	public static function get_spend_cap(): float {
		return (float) get_option( 'lwia_ai_spend_cap', 20.0 );
	}

	// -------------------------------------------------------------------------
	// Spend tracking
	// -------------------------------------------------------------------------

	/**
	 * Return this month's spend record.
	 *
	 * @return array { month, tokens_in, tokens_out, images, estimated_gbp }
	 */
	public static function get_current_spend(): array {
		$month = current_time( 'Y-m' );
		$log   = (array) get_option( 'lwia_spend_log', array() );
		return $log[ $month ] ?? array(
			'month'         => $month,
			'tokens_in'     => 0,
			'tokens_out'    => 0,
			'images'        => 0,
			'estimated_gbp' => 0.0,
		);
	}

	/**
	 * Record API token usage for the current month.
	 *
	 * gpt-4o-mini pricing (batch = 50% discount):
	 *   Input:  $0.150/M tokens → batch $0.075/M
	 *   Output: $0.600/M tokens → batch $0.300/M
	 * USD→GBP at ~0.79.
	 *
	 * @param int  $tokens_in  Input token count.
	 * @param int  $tokens_out Output token count.
	 * @param int  $images     Number of images processed.
	 * @param bool $is_batch   Whether this used the Batch API (50% discount).
	 */
	public static function record_usage( int $tokens_in, int $tokens_out, int $images, bool $is_batch = false ): void {
		$rate_in  = $is_batch ? 0.075 : 0.150; // USD per M tokens
		$rate_out = $is_batch ? 0.300 : 0.600;
		$gbp_rate = 0.79;
		$cost_gbp = ( ( $tokens_in / 1_000_000 ) * $rate_in + ( $tokens_out / 1_000_000 ) * $rate_out ) * $gbp_rate;

		$month = current_time( 'Y-m' );
		$log   = (array) get_option( 'lwia_spend_log', array() );

		if ( ! isset( $log[ $month ] ) ) {
			$log[ $month ] = array(
				'month'         => $month,
				'tokens_in'     => 0,
				'tokens_out'    => 0,
				'images'        => 0,
				'estimated_gbp' => 0.0,
			);
		}

		$log[ $month ]['tokens_in']     += $tokens_in;
		$log[ $month ]['tokens_out']    += $tokens_out;
		$log[ $month ]['images']        += $images;
		$log[ $month ]['estimated_gbp'] += $cost_gbp;

		update_option( 'lwia_spend_log', $log, false );
	}

	/**
	 * Return true if this month's spend has hit or exceeded the cap.
	 */
	public static function is_cap_reached(): bool {
		$cap = self::get_spend_cap();
		if ( $cap <= 0 ) {
			return false;
		}
		return self::get_current_spend()['estimated_gbp'] >= $cap;
	}

	/**
	 * Return true if spend is at or above 80% of the cap (warning threshold).
	 */
	public static function is_cap_warning(): bool {
		$cap = self::get_spend_cap();
		if ( $cap <= 0 ) {
			return false;
		}
		return self::get_current_spend()['estimated_gbp'] >= ( $cap * 0.8 );
	}

	/**
	 * Estimate cost in GBP for a given number of images via Batch API.
	 *
	 * Conservative per-image estimate for gpt-4o-mini: ~800 input tokens, ~60 output.
	 * Image tokens vary with resolution; this covers most typical media library images.
	 *
	 * @param int $image_count
	 * @return float
	 */
	public static function estimate_cost( int $image_count ): float {
		$tokens_in  = $image_count * 800;
		$tokens_out = $image_count * 60;
		// Batch rate (50% off sync): $0.075/M in, $0.300/M out.
		$cost_usd = ( $tokens_in / 1_000_000 ) * 0.075 + ( $tokens_out / 1_000_000 ) * 0.300;
		return round( $cost_usd * 0.79, 2 );
	}

	// -------------------------------------------------------------------------
	// Form handler
	// -------------------------------------------------------------------------

	/**
	 * Persist settings submitted from the settings form.
	 *
	 * Capability check is the caller's responsibility.
	 *
	 * @param array $post $_POST data.
	 * @return array { success: bool, errors: string[] }
	 */
	public static function save_from_post( array $post ): array {
		$errors = array();

		// AI enabled toggle.
		$enabled = ! empty( $post['lwia_ai_enabled'] );
		update_option( 'lwia_ai_enabled', $enabled );

		// API key — only save if a non-masked value was submitted.
		if ( ! empty( $post['lwia_ai_api_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $post['lwia_ai_api_key'] ) );
			if ( ! str_contains( $key, '***' ) ) {
				update_option( 'lwia_ai_api_key', $key );
			}
		}

		// Style guide.
		$style_guide = sanitize_textarea_field( wp_unslash( (string) ( $post['lwia_ai_style_guide'] ?? '' ) ) );
		update_option( 'lwia_ai_style_guide', $style_guide );

		// Spend cap.
		$cap = max( 0.0, (float) ( $post['lwia_ai_spend_cap'] ?? 20.0 ) );
		update_option( 'lwia_ai_spend_cap', $cap );

		return array( 'success' => empty( $errors ), 'errors' => $errors );
	}

	/**
	 * Return a masked version of the API key for display (shows last 6 chars).
	 */
	public static function masked_api_key(): string {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		$suffix = mb_substr( $key, -6 );
		return str_repeat( '*', max( 0, mb_strlen( $key ) - 6 ) ) . $suffix;
	}
}
