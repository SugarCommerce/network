<?php
/**
 * What came with the move into Network.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\AcceptLanguage;

use SugarCommerce\Network\AcceptLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A saved request, a capped header, right-to-left scripts, the visitor's
 * own choice, and languages' names in themselves.
 */
final class ReadingTest extends TestCase {

	protected function setUp(): void {
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
	}

	/**
	 * A stand-in for `$_SERVER` is read instead of the live request.
	 *
	 * So a queued job can ask about the request it saved.
	 */
	public function test_every_reader_takes_a_saved_request(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
		$saved                           = [ 'HTTP_ACCEPT_LANGUAGE' => 'ar-EG,en;q=0.5' ];

		$this->assertSame( 'ar-EG,en;q=0.5', AcceptLanguage::get_header( $saved ) );
		$this->assertSame( 'ar-EG', AcceptLanguage::get_primary( $saved ) );
		$this->assertSame( 'ar', AcceptLanguage::get_primary_language( $saved ) );
		$this->assertSame( 'EG', AcceptLanguage::get_primary_region( $saved ) );
		$this->assertSame( [ 'ar-EG' => 1.0, 'en' => 0.5 ], AcceptLanguage::parse( null, $saved ) );
		$this->assertSame( [ 'ar-EG', 'en' ], AcceptLanguage::get_all( $saved ) );
		$this->assertSame( [ 'ar', 'en' ], AcceptLanguage::get_languages( $saved ) );
		$this->assertTrue( AcceptLanguage::accepts( 'en-GB', false, $saved ) );
		$this->assertFalse( AcceptLanguage::accepts( 'fr', false, $saved ) );
		$this->assertSame( 0.5, AcceptLanguage::get_quality( 'en', $saved ) );
		$this->assertSame( 'en', AcceptLanguage::get_best_match( [ 'fr', 'en' ], null, $saved ) );
		$this->assertTrue( AcceptLanguage::is_rtl( $saved ) );

		// And the live one is still there for everything else.
		$this->assertSame( 'fr', AcceptLanguage::get_primary() );
		$this->assertNull( AcceptLanguage::get_header( [] ) );
	}

	/**
	 * A header past the cap is read up to it, never past it, and never
	 * half a tag.
	 *
	 * It is client input parsed on every call, so ten kilobytes of it is
	 * ten kilobytes of work the visitor chose.
	 */
	public function test_a_long_header_is_capped(): void {
		$header = 'de;q=0.9,' . str_repeat( 'en;q=0.1,', 2000 ) . 'fr';

		$this->assertGreaterThan( 10000, strlen( $header ) );
		$this->assertSame( [ 'de' => 0.9, 'en' => 0.1 ], AcceptLanguage::parse( $header ) );

		// Cut inside a tag, the half tag is dropped rather than read as
		// `zh-HA`.
		$cut = 'de,' . str_repeat( ' ', 990 ) . ',zh-Hant-TW';
		$this->assertSame( [ 'de' => 1.0 ], AcceptLanguage::parse( $cut ) );

		// Exactly at the cap is read whole.
		$exact = str_repeat( ' ', 994 ) . ',de-AT';
		$this->assertSame( AcceptLanguage::MAX_LENGTH, strlen( $exact ) );
		$this->assertSame( [ 'de-AT' => 1.0 ], AcceptLanguage::parse( $exact ) );
	}

	/**
	 * Only the first entries are read.
	 */
	public function test_only_the_first_entries_are_read(): void {
		$tags   = array_map( static fn( int $i ): string => 'x' . $i, range( 1, AcceptLanguage::MAX_ENTRIES + 5 ) );
		$parsed = AcceptLanguage::parse( implode( ',', $tags ) );

		$this->assertCount( AcceptLanguage::MAX_ENTRIES, $parsed );
		$this->assertArrayHasKey( 'x' . AcceptLanguage::MAX_ENTRIES, $parsed );
		$this->assertArrayNotHasKey( 'x' . ( AcceptLanguage::MAX_ENTRIES + 1 ), $parsed );
	}

	/**
	 * A script subtag decides direction on its own.
	 *
	 * @param string $tag The tag.
	 * @param bool   $rtl Whether it reads right to left.
	 */
	#[DataProvider( 'directionProvider' )]
	public function test_direction_of_any_tag( string $tag, bool $rtl ): void {
		$this->assertSame( $rtl, AcceptLanguage::is_rtl_language( $tag ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function directionProvider(): array {
		return [
			'Arabic'                 => [ 'ar', true ],
			'Persian with region'    => [ 'fa_IR', true ],
			'Sorani'                 => [ 'ckb', true ],
			'Kurdish'                => [ 'ku', true ],
			'Kurdish in Latin'       => [ 'ku-Latn', false ],
			'Kurdish in Arabic'      => [ 'ku-Arab-IQ', true ],
			'Azerbaijani'            => [ 'az', false ],
			'Azerbaijani in Arabic'  => [ 'az-Arab', true ],
			'Punjabi in Arabic'      => [ 'pa-Arab', true ],
			'Punjabi'                => [ 'pa-IN', false ],
			'Uzbek in Cyrillic'      => [ 'uz-cyrl', false ],
			'English'                => [ 'en-GB', false ],
			'nothing'                => [ '', false ],
		];
	}

	/**
	 * A tag in the visitor's own choice beats the browser.
	 */
	public function test_a_choice_beats_the_browser(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
		$available                       = [ 'en', 'de', 'fr' ];

		$this->assertSame( 'de', AcceptLanguage::pick( [ 'de-AT' ], $available, 'en' ) );

		// Nothing usable chosen: an array from `?lang[]=`, garbage, the
		// wildcard, something not on offer. The browser decides.
		$this->assertSame( 'fr', AcceptLanguage::pick( [ [ 'de' ], null, '<b>', '*', 'ja', '' ], $available, 'en' ) );

		// The first usable choice wins.
		$this->assertSame( 'en', AcceptLanguage::pick( [ 'ja', 'EN_us', 'de' ], $available, 'fr' ) );

		// Nothing at all: the fallback.
		$this->assertSame( 'en', AcceptLanguage::pick( [], $available, 'en', [] ) );
	}

	/**
	 * A language's name is in itself, and only a tag is echoed back.
	 */
	public function test_a_language_is_named_in_itself(): void {
		$this->assertSame( 'Deutsch', AcceptLanguage::endonym( 'de' ) );
		$this->assertSame( 'Português (Brasil)', AcceptLanguage::endonym( 'pt_br' ) );
		$this->assertSame( '中文（繁體）', AcceptLanguage::endonym( 'ZH-hant' ) );

		// A region with no entry of its own falls back to its language,
		// when ext-intl has nothing better.
		$this->assertNotSame( '', AcceptLanguage::endonym( 'de-CH' ) );

		$this->assertSame( '', AcceptLanguage::endonym( '<b>de</b>' ) );
		$this->assertSame( '', AcceptLanguage::endonym( '../x' ) );
		$this->assertSame( '', AcceptLanguage::endonym( '' ) );
	}

	/**
	 * The labels are in this library's text domain.
	 */
	public function test_the_labels_are_in_the_family_text_domain(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/AcceptLanguage.php' );

		$this->assertStringNotContainsString( "'arraypress'", $source );
		$this->assertStringContainsString( "__( 'English', 'sugarcommerce' )", $source );
	}
}
