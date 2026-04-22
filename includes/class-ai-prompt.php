<?php
/**
 * Prompt templates and response parsing for AI alt text generation.
 *
 * All prompt logic lives here so the version string can be bumped in one place
 * and the Change Log can record which prompt produced each result.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_AI_Prompt
 */
class LWIA_AI_Prompt {

	const VERSION = 'v2.0';

	const SYSTEM = 'You write alt text for images on WordPress websites. Output rules:

- Describe what is visible in the image — the subject, key objects, style, and composition.
- Write in UK English.
- Maximum 125 characters. Prefer 80–120.
- One sentence. No line breaks.
- Never start with "Image of", "Photo of", or "Picture of".
- Never keyword-stuff. Do not include location names, brand names, or SEO phrases unless they are visibly depicted (e.g. a visible shop sign).
- If rewriting existing alt text, improve it by describing what\'s actually shown.
- Return JSON with fields: alt (string), confidence (0.0–1.0 float).

The site-specific style guide, if any, is appended below.';

	/**
	 * Build the system prompt, optionally appending the site style guide.
	 *
	 * @param string $style_guide Per-site context text from settings.
	 * @return string
	 */
	public static function build_system( string $style_guide = '' ): string {
		if ( $style_guide ) {
			return self::SYSTEM . "\n\n" . $style_guide;
		}
		return self::SYSTEM;
	}

	/**
	 * Build the user message content array for Anthropic's messages format.
	 *
	 * @param string $image_url    Publicly accessible image URL.
	 * @param string $existing_alt Current alt text (empty if generating from scratch).
	 * @return array  Anthropic content blocks array.
	 */
	public static function build_user_content( string $image_url, string $existing_alt = '' ): array {
		$content = array(
			array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => $image_url ),
			),
		);

		if ( $existing_alt ) {
			$text = 'This image currently has this alt text: "' . $existing_alt . '". Rewrite it to accurately describe what is actually visible in the image.';
		} else {
			$text = 'Generate alt text for this image.';
		}

		$content[] = array(
			'type' => 'text',
			'text' => $text,
		);

		return $content;
	}

	/**
	 * Parse and validate a raw model response string.
	 *
	 * The model is instructed to return JSON: { "alt": "...", "confidence": 0.0 }.
	 * This method handles JSON wrapped in markdown code fences, validates the
	 * output, and runs post-generation sanitisation rules.
	 *
	 * @param string $raw The text content from the model response.
	 * @return array { alt: string, confidence: float, valid: bool }
	 */
	public static function parse_response( string $raw ): array {
		$raw = trim( $raw );

		// Strip markdown code fences if the model wrapped the JSON.
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```\s*$/i', '', $raw );
		$raw = trim( $raw );

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! array_key_exists( 'alt', $decoded ) ) {
			return array( 'alt' => '', 'confidence' => 0.0, 'valid' => false );
		}

		$alt        = self::sanitise_alt( (string) $decoded['alt'] );
		$confidence = isset( $decoded['confidence'] )
			? max( 0.0, min( 1.0, (float) $decoded['confidence'] ) )
			: 0.5;

		return array(
			'alt'        => $alt,
			'confidence' => $confidence,
			'valid'      => '' !== $alt,
		);
	}

	/**
	 * Sanitise a raw alt text string from the model output.
	 *
	 * Applies the same post-generation validation rules as section 4.6 of the PRD:
	 * forbidden phrase stripping, pipe rejection, length truncation,
	 * whitespace normalisation, and WordPress sanitise_text_field().
	 *
	 * @param string $alt
	 * @return string Empty string if the result fails validation.
	 */
	public static function sanitise_alt( string $alt ): string {
		// Normalise whitespace.
		$alt = preg_replace( '/\s+/', ' ', trim( $alt ) );

		// Strip forbidden leading phrases.
		$alt = preg_replace( '/^(?:image|photo|picture)\s+of\s+/i', '', $alt );
		$alt = trim( $alt );

		// Reject if it contains a pipe (keyword-stuffing indicator).
		if ( str_contains( $alt, '|' ) ) {
			return '';
		}

		// Truncate to nearest word boundary at 125 characters.
		if ( mb_strlen( $alt ) > 125 ) {
			$alt = mb_substr( $alt, 0, 125 );
			$last_space = mb_strrpos( $alt, ' ' );
			if ( false !== $last_space && $last_space > 80 ) {
				$alt = mb_substr( $alt, 0, $last_space );
			}
		}

		return sanitize_text_field( $alt );
	}
}
