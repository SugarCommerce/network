<?php
/**
 * IP::get(), the older way of asking who a request is from.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * get() reads the chain with ClientIp's walker, and keeps the answers it
 * has always given: the cases of tests/client-ip.php and
 * tests/allow-private.php, and the ones that tell its policy from
 * ClientIp::resolve()'s.
 */
final class GetTest extends TestCase {

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
	 * A request, and what get() and get( true ) say about it.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string|null, 2: string|null}>
	 */
	public static function requests(): array {
		return [
			'spoofed CF-Connecting-IP is ignored'     => [ [ 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' ], '8.8.8.8', '8.8.8.8' ],
			'spoofed X-Real-IP is ignored'            => [ [ 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_REAL_IP' => '1.2.3.4' ], '8.8.8.8', '8.8.8.8' ],
			'spoofed X-Forwarded-For is ignored'      => [ [ 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], '8.8.8.8', '8.8.8.8' ],
			'spoofed Client-IP is ignored'            => [ [ 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_CLIENT_IP' => '1.2.3.4' ], '8.8.8.8', '8.8.8.8' ],
			'cloudflare: its header is honoured'      => [ [ 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'cloudflare: forwarded-for is honoured'   => [ [ 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'local proxy: forwarded-for is honoured'  => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'the right-most untrusted address wins'   => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 93.184.216.34, 10.0.0.5' ], '93.184.216.34', '93.184.216.34' ],
			'a pre-seeded hop is not reached'         => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'a port is dropped'                       => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34:51234' ], '93.184.216.34', '93.184.216.34' ],
			'bracketed ipv6 with a port'              => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '[2001:4860:4860::8888]:443' ], '2001:4860:4860::8888', '2001:4860:4860::8888' ],
			'forwarded-for before x-real-ip'          => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_X_REAL_IP' => '9.9.9.9' ], '1.1.1.1', '1.1.1.1' ],
			'x-real-ip without forwarded-for'         => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '9.9.9.9' ], '9.9.9.9', '9.9.9.9' ],
			'a direct public connection'              => [ [ 'REMOTE_ADDR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'a private connection'                    => [ [ 'REMOTE_ADDR' => '127.0.0.1' ], null, '127.0.0.1' ],
			'a docker bridge'                         => [ [ 'REMOTE_ADDR' => '172.17.0.1' ], null, '172.17.0.1' ],
			'no connection address'                   => [ [], null, null ],
			'a connection address that is not one'    => [ [ 'REMOTE_ADDR' => 'not-an-ip' ], null, null ],

			// Where its policy is its own, and not ClientIp::resolve()'s.
			'an entry that is not an address is skipped' => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34, unknown, 10.0.0.5' ], '93.184.216.34', '93.184.216.34' ],
			'every hop trusted: null, or client-most'    => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.50, 10.0.0.5' ], null, '192.168.1.50' ],
			'an intranet client behind a local proxy'    => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.50' ], null, '192.168.1.50' ],
			'a reserved client falls to the next header' => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '169.254.1.1', 'HTTP_X_REAL_IP' => '93.184.216.34' ], '93.184.216.34', '169.254.1.1' ],

			// A proxy of the site's own -- nginx, HAProxy, a load balancer on
			// a private address -- appends to X-Forwarded-For and passes on
			// the rest of what the visitor sent.
			'a visitor\'s CF-Connecting-IP through a local proxy'  => [ [ 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'a visitor\'s CF-Connecting-IP alone, through a proxy' => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6' ], null, '127.0.0.1' ],
			'a visitor\'s X-Real-IP through an appending proxy'    => [ [ 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REAL_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'a visitor\'s Client-IP through an appending proxy'    => [ [ 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_CLIENT_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ], '93.184.216.34', '93.184.216.34' ],
			'cloudflare: its header before forwarded-for'          => [ [ 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '93.184.216.34', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6' ], '93.184.216.34', '93.184.216.34' ],
			'cloudflare in front of a local proxy'                 => [ [ 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '93.184.216.34', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34, 104.16.0.1' ], '93.184.216.34', '93.184.216.34' ],
		];
	}

	/**
	 * Strict and permissive, for every request.
	 *
	 * @param array<string, string> $server     The request.
	 * @param string|null           $strict     What get() says.
	 * @param string|null           $permissive What get( true ) says.
	 */
	#[DataProvider( 'requests' )]
	public function test_get( array $server, ?string $strict, ?string $permissive ): void {
		$_SERVER = $server;

		$this->assertSame( $strict, IP::get() );
		$this->assertSame( $permissive, IP::get( true ) );
	}

	/**
	 * What get() trusts, for anything else to trust: CF-Connecting-IP is
	 * not among its headers, because the list mixes in proxies of the
	 * site's own, which pass that header on from whoever reached them.
	 */
	public function test_trust_does_not_name_cloudflare_s_header(): void {
		$this->assertSame( [ 'X-Forwarded-For', 'X-Real-IP', 'Client-IP' ], IP::trust()->headers );
	}

	/**
	 * The default list is this machine, private networks and Cloudflare.
	 */
	public function test_the_default_proxies(): void {
		foreach ( [ '127.0.0.1', '::1', '10.0.0.5', '172.16.0.1', '192.168.1.4', 'fd00::5', 'fc00::5', '104.16.0.1', '2606:4700::1111', '::ffff:10.0.0.5' ] as $ip ) {
			$this->assertTrue( IP::is_trusted_proxy( $ip ), $ip );
		}

		foreach ( [ '8.8.8.8', '203.0.113.9', '2001:db8::1', 'fe80::1', '', 'nonsense' ] as $ip ) {
			$this->assertFalse( IP::is_trusted_proxy( $ip ), $ip );
		}
	}
}
