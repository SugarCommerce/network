<?php
/**
 * Any named country header.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

use SugarCommerce\Network\Server;
use SugarCommerce\Network\TrustedProxies;

/**
 * Header source.
 *
 * A country in a header the bundled sources do not know, or one of theirs
 * read under your own rules: the header a load balancer of yours writes,
 * believed only from that balancer.
 *
 *     Country::prepend_source( new Header(
 *         'X-Geo-Country',
 *         'balancer',
 *         'high',
 *         TrustedProxies::none()->and_also( [ '10.0.0.0/8' ] )
 *     ) );
 *
 * A header anyone could send is only as good as the list of who it is
 * believed from: without one it is believed from anybody, like
 * {@see Generic}, and should be given a confidence to match.
 *
 * `XX`, which CDNs write for "unknown" and ISO never assigns, is no
 * country; nor is anything that is not two letters.
 *
 * @since 1.0.0
 */
final class Header extends HeaderSource {

	/**
	 * The `$_SERVER` key the header is filed under.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Source identifier.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Confidence tier.
	 *
	 * @var string
	 */
	private string $confidence;

	/**
	 * Read a country from a named header.
	 *
	 * @param string              $header     The header as written in a request: `X-Geo-Country`.
	 * @param string              $name       Source identifier for Result::get_source(). Default 'header'.
	 * @param string              $confidence 'high' / 'medium' / 'low'. Default 'medium'.
	 * @param TrustedProxies|null $proxies    The connections the header is believed from. Null for any.
	 */
	public function __construct( string $header, string $name = 'header', string $confidence = 'medium', ?TrustedProxies $proxies = null ) {
		parent::__construct( $proxies );

		$this->key        = Server::key( TrustedProxies::header_name( $header ) );
		$this->name       = $name;
		$this->confidence = $confidence;
	}

	/** @inheritDoc */
	#[\Override]
	public function get_name(): string {
		return $this->name;
	}

	/** @inheritDoc */
	#[\Override]
	public function get_confidence(): string {
		return $this->confidence;
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_header(): string {
		return $this->key;
	}

	/** @inheritDoc */
	#[\Override]
	protected function get_sentinels(): array {
		return [ 'XX' ];
	}
}
