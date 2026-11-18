<?php
/**
 * Cloudflare's headers, and who they are believed from.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Cloudflare;

use SugarCommerce\Network\Cloudflare;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * Class CloudflareTest
 */
final class CloudflareTest extends TestCase {

	/**
	 * An address inside Cloudflare's published ranges.
	 */
	private const EDGE = '104.16.0.1';

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
	 * Read from Cloudflare, every header answers.
	 */
	public function test_cloudflare_headers_are_read_from_cloudflare(): void {
		$cloudflare = new Cloudflare();
		$server     = [
			'REMOTE_ADDR'       => self::EDGE,
			'HTTP_CF_IPCOUNTRY' => 'gb',
			'HTTP_CF_RAY'       => '8a1b2c3d4e5f6789-LHR',
		];

		$this->assertTrue( $cloudflare->is_active( $server ) );
		$this->assertSame( 'GB', $cloudflare->country( $server ) );
		$this->assertSame( '8a1b2c3d4e5f6789-LHR', $cloudflare->ray_id( $server ) );
	}

	/**
	 * From anywhere else, none does: anyone can send the header.
	 */
	public function test_cloudflare_headers_are_ignored_from_elsewhere(): void {
		$cloudflare = new Cloudflare();
		$server     = [
			'REMOTE_ADDR'       => '8.8.8.8',
			'HTTP_CF_IPCOUNTRY' => 'T1',
			'HTTP_CF_RAY'       => '8a1b2c3d4e5f6789-LHR',
			'HTTP_CF_VISITOR'   => '{"scheme":"https"}',
		];

		$this->assertFalse( $cloudflare->is_active( $server ) );
		$this->assertNull( $cloudflare->country( $server ) );
		$this->assertFalse( $cloudflare->is_tor( $server ) );
		$this->assertNull( $cloudflare->ray_id( $server ) );
		$this->assertNull( $cloudflare->tls_version( $server ) );
	}

	/**
	 * T1 is Tor; a country is not.
	 */
	public function test_tor_exit_nodes_are_flagged(): void {
		$cloudflare = new Cloudflare();

		$this->assertTrue( $cloudflare->is_tor( [ 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_IPCOUNTRY' => 'T1' ] ) );
		$this->assertFalse( $cloudflare->is_tor( [ 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_IPCOUNTRY' => 'GB' ] ) );
		$this->assertFalse( $cloudflare->is_tor( [ 'REMOTE_ADDR' => self::EDGE ] ) );
	}

	/**
	 * What was IP::is_tor(): the live request, believed through the list
	 * IP::get() trusts, which includes a proxy on this machine.
	 */
	public function test_the_list_ip_get_trusts_can_be_handed_over(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';
		$_SERVER['REMOTE_ADDR']       = '203.0.113.9';

		$this->assertFalse( ( new Cloudflare( IP::trust() ) )->is_tor(), 'A header from a direct visitor was believed.' );

		$_SERVER['REMOTE_ADDR'] = '104.16.1.1';
		$this->assertTrue( ( new Cloudflare( IP::trust() ) )->is_tor() );

		// A local proxy is in IP::trust(), and not in Cloudflare's own list.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertTrue( ( new Cloudflare( IP::trust() ) )->is_tor() );
		$this->assertFalse( ( new Cloudflare() )->is_tor() );

		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'GB';
		$this->assertFalse( ( new Cloudflare( IP::trust() ) )->is_tor() );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['REMOTE_ADDR'] );
		$this->assertFalse( ( new Cloudflare( IP::trust() ) )->is_tor() );
	}

	/**
	 * A value not shaped like the thing is not the thing.
	 */
	public function test_malformed_headers_are_rejected(): void {
		$cloudflare = new Cloudflare( TrustedProxies::cloudflare() );
		$server     = [
			'REMOTE_ADDR'       => self::EDGE,
			'HTTP_CF_IPCOUNTRY' => 'NOT-A-COUNTRY',
			'HTTP_CF_RAY'       => 'nonsense',
		];

		$this->assertNull( $cloudflare->country( $server ) );
		$this->assertNull( $cloudflare->ray_id( $server ) );
		$this->assertNull( $cloudflare->ray_id( [ 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_RAY' => "8a1b2c3d4e5f6789-LHR\n<x>" ] ) );
	}

	/**
	 * CF-Visitor is JSON, and comes back as a short token or not at all.
	 */
	public function test_cf_visitor_is_reduced_to_a_token(): void {
		$cloudflare = new Cloudflare();
		$server     = [ 'REMOTE_ADDR' => self::EDGE ];

		$this->assertSame( 'https', $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ] ) );
		$this->assertSame( 'TLSv1.3', $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"https","tls":"TLSv1.3"}' ] ) );
		$this->assertNull( $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"<script>"}' ] ) );
		$this->assertNull( $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"https","tls":["x"]}' ] ) );
		$this->assertNull( $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"tls":"TLSv1.3"}' ] ) );
		$this->assertNull( $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"https\n"}' ] ) );
		$this->assertNull( $cloudflare->tls_version( $server + [ 'HTTP_CF_VISITOR' => '{"scheme":"' . str_repeat( 'a', 300 ) . '"}' ] ) );
	}

	/**
	 * WordPress slashes $_SERVER; the JSON has to be read unslashed.
	 */
	public function test_the_live_request_is_read_unslashed(): void {
		$_SERVER['REMOTE_ADDR']     = self::EDGE;
		$_SERVER['HTTP_CF_VISITOR'] = addslashes( '{"scheme":"https"}' );

		$this->assertSame( 'https', ( new Cloudflare() )->tls_version() );
	}

	/**
	 * An array where a header should be is nothing.
	 */
	public function test_non_string_values_are_ignored(): void {
		$this->assertFalse( ( new Cloudflare() )->is_active( [ 'REMOTE_ADDR' => [ self::EDGE ] ] ) );
		$this->assertNull( ( new Cloudflare() )->country( [ 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_IPCOUNTRY' => [ 'GB' ] ] ) );
	}
}
