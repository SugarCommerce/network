<?php
/**
 * Matching an address against a list, and keeping a typed list clean.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\IP;

use SugarCommerce\Network\Range;

/**
 * Matching an address against a list, and keeping a typed list clean.
 *
 * These read ranges with {@see Range}, the one engine in the library; the
 * names are kept for the code that calls them. What changed when they
 * moved over: an address in a list is compared as an address, so
 * `2001:DB8::1` is `2001:db8::1` and `::ffff:203.0.113.9` is
 * `203.0.113.9`; a from-to range (`203.0.113.1-203.0.113.50`) is a
 * pattern too; and an IPv6 wildcard names whole groups (`2001:db8:*`)
 * rather than matching decimal digits in whatever spelling the address
 * arrived in.
 *
 * @since 1.0.0
 */
trait Patterns {

	/**
	 * Check if a range is in valid CIDR format: `203.0.113.0/24`, `2001:db8::/32`.
	 *
	 * @param string $range The IP range to validate.
	 *
	 * @return bool True if valid CIDR format.
	 */
	public static function is_valid_range( string $range ): bool {
		return str_contains( $range, '/' ) && Range::is_valid( $range );
	}

	/**
	 * Check if an IP address is within a specified CIDR range.
	 *
	 * An address and a range of different families is a miss: mixed lists
	 * are perfectly ordinary.
	 *
	 * @param string $ip    The IP address to check.
	 * @param string $range The IP range in CIDR format.
	 *
	 * @return bool True if IP is in range.
	 */
	public static function is_in_range( string $ip, string $range ): bool {
		return self::is_valid_range( $range ) && Range::contains( $range, $ip );
	}

	/**
	 * Check if an IP address matches any in a list of IPs, ranges, or wildcards.
	 *
	 * @param string $ip      The IP address to check.
	 * @param array  $ip_list Addresses and ranges, in any form {@see Range}
	 *                        reads. Anything that is not a string is passed over.
	 *
	 * @return bool Whether the IP address matches any in the list.
	 */
	public static function is_match( string $ip, array $ip_list ): bool {
		return Range::contains_any( $ip_list, $ip );
	}

	/**
	 * Check if an IP matches a wildcard pattern.
	 *
	 * @param string $ip      The IP address to check.
	 * @param string $pattern Wildcard pattern: `192.168.1.*`, `2001:db8:*`.
	 *
	 * @return bool True if IP matches the pattern.
	 */
	public static function matches_wildcard( string $ip, string $pattern ): bool {
		return str_contains( $pattern, '*' ) && Range::contains( $pattern, $ip );
	}

	/**
	 * Check if a string is a valid IP pattern.
	 *
	 * Valid patterns are what {@see Range} reads:
	 * - Single IP: 192.168.1.1
	 * - CIDR range: 192.168.1.0/24
	 * - Wildcard: 192.168.*.*, 192.168.*, 2001:db8:* or 2001:db8::*
	 * - From one address to another: 192.168.1.1-192.168.1.50
	 *
	 * The same reading is_match() uses, so a pattern that matches is never
	 * one sanitize_pattern_list() strips from a saved list.
	 *
	 * @param string $pattern The pattern to validate.
	 *
	 * @return bool True if valid pattern.
	 */
	public static function is_valid_pattern( string $pattern ): bool {
		return Range::is_valid( $pattern );
	}

	/**
	 * Check whether a wildcard pattern is one that could ever be meant.
	 *
	 * A pattern of nothing but wildcards and separators matches every address
	 * in its family, which nobody types into a list on purpose -- and in an
	 * allowlist it is indistinguishable from having no restriction at all.
	 * Accepting it as "valid" would let it through a sanitiser unremarked.
	 *
	 * @param string $pattern The pattern to validate.
	 *
	 * @return bool True if the pattern has stars and at least one literal part.
	 *
	 * @since 1.2.0
	 */
	public static function is_valid_wildcard( string $pattern ): bool {
		return str_contains( $pattern, '*' ) && Range::is_valid( $pattern );
	}

	/**
	 * Filter a list to only valid IP patterns.
	 *
	 * @param array $patterns List of patterns.
	 *
	 * @return array Valid patterns only.
	 */
	public static function filter_valid_patterns( array $patterns ): array {
		return array_values( array_filter( $patterns, [ self::class, 'is_valid_pattern' ] ) );
	}

	/**
	 * Sanitize and filter a list of IP patterns.
	 *
	 * Takes raw input (string or array) and returns a clean array of valid patterns.
	 * A string is split on new lines and on commas, so "203.0.113.9, 198.51.100.7"
	 * pasted on one line is two patterns rather than one invalid one.
	 *
	 * @param string|array $input     Raw input - text, one pattern a line or comma-separated, or an array.
	 * @param bool         $as_string Return as newline-separated string instead of array.
	 *
	 * @return array|string Sanitized valid patterns.
	 */
	public static function sanitize_pattern_list( $input, bool $as_string = false ) {
		// Convert string to array.
		if ( is_string( $input ) ) {
			$patterns = preg_split( '/[\r\n,]+/', $input ) ?: [];
		} else {
			$patterns = (array) $input;
		}

		// Clean up each pattern.
		$patterns = array_map( 'trim', $patterns );
		$patterns = array_filter( $patterns );

		// WordPress sanitization if available.
		if ( function_exists( 'sanitize_text_field' ) ) {
			$patterns = array_map( 'sanitize_text_field', $patterns );
		}

		// Remove duplicates.
		$patterns = array_unique( $patterns );

		// Filter to valid patterns only.
		$patterns = self::filter_valid_patterns( $patterns );

		return $as_string ? implode( "\n", $patterns ) : $patterns;
	}
}
