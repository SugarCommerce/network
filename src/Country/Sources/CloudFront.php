<?php
/**
 * AWS CloudFront viewer-country header source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * AWS CloudFront source.
 *
 * Reads `HTTP_CLOUDFRONT_VIEWER_COUNTRY`. CloudFront populates
 * this when the distribution has the geo-restriction feature
 * turned on, OR explicitly via a CloudFront Function / origin
 * request policy that whitelists the header.
 *
 * No signature gate — CloudFront sits in front of origin and the
 * header isn't auto-included unless explicitly configured. If
 * it's there, it's probably right.
 *
 * @since 1.0.0
 */
final class CloudFront extends HeaderSource {

	/** @inheritDoc */
	public function get_name(): string {
		return 'cloudfront';
	}

	/** @inheritDoc */
	public function get_confidence(): string {
		return 'high';
	}

	/** @inheritDoc */
	protected function get_header(): string {
		return 'HTTP_CLOUDFRONT_VIEWER_COUNTRY';
	}
}
