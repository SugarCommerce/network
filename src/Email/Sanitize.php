<?php
/**
 * Sanitize Trait
 *
 * Methods for sanitizing email patterns and lists.
 *
 * @package     SugarCommerce\Network
 * @subpackage Email
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Email;

/**
 * Trait Sanitize
 *
 * Provides methods for sanitizing email patterns and lists.
 */
trait Sanitize {

	/**
	 * Sanitize and filter a list of email patterns.
	 *
	 * Takes raw input (string or array) and returns a clean array of valid patterns.
	 * Handles trimming, lowercasing, deduplication, and validation.
	 *
	 * @param string|array $input     Raw input - newline-separated string or array.
	 * @param bool         $as_string Return as newline-separated string instead of array.
	 *
	 * @return array|string Sanitized valid patterns.
	 */
	public static function sanitize_pattern_list( $input, bool $as_string = false ) {
		// Convert string to array.
		if ( is_string( $input ) ) {
			$patterns = explode( "\n", $input );
		} else {
			$patterns = (array) $input;
		}

		// Clean up each pattern.
		$patterns = array_map( 'trim', $patterns );
		$patterns = array_map( 'strtolower', $patterns );
		$patterns = array_filter( $patterns );

		$patterns = array_map( 'sanitize_text_field', $patterns );

		// Remove duplicates.
		$patterns = array_unique( $patterns );

		// Filter to valid patterns only.
		$patterns = self::filter_valid_patterns( $patterns );

		// Re-index array.
		$patterns = array_values( $patterns );

		return $as_string ? implode( "\n", $patterns ) : $patterns;
	}

	/**
	 * Sanitize a single email pattern.
	 *
	 * @param string $pattern Raw pattern input.
	 *
	 * @return string|null Sanitized pattern or null if invalid.
	 */
	public static function sanitize_pattern( string $pattern ): ?string {
		$pattern = trim( $pattern );
		$pattern = strtolower( $pattern );

		$pattern = sanitize_text_field( $pattern );

		return self::is_valid_pattern( $pattern ) ? $pattern : null;
	}

	/**
	 * Sanitize an email address.
	 *
	 * @param string $email Raw email input.
	 *
	 * @return string|null Sanitized email or null if invalid.
	 */
	public static function sanitize_email( string $email ): ?string {
		$email = strtolower( trim( $email ) );
		$email = sanitize_email( $email );

		return self::validate_email( $email ) ? $email : null;
	}

	/**
	 * Mask an email address for display: `p••@example.com`.
	 *
	 * For saying which address is meant without printing it -- "we sent a
	 * code to p••@example.com", a line in a log. The first character of
	 * the name, a fixed run of mask characters, and the domain whole and
	 * in lower case.
	 *
	 * The run is the same length whatever the name's, which is the
	 * difference from {@see self::to_masked()}: that one keeps the last
	 * character and one star per hidden letter, so it gives away the
	 * length of the name and both of its ends. Use this one for anything
	 * shown to somebody who may not be the address's owner.
	 *
	 * Takes a string and not a parsed address, so that an address too odd
	 * to validate -- but that somebody did type -- can still be shown
	 * masked. Only the shape is required: something, an @, something.
	 *
	 * @param string $email  The address.
	 * @param string $mask   The character that hides the name.
	 * @param int    $hidden How many of them, whatever the name's length.
	 *
	 * @return string The masked address, or '' when there is no name or
	 *                no domain to show.
	 */
	public static function mask( string $email, string $mask = '•', int $hidden = 2 ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at || $at < 1 || strlen( $email ) - 1 === $at ) {
			return '';
		}

		// By character, not by byte: half of a multibyte letter is not
		// a letter.
		$first = function_exists( 'mb_substr' ) ? mb_substr( $email, 0, 1, 'UTF-8' ) : substr( $email, 0, 1 );

		return $first . str_repeat( $mask, max( 1, $hidden ) ) . strtolower( substr( $email, $at ) );
	}
}
