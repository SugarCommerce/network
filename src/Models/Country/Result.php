<?php
/**
 * Resolved-country result envelope.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Models\Country;

/**
 * Result of a country-resolution attempt.
 *
 * Always returned (never null) — `has_country()` reports whether
 * resolution actually succeeded. This shape lets callers branch
 * on confidence / source without first null-checking, and lets
 * the empty case carry the same metadata shape as the populated
 * one for logging.
 *
 * @since 1.0.0
 */
final readonly class Result {

	/** @var string */
	private string $country;

	/** @var string */
	private string $source;

	/** @var string */
	private string $confidence;

	/**
	 * @param string $country    Two-letter ISO-3166 alpha-2, or empty on miss.
	 * @param string $source     Source identifier, or empty on miss.
	 * @param string $confidence 'high' / 'medium' / 'low' / empty on miss.
	 */
	public function __construct( string $country = '', string $source = '', string $confidence = '' ) {
		$this->country    = strtoupper( $country );
		$this->source     = $source;
		$this->confidence = $confidence;
	}

	/**
	 * Empty / not-found result.
	 *
	 * @since 1.0.0
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Two-letter ISO-3166 alpha-2 country code (upper-case), or empty.
	 *
	 * @since 1.0.0
	 */
	public function get_country(): string {
		return $this->country;
	}

	/**
	 * Source identifier, e.g. 'cloudflare' / 'cloudfront' / 'custom'.
	 * Empty when no source resolved.
	 *
	 * @since 1.0.0
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Confidence tier — 'high' / 'medium' / 'low'. Empty on miss.
	 *
	 * @since 1.0.0
	 */
	public function get_confidence(): string {
		return $this->confidence;
	}

	/**
	 * Whether resolution succeeded.
	 *
	 * @since 1.0.0
	 */
	public function has_country(): bool {
		return $this->country !== '';
	}

	/**
	 * Cast to plain array — useful for logging / serialisation.
	 *
	 * @since 1.0.0
	 *
	 * @return array{country:string, source:string, confidence:string}
	 */
	public function to_array(): array {
		return [
			'country'    => $this->country,
			'source'     => $this->source,
			'confidence' => $this->confidence,
		];
	}

	/**
	 * String form — just the country code, lets the result be
	 * used directly in concatenation contexts.
	 *
	 * @since 1.0.0
	 */
	public function __toString(): string {
		return $this->country;
	}
}
