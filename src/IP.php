<?php
/**
 * IP Utility Class
 *
 * Provides utility functions for working with IP addresses,
 * including validation, CIDR operations, and user IP detection.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * IP Class
 *
 * Core operations for working with IP addresses.
 */
class IP {

	/**
	 * Forwarding headers get() reads, in order of priority.
	 *
	 * These are ONLY consulted when the connection itself arrived from a
	 * trusted proxy — see {@see IP::get()}. They are ordinary request
	 * headers, so anyone can send them.
	 */
	private const FORWARDED_HEADERS = [
		'CF-Connecting-IP',    // Cloudflare
		'X-Real-IP',           // Nginx proxy
		'X-Forwarded-For',     // Standard proxy chain
		'Client-IP',           // Legacy proxy
	];

	/**
	 * Ranges that are not the public internet, which PHP's private and
	 * reserved flags let through.
	 *
	 * Carrier-grade NAT, the IETF protocol and documentation blocks,
	 * benchmarking, 6to4 relay anycast, multicast and class E for IPv4; for
	 * IPv6 all of the IPv4-compatible block (`::/96`, loopback and
	 * unspecified with it), SIIT (`::ffff:0:0:0/96`, which carries an IPv4
	 * address the way a mapped one does), the discard prefix, the IETF
	 * protocol block (Teredo and ORCHID among it), both documentation
	 * blocks, segment routing, local-use NAT64, unique local, link-local,
	 * the retired site-local block and multicast. See {@see self::is_public()}.
	 */
	private const NON_PUBLIC = [
		'0.0.0.0/8',
		'100.64.0.0/10',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/96',
		'::ffff:0:0:0/96',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'3fff::/20',
		'5f00::/16',
		'64:ff9b:1::/48',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	];

	/**
	 * Get the current user's IP address.
	 *
	 * Forwarding headers (X-Forwarded-For, CF-Connecting-IP, and friends)
	 * are ordinary request headers that anyone can send. They are only
	 * meaningful when the request actually arrived through a proxy you
	 * put there, so they are consulted only when REMOTE_ADDR is itself in
	 * {@see IP::trusted_proxies()}. Otherwise REMOTE_ADDR is used and the
	 * headers are ignored, whatever they claim.
	 *
	 * Without that check, anyone able to reach the origin directly — a
	 * leaked origin address, a DNS record that bypasses the CDN, an open
	 * health-check port — can declare any IP they like, and every
	 * IP-keyed decision becomes theirs to control.
	 *
	 * **For new code, prefer {@see ClientIp::resolve()}**, which takes a
	 * {@see TrustedProxies} saying what the site is behind instead of
	 * assuming Cloudflare and a local proxy at once. This method is kept,
	 * with the answers it has always given, for the code that calls it:
	 * it reads the chain with ClientIp's walker and differs from
	 * ClientIp::resolve() only in the policy it has always had --
	 *
	 *  - four headers are tried in turn (CF-Connecting-IP, X-Real-IP,
	 *    X-Forwarded-For, Client-IP), from any trusted proxy;
	 *  - an entry that is not an address is skipped, where resolve()
	 *    stops reading at it;
	 *  - a private, loopback or reserved address is null unless
	 *    `$allow_private`;
	 *  - when every entry is a trusted proxy the answer is null, or the
	 *    client-most entry with `$allow_private`, where resolve() gives
	 *    the entry the nearest proxy wrote.
	 *
	 * @param bool $allow_private Return private, loopback and reserved
	 *                            addresses instead of null. For audit
	 *                            logs and intranet deployments, where
	 *                            "logged in from 192.168.1.50" is useful
	 *                            data. The trusted-proxy rule still
	 *                            applies — this loosens which addresses
	 *                            are acceptable, never which sources are
	 *                            believed.
	 *
	 * @return string|null The user's IP address, or null if not found/invalid.
	 * @since  1.1.0 Forwarding headers now require a trusted proxy.
	 * @since  1.1.1 Added $allow_private.
	 * @since  1.3.0 Reads the chain with {@see ClientIp::walk()}.
	 */
	public static function get( bool $allow_private = false ): ?string {
		$remote = self::server( 'REMOTE_ADDR' );
		$remote = self::is_valid( $remote ) ? $remote : null;
		$trust  = self::trust();

		if ( null !== $remote && $trust->trusts( $remote ) ) {
			foreach ( $trust->headers as $header ) {
				$value = self::server( Server::key( $header ) );

				if ( '' === $value ) {
					continue;
				}

				$chain = ClientIp::walk( $value, $trust, true );

				// Every entry looked like one of our proxies. On an intranet
				// that is expected rather than suspicious: the trusted list
				// contains RFC 1918 by default, and the client is on RFC 1918
				// too, so the real visitor gets skipped as infrastructure. In
				// permissive mode fall back to the client-most entry.
				//
				// This is deliberately NOT done in strict mode. The leftmost
				// entry is the part of the header a client can pre-seed, so it
				// is advisory information for an audit log, never a security
				// boundary. Configure SUGARCOMMERCE_TRUSTED_PROXIES with your
				// actual proxy addresses if you need this to be authoritative.
				$found = $chain['client'] ?? ( $allow_private ? $chain['furthest'] : null );

				if ( null !== $found && ( $allow_private || ! self::is_private( $found ) ) ) {
					return $found;
				}
			}
		}

		if ( null === $remote ) {
			return null;
		}

		return ( $allow_private || ! self::is_private( $remote ) ) ? $remote : null;
	}

	/**
	 * Whether an address is one of our own proxies.
	 *
	 * @param string $ip Address to check.
	 *
	 * @return bool
	 * @since  1.1.0
	 */
	public static function is_trusted_proxy( string $ip ): bool {
		return self::trust()->trusts( $ip );
	}

	/**
	 * What get() believes, as a TrustedProxies.
	 *
	 * The ranges of {@see self::trusted_proxies()}, and the four headers
	 * get() has always read. Hand it to anything else that should trust
	 * what get() trusts: `new Cloudflare( IP::trust() )`.
	 *
	 * @return TrustedProxies
	 * @since  1.3.0
	 */
	public static function trust(): TrustedProxies {
		return TrustedProxies::none()->and_also( self::trusted_proxies() )->using_headers( self::FORWARDED_HEADERS );
	}

	/**
	 * The proxy ranges whose forwarding headers are believed.
	 *
	 * Configure with either:
	 *
	 *   define( 'SUGARCOMMERCE_TRUSTED_PROXIES', '203.0.113.5,198.51.100.0/24' );
	 *
	 *   add_filter( 'sugarcommerce_trusted_proxies', function ( array $ranges ) {
	 *       return [ '203.0.113.5' ];
	 *   } );
	 *
	 * Set it to an empty list when the application is reached directly —
	 * then no forwarding header is ever believed, which is the safest
	 * configuration and the correct one without a proxy.
	 *
	 * The default is {@see TrustedProxies::LOCAL} and
	 * {@see TrustedProxies::CLOUDFLARE}, which covers the usual
	 * deployments: behind Cloudflare, or behind nginx / Caddy / HAProxy on
	 * the same host or private network.
	 *
	 * @return array List of IPs and ranges, in any form {@see Range} reads.
	 * @since  1.1.0
	 * @since  1.3.0 The private IPv6 range is fc00::/7, all of RFC 4193,
	 *               where it was fd00::/8, the half in use.
	 */
	public static function trusted_proxies(): array {
		$proxies = array_merge( TrustedProxies::LOCAL, TrustedProxies::CLOUDFLARE );

		if ( defined( 'SUGARCOMMERCE_TRUSTED_PROXIES' ) ) {
			$configured = constant( 'SUGARCOMMERCE_TRUSTED_PROXIES' );
			$proxies    = is_array( $configured )
				? $configured
				: array_filter( array_map( 'trim', explode( ',', (string) $configured ) ) );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'sugarcommerce_trusted_proxies', $proxies );

			if ( is_array( $filtered ) ) {
				$proxies = $filtered;
			}
		}

		return $proxies;
	}

	/**
	 * Get an anonymized version of the user's IP address.
	 *
	 * @return string|null Anonymized IP address or null if no valid IP found.
	 */
	public static function get_anonymous(): ?string {
		$ip = self::get();

		return $ip ? self::anonymize( $ip ) : null;
	}

	/**
	 * Validate an IP address (IPv4 or IPv6).
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if the IP address is valid.
	 */
	public static function is_valid( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	/**
	 * Validate an IPv4 address.
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if valid IPv4.
	 */
	public static function is_valid_ipv4( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false;
	}

	/**
	 * Validate an IPv6 address.
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if valid IPv6.
	 */
	public static function is_valid_ipv6( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
	}

	/**
	 * Check if an IP address is private/reserved.
	 *
	 * @param string $ip The IP address to check.
	 *
	 * @return bool True if the IP is private/reserved.
	 */
	public static function is_private( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	/**
	 * Check if an IP address or range is in valid CIDR format.
	 *
	 * @param string $range The IP range to validate.
	 *
	 * @return bool True if valid CIDR format.
	 */
	public static function is_valid_range( string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return false;
		}

		[ $ip, $subnet ] = explode( '/', $range, 2 );
		if ( ! is_numeric( $subnet ) ) {
			return false;
		}

		$subnet = (int) $subnet;
		if ( self::is_valid_ipv4( $ip ) ) {
			return $subnet >= 0 && $subnet <= 32;
		}

		if ( self::is_valid_ipv6( $ip ) ) {
			return $subnet >= 0 && $subnet <= 128;
		}

		return false;
	}

	/**
	 * Check if an IP address is within a specified CIDR range.
	 *
	 * @param string $ip    The IP address to check.
	 * @param string $range The IP range in CIDR format.
	 *
	 * @return bool True if IP is in range.
	 */
	public static function is_in_range( string $ip, string $range ): bool {
		if ( ! self::is_valid( $ip ) || ! self::is_valid_range( $range ) ) {
			return false;
		}

		[ $subnet, $bits ] = explode( '/', $range );
		$bits = (int) $bits;

		// Both sides must be the same family. Dispatching on the address
		// alone sends an IPv4 address into the IPv4 comparison with an
		// IPv6 prefix length, and "32 - 128" is a fatal ArithmeticError
		// rather than a false. Mixed lists are perfectly ordinary, so
		// this has to be a miss, not a crash.
		if ( self::is_valid_ipv4( $ip ) && self::is_valid_ipv4( $subnet ) ) {
			return self::is_ipv4_in_range( $ip, $subnet, $bits );
		}

		if ( self::is_valid_ipv6( $ip ) && self::is_valid_ipv6( $subnet ) ) {
			return self::is_ipv6_in_range( $ip, $subnet, $bits );
		}

		return false;
	}

	/**
	 * Check if an IP address matches any in a list of IPs, ranges, or wildcards.
	 *
	 * @param string $ip      The IP address to check.
	 * @param array  $ip_list List of IPs, CIDR ranges, or wildcard patterns.
	 *
	 * @return bool Whether the IP address matches any in the list.
	 */
	public static function is_match( string $ip, array $ip_list ): bool {
		$ip = trim( $ip );
		if ( empty( $ip ) || ! self::is_valid( $ip ) ) {
			return false;
		}

		foreach ( $ip_list as $pattern ) {
			$pattern = trim( $pattern );

			// Exact match.
			if ( $pattern === $ip ) {
				return true;
			}

			// CIDR range match.
			if ( str_contains( $pattern, '/' ) && self::is_valid_range( $pattern ) ) {
				if ( self::is_in_range( $ip, $pattern ) ) {
					return true;
				}
			}

			// Wildcard match.
			if ( str_contains( $pattern, '*' ) && self::matches_wildcard( $ip, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Anonymize an IP address by zeroing out the last octet (IPv4) or the last group (IPv6).
	 *
	 * @param string $ip The IP address to anonymize.
	 *
	 * @return string|null The anonymized IP address, or null if invalid.
	 */
	public static function anonymize( string $ip ): ?string {
		if ( ! self::is_valid( $ip ) ) {
			return null;
		}

		if ( self::is_valid_ipv4( $ip ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}

		if ( self::is_valid_ipv6( $ip ) ) {
			// Handle special case of all-zeros IPv6
			if ( $ip === '::' ) {
				return '::'; // Already all zeros, no need to change
			}

			// For all other IPv6 addresses, replace the last group with 0
			return preg_replace( '/:[^:]*$/', ':0', $ip );
		}

		return null;
	}

	// ========================================
	// Country, Tor and the ray ID
	// ========================================

	/*
	 * get_country(), is_tor() and get_ray_id() used to live here.
	 *
	 * get_country() took the first two characters of Cloudflare's header and
	 * *then* checked the shape, so "INVALID123" came back as "IN", India.
	 * {@see Country} reads every CDN's header properly, refusing what is not
	 * a country code. is_tor() and get_ray_id() are {@see Cloudflare}'s now,
	 * which reads Cloudflare's headers only from Cloudflare's own addresses
	 * and checks a ray ID is shaped like one:
	 *
	 *     ( new Cloudflare() )->is_tor();
	 *     ( new Cloudflare( IP::trust() ) )->ray_id();   // the wider list get() trusts
	 */

	/**
	 * Read a $_SERVER value, unslashed.
	 *
	 * WordPress runs add_magic_quotes() over $_SERVER as well as the request
	 * superglobals, so a header containing a quote arrives with a backslash in
	 * front of it. Reading these raw is why every caller here looked like an
	 * unsanitised input to the coding standards -- and, less cosmetically, why
	 * a quoted user agent or forwarded header would not compare equal to
	 * itself.
	 *
	 * Falls back to the raw value outside WordPress, where nothing added the
	 * slashes in the first place.
	 *
	 * @param string $key The $_SERVER key.
	 *
	 * @return string The unslashed value, or an empty string.
	 *
	 * @since 1.2.0
	 */
	private static function server( string $key ): string {
		$value = Server::value( Server::get(), $key );

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : $value;
	}

	// ========================================
	// Private Helper Methods
	// ========================================

	/**
	 * Checks if an IPv4 address is within a specified range.
	 *
	 * @param string $ip     The IPv4 address to check.
	 * @param string $subnet The subnet of the range.
	 * @param int    $bits   The number of network bits.
	 *
	 * @return bool True if IPv4 is in range.
	 */
	private static function is_ipv4_in_range( string $ip, string $subnet, int $bits ): bool {
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask        = - 1 << ( 32 - $bits );
		$subnet_long &= $mask;

		return ( $ip_long & $mask ) === $subnet_long;
	}

	/**
	 * Checks if an IPv6 address is within a specified range.
	 *
	 * @param string $ip     The IPv6 address to check.
	 * @param string $subnet The subnet of the range.
	 * @param int    $bits   The number of network bits.
	 *
	 * @return bool True if IPv6 is in range.
	 */
	private static function is_ipv6_in_range( string $ip, string $subnet, int $bits ): bool {
		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( $ip_bin === false || $subnet_bin === false ) {
			return false;
		}

		$bits = max( 0, min( 128, $bits ) );

		// Create mask.
		$mask      = str_repeat( "\xFF", $bits >> 3 );
		$remainder = $bits & 7;
		if ( $remainder ) {
			// Mask to a byte: "0xFF << n" produces a value above 255,
			// which chr() has deprecated and which will become an error.
			$mask .= chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );
		}
		$mask = str_pad( $mask, 16, "\x00" );

		return ( ( $ip_bin & $mask ) === ( $subnet_bin & $mask ) );
	}

	/**
	 * Check if an IP matches a wildcard pattern.
	 *
	 * @param string $ip      The IP address to check.
	 * @param string $pattern Wildcard pattern (e.g., 192.168.1.*).
	 *
	 * @return bool True if IP matches the pattern.
	 */
	public static function matches_wildcard( string $ip, string $pattern ): bool {
		if ( ! self::is_valid( $ip ) || ! str_contains( $pattern, '*' ) ) {
			return false;
		}

		$regex = '/^' . str_replace( [ '.', '*' ], [ '\\.', '\\d+' ], $pattern ) . '$/';

		return preg_match( $regex, $ip ) === 1;
	}

	/**
	 * Check if a string is a valid IP pattern.
	 *
	 * Valid patterns:
	 * - Single IP: 192.168.1.1
	 * - CIDR range: 192.168.1.0/24
	 * - Wildcard: 192.168.*.* or 2001:db8:*
	 *
	 * Wildcards count because is_match() matches them. Leaving them out here
	 * meant sanitize_pattern_list() stripped exactly the rules matching
	 * supports, so a saved list quietly lost every wildcard in it.
	 *
	 * @param string $pattern The pattern to validate.
	 *
	 * @return bool True if valid pattern.
	 */
	public static function is_valid_pattern( string $pattern ): bool {
		$pattern = trim( $pattern );

		if ( empty( $pattern ) ) {
			return false;
		}

		// Valid IP.
		if ( self::is_valid( $pattern ) ) {
			return true;
		}

		// Valid CIDR range.
		if ( self::is_valid_range( $pattern ) ) {
			return true;
		}

		// Wildcard pattern.
		if ( str_contains( $pattern, '*' ) ) {
			return self::is_valid_wildcard( $pattern );
		}

		return false;
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
	 * @return bool True if the pattern has at least one literal part.
	 *
	 * @since 1.2.0
	 */
	public static function is_valid_wildcard( string $pattern ): bool {
		$pattern = trim( $pattern );

		if ( ! str_contains( $pattern, '*' ) ) {
			return false;
		}

		// Only the characters an address is written with, plus the wildcard.
		if ( 1 !== preg_match( '/^[0-9a-fA-F.:*]+$/', $pattern ) ) {
			return false;
		}

		// At least one part has to be a literal.
		return '' !== trim( str_replace( array( '*', '.', ':' ), '', $pattern ) );
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

	/**
	 * An IP address, or null.
	 *
	 * Trimmed, and returned only when it is a whole IPv4 or IPv6 address --
	 * for the moment before one is stored, where the remains of something
	 * that was not an address are worse than no address.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $ip The value.
	 *
	 * @return string|null The address, or null when it is not one.
	 */
	public static function sanitize( mixed $ip ): ?string {
		if ( ! is_string( $ip ) ) {
			return null;
		}

		$ip = trim( $ip );

		return self::is_valid( $ip ) ? $ip : null;
	}

	/**
	 * An address in its one standard spelling, or null.
	 *
	 * An IPv6 address can be written many ways -- `2001:DB8:0:0::1` and
	 * `2001:db8::1` are one address -- and anything that stores an address
	 * and has to recognise it again needs one of them. This is the
	 * shortest standard form, in lower case. Brackets round it, as an
	 * IPv6 address is written wherever a port might follow, are dropped.
	 *
	 * An IPv4 address carried inside an IPv6 one (`::ffff:203.0.113.9`,
	 * which a server listening on both families reports for its IPv4
	 * visitors) comes back as the IPv4 address it is, so it matches IPv4
	 * rules and ranges.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip What might be an address.
	 *
	 * @return string|null The address, or null when it is not one.
	 */
	public static function canonical( string $ip ): ?string {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return null;
		}

		$canonical = inet_ntop( $packed );

		return false === $canonical ? null : $canonical;
	}

	/**
	 * An address as the bytes it is: four for IPv4, sixteen for IPv6.
	 *
	 * The form addresses are compared in. Packed, they sort as bytes in
	 * the order they count in, and an IPv4-mapped IPv6 address is already
	 * the four bytes of the IPv4 address it carries.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip What might be an address, in any spelling.
	 *
	 * @return string|null Four or sixteen bytes, or null.
	 */
	public static function pack( string $ip ): ?string {
		$ip = trim( trim( $ip ), '[]' );

		if ( ! self::is_valid( $ip ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return null;
		}

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\x00", 10 ) . "\xff\xff" ) ) {
			$packed = substr( $packed, 12 );
		}

		return $packed;
	}

	/**
	 * Which family an address belongs to.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address. An IPv4-mapped IPv6 address is IPv4.
	 *
	 * @return int 4, 6, or 0 when it is not an address.
	 */
	public static function version( string $ip ): int {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return 0;
		}

		return 4 === strlen( $packed ) ? 4 : 6;
	}

	/**
	 * Whether an address is the machine itself: 127.0.0.0/8 or ::1.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_loopback( string $ip ): bool {
		$packed = self::pack( $ip );

		return null !== $packed && ( 4 === strlen( $packed ) ? "\x7f" === $packed[0] : str_repeat( "\x00", 15 ) . "\x01" === $packed );
	}

	/**
	 * Whether an address is the one that means "no address": 0.0.0.0 or ::.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_unspecified( string $ip ): bool {
		$packed = self::pack( $ip );

		return null !== $packed && '' === trim( $packed, "\x00" );
	}

	/**
	 * Whether an address is on a private network or is the machine itself.
	 *
	 * RFC 1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), RFC 4193
	 * (fc00::/7 -- all of it, not only the fd00::/8 half in use today) and
	 * loopback: {@see TrustedProxies::LOCAL}. The addresses a proxy of
	 * your own has, and no visitor from the internet does.
	 *
	 * Narrower than {@see self::is_private()}, which is PHP's reading and
	 * also counts every reserved block (link-local, 0.0.0.0/8, 240.0.0.0/4).
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_local( string $ip ): bool {
		return Range::contains_any( TrustedProxies::LOCAL, $ip );
	}

	/**
	 * Whether an address is out on the public internet.
	 *
	 * Stricter than `! is_private()`, which is PHP's reading and lets
	 * through carrier-grade NAT, the documentation and benchmarking blocks,
	 * multicast and their IPv6 kin. And an IPv6 address that carries an
	 * IPv4 one -- IPv4-mapped (`::ffff:0:0/96`), NAT64 (`64:ff9b::/96`),
	 * 6to4 (`2002::/16`) -- is judged by the IPv4 address it carries.
	 * Without that, `64:ff9b::7f00:1` is a public-looking spelling of
	 * 127.0.0.1 on any network with a NAT64 gateway, and a "public
	 * addresses only" rule -- for a webhook URL, say -- lets it through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool False for anything else, and for what is not an address.
	 */
	public static function is_public( string $ip ): bool {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return false;
		}

		// The IPv4 address a NAT64 or 6to4 address carries. pack() has
		// already reduced an IPv4-mapped one to the four bytes it holds.
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x00\x64\xff\x9b" . str_repeat( "\x00", 8 ) ) ) {
			$packed = substr( $packed, 12, 4 );
		} elseif ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x20\x02" ) ) {
			$packed = substr( $packed, 2, 4 );
		}

		$ip = (string) inet_ntop( $packed );

		return ! self::is_private( $ip ) && ! Range::contains_any( self::NON_PUBLIC, $ip );
	}

	/**
	 * The network an address is anonymised to, written as a range.
	 *
	 * What a log can show of where somebody connected from, said so a
	 * reader cannot take it for an address: `203.0.113.0/24` is plainly the
	 * 256 addresses it stands for, where `203.0.113.0` looks like a host.
	 *
	 * IPv4 keeps its first three octets, as {@see self::anonymize()} does.
	 * IPv6 keeps its first 48 bits -- the site prefix, the masking analytics
	 * tools use and European regulators have generally accepted -- which is
	 * coarser than anonymize()'s last group, because a /112 of IPv6 is still
	 * one household.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip An address, in any spelling.
	 *
	 * @return string|null e.g. `203.0.113.0/24`, `2001:db8:85a3::/48`, or
	 *                     null when it is not an address.
	 */
	public static function anonymized_cidr( string $ip ): ?string {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return null;
		}

		$bits = 4 === strlen( $packed ) ? 24 : 48;

		return Range::normalize( inet_ntop( $packed ) . '/' . $bits );
	}

	/**
	 * A stable identifier for an address that does not give it away.
	 *
	 * For counting distinct visitors, or spotting one key shared between
	 * many, without keeping the address. Keyed (HMAC-SHA256), because all
	 * four billion IPv4 addresses go through an unkeyed hash in seconds and
	 * make it pointless. One address is one hash however it is spelt: the
	 * address is put in its {@see self::canonical()} form first.
	 *
	 * Keep the secret out of the database. Changing it makes every stored
	 * hash a stranger.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip     An address.
	 * @param string $secret A secret of the site's: wp_salt( 'auth' ), say.
	 *
	 * @return string 64 hex characters.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function hash( string $ip, #[\SensitiveParameter] string $secret ): string {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'Hashing an IP address without a secret is reversible by brute force.' );
		}

		return hash_hmac( 'sha256', self::canonical( $ip ) ?? trim( $ip ), $secret );
	}
}
