<?php
/**
 * Heuristic quality scorer for existing alt text values.
 *
 * Runs without any API calls — pure string analysis.
 * Used to flag likely-poor existing alts for rewrite candidates.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_Quality_Score
 */
class LWIA_AI_Quality_Score {

	const GOOD         = 'good';
	const QUESTIONABLE = 'questionable';
	const POOR         = 'poor';

	/**
	 * Score a single alt text string.
	 *
	 * Returns a score (good/questionable/poor) and an array of flag names that
	 * triggered the penalty.
	 *
	 * @param string   $alt         The alt text to score.
	 * @param string[] $brand_terms Optional brand/location terms to flag (from AI Settings).
	 * @return array { score: string, flags: string[] }
	 */
	public static function score( string $alt, array $brand_terms = array() ): array {
		if ( '' === trim( $alt ) ) {
			return array( 'score' => self::POOR, 'flags' => array( 'empty' ) );
		}

		$flags = array();

		// Pipe character — strong keyword-stuffing signal.
		if ( str_contains( $alt, '|' ) ) {
			$flags[] = 'pipe';
		}

		// Forbidden leading phrases.
		if ( preg_match( '/^(?:image|photo|picture)\s+of\b/i', trim( $alt ) ) ) {
			$flags[] = 'forbidden_phrase';
		}

		// Exceeds recommended length.
		if ( mb_strlen( $alt ) > 125 ) {
			$flags[] = 'too_long';
		}

		// SEO keyword-style: 3+ short phrases separated by commas or pipes.
		if ( preg_match( '/\b\w+(?:\s+\w+)?\s*[,|]\s*\w+(?:\s+\w+)?\s*[,|]\s*\w+/i', $alt ) ) {
			$flags[] = 'keyword_style';
		}

		// Brand/location terms (configurable per site).
		if ( ! empty( $brand_terms ) ) {
			$escaped = array_map( 'preg_quote', array_filter( $brand_terms ) );
			if ( ! empty( $escaped ) ) {
				$pattern = '/\b(?:' . implode( '|', $escaped ) . ')\b/i';
				if ( preg_match( $pattern, $alt ) ) {
					$flags[] = 'brand_term';
				}
			}
		}

		$flag_count = count( $flags );

		if ( $flag_count >= 2 ) {
			$score = self::POOR;
		} elseif ( 1 === $flag_count ) {
			$score = self::QUESTIONABLE;
		} else {
			$score = self::GOOD;
		}

		return array( 'score' => $score, 'flags' => $flags );
	}

	/**
	 * Find alt texts that appear on more images than the given threshold.
	 *
	 * @param string[] $alts      Alt values keyed by attachment ID.
	 * @param int      $threshold Flag if used on more than this many images.
	 * @return string[]  The duplicate alt text values.
	 */
	public static function find_duplicates( array $alts, int $threshold = 3 ): array {
		$counts = array_count_values( array_filter( $alts ) );
		return array_keys( array_filter( $counts, static fn( $c ) => $c > $threshold ) );
	}

	/**
	 * CSS class for rendering a score badge.
	 */
	public static function score_class( string $score ): string {
		return match ( $score ) {
			self::GOOD         => 'lwia-quality-good',
			self::QUESTIONABLE => 'lwia-quality-warn',
			self::POOR         => 'lwia-quality-poor',
			default            => '',
		};
	}

	/**
	 * Display label for a score value.
	 */
	public static function score_label( string $score ): string {
		return match ( $score ) {
			self::GOOD         => __( 'Good', 'lw-img-alt' ),
			self::QUESTIONABLE => __( 'Questionable', 'lw-img-alt' ),
			self::POOR         => __( 'Poor', 'lw-img-alt' ),
			default            => '',
		};
	}
}
