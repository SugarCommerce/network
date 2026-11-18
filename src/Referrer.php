<?php
/**
 * Referrer Utility Class
 *
 * Provides utility functions for HTTP referrer detection, validation,
 * and analysis for traffic sources and security.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Referrer\Campaigns;
use SugarCommerce\Network\Referrer\Sources;

/**
 * Referrer Class
 *
 * Core operations for working with HTTP referrer headers.
 *
 * The rules -- which hosts are a search engine, what a click ID means, what
 * beats what -- are the ones the JavaScript package @flarecart/referrer uses,
 * so a visit is filed the same way whichever side saw it. The tables are in
 * Sources and Campaigns; analyse() is that package's analyse().
 */
class Referrer {

	/**
	 * The channels a source is filed under.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	public const CHANNELS = [ 'search', 'social', 'email', 'ai', 'paid', 'campaign', 'referral', 'direct' ];

	/**
	 * The longest referrer accepted.
	 *
	 * Browsers send far less; anything longer is padding aimed at a column.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const MAX_URL_LENGTH = 2048;

	/**
	 * The longest tag kept.
	 *
	 * A campaign name fits; an instruction to an AI agent does not.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const TAG_MAX = 60;

	/**
	 * The longest landing path kept.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const LANDING_MAX = 120;

	/**
	 * Two-label public suffixes, where the registered domain is the third
	 * label from the end rather than the second.
	 *
	 * Not the full Public Suffix List -- that is several thousand entries and
	 * a dependency that has to be kept current. This is the set that covers
	 * the countries this library already tracks search engines for, which is
	 * where its referrers actually come from. A suffix that is not listed
	 * falls back to the last two labels, which is right for the great
	 * majority of domains.
	 *
	 * @var string[]
	 */
	protected const COMPOUND_SUFFIXES = [
		'co.uk',
		'org.uk',
		'me.uk',
		'ac.uk',
		'gov.uk',
		'net.uk',
		'sch.uk',
		'com.au',
		'net.au',
		'org.au',
		'edu.au',
		'gov.au',
		'co.nz',
		'net.nz',
		'org.nz',
		'govt.nz',
		'co.za',
		'org.za',
		'co.jp',
		'ne.jp',
		'or.jp',
		'ac.jp',
		'go.jp',
		'co.in',
		'net.in',
		'org.in',
		'gov.in',
		'ac.in',
		'com.br',
		'net.br',
		'org.br',
		'gov.br',
		'com.mx',
		'org.mx',
		'com.ar',
		'com.tr',
		'gov.tr',
		'com.sa',
		'com.sg',
		'com.hk',
		'com.tw',
		'com.cn',
		'net.cn',
		'org.cn',
		'gov.cn',
		'co.kr',
		'or.kr',
		'com.pl',
		'com.es',
		'com.co',
		'co.il',
		'com.my',
		'com.ph',
		'co.th',
		'in.th',
		'ac.th',
		'co.id',
		'com.vn',
	];

	/**
	 * The identifiers this library used before its sources had display names.
	 *
	 * get_search_engine(), get_social_platform() and get_source() return a
	 * lower-case identifier, and those are stored: in order meta, in saved
	 * conditions. Most fall out of the name ("Google" is `google`). These two
	 * do not, and renaming them would orphan what is already saved.
	 *
	 * @since 1.1.0
	 * @var array<string, string> Name => identifier.
	 */
	protected const LEGACY_SLUGS = [
		'X'       => 'twitter',
		'You.com' => 'you',
	];

	/**
	 * Identifiers that were renamed, and what they are now.
	 *
	 * ChatGPT was listed as the search engine `searchgpt`. It is an AI
	 * assistant called `chatgpt` now, and a condition saved against the old
	 * name should go on matching.
	 *
	 * @since 1.1.0
	 * @var array<string, string> Old => current.
	 */
	protected const RENAMED_SLUGS = [
		'searchgpt' => 'chatgpt',
	];

	/**
	 * Get the current HTTP referrer.
	 *
	 * @return string|null The referrer URL or null if not available.
	 */
	public static function get(): ?string {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return null;
		}

		// esc_url_raw rather than a text sanitizer: a referrer is a URL, and
		// this is the one that knows what that means -- it drops schemes that
		// are not http(s) and percent-encodes what has no business being
		// unencoded. Everything downstream parses this value.
		$referrer = esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );

		return '' !== $referrer ? $referrer : null;
	}

	/**
	 * Check if a referrer URL is valid.
	 *
	 * Valid means usable as a referrer: http or https, with a host. An `ftp:`
	 * or `javascript:` URL is well-formed and still not somewhere a visitor
	 * came from.
	 *
	 * @param string|null $referrer Optional referrer URL to check.
	 *
	 * @return bool True if valid URL.
	 */
	public static function is_valid( ?string $referrer = null ): bool {
		$referrer = $referrer ?? self::get();

		return '' !== self::safe_url( $referrer );
	}

	/**
	 * Get the domain from a referrer URL.
	 *
	 * The host as sent, subdomain and `www.` included, in lower case. Use
	 * host_of() for the form the tables are matched against.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null The domain or null if invalid.
	 */
	public static function get_domain( ?string $referrer = null ): ?string {
		$referrer = self::safe_url( $referrer ?? self::get() );

		return '' !== $referrer ? self::parse_host( $referrer ) : null;
	}

	/**
	 * Get the root domain (without subdomains).
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null The root domain or null if invalid.
	 */
	public static function get_root_domain( ?string $referrer = null ): ?string {
		$domain = self::get_domain( $referrer );

		return $domain ? self::root_domain( $domain ) : null;
	}

	/**
	 * Check if referrer is from external domain.
	 *
	 * Judged by registered domain, so `www.example.com`, `shop.example.com`
	 * and `example.com` are one site. Comparing hosts exactly called a visitor
	 * moving from the blog to the shop an external referral.
	 *
	 * @param string|null $referrer     Optional referrer URL.
	 * @param string|null $current_site Optional current site domain or URL.
	 *
	 * @return bool True if external referrer.
	 */
	public static function is_external( ?string $referrer = null, ?string $current_site = null ): bool {
		$referrer_domain = self::get_domain( $referrer );

		if ( ! $referrer_domain ) {
			return false;
		}

		$current_domain = self::site_host( $current_site ?? self::get_current_domain() );

		return null === $current_domain || self::root_domain( $referrer_domain ) !== self::root_domain( $current_domain );
	}

	/**
	 * Check if referrer is from internal domain.
	 *
	 * No referrer is not internal: there is nothing to compare, so nothing to
	 * claim. This used to answer true for a missing or unusable referrer,
	 * because it was written as "not external".
	 *
	 * @param string|null $referrer     Optional referrer URL.
	 * @param string|null $current_site Optional current site domain or URL.
	 *
	 * @return bool True if internal referrer.
	 */
	public static function is_internal( ?string $referrer = null, ?string $current_site = null ): bool {
		if ( ! self::get_domain( $referrer ) ) {
			return false;
		}

		return ! self::is_external( $referrer, $current_site );
	}

	/**
	 * Get search engine from referrer.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null Search engine name or null if not found.
	 */
	public static function get_search_engine( ?string $referrer = null ): ?string {
		return self::source_slug( $referrer, 'search' );
	}

	/**
	 * Get search terms from referrer.
	 *
	 * Read from the parameter that engine uses -- `p` for Yahoo, `wd` for
	 * Baidu, `text` for Yandex -- and cut to a tag (see to_tag()).
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null Search terms or null if not found.
	 */
	public static function get_search_terms( ?string $referrer = null ): ?string {
		$referrer = self::safe_url( $referrer ?? self::get() );
		$host     = '' !== $referrer ? self::host_of( $referrer ) : null;

		if ( null === $host ) {
			return null;
		}

		$found = self::classify_host( $host, $referrer );

		return 'search' === $found['channel'] ? $found['term'] : null;
	}

	/**
	 * Check if referrer is from a search engine.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return bool True if from search engine.
	 */
	public static function is_search_engine( ?string $referrer = null ): bool {
		return self::get_search_engine( $referrer ) !== null;
	}

	/**
	 * Get social media platform from referrer.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null Social platform name or null if not found.
	 */
	public static function get_social_platform( ?string $referrer = null ): ?string {
		return self::source_slug( $referrer, 'social' );
	}

	/**
	 * Check if referrer is from social media.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return bool True if from social media.
	 */
	public static function is_social( ?string $referrer = null ): bool {
		return self::get_social_platform( $referrer ) !== null;
	}

	/**
	 * Get AI assistant from referrer.
	 *
	 * ChatGPT, Claude, Perplexity, Gemini and the rest. They were listed as
	 * search engines, where they were; they are their own channel because
	 * nobody who clicked a link an assistant cited typed a search.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null AI assistant name or null if not found.
	 */
	public static function get_ai_assistant( ?string $referrer = null ): ?string {
		return self::source_slug( $referrer, 'ai' );
	}

	/**
	 * Check if referrer is from an AI assistant.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return bool True if from an AI assistant.
	 */
	public static function is_ai_assistant( ?string $referrer = null ): bool {
		return self::get_ai_assistant( $referrer ) !== null;
	}

	/**
	 * Get webmail provider from referrer.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null Webmail provider name or null if not found.
	 */
	public static function get_email_provider( ?string $referrer = null ): ?string {
		return self::source_slug( $referrer, 'email' );
	}

	/**
	 * Check if referrer is from webmail.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return bool True if from webmail.
	 */
	public static function is_email( ?string $referrer = null ): bool {
		return self::get_email_provider( $referrer ) !== null;
	}

	/**
	 * Get UTM parameters from referrer.
	 *
	 * Each value is cut to a tag (see to_tag()) and the medium is lower-cased,
	 * so `CPC` and `cpc` are one medium.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return array UTM parameters array.
	 */
	public static function get_utm_parameters( ?string $referrer = null ): array {
		$referrer = $referrer ?? self::get();

		// The five keys are always present, even when there is nothing to put
		// in them. This used to return an empty array for a URL with no query
		// string, so get_campaign_source() and get_traffic_source() -- both of
		// which read $params['source'] straight out -- raised an undefined key
		// warning on every direct visit and every plain referral, which is
		// most of them.
		return self::read_utm( self::params_from( [ 'url' => (string) $referrer ] ) );
	}

	/**
	 * Get a specific UTM parameter value.
	 *
	 * @param string      $parameter The UTM parameter to retrieve: 'source', 'medium', 'campaign', 'term', or
	 *                               'content'.
	 * @param string|null $referrer  Optional referrer URL.
	 *
	 * @return string|null The parameter value or null if not found.
	 */
	public static function get_utm_parameter( string $parameter, ?string $referrer = null ): ?string {
		$valid_params = [ 'source', 'medium', 'campaign', 'term', 'content' ];
		$parameter    = strtolower( trim( $parameter ) );

		if ( ! in_array( $parameter, $valid_params, true ) ) {
			return null;
		}

		$utm_params = self::get_utm_parameters( $referrer );

		return $utm_params[ $parameter ] ?? null;
	}

	/**
	 * Get campaign source from referrer.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string|null Campaign source or null if not found.
	 */
	public static function get_campaign_source( ?string $referrer = null ): ?string {
		$utm_params = self::get_utm_parameters( $referrer );

		return $utm_params['source'];
	}

	/**
	 * Get traffic source type.
	 *
	 * The channel analyse() gives when the one URL is all there is: its host
	 * is the referrer, and its query string is read for UTM tags and click
	 * IDs. A referrer that is not usable is direct, as no referrer is.
	 *
	 * On a landing page the tags are on the page's own URL, not the
	 * referrer's, and this cannot see them. Use from_request() there.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string Traffic source type: 'search', 'social', 'email', 'ai', 'paid', 'campaign', 'referral' or
	 *                'direct'.
	 */
	public static function get_traffic_source( ?string $referrer = null ): string {
		$referrer = $referrer ?? self::get();

		$found = self::analyse(
			[
				'referrer' => $referrer,
				'url'      => (string) $referrer,
				'site_url' => self::get_current_domain(),
			]
		);

		return $found['channel'];
	}

	/**
	 * Get simplified referrer source for analytics.
	 *
	 * Returns the specific platform name (google, facebook, etc.)
	 * or a generic type (direct, internal, referral).
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return string Traffic source identifier.
	 */
	public static function get_source( ?string $referrer = null ): string {
		$referrer = $referrer ?? self::get();

		if ( empty( $referrer ) ) {
			return 'direct';
		}

		$found = self::analyse(
			[
				'referrer' => $referrer,
				'site_url' => self::get_current_domain(),
			]
		);

		if ( $found['internal'] ) {
			return 'internal';
		}

		return match ( $found['channel'] ) {
			'search', 'social', 'ai', 'email' => self::slug( $found['source'] ),
			'direct'                          => 'direct',
			default                           => 'referral',
		};
	}

	/**
	 * Get comprehensive referrer information.
	 *
	 * @param string|null $referrer Optional referrer URL.
	 *
	 * @return array Array of referrer information.
	 */
	public static function get_referrer_info( ?string $referrer = null ): array {
		$referrer = $referrer ?? self::get();

		return [
			'url'             => $referrer,
			'is_valid'        => self::is_valid( $referrer ),
			'domain'          => self::get_domain( $referrer ),
			'root_domain'     => self::get_root_domain( $referrer ),
			'is_external'     => self::is_external( $referrer ),
			'search_engine'   => self::get_search_engine( $referrer ),
			'search_terms'    => self::get_search_terms( $referrer ),
			'social_platform' => self::get_social_platform( $referrer ),
			'ai_assistant'    => self::get_ai_assistant( $referrer ),
			'email_provider'  => self::get_email_provider( $referrer ),
			'utm_parameters'  => self::get_utm_parameters( $referrer ),
			'traffic_source'  => self::get_traffic_source( $referrer ),
		];
	}

	// ========================================
	// The whole visit
	// ========================================

	/**
	 * Where a visit came from, from everything a page knows.
	 *
	 * The referrer is the weakest signal and the one most code relies on.
	 * Browsers trim it to an origin or drop it; social apps open links in
	 * their own browser and often send none. Ad networks and email tools put
	 * a click ID on the link instead, which survives. So, strongest first:
	 *
	 * 1. UTM tags: what the marketer deliberately put on the link. The medium
	 *    sets the channel when it is a known one; otherwise a paid click ID
	 *    makes it paid, a known source lends its channel, and anything else
	 *    is a campaign.
	 * 2. A click ID (`gclid`, `ttclid`, `fbclid`).
	 * 3. The in-app browser: the app is the source, referrer or not.
	 * 4. The referrer: webmail, then AI assistants, then search engines, then
	 *    social networks, then a referral named by its registered domain.
	 * 5. Direct, when there is nothing.
	 *
	 * A referrer from the site itself, or from a payment or sign-in page the
	 * visitor came back from, is `internal`: keep the source the visit already
	 * had rather than this one.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input {
	 *     What the page knows. All optional.
	 *
	 *     @type string|null  $referrer The Referer header. `android-app://<package>/` is understood too.
	 *     @type string       $url      The landing page's URL, to read UTM tags and click IDs from.
	 *     @type string|array $query    Or its query: a query string, or an array such as $_GET.
	 *     @type string|null  $in_app   The app whose in-app browser opened the page: "TikTok", "Instagram".
	 *                                  {@see UserAgent::in_app_browser()} names it.
	 *     @type string       $site_url Your own site's URL or host. A referrer from it is internal.
	 *     @type string[]     $ignore   More domains that are never a source.
	 * }
	 *
	 * @return array {
	 *     The channel, the source, and every signal it was decided from.
	 *
	 *     @type string      $channel       One of CHANNELS.
	 *     @type string      $source        "Google", "TikTok", "Gmail", "Google Ads", a tag, a domain, or "Direct".
	 *     @type string|null $medium        utm_medium, lower case.
	 *     @type string|null $campaign      utm_campaign.
	 *     @type string|null $term          utm_term, or the search a search engine's referrer carried.
	 *     @type string|null $content       utm_content.
	 *     @type array|null  $click_id      The click ID's `param` and `network`; never its value.
	 *     @type string|null $in_app        The in-app browser passed in.
	 *     @type string|null $referrer_host The referrer's host, without `www.`, when it was usable.
	 *     @type bool        $internal      The referrer was this site, or a payment or sign-in page.
	 *     @type string      $label         A short phrase: "Google search", "Email (Gmail)", "TikTok ad".
	 * }
	 */
	public static function analyse( array $input = [] ): array {
		$params   = self::params_from( $input );
		$utm      = self::read_utm( $params );
		$click    = self::find_click_id( $params );
		$raw      = (string) ( $input['referrer'] ?? '' );
		$referrer = self::read_referrer( $raw );
		$app      = self::classify_android_app( $raw );
		$in_app   = empty( $input['in_app'] ) ? null : (string) $input['in_app'];
		$internal = self::is_own_referrer( $referrer, $input );

		// What the visit itself showed, without its tags.
		$seen = match ( true ) {
			null !== $click                    => [
				'channel' => $click['channel'],
				'source'  => $click['network'],
			],
			null !== $in_app                   => self::from_in_app( $in_app ),
			null !== $app                      => $app,
			null !== $referrer && ! $internal  => self::classify_host( $referrer['host'], $referrer['url'] ),
			default                            => [
				'channel' => 'direct',
				'source'  => 'Direct',
			],
		};

		$decision = null !== $utm['source'] || null !== $utm['campaign'] ? self::from_utm( $utm, $click, $seen ) : $seen;

		$source = [
			'channel'       => $decision['channel'],
			'source'        => $decision['source'],
			'medium'        => $utm['medium'],
			'campaign'      => $utm['campaign'],
			'term'          => $utm['term'] ?? $decision['term'] ?? null,
			'content'       => $utm['content'],
			'click_id'      => null !== $click ? [
				'param'   => $click['param'],
				'network' => $click['network'],
			] : null,
			'in_app'        => $in_app,
			'referrer_host' => $referrer['host'] ?? null,
			'internal'      => $internal,
		];

		$source['label'] = self::describe_source( $source );

		return $source;
	}

	/**
	 * Where this request came from.
	 *
	 * analyse(), given the request: the Referer header, this page's own query
	 * string, and the site's address. This is the one to call on a landing
	 * page, because that is where the UTM tags and click IDs are.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $in_app Optional. The app whose in-app browser this is. Null reads
	 *                            it from this request's agent ({@see Request::in_app_browser()});
	 *                            '' for none.
	 *
	 * @return array See analyse().
	 */
	public static function from_request( ?string $in_app = null ): array {
		// The raw header rather than get(): esc_url_raw() drops an
		// `android-app://` referrer, which is how Android says an app opened
		// the link. analyse() makes it safe itself, and keeps none of it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- analyse() reduces it to a host.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) : null;

		// Reading, not acting: which campaign a link carried changes nothing
		// on the site, so there is no nonce to check. Every value is cut to a
		// tag or reduced to the name of a click ID before it is returned.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$query = wp_unslash( $_GET );

		return self::analyse(
			[
				'referrer' => $referrer,
				'query'    => is_array( $query ) ? $query : [],
				'in_app'   => $in_app ?? Request::in_app_browser(),
				'site_url' => self::get_current_domain(),
			]
		);
	}

	/**
	 * A source as a short phrase for a report or an admin screen.
	 *
	 * @since 1.1.0
	 *
	 * @param array $source The `channel` and `source`, and the `campaign` and `term` if there are any.
	 *
	 * @return string e.g. `Google search`, `TikTok`, `Email (Mailchimp)`, `Google Ads`, `TikTok ad, campaign
	 *                "launch"`, `campaign "spring-2026"`, `Direct`.
	 */
	public static function describe_source( array $source ): string {
		$name     = (string) ( $source['source'] ?? '' );
		$campaign = $source['campaign'] ?? null;
		$term     = $source['term'] ?? null;

		// "TikTok ad", but "Google Ads" as it is.
		$ad = 1 === preg_match( '/\bAds$/D', $name ) ? $name : $name . ' ad';

		return match ( $source['channel'] ?? '' ) {
			'direct'   => 'Direct',
			'search'   => $term ? sprintf( '%s search for "%s"', $name, $term ) : $name . ' search',
			'email'    => 'Email' === $name ? 'Email' : sprintf( 'Email (%s)', $name ),
			'paid'     => $campaign ? sprintf( '%s, campaign "%s"', $ad, $campaign ) : $ad,
			'campaign' => sprintf( 'campaign "%s"', $campaign ?? $name ),
			default    => $name,
		};
	}

	/**
	 * What a referring host is.
	 *
	 * Webmail first (its hosts overlap with search and AI), then AI
	 * assistants (before search: `gemini.google.com`), then search engines,
	 * then social networks. Anything else is a referral named by its
	 * registered domain.
	 *
	 * @since 1.1.0
	 *
	 * @param string $host The referrer's host, lower case, without `www.`. See host_of().
	 * @param string $url  Optional. The full referrer, to read a search term from.
	 *
	 * @return array{channel: string, source: string, term: string|null}
	 */
	public static function classify_host( string $host, string $url = '' ): array {
		$tables = [
			'email'  => Sources::WEBMAIL,
			'ai'     => Sources::AI_ASSISTANTS,
			'search' => Sources::SEARCH_ENGINES,
			'social' => Sources::SOCIAL_NETWORKS,
		];

		foreach ( $tables as $channel => $table ) {
			foreach ( $table as $entry ) {
				if ( ! self::host_matches( $host, $entry[0] ) ) {
					continue;
				}

				// Only a search engine has a third column: the parameter its
				// search arrives in, or '' when it never sends one.
				$parameter = $entry[2] ?? '';
				$term      = '' !== $parameter && '' !== $url ? self::to_tag( self::query_of( $url )[ $parameter ] ?? null ) : null;

				return [
					'channel' => $channel,
					'source'  => $entry[1],
					'term'    => $term,
				];
			}
		}

		return [
			'channel' => 'referral',
			'source'  => self::root_domain( $host ),
			'term'    => null,
		];
	}

	/**
	 * The app an `android-app://<package>/` referrer names.
	 *
	 * What Android sends when an app, not a browser, opened the link. An app
	 * that is not in the table is a referral under its package name.
	 *
	 * @since 1.1.0
	 *
	 * @param string $referrer The referrer.
	 *
	 * @return array{channel: string, source: string, term: null}|null Null when it is not an app referrer.
	 */
	public static function classify_android_app( string $referrer ): ?array {
		if ( 1 !== preg_match( '/^android-app:\/\/([a-zA-Z][A-Za-z0-9_.]{1,100})/', trim( $referrer ), $found ) ) {
			return null;
		}

		$known = Sources::ANDROID_APPS[ $found[1] ] ?? null;

		return [
			'channel' => $known[0] ?? 'referral',
			'source'  => $known[1] ?? $found[1],
			'term'    => null,
		];
	}

	/**
	 * Whether a host is a payment or sign-in page a visitor passes through.
	 *
	 * Stripe Checkout, PayPal, Google sign-in: never a source.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $host  A host, lower case.
	 * @param string[] $extra Optional. More domains to ignore.
	 *
	 * @return bool True to ignore it.
	 */
	public static function is_ignored_host( string $host, array $extra = [] ): bool {
		foreach ( array_merge( Sources::IGNORED_HOSTS, $extra ) as $domain ) {
			if ( self::host_matches( $host, (string) $domain ) ) {
				return true;
			}
		}

		return false;
	}

	// ========================================
	// Making input safe
	// ========================================

	/**
	 * A referrer that is safe to store and to show as a link.
	 *
	 * http or https, with a host, valid UTF-8, no control characters, not
	 * absurdly long. The characters that would break out of an `href="…"`
	 * are percent-encoded rather than dropped, so the URL still works.
	 *
	 * Anything else comes back empty, and the visit counts as direct: a
	 * `javascript:` or `data:` "referrer" surviving into an admin table is a
	 * stored attack the moment someone clicks it. Invalid UTF-8 is refused
	 * rather than repaired, because a database column or a JSON encoder
	 * further on will refuse it less politely.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer The Referer header.
	 *
	 * @return string The URL, or '' when it is not usable.
	 */
	public static function safe_url( ?string $referrer ): string {
		$value = trim( (string) $referrer );

		if ( '' === $value || ! mb_check_encoding( $value, 'UTF-8' ) || mb_strlen( $value ) > self::MAX_URL_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/^https?:/i', $value ) || null === self::parse_host( $value ) ) {
			return '';
		}

		return (string) preg_replace_callback(
			'/["\'<>`\\\\ ]/',
			static fn( array $found ): string => sprintf( '%%%02X', ord( $found[0] ) ),
			$value
		);
	}

	/**
	 * A UTM value as a tag.
	 *
	 * Letters, digits and `-_.+ ` only, runs of anything else as one space,
	 * at most TAG_MAX characters. What arrives is typed by whoever made the
	 * link, then stored against orders, shown in dashboards and read back by
	 * agents; a tag has room for a campaign name and not for an instruction.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $value As sent.
	 *
	 * @return string|null The tag, or null when nothing is left.
	 */
	public static function to_tag( ?string $value ): ?string {
		$tag = (string) $value;

		// The pattern below is Unicode-aware, and returns nothing at all for
		// a string that is not valid UTF-8.
		if ( ! mb_check_encoding( $tag, 'UTF-8' ) ) {
			$tag = mb_scrub( $tag, 'UTF-8' );
		}

		$tag = (string) preg_replace( '/[^\p{L}\p{N}\-_.+ ]+/u', ' ', $tag );
		$tag = trim( (string) preg_replace( '/\s+/', ' ', $tag ) );
		$tag = trim( mb_substr( $tag, 0, self::TAG_MAX ) );

		return '' !== $tag ? $tag : null;
	}

	/**
	 * A landing page as a path that is safe to keep.
	 *
	 * No query string or fragment -- either can carry a token or an email
	 * address -- and at most LANDING_MAX characters.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $landing A URL or a path, as the page sent it.
	 *
	 * @return string|null e.g. `/products/prism`, or null for nothing usable.
	 */
	public static function landing_path( ?string $landing ): ?string {
		$landing = str_replace( [ "\t", "\r", "\n" ], '', trim( (string) $landing ) );

		if ( '' === $landing ) {
			return null;
		}

		// `//host/path` is a URL without its scheme, not a path.
		if ( 1 === preg_match( '/^[\/\\\\]{2}/', $landing ) ) {
			$landing = 'https:' . $landing;
		}

		if ( 1 === preg_match( '/^https?:/i', $landing ) ) {
			if ( null === self::parse_host( $landing ) ) {
				return null;
			}

			// The path starts at the first slash after the host.
			$landing = (string) preg_replace( '/^https?:[\/\\\\]*[^\/\\\\?#]*/i', '', $landing );
		}

		$path     = str_replace( '\\', '/', (string) preg_replace( '/[?#].*$/s', '', $landing ) );
		$segments = [];

		// Resolved the way a browser would, so `/a/../b` is kept as `/b`. A
		// browser reads `%2e` as a dot here too.
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $segment ) {
			$dots = str_ireplace( '%2e', '.', $segment );

			if ( '..' === $dots ) {
				array_pop( $segments );
			} elseif ( '.' !== $dots ) {
				$segments[] = $segment;
			}
		}

		$path = '/' . implode( '/', $segments );
		$path = (string) preg_replace_callback(
			'/[\x00-\x20"<>^`{}\x7F-\xFF]/',
			static fn( array $found ): string => rawurlencode( $found[0] ),
			$path
		);

		return substr( $path, 0, self::LANDING_MAX );
	}

	// ========================================
	// Hosts
	// ========================================

	/**
	 * A URL's host, lower case, without `www.`.
	 *
	 * The form every table is matched against.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL.
	 *
	 * @return string|null e.g. `reddit.com`, or null when it is not a URL.
	 */
	public static function host_of( string $url ): ?string {
		$host = self::parse_host( $url );
		$host = null !== $host ? self::without_www( $host ) : '';

		return '' !== $host ? $host : null;
	}

	/**
	 * The registered part of a host: `shop.example.co.uk` is `example.co.uk`.
	 *
	 * Knows the common two-label suffixes, not the full Public Suffix List;
	 * fine for grouping and display, not for a security decision.
	 *
	 * @since 1.1.0
	 *
	 * @param string $host A host.
	 *
	 * @return string The registered domain.
	 */
	public static function root_domain( string $host ): string {
		$host = self::without_www( ltrim( strtolower( $host ), '.' ) );

		// An address has no registered domain, and its last two numbers are
		// not one: 192.168.1.10 and 10.0.1.10 would both be "1.10", and so
		// each other's own site.
		if ( str_starts_with( $host, '[' ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		$parts = explode( '.', $host );
		$count = count( $parts );

		if ( $count < 3 ) {
			return $host;
		}

		// Taking the last two labels gives "co.uk" for every site in Britain,
		// which groups the whole country together. Where the last two are a
		// known compound suffix, take three.
		$suffix = $parts[ $count - 2 ] . '.' . $parts[ $count - 1 ];

		if ( in_array( $suffix, self::COMPOUND_SUFFIXES, true ) ) {
			return $parts[ $count - 3 ] . '.' . $suffix;
		}

		return $suffix;
	}

	// ========================================
	// The landing page
	// ========================================

	/**
	 * The landing page's query, from a query or a URL.
	 *
	 * Where a parameter is repeated the first one counts, which is what a
	 * browser's URLSearchParams gives and the opposite of parse_str().
	 *
	 * @since 1.1.0
	 *
	 * @param array $input The `query` (a string, or an array such as $_GET) or the `url`. See analyse().
	 *
	 * @return array<string, string> Parameter => value; empty when there are none.
	 */
	public static function params_from( array $input ): array {
		$query = $input['query'] ?? null;

		if ( is_string( $query ) ) {
			return self::parse_query( ltrim( $query, '?' ) );
		}

		if ( ! is_array( $query ) ) {
			return self::query_of( (string) ( $input['url'] ?? '' ) );
		}

		$params = [];

		foreach ( $query as $name => $value ) {
			// `?utm_source[]=a&utm_source[]=b` arrives as an array.
			$first = is_array( $value ) ? reset( $value ) : $value;

			if ( is_scalar( $first ) ) {
				$params[ (string) $name ] = (string) $first;
			}
		}

		return $params;
	}

	/**
	 * The UTM tags, each cleaned to a tag.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, string> $params The landing page's query. See params_from().
	 *
	 * @return array{source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null}
	 */
	public static function read_utm( array $params ): array {
		$medium = self::to_tag( $params['utm_medium'] ?? null );

		return [
			'source'   => self::to_tag( $params['utm_source'] ?? null ),
			'medium'   => null !== $medium ? mb_strtolower( $medium ) : null,
			'campaign' => self::to_tag( $params['utm_campaign'] ?? null ),
			'term'     => self::to_tag( $params['utm_term'] ?? null ),
			'content'  => self::to_tag( $params['utm_content'] ?? null ),
		];
	}

	/**
	 * The first click ID on the landing page.
	 *
	 * Which parameter it was and the network it means, never its value: the
	 * value identifies one click, and there is no reason to keep it.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, string> $params The landing page's query. See params_from().
	 *
	 * @return array{param: string, channel: string, network: string}|null
	 */
	public static function find_click_id( array $params ): ?array {
		foreach ( Campaigns::CLICK_IDS as [ $param, $channel, $network ] ) {
			if ( '' !== (string) ( $params[ $param ] ?? '' ) || '' !== (string) ( $params[ strtolower( $param ) ] ?? '' ) ) {
				return [
					'param'   => $param,
					'channel' => $channel,
					'network' => $network,
				];
			}
		}

		return null;
	}

	// ========================================
	// Options Methods for Conditional Logic
	// ========================================

	/**
	 * Get search engine options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of search engine options.
	 */
	public static function get_search_engine_options( bool $as_value_label = false, ?string $context = null ): array {
		// Read off the table the classifier uses, so an engine added there is
		// selectable here without anyone remembering to list it twice.
		$options = self::names_in( Sources::SEARCH_ENGINES );

		/**
		 * Filters the search engine options.
		 *
		 * @param array       $options The search engine options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_search_engine_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get social platform options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of social platform options.
	 */
	public static function get_social_platform_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::SOCIAL_NETWORKS );

		/**
		 * Filters the social platform options.
		 *
		 * @param array       $options The social platform options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_social_platform_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get AI assistant options.
	 *
	 * @since 1.1.0
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of AI assistant options.
	 */
	public static function get_ai_assistant_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::AI_ASSISTANTS );

		/**
		 * Filters the AI assistant options.
		 *
		 * @since 1.1.0
		 *
		 * @param array       $options The AI assistant options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_ai_assistant_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get webmail provider options.
	 *
	 * @since 1.1.0
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of webmail provider options.
	 */
	public static function get_email_provider_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::WEBMAIL );

		/**
		 * Filters the webmail provider options.
		 *
		 * @since 1.1.0
		 *
		 * @param array       $options The webmail provider options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_email_provider_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get traffic source options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of traffic source options.
	 */
	public static function get_traffic_source_options( bool $as_value_label = false, ?string $context = null ): array {
		// One for each of CHANNELS, and nothing get_traffic_source() cannot
		// return: an option that never matches is a condition that silently
		// never fires.
		$options = [
			'search'   => __( 'Search Engine', 'sugarcommerce' ),
			'social'   => __( 'Social Media', 'sugarcommerce' ),
			'email'    => __( 'Email', 'sugarcommerce' ),
			'ai'       => __( 'AI Assistant', 'sugarcommerce' ),
			'paid'     => __( 'Paid Advertising', 'sugarcommerce' ),
			'campaign' => __( 'Campaign', 'sugarcommerce' ),
			'referral' => __( 'Referral', 'sugarcommerce' ),
			'direct'   => __( 'Direct', 'sugarcommerce' ),
		];

		/**
		 * Filters the traffic source options.
		 *
		 * @param array       $options The traffic source options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_traffic_source_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get UTM parameter type options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of UTM parameter options.
	 */
	public static function get_utm_parameter_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = [
			'source'   => __( 'Source (utm_source)', 'sugarcommerce' ),
			'medium'   => __( 'Medium (utm_medium)', 'sugarcommerce' ),
			'campaign' => __( 'Campaign (utm_campaign)', 'sugarcommerce' ),
			'term'     => __( 'Term (utm_term)', 'sugarcommerce' ),
			'content'  => __( 'Content (utm_content)', 'sugarcommerce' ),
		];

		/**
		 * Filters the UTM parameter options.
		 *
		 * @param array       $options The UTM parameter options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_utm_parameter_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Check if referrer matches specific criteria.
	 *
	 * @param string|array $criteria Search engine, social platform, AI assistant, webmail provider or traffic
	 *                               source to match.
	 * @param string|null  $referrer Optional referrer URL.
	 *
	 * @return bool True if referrer matches criteria.
	 */
	public static function is_match( $criteria, ?string $referrer = null ): bool {
		if ( is_string( $criteria ) ) {
			$criteria = [ $criteria ];
		}

		if ( ! is_array( $criteria ) ) {
			return false;
		}

		$referrer = $referrer ?? self::get();

		$matches = [
			self::get_search_engine( $referrer ),
			self::get_social_platform( $referrer ),
			self::get_ai_assistant( $referrer ),
			self::get_email_provider( $referrer ),
			self::get_traffic_source( $referrer ),
		];

		foreach ( $criteria as $criterion ) {
			$criterion = strtolower( trim( $criterion ) );
			$criterion = self::RENAMED_SLUGS[ $criterion ] ?? $criterion;

			if ( in_array( $criterion, $matches, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert key/value array to value/label options format.
	 *
	 * @param array $options The options array in key => value format.
	 *
	 * @return array Options in value/label format.
	 */
	protected static function to_value_label( array $options ): array {
		$formatted = [];
		foreach ( $options as $key => $label ) {
			$formatted[] = [
				'value' => $key,
				'label' => $label,
			];
		}

		return $formatted;
	}

	/**
	 * The sources a table names, as identifier => name.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, array<int, string>> $table One of the Sources tables.
	 *
	 * @return array<string, string>
	 */
	protected static function names_in( array $table ): array {
		$names = [];

		foreach ( $table as $entry ) {
			$names[ self::slug( $entry[1] ) ] = $entry[1];
		}

		return $names;
	}

	/**
	 * The lower-case identifier a named source is stored under.
	 *
	 * "Google" is `google` and "Brave Search" is `brave-search`. The names
	 * that had a different identifier before they had a name keep it.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name The source's name, from one of the Sources tables.
	 *
	 * @return string
	 */
	protected static function slug( string $name ): string {
		return self::LEGACY_SLUGS[ $name ] ?? trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
	}

	/**
	 * The identifier of a referrer's source, when it is on the given channel.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer The referrer URL, or null for the current one.
	 * @param string      $channel  `search`, `social`, `ai` or `email`.
	 *
	 * @return string|null
	 */
	protected static function source_slug( ?string $referrer, string $channel ): ?string {
		$referrer = self::safe_url( $referrer ?? self::get() );
		$host     = '' !== $referrer ? self::host_of( $referrer ) : null;

		if ( null === $host ) {
			return null;
		}

		$found = self::classify_host( $host );

		return $channel === $found['channel'] ? self::slug( $found['source'] ) : null;
	}

	/**
	 * Whether a host matches a table entry: a domain, or a pattern.
	 *
	 * @since 1.1.0
	 *
	 * @param string $host    A host, lower case.
	 * @param string $matcher A domain, or a pattern in slashes. See Sources.
	 *
	 * @return bool
	 */
	protected static function host_matches( string $host, string $matcher ): bool {
		if ( str_starts_with( $matcher, '/' ) ) {
			return 1 === preg_match( $matcher, $host );
		}

		return Host::is_within( $host, $matcher );
	}

	/**
	 * The decision from UTM tags.
	 *
	 * The medium says the channel when it is a known one; otherwise a paid
	 * click ID makes it paid, a known source lends its channel, then what the
	 * visit itself showed, and anything else is a campaign.
	 *
	 * A link tagged with a campaign but no `utm_source` names its source from
	 * what the visit showed: a `?utm_campaign=launch` link clicked on TikTok
	 * is TikTok's, with the campaign kept beside it. Only when nothing else
	 * is known does the campaign's name stand in for the source.
	 *
	 * @since 1.1.0
	 *
	 * @param array      $utm   The tags. See read_utm().
	 * @param array|null $click The click ID, if any. See find_click_id().
	 * @param array      $seen  What the visit's other signals decided.
	 *
	 * @return array{channel: string, source: string}
	 */
	protected static function from_utm( array $utm, ?array $click, array $seen ): array {
		$known    = null !== $utm['source'] ? self::known_utm_source( $utm['source'] ) : null;
		$observed = 'direct' === $seen['channel'] ? null : $seen;

		$channel = Campaigns::MEDIUMS[ (string) $utm['medium'] ]
			?? ( 'paid' === ( $click['channel'] ?? null ) ? 'paid' : null )
			?? $known['channel']
			?? $observed['channel']
			?? 'campaign';

		return [
			'channel' => $channel,
			'source'  => $known['source'] ?? $utm['source'] ?? $observed['source'] ?? (string) $utm['campaign'],
		];
	}

	/**
	 * A `utm_source` as a named source.
	 *
	 * A known spelling (`fb`, `newsletter`), or a host (`chatgpt.com`, `t.co`)
	 * that the tables know.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source The tag.
	 *
	 * @return array{channel: string, source: string}|null Null when it is just a word.
	 */
	protected static function known_utm_source( string $source ): ?array {
		$source = strtolower( $source );
		$known  = Campaigns::UTM_SOURCES[ $source ] ?? null;

		if ( null !== $known ) {
			return [
				'channel' => $known[0],
				'source'  => $known[1],
			];
		}

		if ( 1 !== preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D', $source ) ) {
			return null;
		}

		$by_host = self::classify_host( self::without_www( $source ) );

		return 'referral' === $by_host['channel'] ? null : $by_host;
	}

	/**
	 * The decision from an app's in-app browser.
	 *
	 * @since 1.1.0
	 *
	 * @param string $app e.g. `TikTok`.
	 *
	 * @return array{channel: string, source: string}
	 */
	protected static function from_in_app( string $app ): array {
		$known = Sources::IN_APP_SOURCES[ $app ] ?? null;

		return [
			'channel' => $known[0] ?? 'social',
			'source'  => $known[1] ?? $app,
		];
	}

	/**
	 * The referrer, made safe, with its host.
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw The Referer header.
	 *
	 * @return array{url: string, host: string}|null Null when it is absent or unusable.
	 */
	protected static function read_referrer( string $raw ): ?array {
		$url  = self::safe_url( $raw );
		$host = '' !== $url ? self::host_of( $url ) : null;

		return null !== $host ? [
			'url'  => $url,
			'host' => $host,
		] : null;
	}

	/**
	 * Whether the referrer is the site itself, or a payment or sign-in page
	 * the visitor came back from: either way, not a new source.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $referrer The referrer. See read_referrer().
	 * @param array      $input    What the page knows: its `site_url` and `ignore` list.
	 *
	 * @return bool
	 */
	protected static function is_own_referrer( ?array $referrer, array $input ): bool {
		if ( null === $referrer ) {
			return false;
		}

		$site = self::site_host( (string) ( $input['site_url'] ?? '' ) );

		if ( null !== $site && self::root_domain( $referrer['host'] ) === self::root_domain( $site ) ) {
			return true;
		}

		return self::is_ignored_host( $referrer['host'], (array) ( $input['ignore'] ?? [] ) );
	}

	/**
	 * The host of the site's own address, given as a URL or as a bare host.
	 *
	 * @since 1.1.0
	 *
	 * @param string $site `https://shop.example/` or `shop.example`.
	 *
	 * @return string|null
	 */
	protected static function site_host( string $site ): ?string {
		$site = trim( $site );
		$host = self::host_of( $site );

		if ( null === $host && 1 === preg_match( '/^[a-z0-9.-]+$/i', $site ) ) {
			$host = self::without_www( $site );
		}

		return '' !== (string) $host ? $host : null;
	}

	/**
	 * A URL's host exactly as it would be requested: lower case, `www.` kept.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL.
	 *
	 * @return string|null Null when there is no host, or it is not one.
	 */
	protected static function parse_host( string $url ): ?string {
		$url = trim( $url );

		// A browser reads `https:\\host` and `https:/host` as `https://host`,
		// and a backslash anywhere before the path as a slash. parse_url()
		// does neither, and would name a different host than the one the
		// link actually goes to.
		if ( 1 === preg_match( '/^(https?):[\/\\\\]*(.*)$/is', $url, $found ) ) {
			$url = strtolower( $found[1] ) . '://' . str_replace( '\\', '/', $found[2] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- one consistent parser, with or without WordPress loaded.
		$host = parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host || 1 === preg_match( '/[\s<>^|%]/', $host ) ) {
			return null;
		}

		// Square brackets are an IPv6 address or they are nothing.
		if ( str_contains( $host, '[' ) && ( 1 !== preg_match( '/^\[([0-9a-f:.]+)\]$/i', $host, $found ) || false === filter_var( $found[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) ) {
			return null;
		}

		// An internationalised name is requested in its ASCII form.
		if ( 1 === preg_match( '/[^\x20-\x7E]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) && '' !== $ascii ? $ascii : $host;
		}

		return strtolower( $host );
	}

	/**
	 * A URL's query, as parameter => value.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL or a path.
	 *
	 * @return array<string, string>
	 */
	protected static function query_of( string $url ): array {
		$url   = (string) preg_replace( '/#.*$/s', '', $url );
		$start = strpos( $url, '?' );

		return false !== $start ? self::parse_query( substr( $url, $start + 1 ) ) : [];
	}

	/**
	 * A query string, as parameter => value.
	 *
	 * Not parse_str(): that keeps the last of a repeated parameter, rewrites
	 * dots and spaces in names to underscores, and builds arrays out of
	 * square brackets. A browser does none of those, and the two have to
	 * read the same link the same way.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query The query string, without its `?`.
	 *
	 * @return array<string, string>
	 */
	protected static function parse_query( string $query ): array {
		$params = [];

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$name  = urldecode( $parts[0] );

			if ( ! array_key_exists( $name, $params ) ) {
				$params[ $name ] = urldecode( $parts[1] ?? '' );
			}
		}

		return $params;
	}

	/**
	 * A host with any leading `www.` removed.
	 *
	 * @param string $domain The host.
	 *
	 * @return string
	 */
	protected static function without_www( string $domain ): string {
		return (string) preg_replace( '/^www\./i', '', strtolower( $domain ) );
	}

	/**
	 * Get current domain.
	 *
	 * @return string Current domain.
	 */
	protected static function get_current_domain(): string {
		$site_url = (string) get_option( 'siteurl' );

		return self::parse_host( $site_url ) ?? '';
	}
}
