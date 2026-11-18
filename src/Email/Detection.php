<?php
/**
 * Detection Trait
 *
 * Methods for detecting email characteristics and patterns.
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

use SugarCommerce\Network\Email\Data\Educational;
use SugarCommerce\Network\Email\Data\Government;
use SugarCommerce\Network\Email\Data\Military;
use SugarCommerce\Network\Email\Data\Providers;
use SugarCommerce\Network\Email\Data\Roles;
use SugarCommerce\Network\Email\Data\Typos;

/**
 * Trait Detection
 *
 * Provides is_* and has_* detection methods.
 */
trait Detection {

	/**
	 * Check if the email has valid MX records.
	 *
	 * @return bool True if MX records exist.
	 */
	public function has_mx(): bool {
		if ( ! $this->valid || $this->domain === '' ) {
			return false;
		}

		return checkdnsrr( $this->domain );
	}

	/**
	 * Check if email has subaddressing (plus sign).
	 *
	 * @return bool True if subaddressed.
	 */
	public function is_subaddressed(): bool {
		return $this->valid && str_contains( $this->local, '+' );
	}

	/**
	 * Check if domain is a common free email provider.
	 *
	 * Also returns true if the domain is a typo of a common provider.
	 *
	 * @return bool True if common provider.
	 */
	public function is_common_provider(): bool {
		if ( in_array( $this->domain, Providers::COMMON, true ) ) {
			return true;
		}

		$corrected = $this->suggested_domain();
		if ( $corrected !== null ) {
			return in_array( $corrected, Providers::COMMON, true );
		}

		return false;
	}


	/**
	 * Check if the domain appears to be a typo.
	 *
	 * @return bool True if the domain matches a known typo.
	 */
	public function has_typo(): bool {
		return isset( Typos::CORRECTIONS[ $this->domain ] );
	}

	/**
	 * Get the suggested correction for a typo domain.
	 *
	 * @return string|null The corrected domain or null if no typo detected.
	 */
	public function suggested_domain(): ?string {
		return Typos::CORRECTIONS[ $this->domain ] ?? null;
	}

	/**
	 * Get the full suggested email address with corrected domain.
	 *
	 * @return string|null The corrected email or null if no typo detected.
	 */
	public function suggested_email(): ?string {
		$corrected = $this->suggested_domain();

		if ( $corrected === null ) {
			return null;
		}

		return $this->local . '@' . $corrected;
	}

	/**
	 * Check if provider supports subaddressing (plus addressing).
	 *
	 * Also returns true if the domain is a typo of a supporting provider.
	 *
	 * @return bool True if subaddressing is supported.
	 */
	public function supports_subaddressing(): bool {
		if ( in_array( $this->domain, Providers::SUBADDRESS, true ) ) {
			return true;
		}

		$corrected = $this->suggested_domain();
		if ( $corrected !== null ) {
			return in_array( $corrected, Providers::SUBADDRESS, true );
		}

		return false;
	}




	/**
	 * Check if role-based address.
	 *
	 * @param array $additional_prefixes Optional extra prefixes.
	 *
	 * @return bool True if role-based.
	 */
	public function is_role_based( array $additional_prefixes = [] ): bool {
		$prefixes   = array_merge( Roles::PREFIXES, $additional_prefixes );
		$base_local = $this->base_local();

		return in_array( strtolower( $base_local ), $prefixes, true );
	}

	/**
	 * Check if from a government domain.
	 *
	 * @return bool True if government domain.
	 */
	public function is_government(): bool {
		if ( ! $this->valid ) {
			return false;
		}

		if ( in_array( $this->tld, [ 'gov', 'mil' ], true ) ) {
			return true;
		}

		foreach ( Government::PATTERNS as $pattern ) {
			if ( str_contains( $this->domain, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if from an educational domain.
	 *
	 * @return bool True if educational domain.
	 */
	public function is_educational(): bool {
		if ( ! $this->valid ) {
			return false;
		}

		if ( $this->tld === 'edu' ) {
			return true;
		}

		foreach ( Educational::PATTERNS as $pattern ) {
			if ( str_contains( $this->domain, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if email is from a military domain.
	 *
	 * @return bool True if military domain detected.
	 */
	public function is_military(): bool {
		if ( in_array( $this->tld, Military::TLDS, true ) ) {
			return true;
		}

		foreach ( Military::DOMAINS as $pattern ) {
			if ( $this->domain === $pattern || str_ends_with( $this->domain, '.' . $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
