<?php
/**
 * Generic X-Country-Code header source (load-balancers / WAFs).
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * Generic header source.
 *
 * Reads `HTTP_X_COUNTRY_CODE`. Various load balancers (HAProxy
 * with the `geoip2` directive, AWS ALB with custom rules) and
 * WAFs (Sucuri, StackPath legacy, etc.) write this header.
 * Lower confidence than vendor-specific sources because the
 * header name is convention rather than vendor-set: an attacker
 * who knows the convention can set it directly. Use last in the
 * priority list.
 *
 * @since 1.0.0
 */
final class Generic extends HeaderSource {

	/** @inheritDoc */
	public function get_name(): string {
		return 'generic';
	}

	/** @inheritDoc */
	public function get_confidence(): string {
		return 'low';
	}

	/** @inheritDoc */
	protected function get_header(): string {
		return 'HTTP_X_COUNTRY_CODE';
	}
}
