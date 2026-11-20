<?php
/**
 * Where a whole visit came from: tags, click IDs, in-app browsers and the referrer.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

use SugarCommerce\Network\Host;
use SugarCommerce\Network\Referrer\Campaigns;
use SugarCommerce\Network\Referrer\Sources;
use SugarCommerce\Network\Request;

/**
 * Where a whole visit came from: tags, click IDs, in-app browsers and the referrer.
 *
 * @since 1.1.0
 */
trait Analysis {

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
}
