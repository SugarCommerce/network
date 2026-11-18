<?php
/**
 * Ports, typed domains, label boundaries and loopback.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Host;

use SugarCommerce\Network\Host;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class HostTest
 */
final class HostTest extends TestCase {

	/**
	 * The port comes off, and brackets with it.
	 *
	 * @param string $host     Given.
	 * @param string $expected Wanted.
	 */
	#[DataProvider( 'ports' )]
	public function test_the_port_comes_off( string $host, string $expected ): void {
		$this->assertSame( $expected, Host::without_port( $host ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function ports(): array {
		return [
			'a name'                => [ 'shop.example.com', 'shop.example.com' ],
			'a name and port'       => [ 'shop.example.com:8443', 'shop.example.com' ],
			'v4 and port'           => [ '127.0.0.1:8080', '127.0.0.1' ],
			'bracketed v6 and port' => [ '[::1]:8080', '::1' ],
			'bracketed v6'          => [ '[::1]', '::1' ],
			'bare v6 is left alone' => [ '2001:db8::1', '2001:db8::1' ],
			'an unclosed bracket'   => [ '[::1', '[::1' ],
			'case is kept'          => [ 'Shop.Example.com:80', 'Shop.Example.com' ],
			'whitespace'            => [ ' localhost:8099 ', 'localhost' ],
		];
	}

	/**
	 * A typed domain is cleaned, or refused.
	 *
	 * @param string $typed    Given.
	 * @param string $expected Wanted.
	 */
	#[DataProvider( 'typed' )]
	public function test_a_typed_domain_is_cleaned_or_refused( string $typed, string $expected ): void {
		$this->assertSame( $expected, Host::normalize( $typed ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function typed(): array {
		return [
			'a name'            => [ 'shop.example.com', 'shop.example.com' ],
			'a pasted URL'      => [ 'https://Shop.Example.com/some/page?x=1#top', 'shop.example.com' ],
			'a port'            => [ 'shop.example.com:8443', 'shop.example.com' ],
			'a trailing dot'    => [ 'shop.example.com.', 'shop.example.com' ],
			'an address'        => [ 'pat@Example.com', 'example.com' ],
			'an at and domain'  => [ '@example.com', 'example.com' ],
			'a single label'    => [ 'localhost', '' ],
			'an IPv4 address'   => [ '203.0.113.5', '' ],
			'an IPv6 address'   => [ '[2001:db8::1]', '' ],
			'words'             => [ 'not a domain', '' ],
			'a leading hyphen'  => [ '-shop.example.com', '' ],
			'a trailing hyphen' => [ 'shop.example.co-', '' ],
			'a label too long'  => [ str_repeat( 'a', 64 ) . '.com', '' ],
			'too long overall'  => [ str_repeat( str_repeat( 'a', 60 ) . '.', 5 ) . 'com', '' ],
			'a numeric tld'     => [ 'shop.example.123', '' ],
			'empty'             => [ '', '' ],
			'a newline'         => [ "shop.example.com\n.", '' ],
			'a backslash'       => [ 'evil.com\\.example.com', '' ],
			'punycode'          => [ 'xn--bcher-kva.example', 'xn--bcher-kva.example' ],
			'unicode'           => [ 'bücher.example', '' ],
		];
	}

	/**
	 * Within is on a label boundary.
	 */
	public function test_within_is_on_a_label_boundary(): void {
		$this->assertTrue( Host::is_within( 'example.com', 'example.com' ) );
		$this->assertTrue( Host::is_within( 'Shop.Example.com:443', 'example.com' ) );
		$this->assertTrue( Host::is_within( 'a.b.example.com.', 'EXAMPLE.com' ) );
		$this->assertFalse( Host::is_within( 'evil-example.com', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'example.com.evil.net', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'microsoft.com', 't.co' ) );
		$this->assertFalse( Host::is_within( '', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'example.com', '' ) );
	}

	/**
	 * A host parse_url() would accept, and a browser would not go to, is
	 * refused rather than compared.
	 */
	public function test_within_refuses_a_host_no_browser_would_go_to(): void {
		$this->assertFalse( Host::is_within( 'evil.com\\.example.com', 'example.com' ) );
		$this->assertFalse( Host::is_within( 'evil.com/.example.com', 'example.com' ) );
		$this->assertFalse( Host::is_within( "evil.com\n.example.com", 'example.com' ) );
		$this->assertTrue( Host::is_within( '[2001:db8::1]:443', '2001:db8::1' ) );
	}

	/**
	 * Loopback is the whole host, and only loopback.
	 *
	 * @param string $host     Given.
	 * @param bool   $expected Wanted.
	 */
	#[DataProvider( 'loopbacks' )]
	public function test_loopback( string $host, bool $expected ): void {
		$this->assertSame( $expected, Host::is_loopback( $host ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function loopbacks(): array {
		return [
			'localhost'                   => [ 'localhost', true ],
			'localhost, any case'         => [ 'LocalHost', true ],
			'localhost and port'          => [ 'localhost:8080', true ],
			'whitespace'                  => [ ' localhost:8099 ', true ],
			'a name under .localhost'     => [ 'app.localhost', true ],
			'deeper under .localhost'     => [ 'a.b.localhost:3000', true ],
			'v4 loopback'                 => [ '127.0.0.1', true ],
			'v4 loopback and port'        => [ '127.0.0.1:8000', true ],
			'all of 127/8'                => [ '127.255.0.9', true ],
			'bracketed v6'                => [ '[::1]', true ],
			'bracketed v6 and port'       => [ '[::1]:8080', true ],
			'bare v6'                     => [ '::1', true ],
			'v6 spelt long'               => [ '[0:0:0:0:0:0:0:1]', true ],
			'v4 loopback mapped into v6'  => [ '[::ffff:127.0.0.1]:80', true ],
			'highest port'                => [ 'localhost:65535', true ],
			'somebody else\'s name'       => [ 'localhost.evil.example', false ],
			'a name ending localhost'     => [ 'evillocalhost', false ],
			'a v4-shaped name'            => [ '127.0.0.1.nip.io', false ],
			'trailing dot'                => [ 'localhost.', false ],
			'empty label'                 => [ '.localhost', false ],
			'bad label'                   => [ '-x.localhost', false ],
			'a port that is not one'      => [ 'localhost:abc', false ],
			'an empty port'               => [ 'localhost:', false ],
			'a port too high'             => [ '127.0.0.1:65536', false ],
			'junk after the bracket'      => [ '[::1]x', false ],
			'an unclosed bracket'         => [ '[::1', false ],
			'a stray bracket'             => [ '127.0.0.1]', false ],
			'v4 in brackets'              => [ '[127.0.0.1]', false ],
			'shorthand v4'                => [ '127.1', false ],
			'hex v4'                      => [ '0x7f.0.0.1', false ],
			'another v4'                  => [ '128.0.0.1', false ],
			'unspecified'                 => [ '0.0.0.0', false ],
			'another v6'                  => [ '[::2]', false ],
			'v6 unspecified'              => [ '::', false ],
			'NAT64 spelling of 127.0.0.1' => [ '[64:ff9b::7f00:1]', false ],
			'mapped, not loopback'        => [ '::ffff:10.0.0.1', false ],
			'a public name'               => [ 'example.com', false ],
			'empty'                       => [ '', false ],
			'userinfo'                    => [ 'user@localhost', false ],
		];
	}
}
