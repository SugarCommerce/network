<?php
/**
 * Matching Trait
 *
 * Methods for matching email addresses against patterns and lists.
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
 * Trait Matching
 *
 * Provides methods for matching emails against patterns and lists.
 */
trait Matching {

	/**
	 * Check if this email matches any pattern in a list.
	 *
	 * Supports:
	 * - Full email: user@domain.com
	 * - Domain: @domain.com
	 * - TLD: .edu, .gov
	 * - Partial domain: company.com (matches @company.com and @sub.company.com)
	 *
	 * @param array $patterns List of patterns to match against.
	 *
	 * @return bool True if email matches any pattern.
	 */
	public function matches_any( array $patterns ): bool {
		if ( ! $this->valid ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( $this->matches_pattern( $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if this email matches all patterns in a list.
	 *
	 * @param array $patterns List of patterns to match against.
	 *
	 * @return bool True if email matches all patterns.
	 */
	public function matches_all( array $patterns ): bool {
		if ( ! $this->valid || empty( $patterns ) ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( ! $this->matches_pattern( $pattern ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if this email matches a single pattern.
	 *
	 * @param string $pattern Pattern to match against.
	 *
	 * @return bool True if email matches the pattern.
	 */
	public function matches_pattern( string $pattern ): bool {
		if ( ! $this->valid ) {
			return false;
		}

		$pattern = strtolower( trim( $pattern ) );

		if ( empty( $pattern ) ) {
			return false;
		}

		// Full email match.
		if ( self::validate_email( $pattern ) && $pattern === $this->normalized ) {
			return true;
		}

		// Domain match (@domain.com).
		if ( str_starts_with( $pattern, '@' ) && str_ends_with( $this->normalized, $pattern ) ) {
			return true;
		}

		// TLD match (.edu, .gov).
		if ( str_starts_with( $pattern, '.' ) && str_ends_with( $this->normalized, $pattern ) ) {
			return true;
		}

		// Partial domain match (company.com matches @company.com and @sub.company.com).
		if ( ! str_starts_with( $pattern, '@' ) && ! str_starts_with( $pattern, '.' ) && ! str_contains( $pattern, '@' ) ) {
			if ( $this->domain === $pattern || str_ends_with( $this->domain, '.' . $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a string is a valid email pattern.
	 *
	 * Valid patterns:
	 * - Full email: user@domain.com
	 * - Domain: @domain.com
	 * - TLD: .edu, .gov
	 * - Partial domain: company.com
	 *
	 * @param string $pattern The pattern to validate.
	 *
	 * @return bool True if valid pattern.
	 */
	public static function is_valid_pattern( string $pattern ): bool {
		$pattern = trim( $pattern );

		if ( empty( $pattern ) ) {
			return false;
		}

		// Full email.
		if ( self::validate_email( $pattern ) ) {
			return true;
		}

		// Domain pattern (@domain.com).
		if ( str_starts_with( $pattern, '@' ) && strlen( $pattern ) > 1 ) {
			return true;
		}

		// TLD pattern (.edu, .gov).
		if ( str_starts_with( $pattern, '.' ) && strlen( $pattern ) > 1 ) {
			return true;
		}

		// Partial domain (company.com) - must have at least one dot.
		if ( ! str_contains( $pattern, '@' ) && str_contains( $pattern, '.' ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Filter a list to only valid patterns.
	 *
	 * @param array $patterns List of patterns.
	 *
	 * @return array Valid patterns only.
	 */
	public static function filter_valid_patterns( array $patterns ): array {
		return array_filter( $patterns, [ self::class, 'is_valid_pattern' ] );
	}
}
