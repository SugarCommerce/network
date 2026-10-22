<?php
/**
 * Address classification and anonymisation.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Ip
 *
 * Validation, family, "is this on the public internet?", and the two
 * ways of keeping an address without keeping the person: a masked
 * network, or a keyed hash.
 *
 * @since 1.0.0
 */
final readonly class Ip {

	/**
	 * Ranges that are not public unicast but which PHP's
	 * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` lets through.
	 *
	 * Carrier-grade NAT, the IETF protocol and documentation blocks,
	 * benchmarking, multicast and the class E block for IPv4; for IPv6 the
	 * discard prefix, the IETF protocol block (Teredo and ORCHID among it),
	 * documentation, local-use NAT64 and multicast.
	 *
	 * @since 1.3.0
	 *
	 * @var list<string>
	 */
	private const array NON_PUBLIC_RANGES = array(
		'0.0.0.0/8',
		'100.64.0.0/10',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'64:ff9b:1::/48',
		'fc00::/7',
		'fe80::/10',
		'ff00::/8',
	);

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
	 * IPv6 addresses that carry an IPv4 address inside them — IPv4-mapped
	 * (`::ffff:0:0/96`), NAT64 (`64:ff9b::/96`) and 6to4 (`2002::/16`) —
	 * are judged by the IPv4 address they carry. Without that,
	 * `64:ff9b::7f00:1` is a public-looking spelling of 127.0.0.1 on any
	 * network with a NAT64 gateway, and an allowlist built on this method
	 * would let it through.
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

		$embedded = self::embedded_v4( $ip );

		if ( null !== $embedded ) {
			return self::is_private( $embedded );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return Cidr::contains_any( $ip, self::NON_PUBLIC_RANGES );
	}

	/**
	 * The IPv4 address an IPv6 transition address carries, if it is one.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip A valid address.
	 *
	 * @return string|null The IPv4 address, or null when the input is not
	 *                     IPv4-mapped, NAT64 or 6to4.
	 */
	private static function embedded_v4( string $ip ): ?string {
		$packed = @inet_pton( $ip );

		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return null;
		}

		$v4 = match ( true ) {
			str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xFF\xFF" ) => substr( $packed, 12, 4 ),
			str_starts_with( $packed, "\x00\x64\xFF\x9B" . str_repeat( "\0", 8 ) ) => substr( $packed, 12, 4 ),
			str_starts_with( $packed, "\x20\x02" ) => substr( $packed, 2, 4 ),
			default => null,
		};

		if ( null === $v4 ) {
			return null;
		}

		$text = inet_ntop( $v4 );

		return false === $text ? null : $text;
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
	 * The anonymised network an address belongs to, in CIDR notation.
	 *
	 * What {@see self::anonymize()} keeps, said so a reader cannot take it
	 * for an address: `203.0.113.0` looks like a host, `203.0.113.0/24`
	 * says it is the 256 addresses it stands for. An address that is
	 * already anonymised comes back the same.
	 *
	 * @since 1.2.0
	 *
	 * @param string $ip Address.
	 *
	 * @return string e.g. `203.0.113.0/24` or `2001:db8:85a3::/48`, or '' when
	 *                the input is not an address.
	 */
	public static function anonymized_cidr( string $ip ): string {
		$network = self::anonymize( $ip );

		if ( '' === $network ) {
			return '';
		}

		return $network . ( self::is_v6( $network ) ? '/48' : '/24' );
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
