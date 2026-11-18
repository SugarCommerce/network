<?php
/**
 * Base class for header-driven Source implementations.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

use SugarCommerce\Network\ClientIp;
use SugarCommerce\Network\Country\Source;
use SugarCommerce\Network\Server;
use SugarCommerce\Network\TrustedProxies;

/**
 * Abstract HeaderSource
 *
 * Most CDN / server-level country sources are "read this one
 * header, validate, return". This base class handles the common
 * shape (header lookup, signature gating, sentinel filtering,
 * shape validation) so concrete sources just declare their
 * specifics.
 *
 * @since 1.0.0
 */
abstract class HeaderSource implements Source {

	/**
	 * The connections the header is believed from, or null for any.
	 *
	 * @var TrustedProxies|null
	 */
	protected ?TrustedProxies $proxies = null;

	/**
	 * Optionally, believe the header only from known connections.
	 *
	 * A signature header proves little on its own: anyone reaching the
	 * origin directly can send `CF-Ray` as easily as `CF-IPCountry`. Where
	 * the CDN's addresses are known, name them, and the header is read only
	 * when the connection itself came from one:
	 *
	 *     new Cloudflare( TrustedProxies::cloudflare() );
	 *
	 * @since 1.0.0
	 *
	 * @param TrustedProxies|null $proxies The connections believed. Null, the
	 *                                     default, believes any connection
	 *                                     that passes the signature gate.
	 */
	public function __construct( ?TrustedProxies $proxies = null ) {
		$this->proxies = $proxies;
	}

	/**
	 * `$_SERVER` key that carries the country code.
	 *
	 * @return string
	 */
	abstract protected function get_header(): string;

	/**
	 * `$_SERVER` keys that must ALSO be present for the country
	 * header to be trusted. Empty array = trust unconditionally
	 * (the country header's presence is itself the signal).
	 *
	 * @return array<int, string>
	 */
	protected function get_required_signatures(): array {
		return [];
	}

	/**
	 * Header values that mean "unknown" / "anonymous". When seen,
	 * the source returns null so a downstream source can take
	 * over.
	 *
	 * @return array<int, string>
	 */
	protected function get_sentinels(): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function resolve( array $server ): ?string {
		$value = Server::value( $server, $this->get_header() );

		if ( '' === $value ) {
			return null;
		}

		// Connection gate: the header is the client's to write unless one of
		// the named proxies wrote it.
		if ( null !== $this->proxies && ! $this->proxies->any_address ) {
			$remote = ClientIp::direct( $server );

			if ( null === $remote || ! $this->proxies->trusts( $remote ) ) {
				return null;
			}
		}

		// Signature gate.
		$required = $this->get_required_signatures();
		if ( ! empty( $required ) ) {
			$has_signature = false;
			foreach ( $required as $sig_key ) {
				if ( '' !== Server::value( $server, $sig_key ) ) {
					$has_signature = true;
					break;
				}
			}
			if ( ! $has_signature ) {
				return null;
			}
		}

		// Trim + upper-case but DO NOT truncate before validating.
		// An earlier version of this code did `substr(..., 0, 2)`
		// before checking the shape, which let "INVALID123" pass
		// validation as "IN". Reject the whole value instead.
		$value = strtoupper( $value );

		// Sentinel filter — checked against the trimmed value so
		// CDNs that emit unconventional sentinel formats can still
		// be matched. Comparison is case-insensitive against the
		// upper-cased input.
		$sentinels_upper = array_map( 'strtoupper', $this->get_sentinels() );
		if ( in_array( $value, $sentinels_upper, true ) ) {
			return null;
		}

		// Shape validation — ISO-3166 alpha-2 is exactly two
		// letters. Reject anything else (Cloudflare's "T1" Tor
		// sentinel, "XX" unknowns we didn't catch above, ISO-3
		// codes like "GBR" — the standard CDN headers all emit
		// alpha-2 so anything else is upstream weirdness we don't
		// want to silently truncate).
		if ( strlen( $value ) !== 2 || ! ctype_alpha( $value ) ) {
			return null;
		}

		return $value;
	}
}
