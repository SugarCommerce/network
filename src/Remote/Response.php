<?php
/**
 * Response
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Remote;

/**
 * Class Response
 *
 * A service's answer, decoded, with named methods over it in each
 * subclass. Whatever the methods do not cover is still there through
 * get() and to_array().
 *
 * @since 1.0.0
 */
abstract class Response {

	/**
	 * Build it from the decoded answer.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data The decoded answer.
	 */
	public function __construct( protected readonly array $data ) {}

	/**
	 * Everything the service said.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * One field, by the service's own name for it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      The field.
	 * @param mixed  $fallback What to return when it is missing.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	/**
	 * One field, as a property.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The field.
	 *
	 * @return mixed
	 */
	public function __get( string $name ): mixed {
		return $this->data[ $name ] ?? null;
	}

	/**
	 * Whether a field is there.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The field.
	 *
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		return isset( $this->data[ $name ] );
	}
}
