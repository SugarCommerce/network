<?php
/**
 * An address made anonymous: cut to its network, or put through a keyed hash.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\IP;

use SugarCommerce\Network\Range;

/**
 * An address made anonymous: cut to its network, or put through a keyed hash.
 *
 * @since 1.0.0
 */
trait Privacy {

	/**
	 * Anonymize an IP address the way WordPress's privacy tools do.
	 *
	 * IPv4 loses its last octet (a /24) and IPv6 keeps only its /64 network
	 * prefix, which is what core's wp_privacy_anonymize_ip() does -- and what
	 * this now hands over to when WordPress is loaded. Zeroing only the last
	 * IPv6 group, as this used to, kept a /112: one household, not anonymous.
	 * An IPv4-mapped IPv6 address ("::ffff:203.0.113.42") is treated as the
	 * IPv4 address it carries, as core does.
	 *
	 * @param string $ip The IP address to anonymize.
	 *
	 * @return string|null The anonymized IP address, or null if invalid.
	 */
	public static function anonymize( string $ip ): ?string {
		if ( ! self::is_valid( $ip ) ) {
			return null;
		}

		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return wp_privacy_anonymize_ip( $ip );
		}

		return self::anonymize_without_core( $ip );
	}

	/**
	 * Core's wp_privacy_anonymize_ip() for a valid address, for when
	 * WordPress is not loaded.
	 *
	 * @param string $ip A valid IPv4 or IPv6 address.
	 *
	 * @return string|null The anonymized address.
	 */
	private static function anonymize_without_core( string $ip ): ?string {
		if ( self::is_valid_ipv4( $ip ) ) {
			return substr( $ip, 0, (int) strrpos( $ip, '.' ) ) . '.0';
		}

		// An IPv6 address carrying an IPv4 one: anonymize the IPv4 part.
		if ( str_contains( $ip, '.' ) ) {
			$v4 = substr( $ip, (int) strrpos( $ip, ':' ) + 1 );

			return '::ffff:' . substr( $v4, 0, (int) strrpos( $v4, '.' ) ) . '.0';
		}

		$packed = inet_pton( $ip );
		$mask   = inet_pton( 'ffff:ffff:ffff:ffff::' );
		if ( false === $packed || false === $mask ) {
			return null;
		}

		$network = inet_ntop( $packed & $mask );

		return false === $network ? null : $network;
	}

	/**
	 * The network an address is anonymised to, written as a range.
	 *
	 * What a log can show of where somebody connected from, said so a
	 * reader cannot take it for an address: `203.0.113.0/24` is plainly the
	 * 256 addresses it stands for, where `203.0.113.0` looks like a host.
	 *
	 * IPv4 keeps its first three octets, as {@see self::anonymize()} does.
	 * IPv6 keeps its first 48 bits -- the site prefix, the masking analytics
	 * tools use and European regulators have generally accepted -- which is
	 * coarser than anonymize()'s /64 (core's choice), and says plainly in
	 * its "/48" that it is a network rather than an address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip An address, in any spelling.
	 *
	 * @return string|null e.g. `203.0.113.0/24`, `2001:db8:85a3::/48`, or
	 *                     null when it is not an address.
	 */
	public static function anonymized_cidr( string $ip ): ?string {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return null;
		}

		$bits = 4 === strlen( $packed ) ? 24 : 48;

		return Range::normalize( inet_ntop( $packed ) . '/' . $bits );
	}

	/**
	 * A stable identifier for an address that does not give it away.
	 *
	 * For counting distinct visitors, or spotting one key shared between
	 * many, without keeping the address. Keyed (HMAC-SHA256), because all
	 * four billion IPv4 addresses go through an unkeyed hash in seconds and
	 * make it pointless. One address is one hash however it is spelt: the
	 * address is put in its {@see self::canonical()} form first.
	 *
	 * Keep the secret out of the database. Changing it makes every stored
	 * hash a stranger.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip     An address.
	 * @param string $secret A secret of the site's: wp_salt( 'auth' ), say.
	 *
	 * @return string 64 hex characters.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function hash( string $ip, #[\SensitiveParameter] string $secret ): string {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'Hashing an IP address without a secret is reversible by brute force.' );
		}

		return hash_hmac( 'sha256', self::canonical( $ip ) ?? trim( $ip ), $secret );
	}
}
