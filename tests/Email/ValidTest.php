<?php
/**
 * Email::is_valid(): arraypress/email-validator's is_valid_email().
 *
 * Every case from the old library's suite, which is what an address at the
 * door was held to.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Email;

use SugarCommerce\Network\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ValidTest
 */
final class ValidTest extends TestCase {

	/**
	 * Addresses that are valid.
	 *
	 * @param string $email The address.
	 */
	#[DataProvider( 'validAddresses' )]
	public function test_valid( string $email ): void {
		$this->assertTrue( Email::is_valid( $email ) );
	}

	/**
	 * Addresses that are not.
	 *
	 * @param string $email The address.
	 */
	#[DataProvider( 'invalidAddresses' )]
	public function test_invalid( string $email ): void {
		$this->assertFalse( Email::is_valid( $email ) );
	}

	/**
	 * The rules is_email() leaves out are the ones this adds: each of these
	 * passes is_email() and fails here.
	 *
	 * @param string $email The address.
	 */
	#[DataProvider( 'stricterThanCore' )]
	public function test_stricter_than_is_email( string $email ): void {
		$this->assertNotFalse( is_email( $email ) );
		$this->assertFalse( Email::is_valid( $email ) );
	}

	/**
	 * The limits are where they say they are.
	 */
	public function test_the_limits_are_inclusive(): void {
		$this->assertTrue( Email::is_valid( str_repeat( 'a', 64 ) . '@example.com' ) );
		$this->assertTrue( Email::is_valid( str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 185 ) . '.com' ) );   // 254
		$this->assertFalse( Email::is_valid( str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 186 ) . '.com' ) );  // 255
		$this->assertTrue( Email::is_valid( 'pat@example.io' ) );
		$this->assertTrue( Email::is_valid( '  pat@example.com  ' ) );
	}

	/**
	 * Valid, from the old suite.
	 *
	 * @return array
	 */
	public static function validAddresses(): array {
		return [
			'simple'              => [ 'test@example.com' ],
			'with dot'            => [ 'user.name@domain.org' ],
			'with plus'           => [ 'user+tag@gmail.com' ],
			'minimum length'      => [ 'a@b.co' ],
			'subdomain'           => [ 'test@sub.domain.com' ],
			'numbers in local'    => [ 'user123@example.com' ],
			'hyphen in domain'    => [ 'test@my-domain.com' ],
			'multiple subdomains' => [ 'test@a.b.c.example.com' ],
			'special chars local' => [ 'user!#$%@example.com' ],
			'underscore local'    => [ 'user_name@example.com' ],
			'long tld'            => [ 'test@example.museum' ],
			'mixed case'          => [ 'Test@Example.COM' ],
			'apostrophe local'    => [ "o'connor@example.com" ],
			'all numbers local'   => [ '12345@example.com' ],
			'equals sign local'   => [ 'user=name@example.com' ],
			'caret local'         => [ 'user^name@example.com' ],
			'backtick local'      => [ 'user`name@example.com' ],
			'curly braces local'  => [ 'user{name}@example.com' ],
			'pipe local'          => [ 'user|name@example.com' ],
			'tilde local'         => [ 'user~name@example.com' ],
		];
	}

	/**
	 * Invalid, from the old suite.
	 *
	 * @return array
	 */
	public static function invalidAddresses(): array {
		return [
			'empty'                   => [ '' ],
			'no at'                   => [ 'testexample.com' ],
			'no domain'               => [ 'test@' ],
			'no local'                => [ '@domain.com' ],
			'double at'               => [ 'test@@domain.com' ],
			'no tld'                  => [ 'test@domain' ],
			'leading dot domain'      => [ 'test@.domain.com' ],
			'trailing dot domain'     => [ 'test@domain.com.' ],
			'consecutive dots domain' => [ 'test@domain..com' ],
			'leading dot local'       => [ '.test@domain.com' ],
			'trailing dot local'      => [ 'test.@domain.com' ],
			'consecutive dots local'  => [ 'te..st@domain.com' ],
			'leading hyphen domain'   => [ 'test@-domain.com' ],
			'trailing hyphen domain'  => [ 'test@domain-.com' ],
			'short tld'               => [ 'test@domain.c' ],
			'numeric tld'             => [ 'test@domain.123' ],
			'local too long'          => [ str_repeat( 'a', 65 ) . '@test.com' ],
			'too short'               => [ 'a@b.c' ],
			'too long'                => [ str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 190 ) . '.com' ],
			'space in local'          => [ 'te st@domain.com' ],
			'space in domain'         => [ 'test@dom ain.com' ],
			'multiple at'             => [ 'test@test@domain.com' ],
			'newline in email'        => [ "test\n@domain.com" ],
			'tab in email'            => [ "test\t@domain.com" ],
			'null byte'               => [ "test\0@domain.com" ],
			'unicode local'           => [ 'tëst@domain.com' ],
			'unicode domain'          => [ 'test@dömain.com' ],
			'only at'                 => [ '@' ],
			'only dots'               => [ '...@.....' ],
			'brackets local'          => [ 'test[1]@domain.com' ],
			'parentheses local'       => [ 'test(1)@domain.com' ],
			'comma local'             => [ 'test,user@domain.com' ],
			'semicolon local'         => [ 'test;user@domain.com' ],
			'colon local'             => [ 'test:user@domain.com' ],
			'backslash local'         => [ 'test\\user@domain.com' ],
			'quote local'             => [ 'test"user@domain.com' ],
			'less than local'         => [ 'test<user@domain.com' ],
			'greater than local'      => [ 'test>user@domain.com' ],
		];
	}

	/**
	 * Accepted by is_email(), refused here.
	 *
	 * @return array
	 */
	public static function stricterThanCore(): array {
		return [
			'leading dot local'      => [ '.test@domain.com' ],
			'trailing dot local'     => [ 'test.@domain.com' ],
			'consecutive dots local' => [ 'te..st@domain.com' ],
			'numeric tld'            => [ 'test@domain.123' ],
			'one-letter tld'         => [ 'test@domain.c' ],
			'name ends in new line'  => [ "test\n@domain.com" ],
			'local too long'         => [ str_repeat( 'a', 65 ) . '@test.com' ],
			'too long'               => [ str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 190 ) . '.com' ],
		];
	}
}
