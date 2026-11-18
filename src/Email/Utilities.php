<?php
/**
 * Utilities Trait
 *
 * Internal helper methods for email parsing and manipulation.
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

use SugarCommerce\Network\Email\Data\Providers;
use SugarCommerce\Network\Email\Data\Roles;

/**
 * Trait Utilities
 *
 * Internal utility methods used across the Email class.
 */
trait Utilities {

	/**
	 * Extract the TLD from a domain.
	 *
	 * @param string $domain The domain name.
	 *
	 * @return string The TLD in lowercase.
	 */
	protected static function extract_tld( string $domain ): string {
		return strtolower( substr( strrchr( $domain, '.' ), 1 ) );
	}

	/**
	 * Sanitize a subaddress tag.
	 *
	 * Removes plus signs, replaces spaces with hyphens, and trims whitespace.
	 *
	 * @param string $tag The raw tag input.
	 *
	 * @return string The sanitized tag.
	 */
	protected static function sanitize_tag( string $tag ): string {
		return trim( str_replace( [ '+', ' ' ], [ '', '-' ], $tag ) );
	}

	/**
	 * A display form of the address, with the parts separated.
	 *
	 * Useful in admin tables, where an address run together is hard to scan.
	 *
	 * @return string The formatted address, or an empty string if invalid.
	 *
	 * @since 1.1.0
	 */
	public function get_formatted(): string {
		if ( ! $this->valid ) {
			return '';
		}

		return sprintf( '%s @ %s', $this->local, $this->domain );
	}

	/**
	 * The common provider domains, for populating a settings field.
	 *
	 * @param bool $as_options Return as value/label pairs for a select field.
	 *
	 * @return array
	 *
	 * @since 1.1.0
	 */
	public static function get_common_providers( bool $as_options = false ): array {
		$providers = array_combine( Providers::COMMON, Providers::COMMON );

		return $as_options ? self::to_options( $providers ) : $providers;
	}

	/**
	 * The role-based local parts, for populating a settings field.
	 *
	 * @param bool $as_options Return as value/label pairs for a select field.
	 *
	 * @return array
	 *
	 * @since 1.1.0
	 */
	public static function get_role_prefixes( bool $as_options = false ): array {
		$prefixes = array_combine( Roles::PREFIXES, Roles::PREFIXES );

		return $as_options ? self::to_options( $prefixes ) : $prefixes;
	}

	/**
	 * Turn a value => label map into the value/label pairs a select expects.
	 *
	 * @param array $items The map.
	 *
	 * @return array
	 *
	 * @since 1.1.0
	 */
	protected static function to_options( array $items ): array {
		$options = [];

		foreach ( $items as $value => $label ) {
			$options[] = [
				'value' => $value,
				'label' => $label,
			];
		}

		return $options;
	}

	/**
	 * The parsed address as an array.
	 *
	 * @param bool $check_mx Whether to include a live MX lookup. Off by
	 *                       default: it is a DNS round trip, and callers that
	 *                       want it should choose to pay for it.
	 *
	 * @return array
	 */
	public function to_array( bool $check_mx = false ): array {
		return [
			'email'           => $this->normalized,
			'original'        => $this->original,
			'valid'           => $this->valid,
			'local'           => $this->local,
			'domain'          => $this->domain,
			'tld'             => $this->tld,
			'base_address'    => $this->base_address(),
			'subaddress'      => $this->subaddress(),
			'subaddressed'    => $this->is_subaddressed(),
			'common_provider' => $this->is_common_provider(),
			'role_based'      => $this->is_role_based(),
			'government'      => $this->is_government(),
			'educational'     => $this->is_educational(),
			'military'        => $this->is_military(),
			'digit_count'     => $this->digit_count(),
			'has_typo'        => $this->has_typo(),
			'suggestion'      => $this->has_typo() ? $this->suggested_email() : null,
			'mx_valid'        => $check_mx ? $this->has_mx() : null,
		];
	}
}
