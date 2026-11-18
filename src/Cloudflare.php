<?php
/**
 * Cloudflare
 *
 * What Cloudflare says about a request, believed only when Cloudflare
 * said it.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Cloudflare Class
 *
 * Cloudflare adds headers describing the visitor -- a country (`T1` for a
 * Tor exit node), a request ID, how they connected. They save an API call.
 *
 * Like every request header they can be forged, so each reader here
 * returns null, or false, unless the connection came from an address in
 * the {@see TrustedProxies} it was given. Reading `CF-IPCountry` without
 * that check lets anyone pick their own country, which matters the moment
 * it drives tax, pricing or a Tor rule.
 *
 *     $cloudflare = new Cloudflare();         // Cloudflare's own ranges.
 *     $cloudflare->is_tor();
 *     $cloudflare->ray_id();                  // "8a1b2c3d4e5f6789-LHR"
 *
 * Give it Cloudflare's ranges and nothing else. A list that also trusts
 * your own load balancer makes every request through that balancer "from
 * Cloudflare", including ones that reached it some other way with their
 * own `CF-IPCountry`. {@see IP::trust()} is such a list -- the one
 * {@see IP::get()} has always believed -- and is there for code that
 * wants exactly that.
 *
 * For the visitor's country, {@see Country} is usually the better call:
 * it reads every CDN's header, and refuses `XX` and `T1`, which are
 * answers here but are not countries.
 *
 * @since 1.0.0
 */
final class Cloudflare {

	/**
	 * Where Cloudflare's headers are believed from.
	 *
	 * @var TrustedProxies|null
	 */
	private ?TrustedProxies $proxies;

	/**
	 * Set where Cloudflare's headers are believed from.
	 *
	 * @param TrustedProxies|null $proxies The connections believed. Null for
	 *                                     Cloudflare's published ranges.
	 */
	public function __construct( ?TrustedProxies $proxies = null ) {
		$this->proxies = $proxies;
	}

	/**
	 * Whether the request came through Cloudflare.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it. Null reads
	 *                           the live request.
	 *
	 * @return bool
	 */
	public function is_active( ?array $server = null ): bool {
		$remote = ClientIp::direct( Server::get( $server ) );

		return null !== $remote && $this->proxies()->trusts( $remote );
	}

	/**
	 * The visitor's country, as Cloudflare names it.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return string|null Two characters, upper case: an ISO 3166-1 alpha-2
	 *                     code, or `XX` (unknown) or `T1` (Tor), which are
	 *                     answers here. Null when absent, malformed, or not
	 *                     from Cloudflare.
	 */
	public function country( ?array $server = null ): ?string {
		$value = $this->header( 'CF-IPCountry', $server );

		if ( null === $value || 1 !== preg_match( '/^[A-Za-z0-9]{2}$/D', $value ) ) {
			return null;
		}

		return strtoupper( $value );
	}

	/**
	 * Whether the visitor arrived over Tor.
	 *
	 * Cloudflare gives a Tor exit node the country `T1`. One fraud signal
	 * among several; on its own a poor reason to turn anybody away.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return bool
	 */
	public function is_tor( ?array $server = null ): bool {
		return 'T1' === $this->country( $server );
	}

	/**
	 * The Cloudflare request ID.
	 *
	 * Worth logging beside an error: it is what Cloudflare's support asks
	 * for, and it ties a log line to their edge log.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return string|null e.g. `8a1b2c3d4e5f6789-LHR`. Null when absent, not
	 *                     shaped like one, or not from Cloudflare.
	 */
	public function ray_id( ?array $server = null ): ?string {
		$value = $this->header( 'CF-Ray', $server );

		return null !== $value && 1 === preg_match( '/^[a-f0-9]{16}-[A-Z]{3}$/iD', $value ) ? $value : null;
	}

	/**
	 * What `CF-Visitor` says the visitor connected with.
	 *
	 * The header is JSON. Cloudflare sends the scheme
	 * (`{"scheme":"https"}`), so that is what usually comes back; a `tls`
	 * member, when present, is preferred. Either way it is checked to be a
	 * short token before it is returned.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return string|null e.g. `https`, or `TLSv1.3` when Cloudflare names
	 *                     the version. Null when absent, malformed, or not
	 *                     from Cloudflare.
	 */
	public function tls_version( ?array $server = null ): ?string {
		$value = $this->header( 'CF-Visitor', $server );

		if ( null === $value || strlen( $value ) > 256 ) {
			return null;
		}

		$decoded = json_decode( $value, true, 2 );

		if ( ! is_array( $decoded ) || ! isset( $decoded['scheme'] ) ) {
			return null;
		}

		$reported = $decoded['tls'] ?? $decoded['scheme'];

		return is_string( $reported ) && 1 === preg_match( '/^[A-Za-z0-9.]{1,16}$/D', $reported ) ? $reported : null;
	}

	/**
	 * A header, but only from a trusted connection.
	 *
	 * @param string     $header The header's name.
	 * @param array|null $server `$_SERVER`, or a stand-in for it.
	 *
	 * @return string|null Null when absent or not believed.
	 */
	private function header( string $header, ?array $server ): ?string {
		$server = Server::get( $server );

		if ( ! $this->is_active( $server ) ) {
			return null;
		}

		$value = Server::header( $server, $header );

		return '' === $value ? null : $value;
	}

	/**
	 * The list in force.
	 *
	 * @return TrustedProxies
	 */
	private function proxies(): TrustedProxies {
		return $this->proxies ?? TrustedProxies::cloudflare();
	}
}
