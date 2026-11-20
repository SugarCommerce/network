<?php
/**
 * Trusted Proxies
 *
 * Which connections may say who a visitor is, and in which header.
 *
 * @package SugarCommerce\Network
 * @since   1.3.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * TrustedProxies Class
 *
 * The list that makes {@see ClientIp} safe.
 *
 * `X-Forwarded-For` and `CF-Connecting-IP` are request headers, and
 * anyone can send them. A site that reads one because it expects to be
 * behind a proxy has let whoever reaches it some other way -- a leaked
 * origin address, a DNS record that goes round the CDN -- choose their
 * own address, and with it every decision keyed on an address: rate
 * limits, blocklists, fraud scores, the address on an order.
 *
 * A header means something only when the connection carrying it came
 * from a proxy you put there. This is where that is written down:
 *
 *     TrustedProxies::none();        // Reached directly. The default.
 *     TrustedProxies::cloudflare();  // Behind Cloudflare.
 *     TrustedProxies::local();       // nginx, Caddy or HAProxy on this
 *                                    // host or private network.
 *     TrustedProxies::none()->and_also( [ '198.51.100.7' ] );
 *
 * Trusting the connection is half of it; the other half is which
 * headers. `X-Forwarded-For` is the one every proxy appends to, so it is
 * read from the right and is right for a chain of any length; it is the
 * only header believed unless you say otherwise. `CF-Connecting-IP` is
 * a single value Cloudflare overwrites, so it is believed while the list
 * is Cloudflare's alone: add a proxy of your own with and_also() and it
 * is dropped, because your proxy passes it through from whoever reached
 * it, Cloudflare or not. using_headers() names headers yourself, last,
 * when you know what your proxies overwrite.
 *
 * Immutable: every method that changes something returns a copy.
 *
 * @since 1.3.0
 */
final class TrustedProxies {

	/**
	 * The header every proxy appends to.
	 */
	public const string FORWARDED_FOR = 'X-Forwarded-For';

	/**
	 * The header Cloudflare puts the visitor's address in.
	 */
	public const string CF_CONNECTING_IP = 'CF-Connecting-IP';

	/**
	 * This machine and private networks: RFC 1918 and RFC 4193.
	 *
	 * Addresses a proxy of your own has and no visitor from the internet
	 * does. `fc00::/7` is the whole of RFC 4193's unique local range, not
	 * only `fd00::/8`, the half that is assigned today: neither half is
	 * routed on the internet.
	 */
	public const array LOCAL = [
		'127.0.0.0/8',
		'::1/128',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'fc00::/7',
	];

	/**
	 * The addresses Cloudflare connects from.
	 *
	 * As published at https://www.cloudflare.com/ips/ (read 2026-11-16).
	 * They change rarely, and a range missing from here fails the right
	 * way round -- the header from that range is not believed, so the
	 * address seen is Cloudflare's -- but that is still wrong: pass a
	 * fresh list to {@see self::cloudflare()} rather than relying on this
	 * one for ever.
	 */
	public const array CLOUDFLARE = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * The addresses and ranges that are your own proxies.
	 *
	 * @var array<int, string>
	 */
	public readonly array $ranges;

	/**
	 * The headers believed from them, in the order they are tried.
	 *
	 * @var array<int, string>
	 */
	public readonly array $headers;

	/**
	 * Whether the headers are read whatever address connected.
	 *
	 * @var bool
	 */
	public readonly bool $any_address;

	/**
	 * Use one of the named constructors.
	 *
	 * @param array $ranges      Addresses and ranges.
	 * @param array $headers     Header names.
	 * @param bool  $any_address Whether every connection is a proxy's.
	 */
	private function __construct( array $ranges, array $headers, bool $any_address = false ) {
		$this->ranges      = self::clean_ranges( $ranges );
		$this->headers     = array_values( array_unique( array_filter( array_map( [ self::class, 'header_name' ], $headers ), 'strlen' ) ) );
		$this->any_address = $any_address;
	}

	/**
	 * Trust nothing: the connection's own address and no header.
	 *
	 * Right for a site reached directly, and the safe answer when unsure.
	 * It keeps `X-Forwarded-For` as its header, so
	 * `none()->and_also( … )` is a working list for a proxy of your own.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( [], [ self::FORWARDED_FOR ] );
	}

	/**
	 * Cloudflare's addresses, believed for `CF-Connecting-IP`, then
	 * `X-Forwarded-For`.
	 *
	 * Nothing can connect from Cloudflare's addresses but Cloudflare, and
	 * Cloudflare always overwrites its header, so this cannot be faked
	 * from outside.
	 *
	 * @param array|null $ranges A fresher list from
	 *                           https://www.cloudflare.com/ips/, used in
	 *                           place of the bundled one. Null for the
	 *                           bundled list.
	 *
	 * @return self
	 */
	public static function cloudflare( ?array $ranges = null ): self {
		return new self( $ranges ?? self::CLOUDFLARE, [ self::CF_CONNECTING_IP, self::FORWARDED_FOR ] );
	}

	/**
	 * A proxy on this machine or a private network.
	 *
	 * For nginx, Caddy or HAProxy in front of PHP on the same box or
	 * inside the same private network. Safe because a connection from a
	 * private address cannot have begun on the internet -- provided the
	 * network really is private.
	 *
	 * @return self
	 */
	public static function local(): self {
		return new self( self::LOCAL, [ self::FORWARDED_FOR ] );
	}

	/**
	 * A copy that trusts more addresses as well.
	 *
	 * `CF-Connecting-IP` is no longer read by the copy. With a proxy of
	 * your own in the list the connection may be that proxy's, which
	 * hands the header on from whoever reached it. Follow with
	 * using_headers() if your proxy accepts nothing but Cloudflare.
	 *
	 * @param array $ranges Addresses and ranges, in any form {@see Range}
	 *                      reads.
	 *
	 * @return self
	 */
	public function and_also( array $ranges ): self {
		$headers = array_filter(
			$this->headers,
			static fn( string $header ): bool => 0 !== strcasecmp( $header, self::CF_CONNECTING_IP )
		);

		return new self(
			array_merge( $this->ranges, $ranges ),
			[] === $headers ? [ self::FORWARDED_FOR ] : $headers,
			$this->any_address
		);
	}

	/**
	 * A copy that believes these headers and no others.
	 *
	 * Name only headers that **every** trusted proxy overwrites or
	 * appends to. A proxy that passes a header through untouched hands
	 * it to the visitor. Call it last: and_also() after it takes
	 * `CF-Connecting-IP` back out.
	 *
	 * @param array $headers Header names as they are written in a request
	 *                       (`X-Real-IP`), in the order to try them.
	 *
	 * @return self
	 */
	public function using_headers( array $headers ): self {
		return new self( $this->ranges, $headers, $this->any_address );
	}

	/**
	 * A copy that reads the headers whatever address connected.
	 *
	 * For a site that can only be reached through its proxy, when the
	 * proxy's address is public, or changes, or is not known: a managed
	 * load balancer. The ranges then say only which entries of a
	 * forwarding header are stepped over as your own machinery.
	 *
	 * **This is a promise about the network that nothing here can
	 * check.** If the site can be reached any other way, whoever does so
	 * writes the header and chooses their address. Where the proxy's
	 * address is known, list it instead.
	 *
	 * @return self
	 */
	public function from_any_address(): self {
		return new self( $this->ranges, $this->headers, true );
	}

	/**
	 * Whether an address is one of your proxies.
	 *
	 * @param string $ip An address, in any spelling.
	 *
	 * @return bool
	 */
	public function trusts( string $ip ): bool {
		return [] !== $this->ranges && Range::contains_any( $this->ranges, $ip );
	}

	/**
	 * A header's name, with anything that cannot be in one taken out.
	 *
	 * Letters, digits and hyphens, sixty-four at most: a name is looked
	 * up in `$_SERVER`, and nothing given here should be able to name
	 * anything there but a request header. `HTTP_X_REAL_IP`, pasted from
	 * somewhere that shows PHP's name for it, is the header `X-REAL-IP`.
	 *
	 * @param mixed $header What was typed.
	 *
	 * @return string Empty when nothing of a name is left.
	 */
	public static function header_name( mixed $header ): string {
		$header = is_scalar( $header ) ? trim( (string) $header ) : '';

		if ( 0 === stripos( $header, 'HTTP_' ) ) {
			$header = str_replace( '_', '-', substr( $header, 5 ) );
		}

		return substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', rtrim( $header, ':' ) ), 0, 64 );
	}

	/**
	 * A list of ranges as strings, without the empty ones.
	 *
	 * @param array $ranges What was given.
	 *
	 * @return array<int, string>
	 */
	private static function clean_ranges( array $ranges ): array {
		$clean = [];

		foreach ( $ranges as $range ) {
			$range = is_scalar( $range ) ? trim( (string) $range ) : '';

			if ( '' !== $range ) {
				$clean[] = $range;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
