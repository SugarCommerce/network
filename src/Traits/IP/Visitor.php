<?php
/**
 * The visitor's address, and the proxies whose word for it is taken.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\IP;

use SugarCommerce\Network\ClientIp;
use SugarCommerce\Network\Server;
use SugarCommerce\Network\TrustedProxies;

/**
 * The visitor's address, and the proxies whose word for it is taken.
 *
 * @since 1.0.0
 */
trait Visitor {

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
	 * The older names, `ARRAYPRESS_TRUSTED_PROXIES` and the
	 * `arraypress_trusted_proxies` filter, are still read for sites that
	 * set them: both constants' lists are joined, and the old filter runs
	 * before the new one, so each sees the other's work. The new names are
	 * the ones to use.
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
	 * @since  1.4.0 Also reads ARRAYPRESS_TRUSTED_PROXIES and the
	 *               arraypress_trusted_proxies filter.
	 */
	public static function trusted_proxies(): array {
		$proxies = array_merge( TrustedProxies::LOCAL, TrustedProxies::CLOUDFLARE );

		$constants = [ 'SUGARCOMMERCE_TRUSTED_PROXIES', 'ARRAYPRESS_TRUSTED_PROXIES' ];
		$defined   = array_filter( $constants, 'defined' );

		if ( $defined ) {
			$proxies = [];

			foreach ( $defined as $name ) {
				$proxies = array_merge( $proxies, self::configured_proxies( constant( $name ) ) );
			}

			$proxies = array_values( array_unique( $proxies ) );
		}

		if ( function_exists( 'apply_filters' ) ) {
			foreach ( [ 'arraypress_trusted_proxies', 'sugarcommerce_trusted_proxies' ] as $hook ) {
				$filtered = apply_filters( $hook, $proxies );

				if ( is_array( $filtered ) ) {
					$proxies = $filtered;
				}
			}
		}

		return $proxies;
	}

	/**
	 * Read a trusted-proxies constant: a list, or a comma-separated string.
	 *
	 * @param mixed $configured The constant's value.
	 *
	 * @return array List of IPs and ranges.
	 */
	private static function configured_proxies( $configured ): array {
		return is_array( $configured )
			? $configured
			: array_filter( array_map( 'trim', explode( ',', (string) $configured ) ) );
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
}
