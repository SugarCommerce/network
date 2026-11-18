<?php
/**
 * Source icon tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Referrer;

use SugarCommerce\Network\Referrer\Campaigns;
use SugarCommerce\Network\Referrer\Icons;
use SugarCommerce\Network\Referrer;
use SugarCommerce\Network\Referrer\Sources;
use PHPUnit\Framework\TestCase;

/**
 * A picture and a colour for each source.
 *
 * The map is keyed by names another file spells, and points at files a
 * third place holds, so most of what is here checks that the three agree.
 */
final class IconsTest extends TestCase {

	/**
	 * Work the folder's URL out afresh.
	 */
	protected function setUp(): void {
		Icons::reset();
	}

	/**
	 * Every name the tables can produce, with the channel it is filed under.
	 *
	 * @return array<string, string> Name => channel.
	 */
	private static function produced(): array {
		$names = [];

		foreach ( Sources::WEBMAIL as $entry ) {
			$names[ $entry[1] ] = 'email';
		}

		foreach ( Sources::AI_ASSISTANTS as $entry ) {
			$names[ $entry[1] ] = 'ai';
		}

		foreach ( Sources::SEARCH_ENGINES as $entry ) {
			$names[ $entry[1] ] = 'search';
		}

		foreach ( Sources::SOCIAL_NETWORKS as $entry ) {
			$names[ $entry[1] ] = 'social';
		}

		foreach ( array_merge( array_values( Sources::ANDROID_APPS ), array_values( Sources::IN_APP_SOURCES ), array_values( Campaigns::UTM_SOURCES ) ) as $entry ) {
			$names[ $entry[1] ] ??= $entry[0];
		}

		foreach ( Campaigns::CLICK_IDS as $entry ) {
			$names[ $entry[2] ] ??= $entry[1];
		}

		return $names;
	}

	/**
	 * Every name the tables can produce has a file that exists: its own
	 * mark, the envelope for an email source without one, the globe for the
	 * rest. Nothing produced is ever the arrow, which is for no source.
	 */
	public function test_every_source_the_tables_produce_has_a_picture(): void {
		$produced = self::produced();

		$this->assertGreaterThan( 100, count( $produced ) );

		foreach ( $produced as $name => $channel ) {
			$file = Icons::file( $name );

			$this->assertFileExists( Icons::dir() . '/' . $file . '.svg', $name );
			$this->assertSame( Icons::dir() . '/' . $file . '.svg', Icons::path( $name ) );

			if ( Icons::has( $name ) ) {
				$this->assertSame( Icons::FILES[ $name ], $file, $name );
				continue;
			}

			$this->assertSame( 'email' === $channel ? Icons::EMAIL : Icons::WEBSITE, $file, $name );
		}
	}

	/**
	 * The names with a mark of their own are the ones written down here, so
	 * a mark that goes missing from the map is noticed.
	 */
	public function test_the_sources_with_a_mark_of_their_own(): void {
		$without = array_keys( array_filter( self::produced(), static fn( string $channel, string $name ): bool => ! Icons::has( $name ), ARRAY_FILTER_USE_BOTH ) );

		sort( $without );

		$this->assertSame(
			[
				'AOL',
				'AOL Mail',
				'Andi',
				'Ask',
				'Bing',
				'Buttondown',
				'Copilot',
				'Daum',
				'DeepSeek',
				'Doubao',
				'Email',
				'Fastmail',
				'Google Display & Video',
				'Google News',
				'Grok',
				'HubSpot',
				'Kagi',
				'Kimi',
				'Kit',
				'Klaviyo',
				'Lemon8',
				'LinkedIn',
				'LinkedIn Ads',
				'Mailchimp',
				'Marketo',
				'Microsoft Ads',
				'Microsoft Teams',
				'Mojeek',
				'Outlook',
				'Phind',
				'Qwant',
				'Qwen',
				'Searx',
				'Seznam',
				'Sogou',
				'Startpage',
				'WEB.DE',
				'Yahoo',
				'Yahoo Mail',
				'Yandex',
				'Yandex Ads',
				'Yandex Mail',
				'You.com',
				'Zoho Mail',
				'beehiiv',
			],
			$without
		);
	}

	/**
	 * A key in the map that no table spells that way is a typo waiting to
	 * be a globe. Three are there on purpose.
	 */
	public function test_every_key_is_a_name_the_tables_spell(): void {
		$stray = array_values( array_diff( array_keys( Icons::FILES ), array_keys( self::produced() ) ) );

		$this->assertSame( [ 'Brave', 'GitHub', 'Vimeo' ], $stray );
	}

	/**
	 * Every entry names a file that is there, every file is named by an
	 * entry or is one of the three plain ones, and the two maps have the
	 * same keys.
	 */
	public function test_the_map_and_the_folder_agree(): void {
		foreach ( Icons::all() as $name => $file ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+\z/', $file, $name );
			$this->assertFileExists( Icons::dir() . '/' . $file . '.svg', $name );
		}

		$on_disk = array_map( static fn( string $path ): string => basename( $path, '.svg' ), (array) glob( Icons::dir() . '/*.svg' ) );
		$named   = array_merge( array_values( Icons::all() ), [ Icons::DIRECT, Icons::EMAIL, Icons::WEBSITE ] );

		$this->assertSame( [], array_values( array_diff( $on_disk, $named ) ), 'A file nothing uses.' );
		$this->assertSame( array_keys( Icons::all() ), array_keys( Icons::colors() ) );
		$this->assertFileExists( Icons::dir() . '/README.md' );
	}

	/**
	 * Each is a picture and not a script: an svg root, nothing executable.
	 */
	public function test_the_files_are_plain_drawings(): void {
		foreach ( (array) glob( Icons::dir() . '/*.svg' ) as $path ) {
			$svg = (string) file_get_contents( $path );

			$this->assertStringStartsWith( '<svg', ltrim( $svg ), $path );
			$this->assertDoesNotMatchRegularExpression( '/<script|\son[a-z]+\s*=|javascript:|<foreignObject/i', $svg, $path );
		}
	}

	/**
	 * The three plain pictures, and when each is used.
	 */
	public function test_the_fallback(): void {
		$this->assertSame( 'direct', Icons::file( '' ) );
		$this->assertSame( 'direct', Icons::file( '  ' ) );
		$this->assertSame( 'direct', Icons::file( 'Direct' ) );

		$this->assertSame( 'email', Icons::file( 'newsletter' ) );
		$this->assertSame( 'email', Icons::file( 'Weekly Newsletter' ) );
		$this->assertSame( 'email', Icons::file( 'Fastmail' ) );
		$this->assertSame( 'email', Icons::file( 'Outlook' ) );
		$this->assertSame( 'email', Icons::file( 'WEB.DE' ) );
		$this->assertSame( 'email', Icons::file( 'Klaviyo' ) );
		$this->assertSame( 'email', Icons::file( 'HubSpot' ) );

		$this->assertSame( 'website', Icons::file( 'producerblog.net' ) );
		$this->assertSame( 'website', Icons::file( 'Bing' ) );
		// The map is by the name as it is spelt, not by something like it.
		$this->assertSame( 'website', Icons::file( 'reddit' ) );
		$this->assertSame( 'website', Icons::fallback( 'Reddit' ) );
	}

	/**
	 * A mark of its own, including the ones that share a file.
	 */
	public function test_a_named_source(): void {
		$this->assertSame( 'reddit', Icons::file( 'Reddit' ) );
		$this->assertSame( 'reddit', Icons::file( ' Reddit ' ) );
		$this->assertSame( 'openai', Icons::file( 'ChatGPT' ) );
		$this->assertSame( 'ycombinator', Icons::file( 'Hacker News' ) );
		$this->assertSame( 'brave', Icons::file( 'Brave Search' ) );
		$this->assertSame( 'tiktok', Icons::file( 'TikTok Ads' ) );
		$this->assertSame( 'gmail', Icons::file( 'Gmail' ) );
		$this->assertTrue( Icons::has( 'Reddit' ) );
		$this->assertFalse( Icons::has( 'Bing' ) );
	}

	/**
	 * What analyse() returns is what the map is keyed by.
	 */
	public function test_it_takes_what_analyse_gives(): void {
		$brave = Referrer::analyse( [ 'referrer' => 'https://search.brave.com/search?q=drum+kits' ] );
		$none  = Referrer::analyse( [] );

		$this->assertSame( 'Brave Search', $brave['source'] );
		$this->assertSame( 'brave', Icons::file( $brave['source'] ) );
		$this->assertSame( 'direct', Icons::file( (string) $none['source'] ) );
	}

	/**
	 * The URL is the folder's, under the content directory.
	 */
	public function test_the_url(): void {
		$this->assertSame( 'https://example.test/wp-content/assets/sources', Icons::base_url() );
		$this->assertSame( 'https://example.test/wp-content/assets/sources/reddit.svg', Icons::url( 'Reddit' ) );
		$this->assertSame( 'https://example.test/wp-content/assets/sources/direct.svg', Icons::url( '' ) );
		$this->assertSame( 'https://example.test/wp-content/assets/sources/email.svg', Icons::url( 'Fastmail' ) );
		$this->assertSame( 'https://example.test/wp-content/assets/sources/website.svg', Icons::url( 'producerblog.net' ) );
	}

	/**
	 * A file name from somewhere else cannot leave the folder, and one that
	 * is not there is the globe.
	 */
	public function test_a_file_name_stays_in_the_folder(): void {
		$globe = 'https://example.test/wp-content/assets/sources/website.svg';

		$this->assertSame( 'https://example.test/wp-content/assets/sources/vk.svg', Icons::file_url( 'vk' ) );
		$this->assertSame( $globe, Icons::file_url( '../../wp-config' ) );
		$this->assertSame( $globe, Icons::file_url( 'reddit.svg' ) );
		$this->assertSame( $globe, Icons::file_url( "reddit\n" ) );
		$this->assertSame( $globe, Icons::file_url( 'Reddit' ) );
		$this->assertSame( $globe, Icons::file_url( 'nosuchmark' ) );
		$this->assertSame( $globe, Icons::file_url( '' ) );
	}

	/**
	 * A colour for a source that has one, and nothing for the rest.
	 */
	public function test_the_colour(): void {
		$this->assertSame( '#ff4500', Icons::color( 'Reddit' ) );
		$this->assertSame( '#ff4500', Icons::color( ' Reddit Ads ' ) );
		$this->assertSame( '', Icons::color( 'producerblog.net' ) );
		$this->assertSame( '', Icons::color( '' ) );

		foreach ( Icons::colors() as $name => $color ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}\z/', $color, $name );
		}
	}
}
