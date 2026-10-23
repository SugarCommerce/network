<?php
/**
 * Hostnames: from a Host header, and from what somebody typed.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Host
 *
 * Four small jobs that are each easy to get subtly wrong:
 *
 * - **Dropping a port.** `[::1]:8080` is IPv6 loopback on port 8080, and
 *   a regex that strips `:\d+$` either leaves the brackets on or, without
 *   brackets, eats the last group of an IPv6 address. {@see without_port()}
 *   handles both forms and hands back the bare address.
 * - **Cleaning a typed domain.** People paste `https://Shop.Example.com/page`
 *   when asked for a domain. {@see normalize()} keeps the hostname and
 *   refuses anything DNS would not: IP addresses, single labels, labels
 *   with a leading hyphen, names over 253 characters.
 * - **"Is this one of ours?"** `evil-example.com` ends with `example.com`
 *   but is not inside it. {@see is_within()} compares on a label boundary.
 * - **"Is this this machine?"** `localhost.evil.example` and
 *   `127.0.0.1.nip.io` start like loopback and are somebody else's names.
 *   {@see is_loopback()} reads the whole host, port and brackets included.
 *
 * @since 1.2.0
 */
final readonly class Host {

	/**
	 * A host, or an address, without its port.
	 *
	 * IPv6 literals lose their brackets: `[::1]:8080` and `[::1]` are both
	 * `::1`. A bare IPv6 address has several colons and no port, so only a
	 * single colon is treated as one.
	 *
	 * @since 1.2.0
	 *
	 * @param string $host Host header, or an address with a port.
	 *
	 * @return string The host, as given otherwise (case is not changed).
	 */
	public static function without_port( string $host ): string {
		$host = trim( $host );

		if ( str_starts_with( $host, '[' ) ) {
			$close = strpos( $host, ']' );

			return false === $close ? $host : substr( $host, 1, $close - 1 );
		}

		if ( 1 === substr_count( $host, ':' ) ) {
			return explode( ':', $host, 2 )[0];
		}

		return $host;
	}

	/**
	 * A domain name as typed, cleaned, or '' when it is not one.
	 *
	 * Lower-cased; scheme, path, query, fragment, port and a trailing dot
	 * stripped; then refused unless every label is valid and there are at
	 * least two, the last starting with a letter (so no IP addresses).
	 *
	 * @since 1.2.0
	 *
	 * @param string $value What was typed: a name, or a URL.
	 *
	 * @return string e.g. `shop.example.com`, or ''.
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $value );
		$value = rtrim( self::without_port( (string) strtok( $value, '/?#' ) ), '.' );

		if ( '' === $value || strlen( $value ) > 253 || Ip::is_valid( $value ) ) {
			return '';
		}

		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

		return 1 === preg_match( '/^(?:' . $label . '\.)+[a-z][a-z0-9-]{0,62}$/D', $value ) && ! str_ends_with( $value, '-' ) ? $value : '';
	}

	/**
	 * Whether a host is a domain or one of its subdomains.
	 *
	 * On a label boundary: `shop.example.com` is within `example.com`;
	 * `evil-example.com` is not.
	 *
	 * The host must be made only of the characters a hostname or an IP
	 * literal can hold. `parse_url()` will hand back
	 * `evil.com\.example.com` as a host, and it ends with `.example.com`,
	 * but a browser reads the backslash as a slash and goes to
	 * `evil.com` — the classic way past a redirect allowlist. Anything
	 * of that kind is refused rather than compared, so an
	 * internationalised name must be given in its `xn--` form.
	 *
	 * @since 1.2.0
	 *
	 * @param string $host   Host, any case, with or without a port.
	 * @param string $domain Domain, any case.
	 *
	 * @return bool False when either is empty, or the host is not a
	 *              well-formed name or address.
	 */
	public static function is_within( string $host, string $domain ): bool {
		$host   = rtrim( strtolower( self::without_port( $host ) ), '.' );
		$domain = rtrim( strtolower( trim( $domain ) ), '.' );

		if ( '' === $host || '' === $domain ) {
			return false;
		}

		if ( 1 !== preg_match( '/^[a-z0-9_.-]+$/D', $host ) && ! Ip::is_valid( $host ) ) {
			return false;
		}

		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	/**
	 * Whether a host names this machine's loopback interface.
	 *
	 * For the conveniences that must stay on the developer's machine — an
	 * admin panel with no password under a dev flag, plain-HTTP OAuth
	 * redirects for a native client — where the question is asked of a Host
	 * header, an Origin's authority or a redirect URI's host.
	 *
	 * Loopback is:
	 *
	 * - `localhost`, and any name under `.localhost` (`app.localhost`):
	 *   RFC 6761 reserves the whole domain for loopback, and browsers
	 *   resolve it there themselves without asking DNS.
	 * - 127.0.0.0/8 in dotted-quad form — all of it, not only 127.0.0.1.
	 * - `::1` in any spelling, bracketed or bare, and IPv4 loopback mapped
	 *   into IPv6 (`::ffff:127.0.0.1`), which a dual-stack socket delivers
	 *   to the same place. NAT64 and 6to4 spellings of 127.0.0.1 are not:
	 *   they are routed to a gateway, not to this machine.
	 *
	 * The whole value must be the host, with an optional numeric port:
	 * `localhost.evil.example`, `127.0.0.1.nip.io`, `localhost:abc`,
	 * `[::1]x` and a trailing dot are not loopback. Neither are the
	 * shorthand IPv4 spellings (`127.1`, `0x7f.0.0.1`) — no browser sends
	 * them in a Host header, and refusing is the safe side of a gate.
	 *
	 * This reads a name, not a connection. A Host header is whatever the
	 * client typed; pair it with the connection's address where the
	 * answer is a security decision.
	 *
	 * @since 1.6.0
	 *
	 * @param string $host Host, any case, with or without a port; IPv6 in
	 *                     brackets when a port follows.
	 *
	 * @return bool
	 */
	public static function is_loopback( string $host ): bool {
		$host = strtolower( trim( $host ) );

		if ( str_starts_with( $host, '[' ) ) {
			if ( 1 !== preg_match( '/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/D', $host, $parts ) || ! self::valid_port( $parts[2] ?? null ) ) {
				return false;
			}

			return self::loopback_v6( $parts[1] );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			return self::loopback_v6( $host );
		}

		if ( str_contains( $host, ':' ) ) {
			[ $host, $port ] = explode( ':', $host, 2 );

			if ( ! self::valid_port( $port ) ) {
				return false;
			}
		}

		// filter_var() itself, not Ip::is_v4(): that one forgives brackets,
		// and `127.0.0.1]` is not a host.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return str_starts_with( $host, '127.' );
		}

		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

		return strlen( $host ) <= 253 && 1 === preg_match( '/^(?:' . $label . '\.)*localhost$/D', $host );
	}

	/**
	 * Whether a port, if there is one, is a port number.
	 *
	 * @param string|null $port Digits after the colon, or null for none.
	 *
	 * @return bool
	 */
	private static function valid_port( ?string $port ): bool {
		return null === $port || ( 1 === preg_match( '/^\d{1,5}$/D', $port ) && (int) $port <= 65535 );
	}

	/**
	 * Whether an IPv6 address, without brackets, is loopback: `::1`, or
	 * 127.0.0.0/8 mapped as `::ffff:127.x.x.x`.
	 *
	 * @param string $ip Candidate.
	 *
	 * @return bool
	 */
	private static function loopback_v6( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}

		$packed = (string) inet_pton( $ip );

		return str_repeat( "\0", 15 ) . "\1" === $packed
			|| str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xFF\xFF\x7F" );
	}
}
