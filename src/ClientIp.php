<?php
/**
 * Resolve the real client address behind proxies and CDNs.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class ClientIp
 *
 * Answers "who is this request from?" in a way that survives someone
 * trying to lie about it.
 *
 * The common implementation reads `X-Forwarded-For` when a config flag
 * is set. That is not a check — the header is attacker-supplied, and the
 * flag only says you *expect* a proxy, not that this particular request
 * came through one. Anyone who can reach your origin directly then
 * chooses their own address, and every IP-keyed decision you make is
 * theirs to control: rate limits, blocklists, fraud scoring, audit
 * trails, dispute evidence.
 *
 * Here a forwarding header is read only when `REMOTE_ADDR` is itself a
 * proxy you listed. Otherwise it is ignored, whatever it says.
 *
 *   // Direct origin — the default, and the only safe one if unsure.
 *   $ip = ( new ClientIp() )->resolve();
 *
 *   // Behind Cloudflare.
 *   $ip = ( new ClientIp( TrustedProxies::cloudflare() ) )->resolve();
 *
 *   // nginx on the same host.
 *   $ip = ( new ClientIp( TrustedProxies::local() ) )->resolve();
 *
 * @since 1.0.0
 */
final readonly class ClientIp {

	/**
	 * Configure which proxies are believed.
	 *
	 * @since 1.0.0
	 *
	 * @param TrustedProxies|null $proxies Proxies permitted to set
	 *                                     forwarding headers. Null trusts
	 *                                     none, which is correct for an
	 *                                     application reached directly.
	 */
	public function __construct(
		public ?TrustedProxies $proxies = null,
	) {}

	/**
	 * The client address for this request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed>|null $server `$_SERVER`-shaped array. Null
	 *                                  uses the superglobal — the
	 *                                  parameter exists for testability.
	 *
	 * @return string|null Validated address, or null when nothing usable
	 *                     was present.
	 */
	public function resolve( ?array $server = null ): ?string {
		$server ??= $_SERVER;

		$remote = self::valid( self::server_string( $server, 'REMOTE_ADDR' ) );

		if ( null === $remote ) {
			return null;
		}

		// The connection did not come from a proxy we placed, so any
		// forwarding header on it was written by the client.
		if ( null === $this->proxies || ! $this->proxies->trusts( $remote ) ) {
			return $remote;
		}

		foreach ( $this->proxies->headers as $header ) {
			$value = self::server_string( $server, self::server_key( $header ) );

			if ( '' === $value ) {
				continue;
			}

			$found = $this->from_header( $value );

			if ( null !== $found ) {
				return $found;
			}
		}

		return $remote;
	}

	/**
	 * Extract the client address from a forwarding header value.
	 *
	 * `X-Forwarded-For` grows left to right as it passes through proxies:
	 * `client, proxy1, proxy2`. Every entry a trusted proxy appended is
	 * trustworthy; everything to the left of that was supplied by whoever
	 * called first, and a client can pre-seed the header with fictional
	 * hops.
	 *
	 * So the list is walked from the **right**, skipping addresses that
	 * are our own proxies, and the first non-proxy address is the answer.
	 * Taking the leftmost entry — which is what most implementations do —
	 * takes the one value in the header the attacker fully controls.
	 *
	 * An entry that is not an address stops the walk rather than being
	 * stepped over. A trusted proxy only ever appends a real address (or,
	 * for some, `unknown`), so anything else marks where the trustworthy
	 * part of the chain ends; skipping it would carry on into the part
	 * the client wrote.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw header value.
	 *
	 * @return string|null The client address, or null when the chain held
	 *                     nothing usable before an unparseable entry.
	 */
	private function from_header( string $value ): ?string {
		$parts = array_reverse( array_map( 'trim', explode( ',', $value ) ) );

		foreach ( $parts as $part ) {
			// Strip a port from `1.2.3.4:5678` or `[::1]:5678`.
			$candidate = Host::without_port( $part );
			$ip        = self::valid( $candidate );

			if ( null === $ip ) {
				return null;
			}

			if ( null !== $this->proxies && $this->proxies->trusts( $ip ) ) {
				continue;
			}

			return $ip;
		}

		return null;
	}

	/**
	 * Validate an address and put it in canonical form.
	 *
	 * Canonical so that one client is one string: `2001:DB8:0:0::1` and
	 * `2001:db8::1` key the same rate-limit bucket. An IPv4-mapped IPv6
	 * address (`::ffff:203.0.113.5`, which a dual-stack socket reports for
	 * IPv4 clients) comes back as the IPv4 address it carries, so it
	 * matches IPv4 proxy ranges and IPv4 blocklists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Candidate.
	 *
	 * @return string|null Canonical address, or null.
	 */
	private static function valid( string $ip ): ?string {
		$ip = trim( $ip, " \t[]" );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return null;
		}

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xFF\xFF" ) ) {
			$packed = substr( $packed, 12 );
		}

		$canonical = inet_ntop( $packed );

		return false === $canonical ? null : $canonical;
	}

	/**
	 * A `$_SERVER` entry as a string.
	 *
	 * @since 1.3.0
	 *
	 * @param array<mixed> $server `$_SERVER`-shaped array.
	 * @param string       $key    Entry to read.
	 *
	 * @return string The value, or '' when absent or not a string.
	 */
	private static function server_string( array $server, string $key ): string {
		$value = $server[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The `$_SERVER` key for a header name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $header e.g. `X-Forwarded-For`.
	 *
	 * @return string e.g. `HTTP_X_FORWARDED_FOR`.
	 */
	private static function server_key( string $header ): string {
		return 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
	}
}
