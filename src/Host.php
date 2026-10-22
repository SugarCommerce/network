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
 * Three small jobs that are each easy to get subtly wrong:
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

		return 1 === preg_match( '/^(?:' . $label . '\.)+[a-z][a-z0-9-]{0,62}$/', $value ) && ! str_ends_with( $value, '-' ) ? $value : '';
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

		if ( 1 !== preg_match( '/^[a-z0-9_.-]+$/', $host ) && ! Ip::is_valid( $host ) ) {
			return false;
		}

		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}
}
