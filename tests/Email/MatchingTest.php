<?php
/**
 * Pattern matching and list sanitising.
 *
 * This is the API the consuming plugins reach for: a store owner types a list
 * of addresses, domains and patterns into a settings field, it is sanitised on
 * save, and every checkout is matched against it. A pattern that survives
 * sanitising but never matches -- or the reverse -- is a rule that silently
 * does not work.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Email;

use SugarCommerce\Network\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class MatchingTest
 */
final class MatchingTest extends TestCase {

	/**
	 * A full address matches itself, regardless of case.
	 */
	public function test_a_full_address_matches(): void {
		$email = Email::parse( 'Dave@Example.com' );

		$this->assertTrue( $email->matches_pattern( 'dave@example.com' ) );
		$this->assertTrue( $email->matches_pattern( 'DAVE@EXAMPLE.COM' ) );
		$this->assertFalse( $email->matches_pattern( 'someone@example.com' ) );
	}

	/**
	 * A domain pattern matches every address at that domain.
	 */
	public function test_a_domain_pattern_matches_the_domain(): void {
		$email = Email::parse( 'dave@example.com' );

		$this->assertTrue( $email->matches_pattern( '@example.com' ) );
		$this->assertFalse( $email->matches_pattern( '@other.com' ) );
	}

	/**
	 * A domain pattern does not match a domain that merely ends with it.
	 *
	 * "@example.com" matching "notexample.com" would hand an attacker the
	 * whole rule by registering a suffix domain.
	 */
	public function test_a_domain_pattern_is_not_a_suffix_match(): void {
		$this->assertFalse( Email::parse( 'dave@notexample.com' )->matches_pattern( '@example.com' ) );
	}

	/**
	 * matches_any is true when one pattern hits.
	 */
	public function test_matches_any(): void {
		$email = Email::parse( 'dave@example.com' );

		$this->assertTrue( $email->matches_any( [ '@other.com', '@example.com' ] ) );
		$this->assertFalse( $email->matches_any( [ '@other.com', '@third.com' ] ) );
		$this->assertFalse( $email->matches_any( [] ) );
	}

	/**
	 * matches_all needs every pattern to hit.
	 */
	public function test_matches_all(): void {
		$email = Email::parse( 'dave@example.com' );

		$this->assertTrue( $email->matches_all( [ '@example.com', 'dave@example.com' ] ) );
		$this->assertFalse( $email->matches_all( [ '@example.com', '@other.com' ] ) );
	}

	/**
	 * Pattern matching is literal: a tagged address does not match the base.
	 *
	 * Recorded rather than assumed, because it cuts both ways. A blocklist
	 * rule for dave@example.com is defeated by adding "+1"; an allowlist rule
	 * is correspondingly not widened by one. matches_pattern() compares
	 * against the normalized address, not base_address().
	 *
	 * A caller that wants the mailbox rather than the spelling should match
	 * on base_address(), which is what it is for.
	 */
	public function test_pattern_matching_is_literal_about_subaddresses(): void {
		$tagged = Email::parse( 'dave+shopping@example.com' );

		$this->assertFalse( $tagged->matches_pattern( 'dave@example.com' ) );

		// The domain rule still catches it, which is the usual way a store
		// blocks someone who is cycling tags.
		$this->assertTrue( $tagged->matches_pattern( '@example.com' ) );

		// And the base address is available for callers that want it.
		$this->assertSame( 'dave@example.com', $tagged->base_address() );
	}

	/**
	 * Sanitising keeps what matching supports.
	 *
	 * @param string $pattern The pattern.
	 */
	#[DataProvider( 'supportedPatterns' )]
	public function test_supported_patterns_survive_sanitising( string $pattern ): void {
		$this->assertContains( $pattern, Email::sanitize_pattern_list( $pattern ) );
	}

	/**
	 * Patterns the matcher honours.
	 *
	 * @return array
	 */
	public static function supportedPatterns(): array {
		return [
			'full address' => [ 'dave@example.com' ],
			'domain'       => [ '@example.com' ],
			'multi-part'   => [ '@example.co.uk' ],
		];
	}

	/**
	 * Sanitising drops what matching cannot use.
	 */
	public function test_unusable_patterns_are_dropped(): void {
		$kept = Email::sanitize_pattern_list( "dave@example.com\nnonsense\n\n   \n@example.com" );

		$this->assertSame( [ 'dave@example.com', '@example.com' ], $kept );
	}

	/**
	 * Sanitising is case-insensitive and de-duplicates.
	 */
	public function test_sanitising_normalises(): void {
		$kept = Email::sanitize_pattern_list( "Dave@Example.com\ndave@example.com\n  DAVE@EXAMPLE.COM  " );

		$this->assertSame( [ 'dave@example.com' ], $kept );
	}

	/**
	 * A sanitised list still matches what it was meant to.
	 *
	 * The round trip is the thing that matters: what a store owner typed,
	 * saved, and expects to block.
	 */
	public function test_the_round_trip_still_matches(): void {
		$list = Email::sanitize_pattern_list( "  @Spam.com \nnonsense\ndave@example.com" );

		$this->assertTrue( Email::parse( 'anyone@spam.com' )->matches_any( $list ) );
		$this->assertTrue( Email::parse( 'dave@example.com' )->matches_any( $list ) );
		$this->assertFalse( Email::parse( 'someone@elsewhere.com' )->matches_any( $list ) );
	}

	/**
	 * An array is accepted as well as a newline string.
	 */
	public function test_an_array_is_accepted(): void {
		$this->assertSame( [ '@example.com' ], Email::sanitize_pattern_list( [ '@example.com', 'rubbish' ] ) );
	}

	/**
	 * The string form round-trips.
	 */
	public function test_the_string_form_round_trips(): void {
		$this->assertSame( "@example.com\ndave@other.com", Email::sanitize_pattern_list( "@example.com\nrubbish\ndave@other.com", true ) );
	}
}
