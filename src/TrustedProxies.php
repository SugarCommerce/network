<?php
/**
 * Which upstream proxies may set forwarding headers.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class TrustedProxies
 *
 * The list that makes {@see ClientIp} safe.
 *
 * `X-Forwarded-For` and `CF-Connecting-IP` are just request headers.
 * Anyone can send them. Trusting them because a config flag is set means
 * an attacker who can reach your origin directly — a leaked origin IP, a
 * misconfigured DNS record, a health-check port left open — declares
 * whatever IP they like. Everything keyed on the client address then
 * belongs to them: rate limits, blocklists, audit trails, fraud scores,
 * and the IP you would submit as dispute evidence.
 *
 * A header is only meaningful when the connection carrying it came from
 * a proxy you actually put there. That is what this list encodes.
 *
 *   $proxies = TrustedProxies::cloudflare();
 *   $ip      = ( new ClientIp( $proxies ) )->resolve();
 *
 * Cloudflare's ranges change. Refresh them from
 * https://www.cloudflare.com/ips/ and pass your own list rather than
 * relying on the bundled snapshot indefinitely.
 *
 * @since 1.0.0
 */
final readonly class TrustedProxies {

	/**
	 * @since 1.0.0
	 *
	 * @param string[] $ranges  CIDR ranges, or bare addresses.
	 * @param string[] $headers Forwarding headers to honour, in priority
	 *                          order, once the connection is trusted.
	 */
	public function __construct(
		public array $ranges = array(),
		public array $headers = array( 'CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP' ),
	) {}

	/**
	 * Trust nothing — use `REMOTE_ADDR` only.
	 *
	 * The correct setting when your application is reached directly, and
	 * the safe default.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( array(), array() );
	}

	/**
	 * Cloudflare's published ranges, as of 2026-08.
	 *
	 * Verify against https://www.cloudflare.com/ips/ and refresh
	 * periodically — an out-of-date list fails closed (visitors appear as
	 * Cloudflare's address rather than their own), which is the right way
	 * round, but still wrong.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $extra Additional ranges to trust.
	 *
	 * @return self
	 */
	public static function cloudflare( array $extra = array() ): self {
		return new self(
			array_merge(
				array(
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
				),
				$extra
			),
			array( 'CF-Connecting-IP', 'X-Forwarded-For' )
		);
	}

	/**
	 * A proxy on the same host or private network.
	 *
	 * For nginx, Caddy or HAProxy in front of PHP-FPM on the same box or
	 * inside the same private network. Safe because a request from a
	 * private address cannot have originated on the public internet —
	 * provided your network really is private.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function local(): self {
		return new self(
			array( '127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fd00::/8' ),
			array( 'X-Forwarded-For', 'X-Real-IP' )
		);
	}

	/**
	 * Whether a connecting address is one of our proxies.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip `REMOTE_ADDR`.
	 *
	 * @return bool
	 */
	public function trusts( string $ip ): bool {
		return array() !== $this->ranges && Cidr::contains_any( $ip, $this->ranges );
	}

	/**
	 * A copy with extra ranges trusted.
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$ranges CIDR ranges.
	 *
	 * @return self
	 */
	public function and_also( string ...$ranges ): self {
		return new self( array_merge( $this->ranges, $ranges ), $this->headers );
	}

	/**
	 * A copy honouring a different set of headers.
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$headers Header names, in priority order.
	 *
	 * @return self
	 */
	public function using_headers( string ...$headers ): self {
		return clone( $this, array( 'headers' => $headers ) );
	}
}
