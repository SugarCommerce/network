<?php
/**
 * Range Utility Class
 *
 * A range of IP addresses, in whichever way it was written: one spelling
 * to store, whether it holds an address, and how much it covers.
 *
 * @package SugarCommerce\Network
 * @since   1.3.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Range Class
 *
 * A range is written one of three ways, and all three are read here:
 *
 *  - with a prefix, `203.0.113.0/24` or `2001:db8::/32`;
 *  - with stars, `203.0.113.*` or `203.*.113.9`. IPv4 only: an IPv6
 *    address has too many spellings for a star to mean one thing;
 *  - from one address to another, `203.0.113.1-203.0.113.50`, in either
 *    family -- how a hosting company's abuse desk writes one in an email.
 *
 * A single address is the smallest range, and is accepted wherever a
 * range is.
 *
 * Everything is worked out on the packed bytes of an address, so IPv6
 * needs no arbitrary-precision arithmetic, and the two families never
 * match each other: an IPv4 address is not in an IPv6 range, nor the
 * reverse. An IPv4 address carried in an IPv6 one (`::ffff:203.0.113.9`)
 * is the IPv4 address throughout, as in {@see IP::canonical()}.
 *
 * Nothing here has an opinion about how wide a range may be. `0.0.0.0/0`
 * is a range; whether anybody should be allowed to save it is the
 * caller's to say, with {@see self::prefix()} and {@see self::size()}.
 *
 * @since 1.3.0
 */
class Range {

	/**
	 * An address or a range in the one spelling to store, or null.
	 *
	 * So that the same range typed two ways is one rule, and can be
	 * recognised as already there:
	 *
	 *  - a prefixed range is kept as the network it names --
	 *    `203.0.113.77/24` is `203.0.113.0/24`. A prefix as long as the
	 *    address is that one address;
	 *  - stars standing for whole trailing parts are the prefixed range
	 *    they mean: `203.0.113.*` is `203.0.113.0/24`. A star anywhere
	 *    else, `203.*.113.9`, is kept as written, with each number in its
	 *    plain form (`007` is `7`);
	 *  - a from-to range is kept low end first, each end in its standard
	 *    spelling. Two equal ends are one address;
	 *  - an address is {@see IP::canonical()}.
	 *
	 * @param string $range What was typed.
	 *
	 * @return string|null The spelling to store, or null when it is neither
	 *                     an address nor a range -- which includes a
	 *                     pattern of nothing but stars, a prefix longer
	 *                     than the address, and a range from one family
	 *                     to the other.
	 */
	public static function normalize( string $range ): ?string {
		$range = trim( $range );

		if ( str_contains( $range, '*' ) ) {
			return self::normalize_wildcard( $range );
		}

		if ( str_contains( $range, '/' ) ) {
			return self::normalize_cidr( $range );
		}

		if ( str_contains( $range, '-' ) ) {
			return self::normalize_span( $range );
		}

		return IP::canonical( $range );
	}

	/**
	 * Whether a string is an address or a range.
	 *
	 * @param string $range What might be one.
	 *
	 * @return bool
	 */
	public static function is_valid( string $range ): bool {
		return null !== self::normalize( $range );
	}

	/**
	 * Whether a string is a range of addresses and not a single one.
	 *
	 * `203.0.113.9/32` is one address written as a range, and is not one.
	 *
	 * @param string $range An address or a range.
	 *
	 * @return bool False for one address and for anything that is neither.
	 */
	public static function is_range( string $range ): bool {
		$range = self::normalize( $range );

		return null !== $range && strlen( $range ) !== strcspn( $range, '/*-' );
	}

	/**
	 * Whether a range holds an address.
	 *
	 * In any of the three ways a range is written, and for a single
	 * address, which holds itself. A star stands for one whole part:
	 * `203.0.1.*` does not take in 203.0.15.7.
	 *
	 * @param string $range The range.
	 * @param string $ip    The address, in any spelling.
	 *
	 * @return bool False when either is malformed, and always when the two
	 *              are different families.
	 */
	public static function contains( string $range, string $ip ): bool {
		$packed = IP::pack( $ip );
		$range  = trim( $range );

		if ( null === $packed || '' === $range ) {
			return false;
		}

		if ( str_contains( $range, '*' ) ) {
			return self::wildcard_contains( $range, $packed );
		}

		if ( str_contains( $range, '/' ) ) {
			[ $network, $prefix ] = self::split_cidr( $range );

			// A network of the other family is another length, and never equal.
			return null !== $network && self::mask( $packed, $prefix ) === $network;
		}

		if ( str_contains( $range, '-' ) ) {
			[ $low, $high ] = self::split_span( $range );

			return null !== $low
				&& strlen( $low ) === strlen( $packed )
				&& strcmp( $packed, $low ) >= 0
				&& strcmp( $packed, $high ) <= 0;
		}

		return IP::pack( $range ) === $packed;
	}

	/**
	 * Whether any of several ranges holds an address.
	 *
	 * @param array  $ranges Addresses and ranges. Anything that is not a
	 *                       string is passed over.
	 * @param string $ip     The address.
	 *
	 * @return bool
	 */
	public static function contains_any( array $ranges, string $ip ): bool {
		foreach ( $ranges as $range ) {
			if ( is_string( $range ) && self::contains( $range, $ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many addresses a range covers.
	 *
	 * IPv4 only. An IPv6 range is counted in numbers with up to
	 * thirty-nine digits, which do not fit an integer and tell nobody
	 * anything; it has no size here, and that is said with null and not
	 * with a nought or with whatever an overflow leaves. Ask
	 * {@see self::prefix()} how wide an IPv6 range is.
	 *
	 * @param string $range An address or a range.
	 *
	 * @return int|null 1 for a single IPv4 address, up to 4294967296 for
	 *                  `0.0.0.0/0`. Null for IPv6, and for anything that
	 *                  is neither an address nor a range.
	 */
	public static function size( string $range ): ?int {
		$range = self::normalize( $range );

		if ( null === $range || str_contains( $range, ':' ) ) {
			return null;
		}

		if ( str_contains( $range, '/' ) ) {
			return 2 ** ( 32 - (int) substr( $range, (int) strpos( $range, '/' ) + 1 ) );
		}

		if ( str_contains( $range, '*' ) ) {
			return 256 ** substr_count( $range, '*' );
		}

		if ( str_contains( $range, '-' ) ) {
			[ $low, $high ] = explode( '-', $range, 2 );

			return (int) ip2long( $high ) - (int) ip2long( $low ) + 1;
		}

		return 1;
	}

	/**
	 * How many leading bits every address in a range has in common.
	 *
	 * The prefix of the smallest network that holds all of it, which is
	 * how wide it is in either family: 24 for `203.0.113.0/24`, for
	 * `203.0.113.*` and for `203.0.113.1-203.0.113.50`; 8 for
	 * `203.*.113.9`; 32 or 128 for one address; 0 for a range that
	 * reaches across the whole family.
	 *
	 * @param string $range An address or a range.
	 *
	 * @return int|null Null when it is neither.
	 */
	public static function prefix( string $range ): ?int {
		$range = self::normalize( $range );

		if ( null === $range ) {
			return null;
		}

		if ( str_contains( $range, '/' ) ) {
			return (int) substr( $range, (int) strpos( $range, '/' ) + 1 );
		}

		if ( str_contains( $range, '*' ) ) {
			return 8 * (int) array_search( '*', explode( '.', $range ), true );
		}

		if ( ! str_contains( $range, '-' ) ) {
			return 8 * strlen( (string) IP::pack( $range ) );
		}

		[ $low, $high ] = self::split_span( $range );

		// The first bit at which the two ends differ.
		$differs = (string) $low ^ (string) $high;
		$same    = strspn( $differs, "\x00" );
		$bits    = 8 * $same;

		for ( $bit = 0x80; $same < strlen( $differs ) && 0 === ( ord( $differs[ $same ] ) & $bit ); $bit >>= 1 ) {
			++$bits;
		}

		return $bits;
	}

	/**
	 * Which family a range belongs to.
	 *
	 * @param string $range An address or a range.
	 *
	 * @return int 4, 6, or 0 when it is neither.
	 */
	public static function version( string $range ): int {
		$range = self::normalize( $range );

		if ( null === $range ) {
			return 0;
		}

		return str_contains( $range, ':' ) ? 6 : 4;
	}

	/**
	 * A range written with stars.
	 *
	 * Four parts, each a number or a star, and at least one a number: a
	 * pattern of nothing but stars is every address there is, and is not
	 * something anybody means.
	 *
	 * @param string $range What was typed.
	 *
	 * @return string|null
	 */
	private static function normalize_wildcard( string $range ): ?string {
		$parts = self::wildcard_parts( $range );

		if ( null === $parts ) {
			return null;
		}

		$stars = count( array_keys( $parts, '*', true ) );
		$fixed = array_slice( $parts, 0, 4 - $stars );

		if ( 4 === $stars ) {
			return null;
		}

		// Whole trailing parts are the one wildcard that is also a prefix.
		if ( ! in_array( '*', $fixed, true ) ) {
			return implode( '.', array_merge( $fixed, array_fill( 0, $stars, '0' ) ) ) . '/' . ( 8 * count( $fixed ) );
		}

		return implode( '.', $parts );
	}

	/**
	 * The four parts of a pattern with stars in it, each a star or a
	 * number in its plain form.
	 *
	 * @param string $range The pattern.
	 *
	 * @return array|null Null when it is not four parts of numbers up to
	 *                    255 and stars.
	 */
	private static function wildcard_parts( string $range ): ?array {
		if ( 1 !== preg_match( '/^(?:\d{1,3}|\*)(?:\.(?:\d{1,3}|\*)){3}$/D', $range ) ) {
			return null;
		}

		$parts = explode( '.', $range );

		foreach ( $parts as $index => $part ) {
			if ( '*' === $part ) {
				continue;
			}

			if ( (int) $part > 255 ) {
				return null;
			}

			// "007" and "7" are one number, and must be one rule.
			$parts[ $index ] = (string) (int) $part;
		}

		return $parts;
	}

	/**
	 * Whether a pattern with stars takes in a packed address.
	 *
	 * @param string $range  The pattern.
	 * @param string $packed The address, from IP::pack().
	 *
	 * @return bool
	 */
	private static function wildcard_contains( string $range, string $packed ): bool {
		$parts = self::wildcard_parts( $range );

		if ( null === $parts || 4 !== strlen( $packed ) ) {
			return false;
		}

		foreach ( $parts as $index => $part ) {
			if ( '*' !== $part && (int) $part !== ord( $packed[ $index ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A range written with a prefix.
	 *
	 * @param string $range What was typed.
	 *
	 * @return string|null
	 */
	private static function normalize_cidr( string $range ): ?string {
		[ $network, $prefix ] = self::split_cidr( $range );

		if ( null === $network ) {
			return null;
		}

		$address = (string) inet_ntop( $network );

		// One address written as a range is one address.
		return 8 * strlen( $network ) === $prefix ? $address : $address . '/' . $prefix;
	}

	/**
	 * A prefixed range as its packed network address and its prefix.
	 *
	 * @param string $range The range.
	 *
	 * @return array{0: string|null, 1: int} A null network when it is not
	 *                                       an address, a slash, and a
	 *                                       prefix no longer than the
	 *                                       address.
	 */
	private static function split_cidr( string $range ): array {
		[ $address, $prefix ] = explode( '/', $range, 2 );

		$packed = IP::pack( $address );

		if ( null === $packed || 1 !== preg_match( '/^\d{1,3}$/D', $prefix ) || (int) $prefix > 8 * strlen( $packed ) ) {
			return [ null, 0 ];
		}

		return [ self::mask( $packed, (int) $prefix ), (int) $prefix ];
	}

	/**
	 * A range written from one address to another.
	 *
	 * @param string $range What was typed.
	 *
	 * @return string|null
	 */
	private static function normalize_span( string $range ): ?string {
		[ $low, $high ] = self::split_span( $range );

		if ( null === $low ) {
			return null;
		}

		return $low === $high
			? (string) inet_ntop( $low )
			: inet_ntop( $low ) . '-' . inet_ntop( $high );
	}

	/**
	 * A from-to range as its two packed ends, low end first.
	 *
	 * @param string $range The range.
	 *
	 * @return array{0: string|null, 1: string|null} Nulls when it is not two
	 *                                               addresses of one family.
	 */
	private static function split_span( string $range ): array {
		$ends = explode( '-', $range );

		if ( 2 !== count( $ends ) ) {
			return [ null, null ];
		}

		$from = IP::pack( $ends[0] );
		$to   = IP::pack( $ends[1] );

		// From an IPv4 address to an IPv6 one is not a range of anything.
		if ( null === $from || null === $to || strlen( $from ) !== strlen( $to ) ) {
			return [ null, null ];
		}

		// Either way round is the same range. Packed, an address sorts as
		// bytes in the order it counts in.
		return strcmp( $from, $to ) > 0 ? [ $to, $from ] : [ $from, $to ];
	}

	/**
	 * A packed address with everything after the prefix set to nought.
	 *
	 * @param string $packed An address from IP::pack().
	 * @param int    $prefix How many leading bits are the network.
	 *
	 * @return string
	 */
	private static function mask( string $packed, int $prefix ): string {
		$prefix = max( 0, min( strlen( $packed ) * 8, $prefix ) );
		$mask   = str_repeat( "\xff", intdiv( $prefix, 8 ) );

		if ( 0 !== $prefix % 8 ) {
			// Masked to a byte: "0xff << n" is above 255, which chr() has
			// deprecated.
			$mask .= chr( ( 0xff << ( 8 - $prefix % 8 ) ) & 0xff );
		}

		return $packed & str_pad( $mask, strlen( $packed ), "\x00" );
	}
}
