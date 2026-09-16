<?php
/**
 * Cloudflare request headers.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Cloudflare
 *
 * Cloudflare adds headers describing the visitor — country, a request
 * id, and the TLS version. They save an API call, and the country one in
 * particular is more accurate than most GeoIP databases.
 *
 * Like every request header they can be forged, so each reader here
 * takes a {@see TrustedProxies} and returns null when the request did
 * not actually arrive through Cloudflare. Reading `CF-IPCountry` without
 * that check means anyone can choose their own country, which matters
 * the moment it drives tax, pricing, or an embargo rule.
 *
 * @since 1.0.0
 */
final readonly class Cloudflare {

	/**
	 * @since 1.0.0
	 *
	 * @param TrustedProxies|null $proxies Ranges the headers are believed
	 *                                     from. Null uses Cloudflare's
	 *                                     published list.
	 */
	public function __construct(
		private ?TrustedProxies $proxies = null,
	) {}

	/**
	 * Whether this request came through Cloudflare.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return bool
	 */
	public function is_active( ?array $server = null ): bool {
		$server ??= $_SERVER;
		$remote   = (string) ( $server['REMOTE_ADDR'] ?? '' );

		return $this->proxies()->trusts( $remote );
	}

	/**
	 * The visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null Two-letter code, or null when unavailable or
	 *                     untrusted. `XX` (unknown) and `T1` (Tor) are
	 *                     returned as-is — they are meaningful answers.
	 */
	public function country( ?array $server = null ): ?string {
		$value = $this->header( 'HTTP_CF_IPCOUNTRY', $server );

		if ( null === $value || 1 !== preg_match( '/^[A-Za-z0-9]{2}$/', $value ) ) {
			return null;
		}

		return strtoupper( $value );
	}

	/**
	 * The Cloudflare request id.
	 *
	 * Worth recording alongside errors — it is what Cloudflare support
	 * will ask for, and it ties your log line to their edge log.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null
	 */
	public function ray_id( ?array $server = null ): ?string {
		$value = $this->header( 'HTTP_CF_RAY', $server );

		return ( null !== $value && 1 === preg_match( '/^[a-f0-9]{16}-[A-Z]{3}$/i', $value ) ) ? $value : null;
	}

	/**
	 * Whether the visitor arrived over Tor.
	 *
	 * Cloudflare reports the country as `T1` for Tor exit nodes. Useful
	 * as one fraud signal among several; on its own it is a poor reason
	 * to block anyone.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return bool
	 */
	public function is_tor( ?array $server = null ): bool {
		return 'T1' === $this->country( $server );
	}

	/**
	 * The TLS version the visitor negotiated.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null e.g. `TLSv1.3`.
	 */
	public function tls_version( ?array $server = null ): ?string {
		$value = $this->header( 'HTTP_CF_VISITOR', $server );

		if ( null === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) && isset( $decoded['scheme'] )
			? (string) ( $decoded['tls'] ?? $decoded['scheme'] )
			: null;
	}

	/**
	 * Read a header, but only from a trusted connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $key    `$_SERVER` key.
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null
	 */
	private function header( string $key, ?array $server ): ?string {
		$server ??= $_SERVER;

		if ( ! $this->is_active( $server ) ) {
			return null;
		}

		$value = trim( (string) ( $server[ $key ] ?? '' ) );

		return '' === $value ? null : $value;
	}

	/**
	 * The proxy list in force.
	 *
	 * @since 1.0.0
	 *
	 * @return TrustedProxies
	 */
	private function proxies(): TrustedProxies {
		return $this->proxies ?? TrustedProxies::cloudflare();
	}
}
