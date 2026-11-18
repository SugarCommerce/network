<?php
/**
 * Server-level GeoIP module country source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * Server-level GeoIP source.
 *
 * Reads `GEOIP_COUNTRY_CODE`. Apache's `mod_geoip` and nginx's
 * `ngx_http_geoip_module` both write this into the request
 * environment when configured. Also picked up from the PHP
 * `geoip` extension when it sets variables via fastcgi.
 *
 * Note this is `GEOIP_COUNTRY_CODE` (not `HTTP_*`) — the
 * server module sets it as a request env var, not as a
 * forwarded header.
 *
 * @since 1.0.0
 */
final class ServerGeoIP extends HeaderSource {

	/** @inheritDoc */
	public function get_name(): string {
		return 'server_geoip';
	}

	/** @inheritDoc */
	public function get_confidence(): string {
		return 'medium';
	}

	/** @inheritDoc */
	protected function get_header(): string {
		return 'GEOIP_COUNTRY_CODE';
	}
}
