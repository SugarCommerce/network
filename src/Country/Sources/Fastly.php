<?php
/**
 * Fastly Geo-IP country header source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * Fastly source.
 *
 * Reads `HTTP_FASTLY_CLIENT_COUNTRY` or `HTTP_X_COUNTRY_CODE`
 * depending on the VCL configuration — Fastly doesn't set a
 * country header by default, but the documented pattern uses
 * one of these. Sites configure the header in their VCL via
 * `set req.http.X-Country-Code = client.geo.country_code;`
 *
 * Gated on `Fastly-FF` (the Fastly forwarding header) so a
 * direct-to-origin spoof can't fake it.
 *
 * @since 1.0.0
 */
final class Fastly extends HeaderSource {

	/** @inheritDoc */
	#[\Override]
	public function get_name(): string {
		return 'fastly';
	}

	/** @inheritDoc */
	#[\Override]
	public function get_confidence(): string {
		return 'high';
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_header(): string {
		return 'HTTP_FASTLY_CLIENT_COUNTRY';
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_required_signatures(): array {
		return [
			'HTTP_FASTLY_FF',
			'HTTP_FASTLY_CLIENT_IP',
		];
	}
}
