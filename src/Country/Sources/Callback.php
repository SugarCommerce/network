<?php
/**
 * Adapter that wraps a closure as a Source.
 *
 * @package   SugarCommerce\Network\Country\Sources
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Country\Sources;

use SugarCommerce\Network\Country\Source;

/**
 * Callback source.
 *
 * Lets callers register a custom resolver as a closure without
 * having to write a full `Source` implementation. Typical use
 * case: a site running its own MaxMind GeoLite2 database does
 * `Country::add_source( new Callback( fn($s) => MyGeoIP::lookup($s['REMOTE_ADDR']), 'maxmind_local', 'high' ) );`
 *
 * @since 1.0.0
 */
final class Callback implements Source {

	/** @var callable */
	private $resolver;

	/** @var string */
	private $name;

	/** @var string */
	private $confidence;

	/**
	 * @param callable $resolver   `function( array $server ): ?string`.
	 * @param string   $name       Source identifier for Result::get_source().
	 * @param string   $confidence 'high' / 'medium' / 'low'. Default 'medium'.
	 */
	public function __construct( callable $resolver, string $name = 'custom', string $confidence = 'medium' ) {
		$this->resolver   = $resolver;
		$this->name       = $name;
		$this->confidence = $confidence;
	}

	/** @inheritDoc */
	public function get_name(): string {
		return $this->name;
	}

	/** @inheritDoc */
	public function get_confidence(): string {
		return $this->confidence;
	}

	/** @inheritDoc */
	public function resolve( array $server ): ?string {
		$result = ( $this->resolver )( $server );

		if ( ! is_string( $result ) || $result === '' ) {
			return null;
		}

		$value = strtoupper( trim( $result ) );

		if ( strlen( $value ) !== 2 || ! ctype_alpha( $value ) ) {
			return null;
		}

		return $value;
	}
}
