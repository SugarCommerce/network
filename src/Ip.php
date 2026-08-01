<?php
/**
 * Address classification and anonymisation.
 *
 * @package   SugarMill\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Network;

/**
 * Class Ip
 *
 * @since 1.0.0
 */
final readonly class Ip {

	/**
	 * Whether the string is a valid IPv4 or IPv6 address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Candidate.
	 *
	 * @return bool
	 */
	public static function is_valid( string $ip ): bool {
		return false !== filter_var( trim( $ip, '[]' ), FILTER_VALIDATE_IP );
	}

	/**
	 * Whether the address is IPv4.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return bool
	 */
	public static function is_v4( string $ip ): bool {
		return false !== filter_var( trim( $ip, '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * Whether the address is IPv6.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return bool
	 */
	public static function is_v6( string $ip ): bool {
		return false !== filter_var( trim( $ip, '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	}

	/**
	 * Whether the address is private, reserved, or loopback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return bool True for anything not routable on the public internet,
	 *              including invalid input.
	 */
	public static function is_private( string $ip ): bool {
		$ip = trim( $ip, '[]' );

		if ( ! self::is_valid( $ip ) ) {
			return true;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		// Carrier-grade NAT, absent from PHP's reserved set.
		return Cidr::contains( $ip, '100.64.0.0/10' );
	}

	/**
	 * Anonymise an address for storage or logging.
	 *
	 * Drops the last octet of an IPv4 address and everything after the
	 * first 48 bits of an IPv6 one — the masking Google Analytics uses
	 * and which European regulators have generally accepted as
	 * sufficient to stop an address identifying a person.
	 *
	 * Coarse enough to be useless for tracking, precise enough to remain
	 * useful for aggregate geography and abuse patterns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return string Masked address, or '' when the input is not one.
	 */
	public static function anonymize( string $ip ): string {
		$ip = trim( $ip, '[]' );

		if ( self::is_v4( $ip ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';

			return implode( '.', $parts );
		}

		if ( self::is_v6( $ip ) ) {
			$packed = (string) inet_pton( $ip );
			$masked = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );

			return (string) inet_ntop( $masked );
		}

		return '';
	}

	/**
	 * A stable, non-reversible identifier for an address.
	 *
	 * For counting distinct visitors, or rate limiting, without keeping
	 * the address itself. Keyed, so the output cannot be reversed by
	 * hashing all four billion IPv4 addresses — which takes seconds
	 * against an unkeyed hash and makes one pointless.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip     Address.
	 * @param string $secret Application secret. Rotating it resets every
	 *                       previously stored identifier.
	 *
	 * @return string 64 hex characters.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function hash( string $ip, #[\SensitiveParameter] string $secret ): string {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'Hashing an IP without a secret is reversible by brute force.' );
		}

		return hash_hmac( 'sha256', Cidr::normalize( $ip ) ?: trim( $ip ), $secret );
	}

	/**
	 * The version number of an address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 *
	 * @return int 4, 6, or 0 when not an address.
	 */
	public static function version( string $ip ): int {
		return match ( true ) {
			self::is_v4( $ip ) => 4,
			self::is_v6( $ip ) => 6,
			default            => 0,
		};
	}
}
