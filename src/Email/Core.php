<?php
/**
 * Core Email Trait
 *
 * Properties, constructor, factory methods, and basic getters for the Email class.
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

use InvalidArgumentException;

/**
 * Trait Core
 *
 * Provides core properties, construction, and basic accessors.
 */
trait Core {

	/**
	 * The original email string.
	 *
	 * @var string
	 */
	private string $original;

	/**
	 * The normalized (lowercased, trimmed) email.
	 *
	 * @var string
	 */
	private string $normalized;

	/**
	 * The local part (before @).
	 *
	 * @var string
	 */
	private string $local;

	/**
	 * The domain part (after @).
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * The TLD.
	 *
	 * @var string
	 */
	private string $tld;

	/**
	 * Whether the email is valid.
	 *
	 * @var bool
	 */
	private bool $valid;

	/**
	 * Create a new Email instance.
	 *
	 * @param string $email The email address.
	 *
	 * @throws InvalidArgumentException If email is empty.
	 */
	public function __construct( string $email ) {
		$email = trim( $email );

		if ( $email === '' ) {
			throw new InvalidArgumentException( 'Email address cannot be empty.' );
		}

		$this->original = $email;
		$this->valid    = self::validate_email( $email );

		if ( $this->valid ) {
			$this->normalized = strtolower( $email );
			[ $this->local, $this->domain ] = explode( '@', $this->normalized );
			$this->tld = self::extract_tld( $this->domain );
		} else {
			$this->normalized = $email;
			$this->local      = '';
			$this->domain     = '';
			$this->tld        = '';
		}
	}

	/**
	 * Parse an email and return an Email instance or null if invalid.
	 *
	 * @param string $email The email address.
	 *
	 * @return static|null Email instance or null if invalid.
	 */
	public static function parse( string $email ): ?static {
		try {
			$instance = new self( $email );

			return $instance->valid() ? $instance : null;
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}


	/**
	 * Get the original email string.
	 *
	 * @return string The original email.
	 */
	public function original(): string {
		return $this->original;
	}

	/**
	 * Get the normalized email (lowercase, trimmed).
	 *
	 * @return string The normalized email.
	 */
	public function normalized(): string {
		return $this->normalized;
	}

	/**
	 * Get the local part (before @).
	 *
	 * @return string The local part.
	 */
	public function local(): string {
		return $this->local;
	}

	/**
	 * Get the domain part (after @).
	 *
	 * @return string The domain.
	 */
	public function domain(): string {
		return $this->domain;
	}

	/**
	 * Get the TLD.
	 *
	 * @return string The TLD.
	 */
	public function tld(): string {
		return $this->tld;
	}

	/**
	 * Get the base local part (without subaddress).
	 *
	 * @return string The base local part.
	 */
	public function base_local(): string {
		return explode( '+', $this->local )[0];
	}

	/**
	 * Get the base email address (without subaddress).
	 *
	 * @return string The base email.
	 */
	public function base_address(): string {
		if ( ! $this->valid ) {
			return '';
		}

		return $this->base_local() . '@' . $this->domain;
	}

	/**
	 * Get the subaddress (tag after +).
	 *
	 * @return string|null The subaddress or null if none.
	 */
	public function subaddress(): ?string {
		if ( ! $this->is_subaddressed() ) {
			return null;
		}

		$parts = explode( '+', $this->local, 2 );

		return $parts[1] ?? null;
	}


	/**
	 * Count digits in the local part.
	 *
	 * @return int Number of digits.
	 */
	public function digit_count(): int {
		return (int) preg_match_all( '/\d/', $this->local );
	}

	/**
	 * Check if the email is valid.
	 *
	 * @return bool True if valid.
	 */
	public function valid(): bool {
		return $this->valid;
	}

	/**
	 * Validate an email address.
	 *
	 * @param string $email The email to validate.
	 *
	 * @return bool True if valid.
	 */
	protected static function validate_email( string $email ): bool {
		return false !== is_email( $email );
	}
}
