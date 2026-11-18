<?php
/**
 * Reading $_SERVER one way.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Server;

use SugarCommerce\Network\Server;
use PHPUnit\Framework\TestCase;

/**
 * Class ServerTest
 */
final class ServerTest extends TestCase {

	/**
	 * A stand-in is used as it is; null is the live request, unslashed.
	 */
	public function test_the_live_request_is_unslashed_and_a_stand_in_is_not(): void {
		$saved                  = $_SERVER;
		$_SERVER['HTTP_X_TEST'] = addslashes( 'a "b"' );

		try {
			$this->assertSame( 'a "b"', Server::get()['HTTP_X_TEST'] );
			$this->assertSame( [ 'HTTP_X_TEST' => 'a\\"b' ], Server::get( [ 'HTTP_X_TEST' => 'a\\"b' ] ) );
		} finally {
			$_SERVER = $saved;
		}
	}

	/**
	 * A value is trimmed text, and anything else is nothing.
	 */
	public function test_a_value_is_text_or_nothing(): void {
		$server = [
			'A' => ' x ',
			'B' => [ 'x' ],
			'C' => 7,
			'D' => null,
		];

		$this->assertSame( 'x', Server::value( $server, 'A' ) );
		$this->assertSame( '', Server::value( $server, 'B' ) );
		$this->assertSame( '7', Server::value( $server, 'C' ) );
		$this->assertSame( '', Server::value( $server, 'D' ) );
		$this->assertSame( '', Server::value( $server, 'E' ) );
	}

	/**
	 * A header is looked up by the name it was sent under.
	 */
	public function test_a_header_is_read_by_its_name(): void {
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', Server::key( 'CF-Connecting-IP' ) );
		$this->assertSame( 'HTTP_X_REAL_IP', Server::key( ' x-real-ip ' ) );
		$this->assertSame( 'GB', Server::header( [ 'HTTP_CF_IPCOUNTRY' => 'GB' ], 'CF-IPCountry' ) );
	}
}
