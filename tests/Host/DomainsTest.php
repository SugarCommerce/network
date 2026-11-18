<?php
/**
 * The domain half of an address, taken apart.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Host;

use SugarCommerce\Network\Email;
use SugarCommerce\Network\Host;
use PHPUnit\Framework\TestCase;

/**
 * Class DomainsTest
 */
final class DomainsTest extends TestCase {

	/**
	 * Whatever is pasted where a domain is wanted, the host comes out.
	 */
	public function test_from(): void {
		$hosts = [
			'example.com'                          => 'example.com',
			' Example.COM '                        => 'example.com',
			'example.com.'                         => 'example.com',
			'.example.com'                         => 'example.com',
			'@example.com'                         => 'example.com',
			'*@example.com'                        => 'example.com',
			'pat@Example.com'                      => 'example.com',
			'Pat <pat@mail.example.com'            => 'mail.example.com',
			'a..b@spam.example'                    => 'spam.example',
			'a@b@example.com'                      => 'example.com',
			'https://example.com'                  => 'example.com',
			'https://Example.com/pricing?x=1#top'  => 'example.com',
			'http://example.com:8080/a/b'          => 'example.com',
			'ftp://user@example.com/file'          => 'example.com',
			'https://example.com/@pat'             => 'example.com',
			'example.com/pricing'                  => 'example.com',
			'example.com:8080'                     => 'example.com',
			'example.com?x=1'                      => 'example.com',
			'example.com#top'                      => 'example.com',
			'mail.shady.example.co.uk'             => 'mail.shady.example.co.uk',
			'xn--bcher-kva.example'                => 'xn--bcher-kva.example',
			'localhost'                            => 'localhost',
			'xyz'                                  => 'xyz',
			'.xyz'                                 => 'xyz',
			''                                     => '',
			' '                                    => '',
			'pat@'                                 => '',
			'https://'                             => '',
			'...'                                  => '',
		];

		foreach ( $hosts as $typed => $host ) {
			$this->assertSame( $host, Host::from( (string) $typed ), (string) $typed );
		}

		// Nothing is converted: an internationalised name stays as it was typed.
		$this->assertSame( 'bücher.example', Host::from( 'pat@bücher.example' ) );
	}

	/**
	 * An address is at its own domain and at every one above it.
	 */
	public function test_parents(): void {
		$this->assertSame(
			[ 'a.b.example.co.uk', 'b.example.co.uk', 'example.co.uk', 'co.uk' ],
			Host::parents( 'pat@a.b.example.co.uk' )
		);

		$this->assertSame( [ 'mail.shady.example', 'shady.example' ], Host::parents( 'Pat@Mail.Shady.Example' ) );
		$this->assertSame( [ 'example.com' ], Host::parents( 'pat@example.com' ) );
		$this->assertSame( [ 'mail.example.com', 'example.com' ], Host::parents( 'mail.example.com' ) );
		$this->assertSame( [ 'example.com' ], Host::parents( '@example.com' ) );

		// Too odd to be an address, and still at a domain.
		$this->assertSame( [ 'spam.example' ], Host::parents( 'a..b@spam.example' ) );
		$this->assertSame( [ 'spam.example' ], Host::parents( 'a@b@spam.example' ) );
		$this->assertSame( [ 'mail.spam.example', 'spam.example' ], Host::parents( 'pat@mail..spam.example.' ) );

		// One label is an ending, not a domain.
		foreach ( [ 'pat@localhost', 'xyz', '', 'pat@', '@', '...' ] as $value ) {
			$this->assertSame( [], Host::parents( $value ), $value );
		}
	}

	/**
	 * Every ending short of the whole domain, shortest first.
	 */
	public function test_suffixes(): void {
		$this->assertSame(
			[ 'uk', 'co.uk', 'example.co.uk', 'b.example.co.uk' ],
			Host::suffixes( 'pat@a.b.example.co.uk' )
		);

		$this->assertSame( [ 'com' ], Host::suffixes( 'Pat@Example.COM' ) );
		$this->assertSame( [ 'uk', 'co.uk' ], Host::suffixes( 'example.co.uk' ) );
		$this->assertSame( [ 'example' ], Host::suffixes( 'a..b@spam.example' ) );
		$this->assertSame( [ 'xn--p1ai' ], Host::suffixes( 'pat@example.xn--p1ai' ) );

		// The whole of a domain is not an ending of it.
		foreach ( [ 'pat@localhost', 'xyz', '', 'pat@' ] as $value ) {
			$this->assertSame( [], Host::suffixes( $value ), $value );
		}
	}

	/**
	 * A parent and a suffix of every address agree with a rule matched the slow way.
	 */
	public function test_parents_and_suffixes_agree_with_pattern_matching(): void {
		foreach ( [ 'pat@example.com', 'pat@mail.example.co.uk', 'a.b+c@x.y.z.example.org' ] as $address ) {
			$email = Email::parse( $address );

			$this->assertNotNull( $email );
			$this->assertSame( $email->domain(), Host::from( $address ) );
			$this->assertSame( $email->domain(), Host::parents( $address )[0] );
			$this->assertSame( $email->tld(), Host::suffixes( $address )[0] );

			foreach ( Host::parents( $address ) as $parent ) {
				$this->assertTrue( $email->matches_pattern( $parent ), $parent );
			}

			foreach ( Host::suffixes( $address ) as $suffix ) {
				$this->assertTrue( $email->matches_pattern( '.' . $suffix ), $suffix );
			}
		}
	}

	/**
	 * The shape of a host name.
	 */
	public function test_is_hostname(): void {
		foreach ( [ 'example.com', 'a.b.example.co.uk', 'localhost', 'xyz', 'xn--bcher-kva.example', 'a-b.example', '1.example', '127.0.0.1', str_repeat( 'a', 63 ) . '.example', implode( '.', array_fill( 0, 126, 'a' ) ) . '.b' ] as $name ) {
			$this->assertTrue( Host::is_hostname( $name ), $name );
		}

		$wrong = [
			'',
			'.',
			'example.com.',
			'.example.com',
			'example..com',
			'-example.com',
			'example-.com',
			'example.-com',
			'Example.com',
			'exa mple.com',
			'exa_mple.com',
			'bücher.example',
			'pat@example.com',
			'example.com/x',
			'example.com:80',
			"example.com\n",
			str_repeat( 'a', 64 ) . '.example',
			implode( '.', array_fill( 0, 127, 'a' ) ) . '.b',
		];

		foreach ( $wrong as $name ) {
			$this->assertFalse( Host::is_hostname( $name ), $name );
		}
	}
}
