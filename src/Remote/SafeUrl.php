<?php
/**
 * Asking an address somebody typed in.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Remote;

use SugarCommerce\Network\IP;
use SugarCommerce\Network\Range;
use WP_Error;

/**
 * Class SafeUrl
 *
 * A webhook endpoint, a licence store, a storage endpoint: an address a
 * site owner typed into a settings screen -- or a filter set, or a DNS
 * record somebody took over -- and the server sends a request to it. The
 * server has network reach nobody else has: `169.254.169.254` (the cloud's
 * instance credentials), `10.x` (the database), `127.0.0.1` (whatever is
 * bound to loopback). Without a check, the address field is a way to make
 * the server ask there, and hand back what it was told.
 *
 * WordPress's own wp_safe_remote_post() is the base, and it is not enough
 * on its own. It resolves the host with gethostbyname(), which answers one
 * IPv4 address, so a name with a private AAAA record -- or several A
 * records, one of them private -- passes. It knows nothing of IPv6: `[::1]`,
 * `[fd00::1]` and the NAT64 and 6to4 forms that carry an IPv4 address
 * inside are all "external". Before each request this checks:
 *
 * - **http or https** -- https only when asked (`https_only`) -- with no
 *   username or password in the address: `http://real@internal/` is read
 *   differently by different parsers.
 * - **A plain host**: letters, digits, dots, hyphens and underscores, or a
 *   bracketed IPv6 literal. Curl decodes `%2e` in a host into a dot and
 *   parse_url() does not, so the name checked and the name connected to
 *   would be two names. A raw Unicode name is refused for the same reason
 *   (give its `xn--` form), and so is a number that is not a dotted quad --
 *   `2852039166`, `0xa9fea9fe`, `0251.0376.0251.0376` -- which a resolver
 *   reads as an address and no real host ever needs.
 * - **Every address the name resolves to, A and AAAA, is public**
 *   ({@see IP::is_public()}): one private answer among public ones is a
 *   rebinding attempt, not a misconfiguration.
 * - **Never a metadata service, link-local or "no address"**, whatever is
 *   allowed: `metadata.google.internal`, 169.254.0.0/16, fe80::/10,
 *   0.0.0.0/8, `::`, Alibaba's 100.100.100.200, AWS's and Google's IPv6
 *   metadata addresses -- by name, by literal, or by an IPv6 address that
 *   carries one ({@see IP::carried()}).
 * - **The connection pinned** to the address that was checked, when curl
 *   is the transport (CURLOPT_RESOLVE), so a second DNS answer cannot send
 *   it somewhere else. The name stays in the URL, so TLS still checks the
 *   certificate against it.
 * - **No redirects** unless asked for, and then each hop is checked again:
 *   a public address answering "308, go to http://10.0.0.5/" is the whole
 *   attack.
 *
 * Three ways to let a private address through, none of which reaches the
 * metadata and link-local addresses above: `allow_private` (a LAN storage
 * server, a development setup), `own_host` (the site's own host, as core's
 * wp_http_validate_url() allows -- a store selling to itself), and core's
 * `http_request_host_is_external` filter for one host. Ports are
 * WordPress's business, through `http_allowed_safe_ports`.
 *
 *     $safe = new SafeUrl( https_only: true );
 *
 *     if ( null !== $safe->refuse( $url ) ) { … }         // why not, for a log
 *     $response = $safe->post( $url, [ 'body' => $json ] );
 *     $response = $safe->get( $url, [], 2 );              // follow up to two redirects
 *
 * @since 1.3.0
 */
final class SafeUrl {

	/**
	 * Statuses a post follows, when it follows any: the ones that keep the
	 * method and the body. A 303 means "go and GET something else", which is
	 * not delivering what was posted.
	 */
	private const array FOLLOWED_POST = [ 301, 302, 307, 308 ];

	/**
	 * Statuses a get follows, when it follows any.
	 */
	private const array FOLLOWED_GET = [ 301, 302, 303, 307, 308 ];

	/**
	 * Names that are a cloud's metadata service, or no address at all.
	 */
	private const array METADATA_NAMES = [ 'metadata.google.internal', 'metadata', 'instance-data', 'instance-data.ec2.internal', '0' ];

	/**
	 * Addresses no setting lets a request reach: "this network" and the
	 * unspecified address, link-local (where AWS, Azure, GCP and most others
	 * answer with the server's own credentials), Alibaba's metadata service
	 * in the carrier-NAT block, and AWS's and Google's IPv6 metadata
	 * addresses in the unique-local one.
	 */
	private const array NEVER = [
		'0.0.0.0/8',
		'169.254.0.0/16',
		'100.100.100.200/32',
		'::/128',
		'fe80::/10',
		'fd00:ec2::254/128',
		'fd20:ce::254/128',
	];

	/**
	 * How a name is resolved: fn( string $host ): string[].
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * @since 1.3.0
	 *
	 * @param bool          $allow_private Let private networks and loopback
	 *                                     through: a storage server on the
	 *                                     LAN, a development setup. Never the
	 *                                     metadata or link-local addresses.
	 * @param callable|null $resolver      fn( string $host ): string[], every
	 *                                     address a name resolves to. For
	 *                                     tests; DNS when left out.
	 * @param bool          $https_only    Refuse http.
	 * @param bool          $own_host      Let the site's own host through
	 *                                     wherever it resolves (not to the
	 *                                     metadata or link-local addresses).
	 */
	public function __construct(
		private readonly bool $allow_private = false,
		?callable $resolver = null,
		private readonly bool $https_only = false,
		private readonly bool $own_host = false,
	) {
		$this->resolver = $resolver ?? self::resolve( ... );
	}

	/**
	 * Why an address may not be asked, or null when it may.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url The address.
	 *
	 * @return string|null A reason, for a log -- not for the person who
	 *                     typed the address, whom it would help to probe.
	 */
	public function refuse( string $url ): ?string {
		return $this->check( $url )[0];
	}

	/**
	 * Why an address is not one this would ask, judged by how it is written
	 * alone -- no DNS. What a settings screen can say as it saves.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url The address.
	 *
	 * @return string|null A reason, or null when it is well formed.
	 */
	public function malformed( string $url ): ?string {
		// parse_url() turns a control character in a host into an
		// underscore, so `http://example.com\n/` would be checked as a name
		// nobody asked for. No valid URL has whitespace in it.
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return 'The address has whitespace or a control character in it.';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'The address could not be read.';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( $this->https_only ? 'https' !== $scheme : ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return $this->https_only ? 'Only https addresses are asked.' : 'Only http and https addresses are asked.';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'The address has a username or password in it.';
		}

		$host = (string) $parts['host'];

		if ( ! self::is_plain_host( $host ) ) {
			return 'The host is not a plain name or IP address.';
		}

		if ( self::is_metadata_host( $host ) ) {
			return 'The host is a metadata service or no address at all.';
		}

		return null;
	}

	/**
	 * Post to an address, refusing ones that reach inside the network.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $url       The address.
	 * @param array<string, mixed> $args      As for wp_remote_post().
	 *                                        `redirection` is replaced.
	 * @param int                  $redirects Redirects to follow (301, 302,
	 *                                        307, 308), each checked. Nought
	 *                                        treats a 3xx as the answer.
	 *
	 * @return array|WP_Error The response, or why there is none.
	 */
	public function post( string $url, array $args = [], int $redirects = 0 ): array|WP_Error {
		return $this->send( 'POST', $url, $args, $redirects );
	}

	/**
	 * Get an address, refusing ones that reach inside the network.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $url       The address.
	 * @param array<string, mixed> $args      As for wp_remote_get().
	 *                                        `redirection` is replaced.
	 * @param int                  $redirects Redirects to follow (301, 302,
	 *                                        303, 307, 308), each checked.
	 *
	 * @return array|WP_Error The response, or why there is none.
	 */
	public function get( string $url, array $args = [], int $redirects = 0 ): array|WP_Error {
		return $this->send( 'GET', $url, $args, $redirects );
	}

	/**
	 * Every address a name resolves to, A and AAAA. An IP literal is
	 * itself.
	 *
	 * @since 1.3.0
	 *
	 * @param string $host A name or an IP literal.
	 *
	 * @return string[] Empty when it does not resolve.
	 */
	public static function resolve( string $host ): array {
		$host = trim( $host, '[]' );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}

		$ips = [];
		$a   = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not resolve is an answer, not a warning.

		if ( is_array( $a ) ) {
			$ips = $a;
		}

		$aaaa = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.

		foreach ( is_array( $aaaa ) ? $aaaa : [] as $record ) {
			if ( is_string( $record['ipv6'] ?? null ) ) {
				$ips[] = $record['ipv6'];
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether a host is a cloud metadata service, or no address at all --
	 * by name, by literal, or by a number a resolver reads as an address.
	 *
	 * No DNS: what a settings screen can refuse as it saves. A private
	 * network address is not one -- whether that is allowed is
	 * `allow_private`'s question.
	 *
	 * @since 1.3.0
	 *
	 * @param string $host A host, optionally with a port, without a scheme.
	 *
	 * @return bool
	 */
	public static function is_metadata_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		if ( str_starts_with( $host, '[' ) ) {
			$host = (string) preg_replace( '/\](:\d*)?$/', '', substr( $host, 1 ) );
		} elseif ( substr_count( $host, ':' ) <= 1 ) {
			$host = (string) preg_replace( '/:\d*$/', '', $host );
		}

		$host = rtrim( $host, '.' );

		if ( in_array( $host, self::METADATA_NAMES, true ) ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_never( $host );
		}

		// A number that is not a dotted quad -- 2852039166, 0xa9fea9fe,
		// 0251.0376.0251.0376 -- is still an address to the resolver, and
		// says nothing a real host ever needs to.
		return 1 === preg_match( '/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/D', $host );
	}

	/**
	 * Whether a host is spelled the way a resolver and curl agree on.
	 *
	 * @since 1.3.0
	 *
	 * @param string $host Host as wp_parse_url() returned it.
	 *
	 * @return bool
	 */
	public static function is_plain_host( string $host ): bool {
		if ( str_starts_with( $host, '[' ) ) {
			return 1 === preg_match( '/^\[[0-9a-f:.]+\]$/iD', $host )
				&& false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		return 1 === preg_match( '/^[a-z0-9_.-]{1,253}$/iD', $host ) && ! str_contains( $host, '..' );
	}

	/**
	 * Check, ask, and follow what is allowed to be followed.
	 *
	 * @param string               $method    GET or POST.
	 * @param string               $url       The address.
	 * @param array<string, mixed> $args      As for wp_remote_request().
	 * @param int                  $redirects Redirects to follow.
	 *
	 * @return array|WP_Error
	 */
	private function send( string $method, string $url, array $args, int $redirects ): array|WP_Error {
		$args['redirection'] = 0;
		$followed            = 'POST' === $method ? self::FOLLOWED_POST : self::FOLLOWED_GET;

		for ( $hop = 0; ; $hop++ ) {
			[ $reason, $pin ] = $this->check( $url );

			if ( null !== $reason ) {
				return new WP_Error( 'http_request_not_executed', $reason, [ 'url' => $url ] );
			}

			$response = $this->pinned( $method, $url, $args, $pin );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( $hop >= $redirects || '' === $location || ! in_array( $status, $followed, true ) ) {
				return $response;
			}

			$url = self::absolute( $location, $url );
		}
	}

	/**
	 * Check an address: why not, and the address to connect to.
	 *
	 * @param string $url The address.
	 *
	 * @return array{0: string|null, 1: string} A reason or null, and the IP
	 *                                          that passed.
	 */
	private function check( string $url ): array {
		$reason = $this->malformed( $url );

		if ( null !== $reason ) {
			return [ $reason, '' ];
		}

		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );
		$ips  = array_values( array_filter( array_map( 'strval', (array) ( $this->resolver )( $host ) ) ) );

		if ( [] === $ips ) {
			return [ 'The host does not resolve.', '' ];
		}

		foreach ( $ips as $ip ) {
			if ( ! IP::is_valid( trim( $ip, '[]' ) ) ) {
				return [ 'The host resolves to something that is not an address.', '' ];
			}

			// No setting reaches these: not allow_private, not the site's
			// own host, not the core filter.
			if ( self::is_never( $ip ) ) {
				return [ 'The host resolves to a metadata, link-local or unspecified address.', '' ];
			}
		}

		if ( ! $this->allow_private && ! $this->is_own_host( $host ) && ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
			foreach ( $ips as $ip ) {
				// Every answer must pass. One private address among public
				// ones is a rebinding attempt.
				if ( ! IP::is_public( $ip ) ) {
					return [ 'The host resolves to a private or reserved address.', '' ];
				}
			}
		}

		return [ null, trim( $ips[0], '[]' ) ];
	}

	/**
	 * Whether an address is one no setting lets a request reach.
	 *
	 * @param string $ip An address, in any spelling.
	 *
	 * @return bool
	 */
	private static function is_never( string $ip ): bool {
		$carried = IP::carried( $ip );

		return null !== $carried && Range::contains_any( self::NEVER, $carried );
	}

	/**
	 * Whether a host is the site's own, when that is allowed.
	 *
	 * @param string $host The host, without brackets.
	 *
	 * @return bool
	 */
	private function is_own_host( string $host ): bool {
		return $this->own_host
			&& strtolower( $host ) === strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '[]' ) );
	}

	/**
	 * Ask, with curl held to the address that was checked.
	 *
	 * CURLOPT_RESOLVE for this host and port, for this one request. With
	 * another transport the check stands alone. Core's safe variant when
	 * only public addresses are allowed; the plain one when private ones
	 * are, since core's would refuse them after this let them through.
	 *
	 * @param string               $method GET or POST.
	 * @param string               $url    The address.
	 * @param array<string, mixed> $args   As for wp_remote_request().
	 * @param string               $ip     The address that passed.
	 *
	 * @return array|WP_Error
	 */
	private function pinned( string $method, string $url, array $args, string $ip ): array|WP_Error {
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port = $port > 0 ? $port : ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 );

		$pin = static function ( $handle ) use ( $host, $port, $ip ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) && $handle instanceof \CurlHandle ) {
				$address = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;

				curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $address ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the only way to pin the connection; this is WordPress's own curl handle.
			}
		};

		add_action( 'http_api_curl', $pin, 10, 1 );

		try {
			if ( $this->allow_private ) {
				$args['reject_unsafe_urls'] = false;

				return 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
			}

			return 'POST' === $method ? wp_safe_remote_post( $url, $args ) : wp_safe_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
	}

	/**
	 * A `Location` as a whole address, against the one it came from.
	 *
	 * @param string $location The header.
	 * @param string $base     The address that answered.
	 *
	 * @return string
	 */
	private static function absolute( string $location, string $base ): string {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			return $location;
		}

		$parts  = (array) wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}

		$path = (string) ( $parts['path'] ?? '/' );

		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}
}
