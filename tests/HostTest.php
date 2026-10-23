<?php
/**
 * Host test suite.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\{Host, Ip};

final class HostTest extends TestCase {

	/* ─── Ports ─────────────────────────────────────────────────────── */

	#[DataProvider( 'ports' )]
	public function test_the_port_comes_off( string $host, string $expected ): void {
		$this->assertSame( $expected, Host::without_port( $host ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function ports(): array {
		return array(
			'a name'                 => array( 'shop.example.com', 'shop.example.com' ),
			'a name and port'        => array( 'shop.example.com:8443', 'shop.example.com' ),
			'v4 and port'            => array( '127.0.0.1:8080', '127.0.0.1' ),
			'bracketed v6 and port'  => array( '[::1]:8080', '::1' ),
			'bracketed v6'           => array( '[::1]', '::1' ),
			'bare v6 is left alone'  => array( '2001:db8::1', '2001:db8::1' ),
			'an unclosed bracket'    => array( '[::1', '[::1' ),
			'case is kept'           => array( 'Shop.Example.com:80', 'Shop.Example.com' ),
			'whitespace'             => array( ' localhost:8099 ', 'localhost' ),
		);
	}

	/* ─── Typed domains ─────────────────────────────────────────────── */

	#[DataProvider( 'typed' )]
	public function test_a_typed_domain_is_cleaned_or_refused( string $typed, string $expected ): void {
		$this->assertSame( $expected, Host::normalize( $typed ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function typed(): array {
		return array(
			'a name'             => array( 'shop.example.com', 'shop.example.com' ),
			'a pasted URL'       => array( 'https://Shop.Example.com/some/page?x=1#top', 'shop.example.com' ),
			'a port'             => array( 'shop.example.com:8443', 'shop.example.com' ),
			'a trailing dot'     => array( 'shop.example.com.', 'shop.example.com' ),
			'a single label'     => array( 'localhost', '' ),
			'an IPv4 address'    => array( '203.0.113.5', '' ),
			'an IPv6 address'    => array( '[2001:db8::1]', '' ),
			'words'              => array( 'not a domain', '' ),
			'a leading hyphen'   => array( '-shop.example.com', '' ),
			'a trailing hyphen'  => array( 'shop.example.co-', '' ),
			'a label too long'   => array( str_repeat( 'a', 64 ) . '.com', '' ),
			'too long overall'   => array( str_repeat( str_repeat( 'a', 60 ) . '.', 5 ) . 'com', '' ),
			'a numeric tld'      => array( 'shop.example.123', '' ),
			'empty'              => array( '', '' ),
			'punycode'           => array( 'xn--bcher-kva.example', 'xn--bcher-kva.example' ),
		);
	}

	/* ─── Within ────────────────────────────────────────────────────── */

	public function test_within_is_on_a_label_boundary(): void {
		$this->assertTrue( Host::is_within( 'example.com', 'example.com' ) );
		$this->assertTrue( Host::is_within( 'Shop.Example.com:443', 'example.com' ) );
		$this->assertTrue( Host::is_within( 'a.b.example.com.', 'EXAMPLE.com' ) );
		$this->assertFalse( Host::is_within( 'evil-example.com', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'example.com.evil.net', 'example.com' ) );
		$this->assertFalse( Host::is_within( '', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'example.com', '' ) );
	}

	/* ─── Anonymised networks ───────────────────────────────────────── */

	#[DataProvider( 'networks' )]
	public function test_an_anonymised_network_says_its_size( string $ip, string $expected ): void {
		$this->assertSame( $expected, Ip::anonymized_cidr( $ip ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function networks(): array {
		return array(
			'v4'                   => array( '203.0.113.42', '203.0.113.0/24' ),
			'v4 already anonymous' => array( '203.0.113.0', '203.0.113.0/24' ),
			'v6'                   => array( '2001:db8:85a3:1234::1', '2001:db8:85a3::/48' ),
			'v6 bracketed'         => array( '[2001:db8::1]', '2001:db8::/48' ),
			'not an address'       => array( 'nope', '' ),
			'empty'                => array( '', '' ),
		);
	}

	/* ─── Loopback ──────────────────────────────────────────────────── */

	#[DataProvider( 'loopbacks' )]
	public function test_loopback( string $host, bool $expected ): void {
		$this->assertSame( $expected, Host::is_loopback( $host ) );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function loopbacks(): array {
		return array(
			'localhost'                   => array( 'localhost', true ),
			'localhost, any case'         => array( 'LocalHost', true ),
			'localhost and port'          => array( 'localhost:8080', true ),
			'whitespace'                  => array( ' localhost:8099 ', true ),
			'a name under .localhost'     => array( 'app.localhost', true ),
			'deeper under .localhost'     => array( 'a.b.localhost:3000', true ),
			'v4 loopback'                 => array( '127.0.0.1', true ),
			'v4 loopback and port'        => array( '127.0.0.1:8000', true ),
			'all of 127/8'                => array( '127.255.0.9', true ),
			'bracketed v6'                => array( '[::1]', true ),
			'bracketed v6 and port'       => array( '[::1]:8080', true ),
			'bare v6'                     => array( '::1', true ),
			'v6 spelt long'               => array( '[0:0:0:0:0:0:0:1]', true ),
			'v4 loopback mapped into v6'  => array( '[::ffff:127.0.0.1]:80', true ),
			'highest port'                => array( 'localhost:65535', true ),
			'somebody else\'s name'       => array( 'localhost.evil.example', false ),
			'a name ending localhost'     => array( 'evillocalhost', false ),
			'a v4-shaped name'            => array( '127.0.0.1.nip.io', false ),
			'trailing dot'                => array( 'localhost.', false ),
			'empty label'                 => array( '.localhost', false ),
			'bad label'                   => array( '-x.localhost', false ),
			'a port that is not one'      => array( 'localhost:abc', false ),
			'an empty port'               => array( 'localhost:', false ),
			'a port too high'             => array( '127.0.0.1:65536', false ),
			'junk after the bracket'      => array( '[::1]x', false ),
			'an unclosed bracket'         => array( '[::1', false ),
			'a stray bracket'             => array( '127.0.0.1]', false ),
			'v4 in brackets'              => array( '[127.0.0.1]', false ),
			'shorthand v4'                => array( '127.1', false ),
			'hex v4'                      => array( '0x7f.0.0.1', false ),
			'another v4'                  => array( '128.0.0.1', false ),
			'unspecified'                 => array( '0.0.0.0', false ),
			'another v6'                  => array( '[::2]', false ),
			'v6 unspecified'              => array( '::', false ),
			'NAT64 spelling of 127.0.0.1' => array( '[64:ff9b::7f00:1]', false ),
			'mapped, not loopback'        => array( '::ffff:10.0.0.1', false ),
			'a public name'               => array( 'example.com', false ),
			'empty'                       => array( '', false ),
			'userinfo'                    => array( 'user@localhost', false ),
		);
	}
}
