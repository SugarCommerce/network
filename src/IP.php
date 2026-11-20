<?php
/**
 * IP Utility Class
 *
 * Provides utility functions for working with IP addresses,
 * including validation, CIDR operations, and user IP detection.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Traits\IP\Patterns;
use SugarCommerce\Network\Traits\IP\Privacy;
use SugarCommerce\Network\Traits\IP\Visitor;

/**
 * IP Class
 *
 * Core operations for working with IP addresses: whether a string is one,
 * its standard spelling and bytes, and what kind of network it is on.
 *
 * One trait per other job, in src/Traits/IP/: the visitor's address
 * behind trusted proxies (Visitor), matching an address against a typed
 * list (Patterns), and anonymising or hashing one (Privacy).
 */
class IP {

	use Patterns;
	use Privacy;
	use Visitor;

	/**
	 * Forwarding headers get() reads from any trusted proxy, in order of
	 * priority.
	 *
	 * These are ONLY consulted when the connection itself arrived from a
	 * trusted proxy — see {@see IP::get()}. They are ordinary request
	 * headers, so anyone can send them. X-Forwarded-For is first because
	 * every proxy appends to it; a proxy that does only that passes the
	 * other two on from the visitor. CF-Connecting-IP is read only from
	 * Cloudflare's own addresses.
	 */
	private const array FORWARDED_HEADERS = [
		'X-Forwarded-For',     // Standard proxy chain
		'X-Real-IP',           // Nginx proxy
		'Client-IP',           // Legacy proxy
	];

	/**
	 * Ranges that are not the public internet, which PHP's private and
	 * reserved flags let through.
	 *
	 * Carrier-grade NAT, the IETF protocol and documentation blocks,
	 * benchmarking, 6to4 relay anycast, multicast and class E for IPv4; for
	 * IPv6 all of the IPv4-compatible block (`::/96`, loopback and
	 * unspecified with it), SIIT (`::ffff:0:0:0/96`, which carries an IPv4
	 * address the way a mapped one does), the discard prefix, the IETF
	 * protocol block (Teredo and ORCHID among it), both documentation
	 * blocks, segment routing, local-use NAT64, unique local, link-local,
	 * the retired site-local block and multicast. See {@see self::is_public()}.
	 */
	private const array NON_PUBLIC = [
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
		'::/96',
		'::ffff:0:0:0/96',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'3fff::/20',
		'5f00::/16',
		'64:ff9b:1::/48',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	];

	/**
	 * Validate an IP address (IPv4 or IPv6).
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if the IP address is valid.
	 */
	public static function is_valid( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	/**
	 * Validate an IPv4 address.
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if valid IPv4.
	 */
	public static function is_valid_ipv4( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false;
	}

	/**
	 * Validate an IPv6 address.
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if valid IPv6.
	 */
	public static function is_valid_ipv6( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
	}

	/**
	 * Check if an IP address is private/reserved.
	 *
	 * @param string $ip The IP address to check.
	 *
	 * @return bool True if the IP is private/reserved.
	 */
	public static function is_private( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	/*
	 * get_country(), is_tor() and get_ray_id() used to live here.
	 *
	 * get_country() took the first two characters of Cloudflare's header and
	 * *then* checked the shape, so "INVALID123" came back as "IN", India.
	 * {@see Country} reads every CDN's header properly, refusing what is not
	 * a country code. is_tor() and get_ray_id() are {@see Cloudflare}'s now,
	 * which reads Cloudflare's headers only from Cloudflare's own addresses
	 * and checks a ray ID is shaped like one:
	 *
	 *     ( new Cloudflare() )->is_tor();
	 *     ( new Cloudflare( IP::trust() ) )->ray_id();   // the wider list get() trusts
	 */

	/**
	 * An IP address, or null.
	 *
	 * Trimmed, and returned only when it is a whole IPv4 or IPv6 address --
	 * for the moment before one is stored, where the remains of something
	 * that was not an address are worse than no address.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $ip The value.
	 *
	 * @return string|null The address, or null when it is not one.
	 */
	public static function sanitize( mixed $ip ): ?string {
		if ( ! is_string( $ip ) ) {
			return null;
		}

		$ip = trim( $ip );

		return self::is_valid( $ip ) ? $ip : null;
	}

	/**
	 * An address in its one standard spelling, or null.
	 *
	 * An IPv6 address can be written many ways -- `2001:DB8:0:0::1` and
	 * `2001:db8::1` are one address -- and anything that stores an address
	 * and has to recognise it again needs one of them. This is the
	 * shortest standard form, in lower case. Brackets round it, as an
	 * IPv6 address is written wherever a port might follow, are dropped.
	 *
	 * An IPv4 address carried inside an IPv6 one (`::ffff:203.0.113.9`,
	 * which a server listening on both families reports for its IPv4
	 * visitors) comes back as the IPv4 address it is, so it matches IPv4
	 * rules and ranges.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip What might be an address.
	 *
	 * @return string|null The address, or null when it is not one.
	 */
	public static function canonical( string $ip ): ?string {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return null;
		}

		$canonical = inet_ntop( $packed );

		return false === $canonical ? null : $canonical;
	}

	/**
	 * An address as the bytes it is: four for IPv4, sixteen for IPv6.
	 *
	 * The form addresses are compared in. Packed, they sort as bytes in
	 * the order they count in, and an IPv4-mapped IPv6 address is already
	 * the four bytes of the IPv4 address it carries.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip What might be an address, in any spelling.
	 *
	 * @return string|null Four or sixteen bytes, or null.
	 */
	public static function pack( string $ip ): ?string {
		$ip = trim( trim( $ip ), '[]' );

		if ( ! self::is_valid( $ip ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return null;
		}

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\x00", 10 ) . "\xff\xff" ) ) {
			$packed = substr( $packed, 12 );
		}

		return $packed;
	}

	/**
	 * Which family an address belongs to.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address. An IPv4-mapped IPv6 address is IPv4.
	 *
	 * @return int 4, 6, or 0 when it is not an address.
	 */
	public static function version( string $ip ): int {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return 0;
		}

		return 4 === strlen( $packed ) ? 4 : 6;
	}

	/**
	 * Whether an address is the machine itself: 127.0.0.0/8 or ::1.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_loopback( string $ip ): bool {
		$packed = self::pack( $ip );

		return null !== $packed && ( 4 === strlen( $packed ) ? "\x7f" === $packed[0] : str_repeat( "\x00", 15 ) . "\x01" === $packed );
	}

	/**
	 * Whether an address is the one that means "no address": 0.0.0.0 or ::.
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_unspecified( string $ip ): bool {
		$packed = self::pack( $ip );

		return null !== $packed && '' === trim( $packed, "\x00" );
	}

	/**
	 * Whether an address is on a private network or is the machine itself.
	 *
	 * RFC 1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), RFC 4193
	 * (fc00::/7 -- all of it, not only the fd00::/8 half in use today) and
	 * loopback: {@see TrustedProxies::LOCAL}. The addresses a proxy of
	 * your own has, and no visitor from the internet does.
	 *
	 * Narrower than {@see self::is_private()}, which is PHP's reading and
	 * also counts every reserved block (link-local, 0.0.0.0/8, 240.0.0.0/4).
	 *
	 * @since 1.3.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool
	 */
	public static function is_local( string $ip ): bool {
		return Range::contains_any( TrustedProxies::LOCAL, $ip );
	}

	/**
	 * Whether an address is out on the public internet.
	 *
	 * Stricter than `! is_private()`, which is PHP's reading and lets
	 * through carrier-grade NAT, the documentation and benchmarking blocks,
	 * multicast and their IPv6 kin. And an IPv6 address that carries an
	 * IPv4 one -- IPv4-mapped (`::ffff:0:0/96`), NAT64 (`64:ff9b::/96`),
	 * 6to4 (`2002::/16`) -- is judged by the IPv4 address it carries.
	 * Without that, `64:ff9b::7f00:1` is a public-looking spelling of
	 * 127.0.0.1 on any network with a NAT64 gateway, and a "public
	 * addresses only" rule -- for a webhook URL, say -- lets it through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip An address.
	 *
	 * @return bool False for anything else, and for what is not an address.
	 */
	public static function is_public( string $ip ): bool {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return false;
		}

		// The IPv4 address a NAT64 or 6to4 address carries. pack() has
		// already reduced an IPv4-mapped one to the four bytes it holds.
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x00\x64\xff\x9b" . str_repeat( "\x00", 8 ) ) ) {
			$packed = substr( $packed, 12, 4 );
		} elseif ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x20\x02" ) ) {
			$packed = substr( $packed, 2, 4 );
		}

		$ip = (string) inet_ntop( $packed );

		return ! self::is_private( $ip ) && ! Range::contains_any( self::NON_PUBLIC, $ip );
	}
}
