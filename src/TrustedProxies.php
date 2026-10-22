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
 * Trusting the connection is half of it; the other half is which
 * headers. A header is only as good as the proxy that wrote it, so list
 * only headers **every** trusted proxy overwrites or appends to. A proxy
 * that passes a header through untouched hands it to the client:
 * `CF-Connecting-IP` arriving at your own nginx is whatever the caller
 * typed, unless the request really came through Cloudflare first.
 *
 * `X-Forwarded-For` is the safe default because it is read from the
 * right — every proxy in the chain appends the address it saw, so a
 * chain of any length resolves correctly. `CF-Connecting-IP` and
 * `X-Real-IP` are single values that only the last hop sets; they are
 * right only when that last hop is the one that sets them.
 *
 * Cloudflare's ranges change. Refresh them from
 * https://www.cloudflare.com/ips/ and pass your own list rather than
 * relying on the bundled snapshot indefinitely.
 *
 * @since 1.0.0
 */
final readonly class TrustedProxies {

	/**
	 * The header every proxy appends to, and the only one trusted unless
	 * you say otherwise.
	 *
	 * @since 1.3.0
	 */
	public const string FORWARDED_FOR = 'X-Forwarded-For';

	/**
	 * List the proxies, and the headers believed from them.
	 *
	 * Before 1.3.0 the default headers were `CF-Connecting-IP`,
	 * `X-Forwarded-For` and `X-Real-IP`. With a range that was not
	 * Cloudflare's, that let a client set `CF-Connecting-IP` and have your
	 * own proxy pass it straight through; the default is now
	 * `X-Forwarded-For` alone. Name others with {@see self::using_headers()}
	 * when your proxy sets them.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $ranges  CIDR ranges, or bare addresses.
	 * @param list<string> $headers Forwarding headers to honour, in priority
	 *                              order, once the connection is trusted.
	 *                              Each must be one every trusted proxy
	 *                              overwrites or appends to.
	 */
	public function __construct(
		public array $ranges = array(),
		public array $headers = array( self::FORWARDED_FOR ),
	) {}

	/**
	 * Trust nothing — use `REMOTE_ADDR` only.
	 *
	 * The correct setting when your application is reached directly, and
	 * the safe default. With no ranges no header is ever read, so this
	 * keeps `X-Forwarded-For` as its header: `none()->and_also( … )` is
	 * then a working list for your own load balancer rather than one that
	 * silently reads nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Cloudflare's published ranges, as of 2026-08.
	 *
	 * Verify against https://www.cloudflare.com/ips/ and refresh
	 * periodically — an out-of-date list fails closed (visitors appear as
	 * Cloudflare's address rather than their own), which is the right way
	 * round, but still wrong.
	 *
	 * `CF-Connecting-IP` is read only when the list is Cloudflare's alone,
	 * because then the connection is from Cloudflare, which always
	 * overwrites it. Add your own ranges — Cloudflare in front of your
	 * load balancer — and the connection is from your balancer, which
	 * passes the header through from whoever reached it, Cloudflare or
	 * not; so only `X-Forwarded-For`, which both append to, is read.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $extra Additional ranges to trust.
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
				array_values( $extra )
			),
			array() === $extra
				? array( 'CF-Connecting-IP', self::FORWARDED_FOR )
				: array( self::FORWARDED_FOR )
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
	 * The headers are kept as they are. If they include one only the
	 * outermost proxy sets — `CF-Connecting-IP` from
	 * {@see self::cloudflare()} — and the new range is a proxy behind it,
	 * follow with `using_headers( TrustedProxies::FORWARDED_FOR )`, or
	 * pass the ranges to `cloudflare()` instead, which does that for you.
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$ranges CIDR ranges.
	 *
	 * @return self
	 */
	public function and_also( string ...$ranges ): self {
		return new self( array_merge( $this->ranges, array_values( $ranges ) ), $this->headers );
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
		return clone( $this, array( 'headers' => array_values( $headers ) ) );
	}
}
