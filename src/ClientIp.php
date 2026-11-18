<?php
/**
 * Client IP
 *
 * The address a request really came from, behind proxies and CDNs.
 *
 * @package SugarCommerce\Network
 * @since   1.3.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * ClientIp Class
 *
 * Answers "who is this request from?" in a way that survives somebody
 * lying about it.
 *
 * A site with nothing in front of it is told by the connection itself
 * (`REMOTE_ADDR`), and that cannot be faked. A site behind Cloudflare or
 * a load balancer is told the address of *that*, for every visitor
 * alike, and the visitor's own arrives in a header the proxy adds. The
 * header cannot simply be believed, because a header is the visitor's to
 * write. So it is read only when the connection came from a proxy listed
 * in a {@see TrustedProxies}; from anywhere else it is ignored, whatever
 * it says.
 *
 *     ClientIp::resolve();                                    // Direct.
 *     ClientIp::resolve( null, TrustedProxies::cloudflare() );
 *     ClientIp::resolve( null, TrustedProxies::local() );
 *
 * The address comes back as it was written, checked to be an address
 * and nothing more: put it through {@see IP::canonical()} for a key that
 * is the same however the address was spelt.
 *
 * @since 1.3.0
 */
class ClientIp {

	/**
	 * The visitor's address for a request.
	 *
	 * The connection's address, unless that is a trusted proxy. Then each
	 * of the trusted headers is tried in turn, and the first with an
	 * address in it answers -- see {@see self::from_header()} for which
	 * address. A trusted connection with no usable header is answered
	 * with the connection's address.
	 *
	 * @param array|null          $server `$_SERVER`, or a stand-in for it.
	 *                                    Null reads the superglobal.
	 * @param TrustedProxies|null $trust  Which connections are believed.
	 *                                    Null trusts none, which is right
	 *                                    for a site reached directly.
	 *
	 * @return string|null An IP address, or null when the request has none.
	 */
	public static function resolve( ?array $server = null, ?TrustedProxies $trust = null ): ?string {
		$server   = Server::get( $server );
		$trust  ??= TrustedProxies::none();
		$remote   = self::direct( $server );

		// The connection did not come from a proxy of ours, so any
		// forwarding header on it was written by whoever connected.
		if ( ! $trust->any_address && ( null === $remote || ! $trust->trusts( $remote ) ) ) {
			return $remote;
		}

		foreach ( $trust->headers as $header ) {
			$found = self::from_header( Server::header( $server, $header ), $trust );

			if ( null !== $found ) {
				return $found;
			}
		}

		return $remote;
	}

	/**
	 * The address of the connection itself, whatever is in front of it.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return string|null `REMOTE_ADDR` when it is an address.
	 */
	public static function direct( ?array $server = null ): ?string {
		return IP::sanitize( Server::value( Server::get( $server ), 'REMOTE_ADDR' ) );
	}

	/**
	 * The visitor's address out of a forwarding header's value.
	 *
	 * `X-Forwarded-For` is a list that grows as a request passes through
	 * proxies: each one adds, on the right, the address it was connected
	 * to from. So in "203.0.113.9, 10.0.0.5" the visitor is on the left --
	 * but so is anything the visitor sent to begin with. A browser that
	 * sends `X-Forwarded-For: 8.8.8.8` through one proxy arrives as
	 * "8.8.8.8, 203.0.113.9", and the left-most address, the one most
	 * code takes, is the one piece of the header the visitor wrote.
	 *
	 * The right-most is the one your own proxy wrote, and it is true. So
	 * the list is read from the right, stepping over addresses that are
	 * trusted proxies, and the first that is not is the visitor.
	 *
	 * An entry that is not an address stops the reading. A proxy only
	 * ever appends an address, so anything else marks where the part of
	 * the list a proxy wrote ends; stepping over it would carry on into
	 * the part the visitor wrote.
	 *
	 * **When every address read is a trusted proxy**, the right-most is
	 * the answer: a site on a private network, a development machine, a
	 * request Cloudflare made on a visitor's behalf from one of its own
	 * addresses. That entry was still written by your nearest proxy
	 * about who connected to it, so it is true, and on an intranet it is
	 * the visitor. The connection's address, the other candidate, is by
	 * definition the proxy and never the visitor.
	 *
	 * A header with one address in it (`CF-Connecting-IP`, `X-Real-IP`)
	 * is the same rule with a list of one. A port after an address
	 * ("203.0.113.9:51234", "[2001:db8::1]:443") is dropped.
	 *
	 * @param string              $value The header's value.
	 * @param TrustedProxies|null $trust Whose addresses are stepped over.
	 *                                   Null steps over none, so the
	 *                                   right-most address answers.
	 *
	 * @return string|null An address, or null when there is none to the
	 *                     right of the first thing that is not one.
	 */
	public static function from_header( string $value, ?TrustedProxies $trust = null ): ?string {
		$chain = self::walk( $value, $trust ?? TrustedProxies::none() );

		return $chain['client'] ?? $chain['nearest'];
	}

	/**
	 * Read a forwarding header from the right.
	 *
	 * The reading from_header() and {@see IP::get()} both answer from, so
	 * the rule about which end is read, and what is stepped over, is
	 * written once.
	 *
	 * @internal Use from_header().
	 *
	 * @param string         $value          The header's value.
	 * @param TrustedProxies $trust          Whose addresses are stepped over.
	 * @param bool           $skip_malformed Step over an entry that is not
	 *                                       an address, where the default
	 *                                       is to stop at it. IP::get()'s
	 *                                       older, looser reading.
	 *
	 * @return array{client: string|null, nearest: string|null, furthest: string|null}
	 *               `client` is the first address from the right that is
	 *               not a trusted proxy; `nearest` and `furthest` are the
	 *               right-most and left-most addresses read on the way.
	 */
	public static function walk( string $value, TrustedProxies $trust, bool $skip_malformed = false ): array {
		$chain = [
			'client'   => null,
			'nearest'  => null,
			'furthest' => null,
		];

		foreach ( array_reverse( explode( ',', $value ) ) as $entry ) {
			$candidate = IP::sanitize( Host::without_port( $entry ) );

			if ( null === $candidate ) {
				if ( $skip_malformed ) {
					continue;
				}

				break;
			}

			$chain['nearest'] ??= $candidate;
			$chain['furthest']  = $candidate;

			if ( ! $trust->trusts( $candidate ) ) {
				$chain['client'] = $candidate;

				break;
			}
		}

		return $chain;
	}
}
