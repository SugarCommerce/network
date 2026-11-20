<?php
/**
 * Cloudflare CF-IPCountry header source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

/**
 * Cloudflare source.
 *
 * Reads `HTTP_CF_IPCOUNTRY`. Cloudflare auto-populates this on
 * every request to a Cloudflare-fronted domain. Gated on at
 * least one of `CF-Ray`, `CF-Visitor`, `CF-Connecting-IP`
 * being present too — without that, an attacker hitting the
 * origin directly (bypassing Cloudflare) could spoof the
 * country header trivially. Country-spoofing alone is rarely
 * a critical attack surface, but cheap to defend against.
 *
 * @since 1.0.0
 */
final class Cloudflare extends HeaderSource {

	/** @inheritDoc */
	#[\Override]
	public function get_name(): string {
		return 'cloudflare';
	}

	/** @inheritDoc */
	#[\Override]
	public function get_confidence(): string {
		return 'high';
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_header(): string {
		return 'HTTP_CF_IPCOUNTRY';
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_required_signatures(): array {
		return [
			'HTTP_CF_RAY',
			'HTTP_CF_VISITOR',
			'HTTP_CF_CONNECTING_IP',
		];
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_sentinels(): array {
		// CF returns "XX" when geo is unknown and "T1" for Tor
		// exits. The base class's strlen===2 + ctype_alpha
		// gate will catch T1, but XX still looks like a valid
		// country to those checks — list it explicitly.
		return [ 'XX' ];
	}
}
