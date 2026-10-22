<?php
/**
 * Blocklist tests.
 *
 * @package SugarCommerce\Network\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\Blocklist;

/**
 * Class BlocklistTest
 */
final class BlocklistTest extends TestCase {

	private function rules(): Blocklist {
		return new Blocklist( static fn( string $ip ): string => 'h:' . $ip );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function typed(): array {
		return array(
			'ipv4'                 => array( 'ip', ' 203.0.113.9 ', 'h:203.0.113.9' ),
			'ipv6'                 => array( 'ip', '2001:db8::1', 'h:2001:db8::1' ),
			'bracketed ipv6'       => array( 'ip', '[2001:db8::1]', 'h:2001:db8::1' ),
			'not an ip'            => array( 'ip', '203.0.113', '' ),
			'cidr is not one ip'   => array( 'ip', '203.0.113.0/24', '' ),
			'email'                => array( 'email', ' Bad.Actor@Example.COM ', 'bad.actor@example.com' ),
			'not an email'         => array( 'email', 'bad.actor', '' ),
			'domain'               => array( 'email_domain', ' @Spam.Example ', '@spam.example' ),
			'domain without @'     => array( 'email_domain', 'spam.example', '' ),
			'single label'         => array( 'email_domain', '@localhost', '' ),
			'ip as domain'         => array( 'email_domain', '@203.0.113.9', '' ),
			'just @'               => array( 'email_domain', '@', '' ),
			'unknown type'         => array( 'phone', '0800', '' ),
		);
	}

	#[DataProvider( 'typed' )]
	public function test_a_typed_pattern_becomes_its_stored_form( string $type, string $typed, string $stored ): void {
		$this->assertSame( $stored, $this->rules()->pattern( $type, $typed ) );
	}

	public function test_a_visitor_is_checked_by_ip_then_email_then_domain(): void {
		$this->assertSame(
			array(
				'ip'           => 'h:203.0.113.9',
				'email'        => 'someone@spam.example',
				'email_domain' => '@spam.example',
			),
			$this->rules()->keys( '203.0.113.9', 'Someone@Spam.Example' )
		);
	}

	public function test_missing_or_invalid_parts_add_nothing(): void {
		$this->assertSame( array( 'ip' => 'h:203.0.113.9' ), $this->rules()->keys( '203.0.113.9', null ) );
		$this->assertSame( array( 'ip' => 'h:203.0.113.9' ), $this->rules()->keys( '203.0.113.9', 'not-an-email' ) );
		$this->assertSame( array(), $this->rules()->keys( 'unknown', '' ) );
		$this->assertSame( array(), $this->rules()->keys( null ) );
	}

	public function test_what_pattern_stores_is_what_keys_looks_up(): void {
		$rules = $this->rules();
		$keys  = $rules->keys( '2001:db8::1', 'A@B.Example' );

		$this->assertSame( $keys['ip'], $rules->pattern( Blocklist::IP, '2001:db8::1' ) );
		$this->assertSame( $keys['email'], $rules->pattern( Blocklist::EMAIL, 'a@b.example' ) );
		$this->assertSame( $keys['email_domain'], $rules->pattern( Blocklist::DOMAIN, '@B.EXAMPLE' ) );
	}

	public function test_the_app_can_supply_its_own_email_rule(): void {
		$rules = new Blocklist(
			static fn( string $ip ): string => $ip,
			static fn( string $email ): string => str_contains( $email, '@' ) ? 'x:' . strtolower( $email ) : ''
		);

		$this->assertSame( 'x:a@b.example', $rules->pattern( Blocklist::EMAIL, 'A@B.example' ) );
		$this->assertSame( array( 'email' => 'x:a@b.example', 'email_domain' => '@b.example' ), $rules->keys( null, 'A@B.example' ) );
	}

	public function test_an_ip_key_that_gives_nothing_adds_nothing(): void {
		$rules = new Blocklist( static fn( string $ip ): string => '' );

		$this->assertSame( array(), $rules->keys( '203.0.113.9' ) );
	}

	public function test_the_types_are_the_check_order(): void {
		$this->assertSame( array( 'ip', 'email', 'email_domain' ), Blocklist::TYPES );
	}
}
