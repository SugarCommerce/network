<?php
/**
 * Host Names
 *
 * Host names out of a Host header, out of what somebody pasted, and taken
 * apart into the domains and endings a rule could name.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Host Class
 *
 * Small jobs that are each easy to get subtly wrong:
 *
 *  - **Dropping a port.** `[::1]:8080` is IPv6 loopback on port 8080, and
 *    a regex that strips `:\d+$` either leaves the brackets on or, without
 *    brackets, eats the last group of an IPv6 address.
 *    {@see self::without_port()} handles both and hands back the bare
 *    address.
 *  - **Reading a pasted domain.** Asked for a domain, people paste
 *    `https://Shop.Example.com/page`, `@example.com` or an address.
 *    {@see self::from()} reads the host out of any of them, and
 *    {@see self::normalize()} also refuses what DNS would not hold.
 *  - **"Is this one of ours?"** `evil-example.com` ends with `example.com`
 *    but is not inside it. {@see self::is_within()} compares on a label
 *    boundary.
 *  - **"Is this this machine?"** `localhost.evil.example` and
 *    `127.0.0.1.nip.io` start like loopback and are somebody else's
 *    names. {@see self::is_loopback()} reads the whole host.
 *  - **Every domain a rule could name.** `pat@mail.shady.example` is at
 *    `mail.shady.example` and at `shady.example`, and ends in `example`:
 *    {@see self::parents()} and {@see self::suffixes()}.
 *
 * Nothing here knows the public suffix list: `co.uk` is two labels like
 * any other two. Nor is anything converted between an internationalised
 * name and its `xn--` form. Lower-casing is ASCII's; the `xn--` form is a
 * host name here and the Unicode one is not, as with WordPress's
 * `is_email()`.
 *
 * @since 1.0.0
 */
final class Host {

	/**
	 * One label of a host name.
	 */
	private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

	/**
	 * A host, or an address, without its port.
	 *
	 * "203.0.113.9:51234" and "[2001:db8::1]:443", as a Host header or a
	 * proxy's forwarding header writes them. IPv6 literals lose their
	 * brackets: `[::1]:8080` and `[::1]` are both `::1`. A bare IPv6
	 * address has several colons and no port, so only a single colon is
	 * taken for one.
	 *
	 * @param string $host A host or an address, possibly with a port.
	 *
	 * @return string The host, otherwise as given (case is not changed).
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
	 * The host out of an address, a URL, or a host on its own.
	 *
	 * For a box where a domain is wanted and anything might be pasted:
	 * `pat@Example.com`, `@example.com`, `https://example.com/pricing`,
	 * `example.com:8080` and `Example.COM.` are all `example.com`. Lower
	 * case, with a scheme, a path, a query, a port and stray dots at
	 * either end taken off.
	 *
	 * It reads; it does not check. Ask {@see self::is_hostname()} whether
	 * what came back is a name, or use {@see self::normalize()}, which
	 * does both.
	 *
	 * @param string $value An address, a URL or a host.
	 *
	 * @return string The host, or '' when there is nothing of one.
	 */
	public static function from( string $value ): string {
		$host = strtolower( trim( $value ) );

		// Pasted from an address bar as often as typed.
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#', $host ) ) {
			$host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
			$host = (string) preg_replace( '#[/?\#].*$#s', '', $host );
		}

		$at = strrpos( $host, '@' );

		if ( false !== $at ) {
			$host = substr( $host, $at + 1 );
		}

		return trim( (string) preg_replace( '#[/?\#:].*$#s', '', $host ), '.' );
	}

	/**
	 * A domain as typed, cleaned, or '' when it is not one.
	 *
	 * {@see self::from()}, then refused unless it is a host name of at
	 * least two labels whose last starts with a letter: not an IP address,
	 * not `localhost`, nothing DNS would not hold.
	 *
	 * @param string $value What was typed: a name, a URL, or an address.
	 *
	 * @return string e.g. `shop.example.com`, or ''.
	 */
	public static function normalize( string $value ): string {
		$host = self::from( $value );

		if ( ! self::is_hostname( $host ) || ! str_contains( $host, '.' ) ) {
			return '';
		}

		$labels = explode( '.', $host );

		return ctype_alpha( end( $labels )[0] ) ? $host : '';
	}

	/**
	 * Whether a string is shaped like a host name.
	 *
	 * Labels of letters, digits and hyphens, sixty-three characters at
	 * most each and not beginning or ending with a hyphen, joined by
	 * single dots; 253 characters in all. One label is a host name, so
	 * this is true of `localhost` and of `xyz`. In lower case: put what
	 * was typed through {@see self::from()} first.
	 *
	 * @param string $name What might be one.
	 *
	 * @return bool
	 */
	public static function is_hostname( string $name ): bool {
		return '' !== $name
			&& strlen( $name ) <= 253
			&& 1 === preg_match( '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/D', $name );
	}

	/**
	 * Whether a host is a domain or one of its subdomains.
	 *
	 * On a label boundary: `shop.example.com` is within `example.com`;
	 * `evil-example.com` and `example.com.evil.io` are not, and `t.co` is
	 * not inside `microsoft.com`. Either may be in any case, and the host
	 * may carry a port.
	 *
	 * The host must be made only of the characters a host name or an IP
	 * literal can hold. `parse_url()` will hand back
	 * `evil.com\.example.com` as a host, and it ends with `.example.com`,
	 * but a browser reads the backslash as a slash and goes to `evil.com`
	 * -- the classic way past a redirect allowlist. Anything of that kind
	 * is refused rather than compared, so an internationalised name must be
	 * given in its `xn--` form.
	 *
	 * @param string $host   Host, with or without a port.
	 * @param string $domain Domain.
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

		if ( 1 !== preg_match( '/^[a-z0-9_.-]+$/D', $host ) && ! IP::is_valid( $host ) ) {
			return false;
		}

		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	/**
	 * Whether a host names this machine's loopback interface.
	 *
	 * For the conveniences that must stay on the developer's machine -- a
	 * debug screen with no password, a plain-HTTP OAuth redirect for a
	 * native client -- where the question is asked of a Host header, an
	 * Origin's authority or a redirect URI's host.
	 *
	 * Loopback is:
	 *
	 *  - `localhost`, and any name under `.localhost` (`app.localhost`):
	 *    RFC 6761 reserves the whole domain for loopback, and browsers
	 *    resolve it there themselves without asking DNS.
	 *  - 127.0.0.0/8 in dotted-quad form -- all of it, not only 127.0.0.1.
	 *  - `::1` in any spelling, bracketed or bare, and IPv4 loopback mapped
	 *    into IPv6 (`::ffff:127.0.0.1`), which a dual-stack socket delivers
	 *    to the same place. NAT64 and 6to4 spellings of 127.0.0.1 are not:
	 *    they are routed to a gateway, not to this machine.
	 *
	 * The whole value must be the host, with an optional numeric port:
	 * `localhost.evil.example`, `127.0.0.1.nip.io`, `localhost:abc`,
	 * `[::1]x` and a trailing dot are not loopback. Neither are the
	 * shorthand IPv4 spellings (`127.1`, `0x7f.0.0.1`) -- no browser sends
	 * them in a Host header, and refusing is the safe side of a gate.
	 *
	 * This reads a name, not a connection. A Host header is whatever the
	 * client typed; pair it with the connection's address
	 * ({@see IP::is_loopback()} on {@see ClientIp::direct()}) where the
	 * answer is a security decision.
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

		// filter_var() itself: `127.0.0.1]` is not a host.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return str_starts_with( $host, '127.' );
		}

		return strlen( $host ) <= 253 && 1 === preg_match( '/^(?:' . self::LABEL . '\.)*localhost$/D', $host );
	}

	/**
	 * Every domain an address is at: its own, and each one above it.
	 *
	 * `pat@mail.shady.example` is at `mail.shady.example` and at
	 * `shady.example`, so a rule on the second covers the first without
	 * anybody having to know which subdomain was used this time. Down to
	 * two labels: one label is an ending, which is
	 * {@see self::suffixes()}.
	 *
	 * @param string $value An address, or a domain on its own.
	 *
	 * @return array<int, string> Longest first, in lower case. Empty when
	 *                            there are not two labels.
	 */
	public static function parents( string $value ): array {
		$labels  = self::labels( $value );
		$parents = [];

		while ( count( $labels ) >= 2 ) {
			$parents[] = implode( '.', $labels );

			array_shift( $labels );
		}

		return $parents;
	}

	/**
	 * Every ending an address has, shortest first: `uk`, then `co.uk`.
	 *
	 * Each one short of the whole domain, without a leading dot, so a
	 * rule on `co.uk` can be found as well as one on `uk`.
	 *
	 * @param string $value An address, or a domain on its own.
	 *
	 * @return array<int, string> In lower case. Empty for a single label.
	 */
	public static function suffixes( string $value ): array {
		$labels   = self::labels( $value );
		$count    = count( $labels );
		$suffixes = [];

		for ( $take = 1; $take < $count; $take++ ) {
			$suffixes[] = implode( '.', array_slice( $labels, -$take ) );
		}

		return $suffixes;
	}

	/**
	 * The dot-separated parts of what follows an address's last `@`.
	 *
	 * @param string $value An address, or a domain on its own.
	 *
	 * @return array<int, string> In lower case, without the empty ones.
	 */
	private static function labels( string $value ): array {
		$value = strtolower( trim( $value ) );
		$at    = strrpos( $value, '@' );

		if ( false !== $at ) {
			$value = substr( $value, $at + 1 );
		}

		// Longer than DNS allows is no domain, and cut off here because each
		// parent and ending copies the rest of the name: the work grows with
		// the square of the length, and the value is often a visitor's.
		if ( strlen( trim( $value, '.' ) ) > 253 ) {
			return [];
		}

		return array_values( array_filter( explode( '.', $value ), 'strlen' ) );
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
