<?php
/**
 * Where a visitor's IP address is read from.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\ClientIp;
use SugarCommerce\Network\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * The connection, or a header -- and only a header from a proxy of ours.
 *
 * Every test here is one of two questions. Behind a proxy, is the
 * visitor's own address the one returned, and not the proxy's? And
 * everywhere else, is a header ignored? The second is the one that
 * matters: a header is the visitor's to write, and a site that believes
 * one from a connection it does not trust has let every visitor choose
 * their address.
 */
final class ClientIpTest extends TestCase {

	/**
	 * One of Cloudflare's own addresses, a visitor, and one that is
	 * nobody's proxy and is lying.
	 */
	private const CLOUDFLARE = '173.245.48.9';
	private const VISITOR    = '203.0.113.9';
	private const LIAR       = '198.51.100.66';

	/**
	 * The server variables as they were.
	 *
	 * @var array
	 */
	private array $server;

	protected function setUp(): void {
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
	}

	/**
	 * A request, as `$_SERVER` has it.
	 *
	 * @param string|null           $remote  The connection's address.
	 * @param array<string, string> $headers `$_SERVER` keys to values.
	 *
	 * @return array<string, string>
	 */
	private static function request( ?string $remote, array $headers = [] ): array {
		return ( null === $remote ? [] : [ 'REMOTE_ADDR' => $remote ] ) + $headers;
	}

	/**
	 * Every header a liar might send, all naming somebody else.
	 *
	 * @return array<string, string>
	 */
	private static function lies(): array {
		return [
			'HTTP_X_FORWARDED_FOR'  => self::LIAR,
			'HTTP_X_REAL_IP'        => self::LIAR,
			'HTTP_CLIENT_IP'        => self::LIAR,
			'HTTP_CF_CONNECTING_IP' => self::LIAR,
			'HTTP_FORWARDED'        => 'for=' . self::LIAR,
		];
	}

	/**
	 * Out of the box nothing is believed but the connection.
	 */
	public function test_headers_are_ignored_by_default(): void {
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, self::lies() ) ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, self::lies() ), TrustedProxies::none() ) );

		// Not from Cloudflare's address either, nor from this machine.
		$this->assertSame( self::CLOUDFLARE, ClientIp::resolve( self::request( self::CLOUDFLARE, self::lies() ) ) );
		$this->assertSame( '127.0.0.1', ClientIp::resolve( self::request( '127.0.0.1', self::lies() ) ) );
	}

	/**
	 * With no array given, the request is `$_SERVER`.
	 */
	public function test_the_request_is_the_superglobal_by_default(): void {
		$_SERVER = self::request( self::CLOUDFLARE, [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] );

		$this->assertSame( self::CLOUDFLARE, ClientIp::resolve() );
		$this->assertSame( self::CLOUDFLARE, ClientIp::direct() );
		$this->assertSame( self::VISITOR, ClientIp::resolve( null, TrustedProxies::cloudflare() ) );
	}

	/**
	 * A header from a connection that is not a trusted proxy is the
	 * visitor's own writing, whatever the policy and whichever header.
	 */
	public function test_a_header_from_an_untrusted_address_is_never_believed(): void {
		$policies = [
			'none'             => TrustedProxies::none(),
			'cloudflare'       => TrustedProxies::cloudflare(),
			'cloudflare alone' => TrustedProxies::cloudflare()->using_headers( [ 'CF-Connecting-IP' ] ),
			'local'            => TrustedProxies::local(),
			'local, any name'  => TrustedProxies::local()->using_headers( [ 'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'CF-Connecting-IP' ] ),
			'a named proxy'    => TrustedProxies::none()->and_also( [ '192.0.2.77' ] ),
			'both'             => TrustedProxies::cloudflare()->and_also( TrustedProxies::LOCAL ),
		];

		foreach ( $policies as $name => $trust ) {
			// The visitor connects directly and claims to be somebody else.
			$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, self::lies() ), $trust ), $name );

			// The same over IPv6.
			$this->assertSame( '2001:db8::7', ClientIp::resolve( self::request( '2001:db8::7', self::lies() ), $trust ), $name . ' v6' );

			// A chain dressed up to look as if it came through proxies.
			$chain = [ 'HTTP_X_FORWARDED_FOR' => self::LIAR . ', 10.0.0.5, ' . self::CLOUDFLARE ];
			$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, $chain ), $trust ), $name . ' chain' );
		}

		// A private address is not Cloudflare; Cloudflare is not a local proxy.
		$this->assertSame( '10.0.0.5', ClientIp::resolve( self::request( '10.0.0.5', self::lies() ), TrustedProxies::cloudflare() ) );
		$this->assertSame( self::CLOUDFLARE, ClientIp::resolve( self::request( self::CLOUDFLARE, self::lies() ), TrustedProxies::local() ) );

		// Next door to a trusted proxy is not the trusted proxy.
		$this->assertSame( '192.0.2.78', ClientIp::resolve( self::request( '192.0.2.78', self::lies() ), TrustedProxies::none()->and_also( [ '192.0.2.77' ] ) ) );
	}

	/**
	 * Cloudflare's header is read when Cloudflare is who connected.
	 */
	public function test_cloudflare_is_believed_only_from_cloudflare(): void {
		$trust = TrustedProxies::cloudflare();

		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::CLOUDFLARE, [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] ), $trust ) );

		// IPv6, both ends.
		$this->assertSame( '2001:db8::7', ClientIp::resolve( self::request( '2606:4700::1111', [ 'HTTP_CF_CONNECTING_IP' => '2001:db8::7' ] ), $trust ) );

		// A server listening on both families reports Cloudflare's IPv4 address inside an IPv6 one.
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( '::ffff:' . self::CLOUDFLARE, [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] ), $trust ) );

		// Somebody who found the server's real address and sent the header themselves.
		$this->assertSame( self::LIAR, ClientIp::resolve( self::request( self::LIAR, [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] ), $trust ) );

		// A header that is not an address is no header.
		$this->assertSame( self::CLOUDFLARE, ClientIp::resolve( self::request( self::CLOUDFLARE, [ 'HTTP_CF_CONNECTING_IP' => '<script>' ] ), $trust ) );

		// Cloudflare's own header first; the forwarding header only without it.
		$both = [
			'HTTP_CF_CONNECTING_IP' => self::VISITOR,
			'HTTP_X_FORWARDED_FOR'  => self::LIAR,
		];
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::CLOUDFLARE, $both ), $trust ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::CLOUDFLARE, [ 'HTTP_X_FORWARDED_FOR' => self::LIAR . ', ' . self::VISITOR ] ), $trust ) );

		// Told to read Cloudflare's header alone, the forwarding header is not read at all.
		$alone = $trust->using_headers( [ 'CF-Connecting-IP' ] );
		$this->assertSame( self::CLOUDFLARE, ClientIp::resolve( self::request( self::CLOUDFLARE, [ 'HTTP_X_FORWARDED_FOR' => self::VISITOR ] ), $alone ) );

		// A fresher list of Cloudflare's addresses.
		$this->assertSame( '192.0.2.200', ClientIp::resolve( self::request( '192.0.2.200', [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] ), $trust ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( '192.0.2.200', [ 'HTTP_CF_CONNECTING_IP' => self::VISITOR ] ), TrustedProxies::cloudflare( array_merge( TrustedProxies::CLOUDFLARE, [ '192.0.2.0/24' ] ) ) ) );
	}

	/**
	 * With a proxy of the site's own in the list, Cloudflare's header is
	 * whatever reached that proxy, and is not read unless it is named.
	 */
	public function test_cloudflares_header_through_a_proxy_of_your_own(): void {
		$request = self::request( '127.0.0.1', [ 'HTTP_CF_CONNECTING_IP' => self::LIAR ] );
		$mixed   = TrustedProxies::cloudflare()->and_also( TrustedProxies::LOCAL );

		$this->assertSame( '127.0.0.1', ClientIp::resolve( $request, $mixed ) );
		$this->assertSame( self::LIAR, ClientIp::resolve( $request, $mixed->using_headers( [ 'CF-Connecting-IP' ] ) ) );

		// It reads the header every proxy appends to.
		$chain = self::request( '127.0.0.1', [ 'HTTP_X_FORWARDED_FOR' => self::LIAR . ', ' . self::VISITOR . ', ' . self::CLOUDFLARE ] );
		$this->assertSame( self::VISITOR, ClientIp::resolve( $chain, $mixed ) );
	}

	/**
	 * Behind a proxy, the address the proxy wrote is the one on the right.
	 */
	public function test_a_forwarding_header_is_read_from_the_right(): void {
		$trust = TrustedProxies::local();
		$via   = static fn( string $chain, string $remote = '10.0.0.5' ): ?string => ClientIp::resolve( self::request( $remote, [ 'HTTP_X_FORWARDED_FOR' => $chain ] ), $trust );

		// One proxy: it adds who connected to it.
		$this->assertSame( self::VISITOR, $via( self::VISITOR ) );

		// The visitor sent a header of their own first. Theirs is on the
		// left, and is the one most code would take.
		$this->assertSame( self::VISITOR, $via( self::LIAR . ', ' . self::VISITOR ) );
		$this->assertSame( self::VISITOR, $via( self::LIAR . ',' . self::VISITOR ) );
		$this->assertSame( self::VISITOR, $via( '10.0.0.99, ' . self::VISITOR ) );

		// Two of the site's own proxies after the visitor: stepped over.
		$this->assertSame( self::VISITOR, $via( self::LIAR . ', ' . self::VISITOR . ', 10.0.0.9, 192.168.1.4' ) );

		// With a port, and IPv6 in brackets with one.
		$this->assertSame( self::VISITOR, $via( self::VISITOR . ':51234' ) );
		$this->assertSame( '2001:db8::7', $via( '[2001:db8::7]:443' ) );
		$this->assertSame( '2001:db8::7', $via( self::LIAR . ', 2001:db8::7, [fd00::5]:8080' ) );

		// From this machine, over either family.
		$this->assertSame( self::VISITOR, $via( self::VISITOR, '127.0.0.1' ) );
		$this->assertSame( self::VISITOR, $via( self::VISITOR, '::1' ) );

		// A proxy with a public address is not stepped over until it is named.
		$this->assertSame( '192.0.2.77', $via( self::VISITOR . ', 192.0.2.77' ) );
		$this->assertSame(
			self::VISITOR,
			ClientIp::resolve( self::request( '10.0.0.5', [ 'HTTP_X_FORWARDED_FOR' => self::VISITOR . ', 192.0.2.77' ] ), $trust->and_also( [ '192.0.2.77' ] ) )
		);

		// A load balancer behind Cloudflare.
		$this->assertSame(
			self::VISITOR,
			ClientIp::resolve( self::request( '10.0.0.5', [ 'HTTP_X_FORWARDED_FOR' => self::VISITOR . ', ' . self::CLOUDFLARE ] ), $trust->and_also( TrustedProxies::CLOUDFLARE ) )
		);
	}

	/**
	 * The header named is the one read, and no other.
	 */
	public function test_the_named_header_is_the_one_read(): void {
		$request = self::request(
			'10.0.0.5',
			[
				'HTTP_X_FORWARDED_FOR'  => self::LIAR,
				'HTTP_X_REAL_IP'        => self::VISITOR,
				'HTTP_CF_CONNECTING_IP' => '192.0.2.1',
			]
		);

		$this->assertSame( self::LIAR, ClientIp::resolve( $request, TrustedProxies::local() ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( $request, TrustedProxies::local()->using_headers( [ 'X-Real-IP' ] ) ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( $request, TrustedProxies::local()->using_headers( [ 'HTTP_X_REAL_IP' ] ) ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( $request, TrustedProxies::local()->using_headers( [ 'X-Missing', 'X-Real-IP', 'X-Forwarded-For' ] ) ) );

		// No header named: the connection.
		$this->assertSame( '10.0.0.5', ClientIp::resolve( $request, TrustedProxies::local()->using_headers( [] ) ) );
	}

	/**
	 * An entry that is not an address stops the reading: what is left of
	 * it was not written by a proxy that would have written that.
	 */
	public function test_a_malformed_entry_stops_the_walk(): void {
		$trust = TrustedProxies::local();
		$via   = static fn( string $chain ): ?string => ClientIp::resolve( self::request( '10.0.0.5', [ 'HTTP_X_FORWARDED_FOR' => $chain ] ), $trust );

		// Rubbish to the left of the visitor does not get the visitor thrown away.
		$this->assertSame( self::VISITOR, $via( 'unknown, ' . self::VISITOR ) );

		// Rubbish between the proxies and a claimed address: the claim is not reached.
		$this->assertSame( '10.0.0.9', $via( self::LIAR . ', unknown, 10.0.0.9' ) );
		$this->assertSame( '10.0.0.9', $via( self::LIAR . ', , 10.0.0.9' ) );
		$this->assertSame( '192.168.1.4', $via( self::LIAR . ', 999.1.1.1, 10.0.0.9, 192.168.1.4' ) );

		// Nothing usable at all: the connection.
		foreach ( [ '', 'unknown', 'not, an, address', ',,', self::LIAR . ', unknown', '203.0.113.9 10.0.0.1' ] as $value ) {
			$this->assertSame( '10.0.0.5', $via( $value ), $value );
		}
	}

	/**
	 * When every address in the header is a proxy of the site's own, the
	 * right-most is the one the nearest proxy wrote, and is the answer.
	 */
	public function test_when_every_hop_is_trusted_the_nearest_is_returned(): void {
		$trust = TrustedProxies::local();

		$this->assertSame( '10.0.0.9', ClientIp::resolve( self::request( '10.0.0.5', [ 'HTTP_X_FORWARDED_FOR' => '192.168.1.20, 10.0.0.9' ] ), $trust ) );
		$this->assertSame( '192.168.1.20', ClientIp::resolve( self::request( '127.0.0.1', [ 'HTTP_X_FORWARDED_FOR' => '192.168.1.20' ] ), $trust ) );

		// A request Cloudflare makes from one of its own addresses.
		$this->assertSame( '2a06:98c0:3600::103', ClientIp::resolve( self::request( self::CLOUDFLARE, [ 'HTTP_CF_CONNECTING_IP' => '2a06:98c0:3600::103' ] ), TrustedProxies::cloudflare() ) );
	}

	/**
	 * Reading from any address: the header is read whoever connected, and
	 * the list only says what is stepped over.
	 */
	public function test_from_any_address_reads_the_header_whoever_connected(): void {
		$trust = TrustedProxies::local()->from_any_address();

		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( '192.0.2.200', [ 'HTTP_X_FORWARDED_FOR' => self::LIAR . ', ' . self::VISITOR . ', 10.0.0.9' ] ), $trust ) );

		// With no connection address at all.
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( null, [ 'HTTP_X_FORWARDED_FOR' => self::VISITOR ] ), $trust ) );

		// A useless header falls back to the connection.
		foreach ( [ '', 'unknown', 'not, an, address', ',,' ] as $value ) {
			$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, [ 'HTTP_X_FORWARDED_FOR' => $value ] ), $trust ), $value );
		}

		// Only the named header, still.
		$this->assertSame( '192.0.2.200', ClientIp::resolve( self::request( '192.0.2.200', [ 'HTTP_X_REAL_IP' => self::LIAR ] ), $trust ) );

		// And it is the one policy under which a direct visitor is believed,
		// which is what its name says and why it is not the default.
		$this->assertSame( self::LIAR, ClientIp::resolve( self::request( self::VISITOR, [ 'HTTP_X_FORWARDED_FOR' => self::LIAR ] ), $trust ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( self::VISITOR, [ 'HTTP_X_FORWARDED_FOR' => self::LIAR ] ), TrustedProxies::local() ) );
	}

	/**
	 * No address is null, and rubbish is not an address.
	 */
	public function test_a_request_with_no_address_is_null(): void {
		foreach ( [ null, '', 'not-an-ip', '999.999.999.999', '<script>', '203.0.113.9, 10.0.0.1', '203.0.113.9:80' ] as $remote ) {
			$this->assertNull( ClientIp::resolve( self::request( $remote, self::lies() ) ), (string) $remote );
			$this->assertNull( ClientIp::resolve( self::request( $remote, self::lies() ), TrustedProxies::local() ), (string) $remote );
			$this->assertNull( ClientIp::direct( self::request( $remote ) ), (string) $remote );
		}

		$this->assertNull( ClientIp::resolve( [ 'REMOTE_ADDR' => [ '203.0.113.9' ] ] ) );
	}

	/**
	 * The address comes back as it was written.
	 */
	public function test_the_address_is_returned_as_written(): void {
		$long = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

		$this->assertSame( $long, ClientIp::resolve( self::request( $long ) ) );
		$this->assertSame( self::VISITOR, ClientIp::resolve( self::request( ' ' . self::VISITOR . ' ' ) ) );
		$this->assertSame( $long, ClientIp::resolve( self::request( '10.0.0.5', [ 'HTTP_X_FORWARDED_FOR' => $long ] ), TrustedProxies::local() ) );
	}

	/**
	 * One header's value, read on its own.
	 */
	public function test_from_header(): void {
		$trust = TrustedProxies::local();

		$this->assertSame( self::VISITOR, ClientIp::from_header( self::LIAR . ', ' . self::VISITOR . ', 10.0.0.9', $trust ) );
		$this->assertSame( '10.0.0.9', ClientIp::from_header( self::LIAR . ', ' . self::VISITOR . ', 10.0.0.9' ) );
		$this->assertSame( '10.0.0.9', ClientIp::from_header( '192.168.1.20, 10.0.0.9', $trust ) );
		$this->assertNull( ClientIp::from_header( '', $trust ) );
		$this->assertNull( ClientIp::from_header( self::VISITOR . ', unknown', $trust ) );
	}

	/**
	 * The reading both resolve() and IP::get() answer from.
	 */
	public function test_walk(): void {
		$trust = TrustedProxies::local();

		$this->assertSame(
			[
				'client'   => self::VISITOR,
				'nearest'  => '10.0.0.9',
				'furthest' => self::VISITOR,
			],
			ClientIp::walk( self::LIAR . ', ' . self::VISITOR . ', 192.168.1.4, 10.0.0.9', $trust )
		);

		$this->assertSame(
			[
				'client'   => null,
				'nearest'  => '10.0.0.9',
				'furthest' => '192.168.1.4',
			],
			ClientIp::walk( self::LIAR . ', unknown, 192.168.1.4, 10.0.0.9', $trust )
		);

		// The looser reading steps over what is not an address.
		$this->assertSame( self::LIAR, ClientIp::walk( self::LIAR . ', unknown, 192.168.1.4, 10.0.0.9', $trust, true )['client'] );
	}
}
