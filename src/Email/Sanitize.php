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
	 * masked. Only the shape is required: something, an @, something. It is
	 * split at the last `@`: a domain cannot hold one and a quoted name can.
	 *
	 * For an admin list that should look like the address it hides, keep
	 * two characters and let the run follow the name's length, with
	 * `$hidden` as its least -- and show whatever is stored when it is not
	 * an address at all:
	 *
	 *     Email::mask( $email, keep: 2, show_length: true, keep_invalid: true );   // "ge••••@gmail.com"
	 *
	 * @param string $email        The address.
	 * @param string $mask         The character that hides the name.
	 * @param int    $hidden       How many of them, whatever the name's length;
	 *                             the fewest, with $show_length. At least one.
	 * @param int    $keep         How many characters of the name to show, at
	 *                             most all of it. At least one.
	 * @param bool   $keep_invalid Hand back what is not an address -- no `@`,
	 *                             nothing before it or after it -- as given,
	 *                             rather than ''. For a list cell that shows
	 *                             whatever is stored.
	 * @param bool   $show_length  One mask character per hidden one, so the
	 *                             mask is as long as the name it hides.
	 *
	 * @return string The masked address; '' (or $email, with $keep_invalid)
	 *                when there is no name or no domain to show.
	 */
	public static function mask( string $email, string $mask = '•', int $hidden = 2, int $keep = 1, bool $keep_invalid = false, bool $show_length = false ): string {
		$trimmed = trim( $email );
		$at      = strrpos( $trimmed, '@' );

		if ( false === $at || $at < 1 || strlen( $trimmed ) - 1 === $at ) {
			return $keep_invalid ? $email : '';
		}

		// By character, not by byte: half of a multibyte letter is not
		// a letter.
		$name   = substr( $trimmed, 0, $at );
		$length = mb_strlen( $name, 'UTF-8' );
		$shown  = mb_substr( $name, 0, max( 1, $keep ), 'UTF-8' );
		$run    = max( 1, $hidden );

		if ( $show_length ) {
			$run = max( $run, $length - mb_strlen( $shown, 'UTF-8' ) );
		}

		return $shown . str_repeat( $mask, $run ) . strtolower( substr( $trimmed, $at ) );
	}

	/**
	 * A valid address as it was typed, with only the domain lower-cased.
	 *
	 * The name keeps its case, because RFC 5321 says it is significant and
	 * some mail servers mean it; the domain is lower-cased because it is
	 * not. {@see self::sanitize_email()} lower-cases the whole address,
	 * which is right for a key to compare on and wrong for the address to
	 * send to.
	 *
	 * Longer than 254 characters is refused, not cut: the first 254
	 * characters of something are a different address, possibly somebody
	 * else's. Anything that is not a string -- an array posted where an
	 * address was expected -- is nothing, never "Array".
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value What was typed.
	 *
	 * @return string The address, or '' when it is not one.
	 */
	public static function clean( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		$at    = strrpos( $value, '@' );

		if ( false === $at || mb_strlen( $value, 'UTF-8' ) > 254 ) {
			return '';
		}

		$value = substr( $value, 0, $at ) . '@' . strtolower( substr( $value, $at + 1 ) );

		return self::validate_email( $value ) ? $value : '';
	}
}
