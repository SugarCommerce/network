<?php
/**
 * BunnyCDN country header source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * BunnyCDN source.
 *
 * Reads `HTTP_CDN_LOOPCOUNTRY` (BunnyCDN's documented header
 * name for the visitor's country). BunnyCDN populates this
 * automatically when geo headers are enabled on the pull zone.
 *
 * @since 1.0.0
 */
final class BunnyCDN extends HeaderSource {

	/** @inheritDoc */
	public function get_name(): string {
		return 'bunnycdn';
	}

	/** @inheritDoc */
	public function get_confidence(): string {
		return 'high';
	}

	/** @inheritDoc */
	protected function get_header(): string {
		return 'HTTP_CDN_LOOPCOUNTRY';
	}
}
