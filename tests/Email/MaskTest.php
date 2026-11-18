<?php
/**
 * Masking for a list, and an address kept as it was typed.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Email;

use SugarCommerce\Network\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class MaskTest
 */
final class MaskTest extends TestCase {

	/**
	 * An admin list's mask: two characters kept, at least two bullets, one
	 * per hidden character, split at the last @, and anything that is not
	 * an address shown as stored.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function listed(): array {
		return [
			'george'         => [ 'george@gmail.com', "ge\u{2022}\u{2022}\u{2022}\u{2022}@gmail.com" ],
			'one character'  => [ 'a@example.com', "a\u{2022}\u{2022}@example.com" ],
			'long'           => [ 'david.sherlock@x.co.uk', 'da' . str_repeat( "\u{2022}", 12 ) . '@x.co.uk' ],
			'two characters' => [ 'ab@x', "ab\u{2022}\u{2022}@x" ],
			'last @'         => [ 'a@b@c.com', "a@\u{2022}\u{2022}@c.com" ],
			'multibyte'      => [ 'jörg@x.de', "jö\u{2022}\u{2022}@x.de" ],
			'no @'           => [ 'nope', 'nope' ],
			'nothing before' => [ '@x.com', '@x.com' ],
			'nothing after'  => [ 'pat@', 'pat@' ],
			'empty'          => [ '', '' ],
		];
	}

	/**
	 * The list mask.
	 *
	 * @param string $email    Given.
	 * @param string $expected Wanted.
	 */
	#[DataProvider( 'listed' )]
	public function test_the_list_mask( string $email, string $expected ): void {
		$this->assertSame( $expected, Email::mask( $email, keep: 2, show_length: true, keep_invalid: true ) );
	}

	/**
	 * Without keep_invalid a non-address is '', as it always was; the
	 * default mask is unchanged.
	 */
	public function test_the_defaults_are_unchanged(): void {
		$this->assertSame( '', Email::mask( 'nope' ) );
		$this->assertSame( '', Email::mask( '@x.com' ) );
		$this->assertSame( "p\u{2022}\u{2022}@example.com", Email::mask( 'patricia@example.com' ) );
		$this->assertSame( "a\u{2022}\u{2022}@example.com", Email::mask( 'a@b@example.com' ) );
	}

	/**
	 * Without show_length the run stays fixed, whatever is kept.
	 */
	public function test_keep_alone_does_not_give_the_length_away(): void {
		$this->assertSame( Email::mask( 'pa@example.com', keep: 2 ), Email::mask( 'pat@example.com', keep: 2 ) );
		$this->assertSame( "pa\u{2022}\u{2022}@example.com", Email::mask( 'patricia@example.com', keep: 2 ) );
	}

	/**
	 * clean() keeps the name's case and lowers the domain's.
	 */
	public function test_clean_lower_cases_only_the_domain(): void {
		$this->assertSame( 'Jordan@example.com', Email::clean( 'Jordan@EXAMPLE.COM' ) );
		$this->assertSame( 'a.b@example.com', Email::clean( '  a.b@example.com  ' ) );
	}

	/**
	 * Anything else is ''.
	 */
	public function test_clean_refuses_what_is_not_an_address(): void {
		foreach ( [ 'not-an-address', 'two@at@signs.com', '', '@example.com', null, [ 'a@example.com' ], 42 ] as $value ) {
			$this->assertSame( '', Email::clean( $value ), var_export( $value, true ) );
		}
	}

	/**
	 * The first 254 characters of a longer string are a different address.
	 */
	public function test_clean_refuses_an_address_too_long_rather_than_cutting_it(): void {
		$long = str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 63 ) . '.example.org';

		$this->assertGreaterThan( 254, strlen( $long ) );
		$this->assertSame( '', Email::clean( $long ) );
	}
}
