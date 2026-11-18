<?php
/**
 * Source contract for visitor-country resolvers.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country;

/**
 * Interface Source
 *
 * Each implementation knows how to extract a country code from a
 * specific provenance — a CDN header, a server-level GeoIP module,
 * a custom database. Returns a Result on hit, null on miss.
 *
 * Sources are tried in registration order. The first non-null
 * Result wins.
 *
 * @since 1.0.0
 */
interface Source {

	/**
	 * Identifier for this source — used in Result::get_source()
	 * and for filtering in resolver options. Stable string,
	 * machine-readable, not user-facing.
	 *
	 * @return string e.g. 'cloudflare', 'cloudfront', 'custom'
	 */
	public function get_name(): string;

	/**
	 * Confidence tier for this source.
	 *
	 * Returned values: 'high' / 'medium' / 'low'. Callers can use
	 * this to decide whether to short-circuit evaluation (a 'high'
	 * source is trustworthy enough to skip downstream paid
	 * lookups) or treat as advisory only.
	 *
	 * Suggested:
	 *   - high   : sig-gated CDN headers (Cloudflare with CF-Ray
	 *              required, CloudFront, Fastly).
	 *   - medium : server-level GeoIP modules.
	 *   - low    : generic / unverified headers.
	 *
	 * @return string
	 */
	public function get_confidence(): string;

	/**
	 * Resolve a country code from the supplied server array.
	 *
	 * @param array $server $_SERVER-shaped array.
	 *
	 * @return string|null Two-letter ISO-3166 alpha-2 code on hit,
	 *                     null when this source can't resolve.
	 */
	public function resolve( array $server ): ?string;
}
