<?php
/**
 * Transformation Trait
 *
 * Methods for transforming emails and creating new instances.
 *
 * @package     SugarCommerce\Network
 * @subpackage Email
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Email;

/**
 * Trait Transformation
 *
 * Provides with_*, to_*, and comparison methods.
 */
trait Transformation {

	/**
	 * Create new instance with different local part.
	 *
	 * @param string $local The new local part.
	 *
	 * @return static|null New Email instance or null if invalid.
	 */
	public function with_local( string $local ): ?static {
		return self::parse( $local . '@' . $this->domain );
	}

	/**
	 * Create new instance with different domain.
	 *
	 * @param string $domain The new domain.
	 *
	 * @return static|null New Email instance or null if invalid.
	 */
	public function with_domain( string $domain ): ?static {
		return self::parse( $this->local . '@' . $domain );
	}

	/**
	 * Create new instance with subaddress added/changed.
	 *
	 * @param string $tag The subaddress tag.
	 *
	 * @return static|null New Email instance or null if not supported.
	 */
	public function with_subaddress( string $tag ): ?static {
		if ( ! $this->valid || ! $this->supports_subaddressing() ) {
			return null;
		}

		$tag = self::sanitize_tag( $tag );
		if ( $tag === '' ) {
			return null;
		}

		return self::parse( $this->base_local() . '+' . $tag . '@' . $this->domain );
	}

	/**
	 * Create new instance without subaddress.
	 *
	 * @return static|null New Email instance.
	 */
	public function without_subaddress(): ?static {
		if ( ! $this->valid ) {
			return null;
		}

		return self::parse( $this->base_address() );
	}



	/**
	 * Get masked version of email.
	 *
	 * @param int $show_first Characters to show at start.
	 * @param int $show_last  Characters to show at end.
	 *
	 * @return string Masked email (e.g., 'd***k@gmail.com').
	 */
	public function to_masked( int $show_first = 1, int $show_last = 1 ): string {
		if ( ! $this->valid ) {
			return '';
		}

		$length = strlen( $this->local );

		if ( $show_first + $show_last >= $length ) {
			return $this->normalized;
		}

		$masked_length = $length - $show_first - $show_last;
		$masked_local  = substr( $this->local, 0, $show_first )
						. str_repeat( '*', $masked_length )
						. substr( $this->local, - $show_last );

		return $masked_local . '@' . $this->domain;
	}
}
