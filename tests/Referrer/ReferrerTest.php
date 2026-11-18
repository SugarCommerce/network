<?php
/**
 * Referrer parsing tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Referrer;

use SugarCommerce\Network\Referrer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this library does is classify a URL somebody else sent.
 *
 * The classification is table-driven -- a list of domains per search engine,
 * another per social platform -- and a table lookup that quietly misses is the
 * failure mode worth guarding. Nothing errors. The visit is simply counted as
 * a plain referral, and the only way to notice is to look at a report and
 * wonder why no traffic ever comes from Google.
 */
final class ReferrerTest extends TestCase {

	/**
	 * Clear the stubbed options, filters and superglobal.
	 */
	protected function setUp(): void {
		ru_reset_globals();
	}

	/**
	 * And again, so nothing leaks.
	 */
	protected function tearDown(): void {
		ru_reset_globals();
	}

	/**
	 * A search engine is recognised whether or not the host carries `www.`.
	 *
	 * This is what the library got wrong. Referrers arrive as
	 * `www.google.com`; the table lists `google.com`; the comparison was
	 * exact, so Google -- twenty-one domains of it -- was never recognised at
	 * all and every search visit was filed as a referral. A few entries did
	 * carry their `www.` form, which is why the tables looked right and some
	 * platforms worked.
	 *
	 * @param string $url    The referrer.
	 * @param string $engine The engine it should be attributed to.
	 */
	#[DataProvider( 'searchEngineProvider' )]
	public function test_a_search_engine_is_recognised_with_or_without_www( string $url, string $engine ): void {
		$this->assertSame( $engine, Referrer::get_search_engine( $url ) );
		$this->assertTrue( Referrer::is_search_engine( $url ) );
		$this->assertSame( 'search', Referrer::get_traffic_source( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function searchEngineProvider(): array {
		return [
			'google with www'    => [ 'https://www.google.com/search?q=hats', 'google' ],
			'google without'     => [ 'https://google.com/search?q=hats', 'google' ],
			'a country google'   => [ 'https://www.google.co.uk/search?q=hats', 'google' ],
			'bing'               => [ 'https://www.bing.com/search?q=hats', 'bing' ],
			'duckduckgo'         => [ 'https://duckduckgo.com/?q=hats', 'duckduckgo' ],
		];
	}

	/**
	 * A social platform is recognised the same way.
	 */
	public function test_a_social_platform_is_recognised_with_or_without_www(): void {
		$this->assertSame( 'facebook', Referrer::get_social_platform( 'https://www.facebook.com/x' ) );
		$this->assertSame( 'facebook', Referrer::get_social_platform( 'https://facebook.com/x' ) );
		$this->assertTrue( Referrer::is_social( 'https://facebook.com/x' ) );
		$this->assertSame( 'social', Referrer::get_traffic_source( 'https://facebook.com/x' ) );
	}

	/**
	 * Something that is neither is neither.
	 */
	public function test_an_ordinary_site_is_a_plain_referral(): void {
		$this->assertNull( Referrer::get_search_engine( 'https://blog.test/post' ) );
		$this->assertNull( Referrer::get_social_platform( 'https://blog.test/post' ) );
		$this->assertFalse( Referrer::is_search_engine( 'https://blog.test/post' ) );
		$this->assertFalse( Referrer::is_social( 'https://blog.test/post' ) );
		$this->assertSame( 'referral', Referrer::get_traffic_source( 'https://blog.test/post' ) );
	}

	/**
	 * No referrer at all is direct traffic.
	 */
	public function test_no_referrer_is_direct(): void {
		$this->assertNull( Referrer::get() );
		$this->assertSame( 'direct', Referrer::get_traffic_source() );
		$this->assertFalse( Referrer::is_valid() );
		$this->assertNull( Referrer::get_domain() );
	}

	/**
	 * Search terms are pulled out of the query.
	 */
	public function test_search_terms_are_extracted(): void {
		$this->assertSame( 'felt hats', Referrer::get_search_terms( 'https://www.google.com/search?q=felt+hats' ) );
		$this->assertSame( 'felt hats', Referrer::get_search_terms( 'https://www.bing.com/search?q=felt%20hats' ) );
	}

	/**
	 * A site that is not a search engine has no search terms, whatever its
	 * query string says.
	 */
	public function test_a_non_search_engine_has_no_search_terms(): void {
		$this->assertNull( Referrer::get_search_terms( 'https://blog.test/?q=hats' ) );
	}

	/**
	 * The root domain drops subdomains without eating the registered name.
	 *
	 * Taking the last two labels gives "co.uk" for every site in Britain,
	 * which groups the entire country under one referrer.
	 *
	 * @param string $url    The referrer.
	 * @param string $expect The registered domain.
	 */
	#[DataProvider( 'rootDomainProvider' )]
	public function test_the_root_domain_survives_a_compound_suffix( string $url, string $expect ): void {
		$this->assertSame( $expect, Referrer::get_root_domain( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function rootDomainProvider(): array {
		return [
			'a subdomain'         => [ 'https://sub.example.com/x', 'example.com' ],
			'www'                 => [ 'https://www.example.com/x', 'example.com' ],
			'already bare'        => [ 'https://example.com/x', 'example.com' ],
			'a British domain'    => [ 'https://www.example.co.uk/x', 'example.co.uk' ],
			'and a subdomain too' => [ 'https://news.bbc.co.uk/x', 'bbc.co.uk' ],
			'an Australian one'   => [ 'https://a.b.example.com.au/x', 'example.com.au' ],
			'a single label'      => [ 'http://localhost/x', 'localhost' ],
		];
	}

	/**
	 * The host comes back as sent, subdomain and all.
	 */
	public function test_the_domain_is_the_host_as_sent(): void {
		$this->assertSame( 'news.bbc.co.uk', Referrer::get_domain( 'https://news.bbc.co.uk/story' ) );
		$this->assertNull( Referrer::get_domain( 'not a url' ) );
	}

	/**
	 * Only something that is actually a URL is valid.
	 *
	 * The value arrives in a request header, so this is the gate everything
	 * else sits behind.
	 *
	 * @param string $value What arrived.
	 * @param bool   $valid Whether it should be accepted.
	 */
	#[DataProvider( 'validityProvider' )]
	public function test_only_a_url_is_valid( string $value, bool $valid ): void {
		$this->assertSame( $valid, Referrer::is_valid( $value ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function validityProvider(): array {
		return [
			'an https url'    => [ 'https://example.test/a', true ],
			'an http url'     => [ 'http://example.test/a', true ],
			'prose'           => [ 'not a url', false ],
			'empty'           => [ '', false ],
			'a bare host'     => [ 'example.test', false ],
			'a javascript'    => [ 'javascript:alert(1)', false ],
		];
	}

	/**
	 * The referrer is read from the request, unslashed and stripped.
	 */
	public function test_the_referrer_is_read_from_the_request(): void {
		$_SERVER['HTTP_REFERER'] = 'https://example.test/a?b=1';

		$this->assertSame( 'https://example.test/a?b=1', Referrer::get() );
	}

	/**
	 * Slashes core added are taken back off.
	 *
	 * Asserted on the apostrophe rather than a quote mark, because
	 * esc_url_raw() strips double quotes outright and the test would pass
	 * whether anything unslashed the value or not.
	 */
	public function test_the_referrer_is_unslashed(): void {
		$_SERVER['HTTP_REFERER'] = "https://example.test/it\\'s";

		$referrer = (string) Referrer::get();

		$this->assertStringNotContainsString( '\\', $referrer, 'The slashes core added are still on.' );
		$this->assertSame( "https://example.test/it's", $referrer );
	}

	/**
	 * Markup in the header does not survive.
	 */
	public function test_markup_in_the_referrer_is_stripped(): void {
		$_SERVER['HTTP_REFERER'] = 'https://example.test/<script>alert(1)</script>';

		$referrer = (string) Referrer::get();

		$this->assertStringNotContainsString( '<script', $referrer );
	}

	/**
	 * A blank header reads as no referrer at all.
	 */
	public function test_a_blank_referrer_is_no_referrer(): void {
		$_SERVER['HTTP_REFERER'] = '';

		$this->assertNull( Referrer::get() );
	}

	/**
	 * Whether a referrer is off-site is judged against the site's own domain.
	 */
	public function test_external_is_judged_against_the_site_domain(): void {
		$this->assertTrue( Referrer::is_external( 'https://other.test/x' ) );
		$this->assertFalse( Referrer::is_external( 'https://example.test/x' ) );

		$this->assertTrue( Referrer::is_internal( 'https://example.test/x' ) );
		$this->assertFalse( Referrer::is_internal( 'https://other.test/x' ) );
	}

	/**
	 * The site to compare against can be given.
	 */
	public function test_the_site_to_compare_against_can_be_given(): void {
		$this->assertFalse( Referrer::is_external( 'https://other.test/x', 'other.test' ) );
		$this->assertTrue( Referrer::is_external( 'https://other.test/x', 'example.test' ) );
	}

	/**
	 * No referrer is not external.
	 *
	 * Nothing to compare, so nothing to claim. Answering true would file every
	 * direct visit as an external referral.
	 */
	public function test_no_referrer_is_not_external(): void {
		$this->assertFalse( Referrer::is_external() );
	}

	/**
	 * UTM parameters are read off the query string.
	 */
	public function test_utm_parameters_are_read(): void {
		$url = 'https://example.test/?utm_source=news&utm_medium=email&utm_campaign=spring&utm_term=hats&utm_content=top';

		$this->assertSame(
			[
				'source'   => 'news',
				'medium'   => 'email',
				'campaign' => 'spring',
				'term'     => 'hats',
				'content'  => 'top',
			],
			Referrer::get_utm_parameters( $url )
		);

		$this->assertSame( 'news', Referrer::get_utm_parameter( 'source', $url ) );
		$this->assertNull( Referrer::get_utm_parameter( 'nonsense', $url ) );
	}

	/**
	 * A URL with no UTM parameters gives the shape with nothing in it.
	 *
	 * The keys are always there, so reporting code does not have to check
	 * before reading.
	 */
	public function test_a_url_with_no_utm_still_gives_the_shape(): void {
		$this->assertSame(
			[
				'source'   => null,
				'medium'   => null,
				'campaign' => null,
				'term'     => null,
				'content'  => null,
			],
			Referrer::get_utm_parameters( 'https://example.test/' )
		);
	}

	/**
	 * An invalid referrer gives the shape too, not an empty array.
	 *
	 * The branch that matters most, because it is the one every direct visit
	 * takes: get_campaign_source() and get_traffic_source() both read
	 * $params['source'] straight out, so an empty array is an undefined key
	 * warning on the commonest path through the library.
	 *
	 * @param string|null $referrer What arrived, if anything.
	 */
	#[DataProvider( 'noUtmProvider' )]
	public function test_anything_without_utm_still_gives_the_shape( ?string $referrer ): void {
		$this->assertSame(
			[ 'source', 'medium', 'campaign', 'term', 'content' ],
			array_keys( Referrer::get_utm_parameters( $referrer ) )
		);

		// And the two readers of it come back clean rather than warning.
		$this->assertNull( Referrer::get_campaign_source( $referrer ) );
		$this->assertIsString( Referrer::get_traffic_source( $referrer ) );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function noUtmProvider(): array {
		return [
			'no referrer at all' => [ null ],
			'not a url'          => [ 'not a url' ],
			'empty'              => [ '' ],
			'a url with no query'=> [ 'https://example.test/' ],
			'a query, no utm'    => [ 'https://example.test/?a=1' ],
		];
	}

	/**
	 * A campaign source outranks the referring domain.
	 *
	 * The tag names the source wherever the link was clicked. It only decides
	 * the channel when nothing else does: a tagged link on somebody's blog is
	 * still a referral, and the same link with no referrer worth the name --
	 * here, the site's own landing page -- is a campaign.
	 */
	public function test_a_tagged_campaign_is_the_source(): void {
		$this->assertSame( 'campaign', Referrer::get_traffic_source( 'https://example.test/?utm_source=spring-launch' ) );

		$tagged = Referrer::analyse(
			[
				'referrer' => 'https://blog.test/post',
				'url'      => 'https://example.test/?utm_source=spring-launch',
			]
		);

		$this->assertSame( 'spring-launch', $tagged['source'] );
		$this->assertSame( 'referral', $tagged['channel'] );
	}

	/**
	 * A tag that names a channel is filed under that channel, not under
	 * "campaign".
	 *
	 * `utm_source=newsletter` is an email and `utm_medium=cpc` is an ad, and
	 * a report with every tagged link in one bucket cannot tell them apart.
	 *
	 * @param string $url     The tagged URL.
	 * @param string $channel The channel it should be filed under.
	 */
	#[DataProvider( 'taggedProvider' )]
	public function test_a_tag_that_names_a_channel_is_that_channel( string $url, string $channel ): void {
		$this->assertSame( $channel, Referrer::get_traffic_source( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function taggedProvider(): array {
		return [
			'a newsletter'         => [ 'https://blog.test/?utm_source=newsletter', 'email' ],
			'cost per click'       => [ 'https://blog.test/?utm_source=partner&utm_medium=cpc', 'paid' ],
			'typed in capitals'    => [ 'https://blog.test/?utm_source=partner&utm_medium=CPC', 'paid' ],
			'a known source'       => [ 'https://blog.test/?utm_source=tiktok', 'social' ],
			'a host as the source' => [ 'https://blog.test/?utm_source=chatgpt.com', 'ai' ],
			'a google click id'    => [ 'https://blog.test/?gclid=abc', 'paid' ],
			'a medium on its own'  => [ 'https://blog.test/?utm_medium=email', 'referral' ],
		];
	}

	/**
	 * A host is matched by whole labels, never as a substring.
	 *
	 * `t.co` is inside `microsoft.com` and `x.com` is inside `netflix.com`.
	 * Match on substrings and half the web is Twitter.
	 *
	 * @param string      $url      The referrer.
	 * @param string|null $platform The social platform, or null for none.
	 */
	#[DataProvider( 'wholeLabelProvider' )]
	public function test_a_host_is_matched_by_whole_labels( string $url, ?string $platform ): void {
		$this->assertSame( $platform, Referrer::get_social_platform( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function wholeLabelProvider(): array {
		return [
			'the short link'        => [ 'https://t.co/abc123', 'twitter' ],
			'a subdomain'           => [ 'https://old.reddit.com/r/x', 'reddit' ],
			'a subdomain not named' => [ 'https://out.reddit.com/t3_abc', 'reddit' ],
			'a link shim'           => [ 'https://l.facebook.com/l.php?u=x', 'facebook' ],
			'a country domain'      => [ 'https://uk.pinterest.co.uk/', 'pinterest' ],
			'any mastodon server'   => [ 'https://mastodon.art/@x', 'mastodon' ],
			'contains t.co'         => [ 'https://www.microsoft.com/', null ],
			'contains x.com'        => [ 'https://www.netflix.com/', null ],
			'ends like reddit'      => [ 'https://notreddit.com/', null ],
			'reddit as a subdomain' => [ 'https://reddit.com.evil.io/', null ],
		];
	}

	/**
	 * Google is its search host on any country domain, and nothing else of
	 * Google's.
	 *
	 * A shared document, an inbox and an assistant are not searches, and a
	 * sign-in page is not a source at all.
	 */
	public function test_google_search_is_not_the_rest_of_google(): void {
		$this->assertSame( 'google', Referrer::get_search_engine( 'https://www.google.pt/' ) );
		$this->assertSame( 'google', Referrer::get_search_engine( 'https://www.google.com.au/' ) );
		$this->assertSame( 'google-news', Referrer::get_search_engine( 'https://news.google.com/articles/x' ) );

		$this->assertNull( Referrer::get_search_engine( 'https://docs.google.com/document/d/1' ) );
		$this->assertSame( 'referral', Referrer::get_traffic_source( 'https://docs.google.com/document/d/1' ) );

		$this->assertNull( Referrer::get_search_engine( 'https://mail.google.com/mail/u/0/' ) );
		$this->assertSame( 'gmail', Referrer::get_email_provider( 'https://mail.google.com/mail/u/0/' ) );
		$this->assertTrue( Referrer::is_email( 'https://mail.google.com/mail/u/0/' ) );
		$this->assertSame( 'email', Referrer::get_traffic_source( 'https://mail.google.com/mail/u/0/' ) );

		$this->assertSame( 'direct', Referrer::get_traffic_source( 'https://accounts.google.com/signin' ) );
	}

	/**
	 * An AI assistant is its own channel, not a search engine.
	 *
	 * They were listed with the search engines. Nobody who followed a link
	 * ChatGPT cited typed a search, and `gemini.google.com` is not Google.
	 *
	 * @param string $url       The referrer.
	 * @param string $assistant The assistant it should be attributed to.
	 */
	#[DataProvider( 'assistantProvider' )]
	public function test_an_ai_assistant_is_not_a_search_engine( string $url, string $assistant ): void {
		$this->assertSame( $assistant, Referrer::get_ai_assistant( $url ) );
		$this->assertTrue( Referrer::is_ai_assistant( $url ) );
		$this->assertNull( Referrer::get_search_engine( $url ) );
		$this->assertSame( 'ai', Referrer::get_traffic_source( $url ) );
		$this->assertSame( $assistant, Referrer::get_source( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function assistantProvider(): array {
		return [
			'chatgpt'          => [ 'https://chatgpt.com/', 'chatgpt' ],
			'its old address'  => [ 'https://chat.openai.com/', 'chatgpt' ],
			'claude'           => [ 'https://claude.ai/chat/abc', 'claude' ],
			'perplexity'       => [ 'https://www.perplexity.ai/search/x', 'perplexity' ],
			'gemini'           => [ 'https://gemini.google.com/app', 'gemini' ],
			'copilot'          => [ 'https://copilot.microsoft.com/', 'copilot' ],
			'you.com'          => [ 'https://you.com/search?q=x', 'you' ],
			'deepseek, bare'   => [ 'https://www.deepseek.com/', 'deepseek' ],
		];
	}

	/**
	 * A condition saved against an identifier that has since moved goes on
	 * matching.
	 *
	 * `perplexity` was a search engine and is an assistant; `searchgpt` is
	 * `chatgpt`; `twitter` is still what X is stored as.
	 */
	public function test_a_saved_condition_still_matches(): void {
		$this->assertTrue( Referrer::is_match( 'perplexity', 'https://www.perplexity.ai/' ) );
		$this->assertTrue( Referrer::is_match( 'searchgpt', 'https://chatgpt.com/' ) );
		$this->assertTrue( Referrer::is_match( 'chatgpt', 'https://chatgpt.com/' ) );
		$this->assertTrue( Referrer::is_match( 'twitter', 'https://x.com/someone' ) );
		$this->assertTrue( Referrer::is_match( [ 'bing', 'ai' ], 'https://claude.ai/' ) );
		$this->assertTrue( Referrer::is_match( 'gmail', 'https://mail.google.com/' ) );

		$this->assertFalse( Referrer::is_match( 'search', 'https://chatgpt.com/' ) );
		$this->assertFalse( Referrer::is_match( 'twitter', 'https://www.microsoft.com/' ) );
		$this->assertFalse( Referrer::is_match( 42, 'https://x.com/' ) );
	}

	/**
	 * The search is read from the parameter that engine uses.
	 *
	 * Trying every known parameter on every engine reads `?p=2` -- a page
	 * number -- as what somebody searched for.
	 */
	public function test_search_terms_come_from_the_engines_own_parameter(): void {
		$this->assertSame( 'sample packs', Referrer::get_search_terms( 'https://uk.search.yahoo.com/search?p=sample+packs' ) );
		$this->assertSame( 'serum presets', Referrer::get_search_terms( 'https://www.baidu.com/s?wd=serum+presets' ) );
		$this->assertSame( 'kick drums', Referrer::get_search_terms( 'https://yandex.ru/search/?text=kick+drums' ) );
		$this->assertSame( 'vital presets', Referrer::get_search_terms( 'https://www.startpage.com/do/search?query=vital+presets' ) );

		$this->assertNull( Referrer::get_search_terms( 'https://www.google.com/search?p=2' ) );
		$this->assertNull( Referrer::get_search_terms( 'https://news.google.com/search?q=hats' ) );
	}

	/**
	 * A search term is cut to a tag like everything else a stranger typed.
	 */
	public function test_search_terms_are_cut_to_a_tag(): void {
		$this->assertSame( 'hats b cheap b', Referrer::get_search_terms( 'https://www.bing.com/search?q=hats+%3Cb%3Echeap%3C%2Fb%3E' ) );
		$this->assertSame( 60, mb_strlen( (string) Referrer::get_search_terms( 'https://www.bing.com/search?q=' . str_repeat( 'a', 200 ) ) ) );
	}

	/**
	 * A subdomain of the site is the site.
	 *
	 * Hosts were compared exactly, so a visitor going from `blog.` to `shop.`
	 * -- or from `www.` to the bare domain -- arrived as an external referral
	 * from the shop's own blog.
	 */
	public function test_a_subdomain_of_the_site_is_internal(): void {
		$this->assertTrue( Referrer::is_internal( 'https://www.example.test/x' ) );
		$this->assertTrue( Referrer::is_internal( 'https://blog.example.test/x' ) );
		$this->assertFalse( Referrer::is_external( 'https://blog.example.test/x' ) );
		$this->assertTrue( Referrer::is_internal( 'https://shop.example.co.uk/cart/', 'https://www.example.co.uk/' ) );
		$this->assertFalse( Referrer::is_internal( 'https://other.co.uk/', 'https://www.example.co.uk/' ) );

		$this->assertSame( 'internal', Referrer::get_source( 'https://blog.example.test/x' ) );
		$this->assertSame( 'direct', Referrer::get_traffic_source( 'https://blog.example.test/x' ) );
	}

	/**
	 * No referrer is not internal, any more than it is external.
	 *
	 * is_internal() was "not external", so nothing at all came back as a
	 * visit from the site itself.
	 */
	public function test_no_referrer_is_not_internal(): void {
		$this->assertFalse( Referrer::is_internal() );
		$this->assertFalse( Referrer::is_internal( 'not a url' ) );
		$this->assertSame( 'direct', Referrer::get_source( 'not a url' ) );
	}

	/**
	 * A referrer that cannot be used is direct, not a category of its own.
	 *
	 * @param string $referrer What arrived.
	 */
	#[DataProvider( 'unusableProvider' )]
	public function test_an_unusable_referrer_is_direct( string $referrer ): void {
		$this->assertFalse( Referrer::is_valid( $referrer ) );
		$this->assertSame( 'direct', Referrer::get_traffic_source( $referrer ) );
		$this->assertNull( Referrer::get_domain( $referrer ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusableProvider(): array {
		return [
			'prose'           => [ 'not a url' ],
			'a javascript'    => [ 'javascript:alert(1)' ],
			'a data url'      => [ 'data:text/html,<b>x</b>' ],
			'another scheme'  => [ 'ftp://files.example.com/x' ],
			'a control char'  => [ "https://example.com/a\nb" ],
			'absurdly long'   => [ 'https://example.com/' . str_repeat( 'x', 3000 ) ],
		];
	}

	/**
	 * A return from the checkout is the same visit.
	 *
	 * Without this Stripe is every shop's best referrer.
	 */
	public function test_a_payment_page_is_never_a_source(): void {
		$this->assertSame( 'internal', Referrer::get_source( 'https://checkout.stripe.com/c/pay/cs_1' ) );
		$this->assertSame( 'direct', Referrer::get_traffic_source( 'https://www.paypal.com/checkoutnow' ) );
		$this->assertTrue( Referrer::is_ignored_host( 'partner.test', [ 'partner.test' ] ) );
		$this->assertFalse( Referrer::is_ignored_host( 'partner.test' ) );
	}

	/**
	 * The whole visit is read from the request.
	 *
	 * The tags are on the landing page's own address, which is the request,
	 * not the referrer.
	 */
	public function test_the_whole_visit_is_read_from_the_request(): void {
		$_SERVER['HTTP_REFERER'] = 'https://www.google.com/';
		$_GET                    = [
			'utm_source'   => 'newsletter',
			'utm_medium'   => 'Email',
			'utm_campaign' => "spring\\'s <b>sale</b>",
		];

		$source = Referrer::from_request();

		$_GET = [];

		$this->assertSame( 'email', $source['channel'] );
		$this->assertSame( 'Email', $source['source'] );
		$this->assertSame( 'email', $source['medium'] );
		$this->assertSame( 'spring s b sale b', $source['campaign'] );
		$this->assertSame( 'google.com', $source['referrer_host'] );
		$this->assertSame( 'Email', $source['label'] );
	}

	/**
	 * An Android app's referrer survives being read from the request.
	 *
	 * get() is a URL sanitizer and drops it, which is right for get() and
	 * would file every tap from the Gmail app as direct.
	 */
	public function test_an_android_app_is_read_from_the_request(): void {
		$_SERVER['HTTP_REFERER'] = 'android-app://com.google.android.gm/';

		$this->assertNull( Referrer::get() );

		$source = Referrer::from_request( null );

		$this->assertSame( [ 'email', 'Gmail' ], [ $source['channel'], $source['source'] ] );
	}

	/**
	 * The site's own pages are internal when read from the request, and the
	 * in-app browser is passed through.
	 */
	public function test_the_request_knows_its_own_site(): void {
		$_SERVER['HTTP_REFERER'] = 'https://www.example.test/cart/';

		$this->assertTrue( Referrer::from_request()['internal'] );
		$this->assertSame( 'TikTok', Referrer::from_request( 'TikTok' )['source'] );
	}

	/**
	 * A repeated parameter is read the way a browser reads it: the first one.
	 */
	public function test_the_first_of_a_repeated_parameter_counts(): void {
		$this->assertSame( 'first', Referrer::params_from( [ 'query' => 'utm_source=first&utm_source=second' ] )['utm_source'] );
		$this->assertSame( 'first', Referrer::params_from( [ 'query' => [ 'utm_source' => [ 'first', 'second' ] ] ] )['utm_source'] );
		$this->assertSame( 'x', Referrer::params_from( [ 'url' => '/p/prism?gclid=x#utm_source=no' ] )['gclid'] );
		$this->assertSame( [], Referrer::params_from( [] ) );
	}

	/**
	 * A click ID is recorded by name, never by value.
	 *
	 * The value identifies one click. Which network sent it is all anybody
	 * needs to keep.
	 */
	public function test_a_click_id_is_recorded_by_name_only(): void {
		$source = Referrer::analyse( [ 'url' => 'https://shop.test/?ttclid=E.C.P.123' ] );

		$this->assertSame(
			[
				'param'   => 'ttclid',
				'network' => 'TikTok Ads',
			],
			$source['click_id']
		);
		$this->assertStringNotContainsString( 'E.C.P.123', (string) json_encode( $source ) );
	}

	/**
	 * Every click ID that is read is also one Tracking strips.
	 *
	 * Reading it and cleaning it are two halves of one job, in two tables.
	 */
	public function test_every_click_id_is_also_stripped(): void {
		foreach ( \SugarCommerce\Network\Referrer\Campaigns::CLICK_IDS as [ $param ] ) {
			$url = 'https://example.test/?' . $param . '=1&keep=2';

			$this->assertSame( 'https://example.test/?keep=2', \SugarCommerce\Network\Referrer\Tracking::strip( $url ), $param );
		}
	}

	/**
	 * A source is described in a phrase.
	 *
	 * @param array<string, string|null> $source The source.
	 * @param string                     $label  The phrase.
	 */
	#[DataProvider( 'labelProvider' )]
	public function test_a_source_is_described( array $source, string $label ): void {
		$this->assertSame( $label, Referrer::describe_source( $source ) );
	}

	/**
	 * @return array<string, array{0: array<string, string|null>, 1: string}>
	 */
	public static function labelProvider(): array {
		return [
			'a search'              => [ [ 'channel' => 'search', 'source' => 'Google' ], 'Google search' ],
			'a search with a term'  => [ [ 'channel' => 'search', 'source' => 'Bing', 'term' => 'hats' ], 'Bing search for "hats"' ],
			'a named email'         => [ [ 'channel' => 'email', 'source' => 'Mailchimp' ], 'Email (Mailchimp)' ],
			'an email'              => [ [ 'channel' => 'email', 'source' => 'Email' ], 'Email' ],
			'an ad'                 => [ [ 'channel' => 'paid', 'source' => 'TikTok', 'campaign' => 'launch' ], 'TikTok ad, campaign "launch"' ],
			'an ad network'         => [ [ 'channel' => 'paid', 'source' => 'Google Ads' ], 'Google Ads' ],
			'a campaign'            => [ [ 'channel' => 'campaign', 'source' => 'podcast', 'campaign' => 'spring' ], 'campaign "spring"' ],
			'a campaign, no name'   => [ [ 'channel' => 'campaign', 'source' => 'podcast' ], 'campaign "podcast"' ],
			'social'                => [ [ 'channel' => 'social', 'source' => 'Reddit' ], 'Reddit' ],
			'direct'                => [ [ 'channel' => 'direct', 'source' => 'Direct' ], 'Direct' ],
		];
	}

	/**
	 * The summary carries every part in one call.
	 */
	public function test_the_summary_carries_every_part(): void {
		$info = Referrer::get_referrer_info( 'https://www.google.com/search?q=hats' );

		$this->assertSame( 'www.google.com', $info['domain'] );
		$this->assertSame( 'google.com', $info['root_domain'] );
		$this->assertSame( 'google', $info['search_engine'] );
		$this->assertSame( 'search', $info['traffic_source'] );
		$this->assertSame( 'hats', $info['search_terms'] );
		$this->assertNull( $info['social_platform'] );
		$this->assertNull( $info['ai_assistant'] );
		$this->assertNull( $info['email_provider'] );
	}

	/**
	 * The option lists come in both shapes and are not empty.
	 *
	 * @param string $method The getter.
	 */
	#[DataProvider( 'optionProvider' )]
	public function test_an_option_list_comes_in_both_shapes( string $method ): void {
		$map = Referrer::$method();

		$this->assertNotEmpty( $map );

		$options = Referrer::$method( true );

		$this->assertCount( count( $map ), $options );
		$this->assertSame( [ 'value', 'label' ], array_keys( $options[0] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function optionProvider(): array {
		return [
			'search engines'  => [ 'get_search_engine_options' ],
			'social networks' => [ 'get_social_platform_options' ],
			'ai assistants'   => [ 'get_ai_assistant_options' ],
			'webmail'         => [ 'get_email_provider_options' ],
			'traffic sources' => [ 'get_traffic_source_options' ],
			'utm parameters'  => [ 'get_utm_parameter_options' ],
		];
	}

	/**
	 * Every search engine and platform in the tables can be selected.
	 *
	 * The dropdown and the classifier read the same tables, so a key in one
	 * and not the other gives an option nothing ever matches.
	 */
	public function test_every_listed_engine_and_platform_is_selectable(): void {
		$this->assertArrayHasKey( 'google', Referrer::get_search_engine_options() );
		$this->assertArrayHasKey( 'bing', Referrer::get_search_engine_options() );
		$this->assertArrayHasKey( 'facebook', Referrer::get_social_platform_options() );

		// The identifiers that were stored before the sources had names.
		$this->assertSame( 'X', Referrer::get_social_platform_options()['twitter'] );
		$this->assertSame( 'You.com', Referrer::get_ai_assistant_options()['you'] );
		$this->assertArrayHasKey( 'gmail', Referrer::get_email_provider_options() );

		// And nothing the classifier no longer calls a search engine.
		$this->assertArrayNotHasKey( 'perplexity', Referrer::get_search_engine_options() );
		$this->assertArrayHasKey( 'perplexity', Referrer::get_ai_assistant_options() );
	}

	/**
	 * Whatever the classifier returns for a host is in the list it belongs to.
	 *
	 * Checked across every table rather than by example, because the lists
	 * are built from the tables and this is what proves it.
	 */
	public function test_every_classified_source_is_selectable(): void {
		$lists = [
			'search' => [ \SugarCommerce\Network\Referrer\Sources::SEARCH_ENGINES, Referrer::get_search_engine_options(), 'get_search_engine' ],
			'social' => [ \SugarCommerce\Network\Referrer\Sources::SOCIAL_NETWORKS, Referrer::get_social_platform_options(), 'get_social_platform' ],
			'ai'     => [ \SugarCommerce\Network\Referrer\Sources::AI_ASSISTANTS, Referrer::get_ai_assistant_options(), 'get_ai_assistant' ],
			'email'  => [ \SugarCommerce\Network\Referrer\Sources::WEBMAIL, Referrer::get_email_provider_options(), 'get_email_provider' ],
		];

		foreach ( $lists as [ $table, $options, $getter ] ) {
			foreach ( $table as $entry ) {
				// A pattern has no single host to try; the domains cover it.
				if ( str_starts_with( $entry[0], '/' ) ) {
					continue;
				}

				$slug = Referrer::$getter( 'https://' . $entry[0] . '/' );

				$this->assertNotNull( $slug, $entry[0] . ' is listed and not recognised.' );
				$this->assertSame( $entry[1], $options[ $slug ] ?? null, $entry[0] );
			}
		}
	}

	/**
	 * The traffic sources on offer are exactly the channels there are.
	 */
	public function test_the_traffic_sources_are_the_channels(): void {
		$this->assertSame( Referrer::CHANNELS, array_keys( Referrer::get_traffic_source_options() ) );
	}
}
