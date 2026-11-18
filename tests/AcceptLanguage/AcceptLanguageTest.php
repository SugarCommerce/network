<?php
/**
 * Accept-Language parsing tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\AcceptLanguage;

use SugarCommerce\Network\AcceptLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Accept-Language is a client-supplied header, and this library turns it into
 * a decision: which language to serve.
 *
 * Two things follow from that. It has to read the header the way the standard
 * says, including the parts that are easy to skip -- `q=0` means "not this
 * one", and a quality is a number between nought and one. And it has to hold
 * up when the header is nonsense, because the sender chooses what it contains.
 */
final class AcceptLanguageTest extends TestCase {

	/**
	 * The live header, put back after each test.
	 *
	 * @var string|null
	 */
	private $original;

	/**
	 * Remember the real header.
	 */
	protected function setUp(): void {
		$this->original = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
	}

	/**
	 * Put it back, because every accessor here reads the superglobal.
	 */
	protected function tearDown(): void {
		if ( null === $this->original ) {
			unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		} else {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $this->original;
		}
	}

	/**
	 * Pretend the client sent this header.
	 *
	 * @param string $header The header value.
	 */
	private function sent( string $header ): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;
	}

	/**
	 * A header is read into languages against qualities, best first.
	 */
	public function test_a_header_parses_in_preference_order(): void {
		$this->assertSame(
			[
				'en-GB' => 1.0,
				'en'    => 0.9,
				'fr'    => 0.8,
			],
			AcceptLanguage::parse( 'en-GB,en;q=0.9,fr;q=0.8' )
		);
	}

	/**
	 * Entries of equal quality keep the order they were sent in.
	 *
	 * The standard makes the order significant when the qualities tie, so the
	 * sort has to be stable. PHP's sorts have been stable since 8.0; this
	 * pins the fact that the library relies on it.
	 */
	public function test_equal_qualities_keep_the_order_they_were_sent(): void {
		$this->assertSame(
			[ 'fr', 'de', 'es' ],
			array_keys( AcceptLanguage::parse( 'fr;q=0.5,de;q=0.5,es;q=0.5' ) )
		);
	}

	/**
	 * A language sent with `q=0` is refused, not merely ranked last.
	 *
	 * This is the header's way of saying "not this one". Keeping the entry
	 * meant accepts() answered true for a language the client had explicitly
	 * ruled out, and get_best_match() returned it when it was the only thing
	 * on offer -- serving the one language the visitor asked not to have.
	 */
	public function test_a_language_refused_with_q_zero_is_not_accepted(): void {
		$this->sent( 'en;q=0,fr;q=0.9' );

		$this->assertArrayNotHasKey( 'en', AcceptLanguage::parse() );
		$this->assertFalse( AcceptLanguage::accepts( 'en' ) );
		$this->assertNull( AcceptLanguage::get_quality( 'en' ) );

		// And it is not picked even when nothing else is available.
		$this->assertNull( AcceptLanguage::get_best_match( [ 'en' ] ) );
		$this->assertSame( 'de', AcceptLanguage::get_best_match( [ 'en' ], 'de' ) );
	}

	/**
	 * A quality outside nought-to-one is brought back into range.
	 *
	 * The header comes from the client. Left alone, `q=99` outranks every
	 * well-formed entry and becomes the primary language, which is a
	 * preference the sender does not get to assert.
	 */
	public function test_a_quality_outside_the_range_is_clamped(): void {
		$this->assertSame( [ 'de' => 1.0 ], AcceptLanguage::parse( 'de;q=99' ) );
		$this->assertSame( [ 'de' => 1.0 ], AcceptLanguage::parse( 'de;q=1.5' ) );

		// Clamped rather than dropped: the client does want German, it just
		// does not get to want it more than is possible. With both at one, the
		// order sent decides.
		$this->assertSame( [ 'en', 'de' ], array_keys( AcceptLanguage::parse( 'en;q=1.0,de;q=99' ) ) );
	}

	/**
	 * The parameter name is case-insensitive, as the standard says.
	 */
	public function test_an_upper_case_q_is_read(): void {
		$this->assertSame( [ 'de' => 0.5 ], AcceptLanguage::parse( 'de;Q=0.5' ) );
	}

	/**
	 * What does not look like a language tag is dropped.
	 *
	 * The header is the client's to write. A "language" of `<b>en</b>` or
	 * `../x` is something a caller would otherwise print, or use as a file
	 * name, on the strength of it having come out of a parser.
	 */
	public function test_what_is_not_a_tag_is_dropped(): void {
		$this->assertSame(
			[ 'zh-Hant-TW' => 1.0, 'it' => 0.5 ],
			AcceptLanguage::parse( 'en us,fr!,<b>de</b>,../x,it;q=0.5,zh-Hant-TW,toolongsubtag' )
		);
	}

	/**
	 * A quality that is not a number at all is treated as unqualified.
	 */
	public function test_an_unreadable_quality_is_treated_as_one(): void {
		$this->assertSame( [ 'en' => 1.0 ], AcceptLanguage::parse( 'en;q=abc' ) );
	}

	/**
	 * Junk in the header does not produce junk languages.
	 *
	 * @param string $header What the client sent.
	 * @param array  $expect What should come out.
	 */
	#[DataProvider( 'oddHeaderProvider' )]
	public function test_an_odd_header_parses_sensibly( string $header, array $expect ): void {
		$this->assertSame( $expect, AcceptLanguage::parse( $header ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, float>}>
	 */
	public static function oddHeaderProvider(): array {
		return [
			'empty'                 => [ '', [] ],
			'only commas'           => [ ',,,', [] ],
			'padding and gaps'      => [ '  en-GB ,, fr ', [ 'en-GB' => 1.0, 'fr' => 1.0 ] ],
			'underscore separator'  => [ 'en_us', [ 'en-US' => 1.0 ] ],
			'a further parameter'   => [ 'en;q=0.8;x=1', [ 'en' => 0.8 ] ],
			'the wildcard'          => [ '*', [ '*' => 1.0 ] ],
			'a three part tag'      => [ 'en-US-posix', [ 'en-US-posix' => 1.0 ] ],
			'no quality at all'     => [ 'de', [ 'de' => 1.0 ] ],
			'a semicolon, no q'     => [ 'de;', [ 'de' => 1.0 ] ],
		];
	}

	/**
	 * The same tag sent twice keeps the last quality given.
	 */
	public function test_a_repeated_tag_keeps_the_last_quality(): void {
		$this->assertSame( [ 'en-US' => 0.9 ], AcceptLanguage::parse( 'en-US;q=0.2,en-US;q=0.9' ) );
	}

	/**
	 * With no header there is nothing to say.
	 */
	public function test_no_header_means_no_answer(): void {
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		$this->assertNull( AcceptLanguage::get_header() );
		$this->assertSame( [], AcceptLanguage::parse() );
		$this->assertNull( AcceptLanguage::get_primary() );
		$this->assertNull( AcceptLanguage::get_primary_language() );
		$this->assertNull( AcceptLanguage::get_primary_region() );
		$this->assertFalse( AcceptLanguage::is_rtl() );
		$this->assertSame( [], AcceptLanguage::get_all() );
		$this->assertSame( [], AcceptLanguage::get_languages() );
		$this->assertSame( 'en', AcceptLanguage::get_best_match( [ 'en', 'fr' ], 'en' ) );
	}

	/**
	 * An empty header is the same as none.
	 */
	public function test_an_empty_header_is_the_same_as_none(): void {
		$this->sent( '' );

		$this->assertNull( AcceptLanguage::get_header() );
		$this->assertSame( [], AcceptLanguage::parse() );
	}

	/**
	 * The primary language is the best-ranked one, split into its parts.
	 */
	public function test_the_primary_language_and_its_parts(): void {
		$this->sent( 'fr;q=0.4,en-GB;q=0.9' );

		$this->assertSame( 'en-GB', AcceptLanguage::get_primary() );
		$this->assertSame( 'en', AcceptLanguage::get_primary_language() );
		$this->assertSame( 'GB', AcceptLanguage::get_primary_region() );
	}

	/**
	 * A tag with no region has no region.
	 */
	public function test_a_tag_without_a_region_has_none(): void {
		$this->sent( 'de' );

		$this->assertSame( 'de', AcceptLanguage::get_primary() );
		$this->assertNull( AcceptLanguage::get_primary_region() );
	}

	/**
	 * The distinct languages come back once each, in preference order.
	 */
	public function test_the_languages_are_deduplicated(): void {
		$this->sent( 'en-GB,en-US;q=0.9,fr;q=0.8,en;q=0.7' );

		$this->assertSame( [ 'en', 'fr' ], AcceptLanguage::get_languages() );
		$this->assertSame( [ 'en-GB', 'en-US', 'fr', 'en' ], AcceptLanguage::get_all() );
	}

	/**
	 * Acceptance falls back to the base language unless asked not to.
	 *
	 * A visitor who sent `en-GB` accepts English. Whether they accept
	 * *American* English is a different question, which is what the exact
	 * flag is for.
	 */
	public function test_acceptance_matches_the_base_language_unless_exact(): void {
		$this->sent( 'en-GB;q=0.9,fr;q=0.5' );

		$this->assertTrue( AcceptLanguage::accepts( 'en-GB' ) );
		$this->assertTrue( AcceptLanguage::accepts( 'en' ) );
		$this->assertTrue( AcceptLanguage::accepts( 'en-US' ) );
		$this->assertTrue( AcceptLanguage::accepts( 'en-GB', true ) );

		$this->assertFalse( AcceptLanguage::accepts( 'en-US', true ) );
		$this->assertFalse( AcceptLanguage::accepts( 'de' ) );
		$this->assertFalse( AcceptLanguage::accepts( '' ) );
	}

	/**
	 * Acceptance does not care how the tag was written.
	 */
	public function test_acceptance_normalises_what_it_is_asked(): void {
		$this->sent( 'en-GB' );

		$this->assertTrue( AcceptLanguage::accepts( 'EN_gb', true ) );
		$this->assertSame( 1.0, AcceptLanguage::get_quality( 'en_GB' ) );
	}

	/**
	 * The quality of something not offered is nothing, not nought.
	 *
	 * Nought is a real quality with a real meaning, so a miss cannot use it.
	 */
	public function test_an_unoffered_language_has_no_quality(): void {
		$this->sent( 'en' );

		$this->assertNull( AcceptLanguage::get_quality( 'de' ) );
	}

	/**
	 * The best match prefers an exact tag over the same base language.
	 */
	public function test_the_best_match_prefers_an_exact_tag(): void {
		$this->sent( 'en-GB,en-US;q=0.9' );

		$this->assertSame( 'en-GB', AcceptLanguage::get_best_match( [ 'en-US', 'en-GB' ] ) );
	}

	/**
	 * Falling back to the base language beats returning the default.
	 */
	public function test_the_best_match_falls_back_to_the_base_language(): void {
		$this->sent( 'en-AU' );

		$this->assertSame( 'en-GB', AcceptLanguage::get_best_match( [ 'de', 'en-GB' ], 'de' ) );
	}

	/**
	 * The answer is the caller's own spelling, not the normalised one.
	 *
	 * The list handed in is what the caller has files or routes for, so
	 * handing back a re-cased version of it would not match anything.
	 */
	public function test_the_best_match_returns_the_callers_own_spelling(): void {
		$this->sent( 'en-gb' );

		$this->assertSame( 'en_GB', AcceptLanguage::get_best_match( [ 'en_GB' ] ) );
	}

	/**
	 * Nothing available, or nothing acceptable, gives the default.
	 */
	public function test_the_best_match_gives_the_default_when_nothing_fits(): void {
		$this->sent( 'ja' );

		$this->assertSame( 'en', AcceptLanguage::get_best_match( [ 'en', 'fr' ], 'en' ) );
		$this->assertNull( AcceptLanguage::get_best_match( [ 'en', 'fr' ] ) );
		$this->assertSame( 'en', AcceptLanguage::get_best_match( [], 'en' ) );
	}

	/**
	 * A right-to-left preference is recognised from the base language.
	 *
	 * @param string $header What the client sent.
	 * @param bool   $rtl    Whether it reads right to left.
	 */
	#[DataProvider( 'rtlProvider' )]
	public function test_a_right_to_left_preference_is_recognised( string $header, bool $rtl ): void {
		$this->sent( $header );

		$this->assertSame( $rtl, AcceptLanguage::is_rtl() );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function rtlProvider(): array {
		return [
			'Arabic'                  => [ 'ar', true ],
			'Arabic with a region'    => [ 'ar-EG', true ],
			'Hebrew'                  => [ 'he', true ],
			'Persian'                 => [ 'fa-IR', true ],
			'Urdu'                    => [ 'ur', true ],
			'English'                 => [ 'en-GB', false ],
			'German'                  => [ 'de', false ],
			// The preference that counts is the best-ranked one.
			'Arabic ranked below'     => [ 'en;q=0.9,ar;q=0.1', false ],
			'Arabic ranked above'     => [ 'ar;q=0.9,en;q=0.1', true ],
		];
	}

	/**
	 * Normalising follows BCP 47's casing: language lower, script title,
	 * region upper, the rest lower.
	 *
	 * It used to upper-case everything after the first hyphen, so
	 * `zh-Hant-TW` came out `zh-HANT-TW` and read as a region of `HANT`.
	 *
	 * @param string $input  What was written.
	 * @param string $expect What it settles to.
	 */
	#[DataProvider( 'normaliseProvider' )]
	public function test_normalising_a_tag( string $input, string $expect ): void {
		$this->assertSame( $expect, AcceptLanguage::normalize( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function normaliseProvider(): array {
		return [
			'mixed case'    => [ 'EN-us', 'en-US' ],
			'underscore'    => [ 'en_us', 'en-US' ],
			'already right' => [ 'en-US', 'en-US' ],
			'no region'     => [ 'DE', 'de' ],
			'padded'        => [ '  fr-CA  ', 'fr-CA' ],
			'empty'         => [ '', '' ],
			'whitespace'    => [ '   ', '' ],
			'three parts'   => [ 'en-US-posix', 'en-US-posix' ],
			'a script'      => [ 'ZH_hant_tw', 'zh-Hant-TW' ],
			'a UN region'   => [ 'ES-419', 'es-419' ],
			'an extlang'    => [ 'ZH-YUE-hk', 'zh-yue-HK' ],
			'an extension'  => [ 'en-US-u-CA-gregory', 'en-US-u-ca-gregory' ],
			'private use'   => [ 'en-x-AB', 'en-x-ab' ],
		];
	}

	/**
	 * Splitting a tag into language and region.
	 */
	public function test_splitting_a_tag(): void {
		$this->assertSame( 'en', AcceptLanguage::extract_language( 'en-US' ) );
		$this->assertSame( 'zh', AcceptLanguage::extract_language( 'ZH_Hant' ) );
		$this->assertSame( 'de', AcceptLanguage::extract_language( 'DE' ) );
		$this->assertSame( '', AcceptLanguage::extract_language( '' ) );

		$this->assertSame( 'US', AcceptLanguage::extract_region( 'en-US' ) );
		$this->assertSame( 'AT', AcceptLanguage::extract_region( 'de_at' ) );
		$this->assertSame( 'US', AcceptLanguage::extract_region( 'en-US-posix' ) );
		$this->assertNull( AcceptLanguage::extract_region( 'de' ) );

		// A script is not a region, and a region can be three digits.
		$this->assertSame( 'TW', AcceptLanguage::extract_region( 'zh-Hant-TW' ) );
		$this->assertNull( AcceptLanguage::extract_region( 'zh-Hant' ) );
		$this->assertSame( '419', AcceptLanguage::extract_region( 'es-419' ) );
		$this->assertSame( 'HK', AcceptLanguage::extract_region( 'zh-yue-HK' ) );
		$this->assertNull( AcceptLanguage::extract_region( 'en-x-gb' ) );
	}

	/**
	 * The dropdown lists are non-empty and come in both shapes.
	 *
	 * They are what a settings screen renders, so the option shape is part of
	 * the contract rather than a convenience.
	 */
	public function test_the_language_lists_come_in_both_shapes(): void {
		foreach ( [ 'get_common_languages', 'get_base_languages' ] as $method ) {
			$map = AcceptLanguage::$method();

			$this->assertNotEmpty( $map, sprintf( '%s is empty.', $method ) );
			$this->assertContainsOnlyString( array_keys( $map ) );

			$options = AcceptLanguage::$method( true );

			$this->assertCount( count( $map ), $options );
			$this->assertSame( [ 'value', 'label' ], array_keys( $options[0] ) );
			$this->assertSame( array_key_first( $map ), $options[0]['value'] );
			$this->assertSame( reset( $map ), $options[0]['label'] );
		}
	}

	/**
	 * The base list holds bare languages and the common one holds locales.
	 */
	public function test_the_base_list_holds_bare_languages(): void {
		foreach ( array_keys( AcceptLanguage::get_base_languages() ) as $code ) {
			$this->assertStringNotContainsString( '-', $code );
		}

		$this->assertNotEmpty(
			array_filter(
				array_keys( AcceptLanguage::get_common_languages() ),
				static fn( $code ) => str_contains( $code, '-' )
			),
			'The common list has no locale in it at all.'
		);
	}
}
