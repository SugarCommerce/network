<?php
/**
 * CIDR range matching for IPv4 and IPv6.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Cidr
 *
 * Matching is done on the packed binary form, so IPv6 works without
 * arbitrary-precision arithmetic and without `ext-gmp` or `ext-bcmath`.
 * The usual `ip2long()` approach silently fails on IPv6 and on 32-bit
 * builds; this does neither.
 *
 * @since 1.0.0
 */
final readonly class Cidr {

	/**
	 * Whether an address falls inside a CIDR range.
	 *
	 * A bare address with no `/prefix` is treated as a single-host range,
	 * so both forms can appear in the same allowlist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip    Address to test.
	 * @param string $range CIDR range, or a bare address.
	 *
	 * @return bool False when either side is malformed, or when the two
	 *              are different address families.
	 */
	public static function contains( string $ip, string $range ): bool {
		$ip = trim( $ip, '[]' );

		if ( ! str_contains( $range, '/' ) ) {
			return self::normalize( $ip ) === self::normalize( trim( $range, '[]' ) )
				&& '' !== self::normalize( $ip );
		}

		[ $subnet, $prefix ] = explode( '/', $range, 2 );

		$ip_packed     = @inet_pton( $ip );
		$subnet_packed = @inet_pton( trim( $subnet, '[]' ) );

		if ( false === $ip_packed || false === $subnet_packed ) {
			return false;
		}

		// Different families never match — comparing a 4-byte and a
		// 16-byte string would otherwise pass on a short prefix.
		if ( strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		if ( 1 !== preg_match( '/^\d{1,3}$/', $prefix ) ) {
			return false;
		}

		$bits     = (int) $prefix;
		$max_bits = strlen( $ip_packed ) * 8;

		if ( $bits > $max_bits ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$spare_bits  = $bits % 8;

		if ( $whole_bytes > 0 && ! hash_equals( substr( $subnet_packed, 0, $whole_bytes ), substr( $ip_packed, 0, $whole_bytes ) ) ) {
			return false;
		}

		if ( 0 === $spare_bits ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $spare_bits ) ) - 1 ) & 0xFF;

		return ( ord( $ip_packed[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_packed[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Whether an address falls inside any of several ranges.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $ip     Address to test.
	 * @param array<string> $ranges CIDR ranges or bare addresses.
	 *
	 * @return bool
	 */
	public static function contains_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::contains( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address matches a pattern.
	 *
	 * Accepts three forms in one list, so an operator-facing allowlist or
	 * blocklist can hold whichever the operator finds natural:
	 *
	 *   192.168.1.10      exact address
	 *   192.168.0.0/16    CIDR range
	 *   192.168.1.*       wildcard
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip      Address to test.
	 * @param string $pattern Address, range, or wildcard.
	 *
	 * @return bool
	 */
	public static function matches( string $ip, string $pattern ): bool {
		$pattern = trim( $pattern );

		if ( '' === $pattern ) {
			return false;
		}

		if ( str_contains( $pattern, '*' ) ) {
			return self::matches_wildcard( $ip, $pattern );
		}

		return self::contains( $ip, $pattern );
	}

	/**
	 * Whether an address matches any pattern in a list.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $ip       Address to test.
	 * @param array<mixed> $patterns Addresses, ranges, or wildcards, as
	 *                               stored — an entry that is not a
	 *                               string is skipped.
	 *
	 * @return bool
	 */
	public static function matches_any( string $ip, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && self::matches( $ip, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address matches a wildcard pattern.
	 *
	 * Matching is per label rather than per character, so `192.168.1.*`
	 * covers `192.168.1.5` but not `192.168.15.5` — a plain string
	 * comparison gets that wrong and quietly widens the range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip      Address to test.
	 * @param string $pattern Wildcard pattern, e.g. `192.168.1.*`.
	 *
	 * @return bool
	 */
	public static function matches_wildcard( string $ip, string $pattern ): bool {
		$ip      = trim( $ip, '[]' );
		$pattern = trim( $pattern );

		if ( '' === self::normalize( $ip ) ) {
			return false;
		}

		// IPv6 has too many spellings for wildcards to be meaningful —
		// "::1" and "0:0:0:0:0:0:0:1" are the same address written
		// differently, and a pattern can only match one of them.
		$separator = str_contains( $pattern, ':' ) ? ':' : '.';

		if ( ':' === $separator ) {
			return false;
		}

		$ip_parts      = explode( $separator, $ip );
		$pattern_parts = explode( $separator, $pattern );

		if ( count( $ip_parts ) !== count( $pattern_parts ) ) {
			return false;
		}

		foreach ( $pattern_parts as $index => $part ) {
			if ( '*' === $part ) {
				continue;
			}

			if ( $part !== $ip_parts[ $index ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Keep only the syntactically valid entries from a pattern list.
	 *
	 * For sanitising an operator-supplied allowlist before storing it —
	 * a typo silently widening a range is worse than the entry being
	 * dropped and noticed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed>|string $patterns List, or newline/comma-separated
	 *                                      text. An entry that is not a
	 *                                      string is dropped.
	 *
	 * @return list<string> Valid entries, de-duplicated and in order.
	 */
	public static function filter_valid( array|string $patterns ): array {
		if ( is_string( $patterns ) ) {
			$patterns = preg_split( '/[\r\n,]+/', $patterns ) ?: array();
		}

		$valid = array();

		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}

			$pattern = trim( $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			$ok = str_contains( $pattern, '*' )
				? 1 === preg_match( '/^(?:\d{1,3}|\*)(?:\.(?:\d{1,3}|\*)){3}$/', $pattern )
				: self::is_valid( $pattern );

			if ( $ok ) {
				$valid[] = $pattern;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Canonical form of an address, for comparison.
	 *
	 * `inet_pton` then `inet_ntop` collapses the many spellings of an
	 * IPv6 address (`::1` and `0:0:0:0:0:0:0:1`) onto one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return string Canonical form, or '' when not an address.
	 */
	public static function normalize( string $ip ): string {
		$packed = @inet_pton( trim( $ip, '[]' ) );

		if ( false === $packed ) {
			return '';
		}

		$text = @inet_ntop( $packed );

		return false === $text ? '' : $text;
	}

	/**
	 * Whether a string is a syntactically valid CIDR range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $range Candidate.
	 *
	 * @return bool
	 */
	public static function is_valid( string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return '' !== self::normalize( $range );
		}

		[ $subnet, $prefix ] = explode( '/', $range, 2 );

		$packed = @inet_pton( trim( $subnet, '[]' ) );

		if ( false === $packed || 1 !== preg_match( '/^\d{1,3}$/', $prefix ) ) {
			return false;
		}

		return (int) $prefix <= strlen( $packed ) * 8;
	}
}
