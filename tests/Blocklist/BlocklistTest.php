<?php
/**
 * The rules a blocklist runs on.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Blocklist;

use SugarCommerce\Network\Blocklist;
use SugarCommerce\Network\IP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class BlocklistTest
 */
final class BlocklistTest extends TestCase {

	/**
	 * Rules whose stored address shows what it was given.
	 *
	 * @return Blocklist
	 */
	private function rules(): Blocklist {
		return new Blocklist( static fn( string $ip ): string => 'h:' . $ip );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function typed(): array {
		return [
			'ipv4'               => [ 'ip', ' 203.0.113.9 ', 'h:203.0.113.9' ],
			'ipv6'               => [ 'ip', '2001:db8::1', 'h:2001:db8::1' ],
			'ipv6 spelt long'    => [ 'ip', '2001:DB8:0:0:0:0:0:1', 'h:2001:db8::1' ],
			'bracketed ipv6'     => [ 'ip', '[2001:db8::1]', 'h:2001:db8::1' ],
			'ipv4-mapped'        => [ 'ip', '::ffff:203.0.113.9', 'h:203.0.113.9' ],
			'not an ip'          => [ 'ip', '203.0.113', '' ],
			'cidr is not one ip' => [ 'ip', '203.0.113.0/24', '' ],
			'email'              => [ 'email', ' Bad.Actor@Example.COM ', 'bad.actor@example.com' ],
			'not an email'       => [ 'email', 'bad.actor', '' ],
			'domain'             => [ 'email_domain', ' @Spam.Example ', '@spam.example' ],
			'domain without @'   => [ 'email_domain', 'spam.example', '' ],
			'single label'       => [ 'email_domain', '@localhost', '' ],
			'ip as domain'       => [ 'email_domain', '@203.0.113.9', '' ],
			'just @'             => [ 'email_domain', '@', '' ],
			'unknown type'       => [ 'phone', '0800', '' ],
		];
	}

	/**
	 * A typed pattern becomes its stored form.
	 *
	 * @param string $type   Type.
	 * @param string $typed  What was typed.
	 * @param string $stored What is stored.
	 */
	#[DataProvider( 'typed' )]
	public function test_a_typed_pattern_becomes_its_stored_form( string $type, string $typed, string $stored ): void {
		$this->assertSame( $stored, $this->rules()->pattern( $type, $typed ) );
	}

	/**
	 * IP, then email, then domain.
	 */
	public function test_a_visitor_is_checked_by_ip_then_email_then_domain(): void {
		$this->assertSame(
			[
				'ip'           => 'h:203.0.113.9',
				'email'        => 'someone@spam.example',
				'email_domain' => '@spam.example',
			],
			$this->rules()->keys( '203.0.113.9', 'Someone@Spam.Example' )
		);
	}

	/**
	 * Nothing usable, no key.
	 */
	public function test_missing_or_invalid_parts_add_nothing(): void {
		$this->assertSame( [ 'ip' => 'h:203.0.113.9' ], $this->rules()->keys( '203.0.113.9', null ) );
		$this->assertSame( [ 'ip' => 'h:203.0.113.9' ], $this->rules()->keys( '203.0.113.9', 'not-an-email' ) );
		$this->assertSame( [], $this->rules()->keys( null, '@spam.example' ) );
		$this->assertSame( [], $this->rules()->keys( null, 'someone@' ) );
		$this->assertSame( [], $this->rules()->keys( 'unknown', '' ) );
		$this->assertSame( [], $this->rules()->keys( null ) );
	}

	/**
	 * What pattern() stores is what keys() looks up, however it was spelt.
	 */
	public function test_what_pattern_stores_is_what_keys_looks_up(): void {
		$rules = $this->rules();
		$keys  = $rules->keys( '[2001:DB8::1]', 'A@B.Example' );

		$this->assertSame( $keys['ip'], $rules->pattern( Blocklist::IP, '2001:db8:0::1' ) );
		$this->assertSame( $keys['email'], $rules->pattern( Blocklist::EMAIL, 'a@b.example' ) );
		$this->assertSame( $keys['email_domain'], $rules->pattern( Blocklist::DOMAIN, '@B.EXAMPLE' ) );
	}

	/**
	 * The site may store email addresses its own way.
	 */
	public function test_the_site_can_supply_its_own_email_rule(): void {
		$rules = new Blocklist(
			static fn( string $ip ): string => $ip,
			static fn( string $email ): string => str_contains( $email, '@' ) ? 'x:' . strtolower( $email ) : ''
		);

		$this->assertSame( 'x:a@b.example', $rules->pattern( Blocklist::EMAIL, 'A@B.example' ) );
		$this->assertSame(
			[
				'email'        => 'x:a@b.example',
				'email_domain' => '@b.example',
			],
			$rules->keys( null, 'A@B.example' )
		);
	}

	/**
	 * An IP key that gives nothing adds nothing.
	 */
	public function test_an_ip_key_that_gives_nothing_adds_nothing(): void {
		$rules = new Blocklist( static fn( string $ip ): string => '' );

		$this->assertSame( [], $rules->keys( '203.0.113.9' ) );
	}

	/**
	 * The keyed hash is a fine IP key.
	 */
	public function test_the_ip_hash_is_a_fine_key(): void {
		$rules = new Blocklist( static fn( string $ip ): string => IP::hash( $ip, 'secret' ) );

		$this->assertSame( IP::hash( '2001:db8::1', 'secret' ), $rules->keys( '2001:0db8::0001' )['ip'] );
	}

	/**
	 * The types are the check order.
	 */
	public function test_the_types_are_the_check_order(): void {
		$this->assertSame( [ 'ip', 'email', 'email_domain' ], Blocklist::TYPES );
	}

	/**
	 * An address too odd to store is still looked up, by itself and its domain.
	 */
	public function test_an_address_too_odd_to_store_is_still_looked_up(): void {
		$this->assertSame(
			[
				'email'        => 'pat(x)@spam.example',
				'email_domain' => '@spam.example',
			],
			$this->rules()->keys( null, ' Pat(x)@Spam.Example ' )
		);
		$this->assertSame( '', $this->rules()->pattern( Blocklist::EMAIL, 'pat(x)@spam.example' ), 'but cannot be stored' );
	}

	/**
	 * A blocked domain cannot be stepped round by spelling it another way
	 * that still reaches it: a trailing dot is the same domain to DNS,
	 * and pattern() stores the domain without one.
	 */
	public function test_a_domain_spelt_with_a_trailing_dot_is_still_its_domain(): void {
		$rules  = $this->rules();
		$stored = $rules->pattern( Blocklist::DOMAIN, '@spam.example' );

		$this->assertSame( $stored, $rules->keys( null, 'pat@spam.example.' )['email_domain'] );
		$this->assertSame( $stored, $rules->keys( null, 'pat@Spam.Example..' )['email_domain'] );
		$this->assertArrayNotHasKey( 'email_domain', $rules->keys( null, 'pat@.' ) );
	}
}
