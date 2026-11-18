<?php
/**
 * Parsing, validity and the value-object contract.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Email;

use SugarCommerce\Network\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ParsingTest
 */
final class ParsingTest extends TestCase {

	/**
	 * parse() returns null for anything unusable, rather than an invalid object.
	 *
	 * @param string $input The input.
	 */
	#[DataProvider( 'unusableInput' )]
	public function test_unusable_input_parses_to_null( string $input ): void {
		$this->assertNull( Email::parse( $input ) );
	}

	/**
	 * Input that is not an address.
	 *
	 * @return array
	 */
	public static function unusableInput(): array {
		return [
			'empty'        => [ '' ],
			'whitespace'   => [ '   ' ],
			'no at'        => [ 'notanemail' ],
			'no domain'    => [ 'user@' ],
			'no local'     => [ '@example.com' ],
			'two ats'      => [ 'a@b@example.com' ],
			'no tld'       => [ 'user@localhost' ],
			'trailing dot' => [ 'user@example.com.' ],
			'space inside' => [ 'us er@example.com' ],
		];
	}

	/**
	 * Validity follows WordPress, not filter_var.
	 *
	 * The two disagree in both directions. Which one the library uses decides
	 * whether these addresses exist at all, so it is worth pinning rather
	 * than leaving to whichever happens to be reachable.
	 */
	public function test_validity_follows_wordpress(): void {
		// filter_var rejects consecutive dots in the local part; core allows them.
		$this->assertNotNull( Email::parse( 'a..b@example.com' ) );

		// filter_var accepts this; core refuses anything under six characters.
		$this->assertNull( Email::parse( 'a@b.c' ) );
	}

	/**
	 * The parts come out as expected.
	 */
	public function test_the_parts_are_split(): void {
		$email = Email::parse( 'Dave.Smith+newsletter@Example.CO.UK' );

		$this->assertSame( 'dave.smith+newsletter', $email->local() );
		$this->assertSame( 'example.co.uk', $email->domain() );
		$this->assertSame( 'uk', $email->tld() );
		$this->assertSame( 'Dave.Smith+newsletter@Example.CO.UK', $email->original() );
		$this->assertSame( 'dave.smith+newsletter@example.co.uk', $email->normalized() );
	}

	/**
	 * The original is preserved exactly.
	 *
	 * Normalising is for comparison; the address a customer typed is what
	 * gets emailed, and lowercasing a local part is not always safe.
	 */
	public function test_the_original_is_preserved(): void {
		$this->assertSame( 'CaseSensitive@Example.com', Email::parse( 'CaseSensitive@Example.com' )->original() );
	}

	/**
	 * Subaddressing is detected and can be stripped.
	 */
	public function test_subaddressing(): void {
		$email = Email::parse( 'dave+shopping@gmail.com' );

		$this->assertTrue( $email->is_subaddressed() );
		$this->assertSame( 'shopping', $email->subaddress() );
		$this->assertSame( 'dave', $email->base_local() );
		$this->assertSame( 'dave@gmail.com', $email->base_address() );
	}

	/**
	 * An address without a tag has no subaddress.
	 */
	public function test_no_subaddress_when_untagged(): void {
		$email = Email::parse( 'dave@gmail.com' );

		$this->assertFalse( $email->is_subaddressed() );
		$this->assertSame( 'dave@gmail.com', $email->base_address() );
	}

	/**
	 * Two spellings of the same mailbox compare equal on the base address.
	 *
	 * This is what makes the object useful for blocking: someone adding +1,
	 * +2, +3 is the same person.
	 */
	public function test_tagged_variants_share_a_base(): void {
		$a = Email::parse( 'dave+one@gmail.com' );
		$b = Email::parse( 'Dave+two@GMAIL.com' );

		$this->assertFalse( $a->equals( $b ) );
		$this->assertTrue( $a->equals_base( $b ) );
	}

	/**
	 * The object is immutable: the with_* methods return new instances.
	 */
	public function test_the_object_is_immutable(): void {
		$original = Email::parse( 'dave@example.com' );
		$changed  = $original->with_domain( 'other.com' );

		$this->assertSame( 'dave@example.com', $original->normalized() );
		$this->assertSame( 'dave@other.com', $changed->normalized() );
		$this->assertNotSame( $original, $changed );
	}

	/**
	 * It stringifies and serialises to JSON.
	 */
	public function test_it_stringifies_and_serialises(): void {
		$email = Email::parse( 'dave@example.com' );

		$this->assertSame( 'dave@example.com', (string) $email );
		$this->assertIsArray( $email->to_array() );
		$this->assertJson( (string) wp_json_encode_shim( $email ) );
	}

	/**
	 * to_array reports only what the object still answers.
	 *
	 * It used to call a method per key, including several that have since
	 * been removed -- so it was the one place a deletion turned into a fatal
	 * rather than a missing method nobody called.
	 */
	public function test_to_array_matches_the_surviving_surface(): void {
		$data = Email::parse( 'dave+tag@gmail.com' )->to_array();

		foreach ( [ 'email', 'original', 'valid', 'local', 'domain', 'tld', 'base_address', 'subaddress', 'subaddressed', 'common_provider', 'role_based', 'government', 'educational', 'military', 'digit_count', 'has_typo', 'suggestion', 'mx_valid' ] as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}

		$this->assertSame( 'dave@gmail.com', $data['base_address'] );
		$this->assertTrue( $data['subaddressed'] );
		$this->assertNull( $data['mx_valid'], 'The MX lookup is a DNS round trip and must stay opt-in.' );
	}

	/**
	 * mask() shows the first character, a fixed run, and the domain.
	 *
	 * @param string $input    The address.
	 * @param string $expected What is shown.
	 */
	#[DataProvider( 'maskedAddresses' )]
	public function test_mask_hides_the_name_and_its_length( string $input, string $expected ): void {
		$this->assertSame( $expected, Email::mask( $input ) );
	}

	/**
	 * Addresses and their masks.
	 *
	 * @return array
	 */
	public static function maskedAddresses(): array {
		return [
			'ordinary'          => [ 'pat@example.com', 'p••@example.com' ],
			'long name'         => [ 'patricia.longname+tag@example.com', 'p••@example.com' ],
			'one letter'        => [ 'p@example.com', 'p••@example.com' ],
			'domain lowered'    => [ 'Pat@Example.COM', 'P••@example.com' ],
			'trimmed'           => [ '  pat@example.com ', 'p••@example.com' ],
			'multibyte'         => [ 'élan@example.com', 'é••@example.com' ],
			'odd but typed'     => [ 'a..b@spam.example', 'a••@spam.example' ],
			'last at wins'      => [ 'a@b@example.com', 'a••@example.com' ],
			'not an address'    => [ 'notanemail', '' ],
			'no name'           => [ '@example.com', '' ],
			'no domain'         => [ 'pat@', '' ],
			'empty'             => [ '', '' ],
		];
	}

	/**
	 * The mask character and the length of the run can be chosen, and
	 * the run never says how long the name was.
	 */
	public function test_mask_takes_a_character_and_a_length(): void {
		$this->assertSame( 'p***@example.com', Email::mask( 'pat@example.com', '*', 3 ) );
		$this->assertSame( 'p*@example.com', Email::mask( 'pat@example.com', '*', 0 ) );
		$this->assertSame( Email::mask( 'pa@example.com' ), Email::mask( 'patricia@example.com' ) );
	}

	/**
	 * The methods removed as unused are actually gone.
	 *
	 * Recorded so a future merge does not quietly bring back a second,
	 * weaker fraud scorer alongside EDD - Fraud Filter, or pseudonymisation
	 * nobody exercises.
	 */
	public function test_the_removed_surface_stays_removed(): void {
		foreach ( [ 'spam_score', 'spam_rating', 'to_hashed', 'to_anonymized', 'country', 'is_private', 'is_commercial_tld', 'from_parts', 'with_tag' ] as $gone ) {
			$this->assertFalse( method_exists( Email::class, $gone ), "{$gone} is back." );
		}
	}
}

/**
 * json_encode without needing WordPress loaded.
 *
 * @param mixed $value The value.
 *
 * @return string
 */
function wp_json_encode_shim( $value ): string {
	return (string) json_encode( $value );
}
