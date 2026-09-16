<?php
/**
 * Referrer and campaign attribution.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Referrer
 *
 * Turns a `Referer` header and a query string into "where did this
 * person come from" — search, social, email, a campaign, or nowhere in
 * particular.
 *
 * Attribution order follows the convention every analytics product
 * uses: an explicit UTM campaign beats the referrer header, because the
 * campaign is what the marketer deliberately tagged and the header is
 * whatever the browser felt like sending. Referrer headers are also
 * increasingly absent — `strict-origin-when-cross-origin` is now the
 * default in every major browser, so the path is stripped and plenty of
 * navigations send nothing at all.
 *
 * Typical use:
 *
 *   $source = Referrer::analyse( $_SERVER['HTTP_REFERER'] ?? '', $_GET );
 *
 *   $source->channel;   // 'search' | 'social' | 'email' | 'referral' | 'direct'
 *   $source->name;      // 'Google', 'Twitter', 'spring-2026', …
 *   $source->label();   // 'Google search', 'campaign "spring-2026"'
 *
 * @since 1.0.0
 */
final readonly class Referrer {

	/**
	 * Search engines by domain fragment, with their query parameter.
	 */
	private const SEARCH_ENGINES = array(
		'google.'         => array( 'Google', 'q' ),
		'bing.com'        => array( 'Bing', 'q' ),
		'duckduckgo.com'  => array( 'DuckDuckGo', 'q' ),
		'search.yahoo.'   => array( 'Yahoo', 'p' ),
		'yandex.'         => array( 'Yandex', 'text' ),
		'baidu.com'       => array( 'Baidu', 'wd' ),
		'ecosia.org'      => array( 'Ecosia', 'q' ),
		'brave.com'       => array( 'Brave Search', 'q' ),
		'startpage.com'   => array( 'Startpage', 'query' ),
		'qwant.com'       => array( 'Qwant', 'q' ),
		'ask.com'         => array( 'Ask', 'q' ),
		'perplexity.ai'   => array( 'Perplexity', 'q' ),
		'chatgpt.com'     => array( 'ChatGPT', '' ),
		'claude.ai'       => array( 'Claude', '' ),
		'gemini.google.'  => array( 'Gemini', '' ),
		'copilot.micro'   => array( 'Copilot', '' ),
	);

	/**
	 * Social networks by domain fragment.
	 */
	private const SOCIAL_NETWORKS = array(
		'facebook.com'   => 'Facebook',
		'fb.com'         => 'Facebook',
		'l.facebook.com' => 'Facebook',
		'instagram.com'  => 'Instagram',
		'twitter.com'    => 'Twitter',
		'x.com'          => 'Twitter',
		't.co'           => 'Twitter',
		'linkedin.com'   => 'LinkedIn',
		'lnkd.in'        => 'LinkedIn',
		'youtube.com'    => 'YouTube',
		'youtu.be'       => 'YouTube',
		'reddit.com'     => 'Reddit',
		'pinterest.'     => 'Pinterest',
		'tiktok.com'     => 'TikTok',
		'threads.net'    => 'Threads',
		'bsky.app'       => 'Bluesky',
		'mastodon.'      => 'Mastodon',
		'discord.com'    => 'Discord',
		't.me'           => 'Telegram',
		'whatsapp.com'   => 'WhatsApp',
		'news.ycombinator.com' => 'Hacker News',
		'producthunt.com'      => 'Product Hunt',
		'medium.com'     => 'Medium',
		'substack.com'   => 'Substack',
		'tumblr.com'     => 'Tumblr',
		'quora.com'      => 'Quora',
		'twitch.tv'      => 'Twitch',
	);

	/**
	 * Webmail hosts, which indicate an email click.
	 */
	private const EMAIL_HOSTS = array(
		'mail.google.com', 'outlook.', 'mail.yahoo.', 'mail.proton', 'protonmail.',
		'mail.aol.com', 'zoho.com', 'icloud.com', 'fastmail.com', 'hey.com',
	);

	/**
	 * `utm_medium` values, grouped by the channel they mean.
	 *
	 * Marketers write these by hand, so the same channel arrives spelled
	 * several ways.
	 */
	/**
	 * Longest referrer accepted.
	 *
	 * Browsers cap the header well below this; anything longer is a
	 * padding attempt aimed at whatever column it lands in.
	 */
	private const MAX_URL_LENGTH = 2048;

	private const MEDIUM_CHANNELS = array(
		'paid'   => array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'display', 'banner' ),
		'email'  => array( 'email', 'newsletter', 'e-mail' ),
		'social' => array( 'social', 'social-network', 'social-media', 'sm' ),
		'search' => array( 'organic', 'search' ),
	);

	/**
	 * Analyse a referrer and query string.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $referrer `Referer` header value.
	 * @param array<string, mixed> $query    Query parameters, usually `$_GET`.
	 *
	 * @return Source
	 */
	public static function analyse( string $referrer, array $query = array() ): Source {
		$utm = self::campaign( $query );

		// A deliberate tag beats a header the browser may have trimmed.
		if ( null !== $utm ) {
			return $utm;
		}

		$referrer = self::safe_url( $referrer );

		if ( '' === $referrer ) {
			return new Source( 'direct', 'Direct', '', '' );
		}

		$host = strtolower( (string) parse_url( $referrer, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return new Source( 'direct', 'Direct', '', $referrer );
		}

		// Webmail is checked first because the hosts overlap: mail.google.com
		// contains "google.", and Gmail is an email click rather than a
		// search. Same for outlook.live.com against Bing.
		foreach ( self::EMAIL_HOSTS as $fragment ) {
			if ( str_contains( $host, $fragment ) ) {
				return new Source( 'email', 'Webmail', '', $referrer );
			}
		}

		foreach ( self::SEARCH_ENGINES as $fragment => [ $name, $parameter ] ) {
			if ( str_contains( $host, $fragment ) ) {
				return new Source( 'search', $name, self::search_term( $referrer, $parameter ), $referrer );
			}
		}

		foreach ( self::SOCIAL_NETWORKS as $fragment => $name ) {
			if ( str_contains( $host, $fragment ) ) {
				return new Source( 'social', $name, '', $referrer );
			}
		}

		return new Source( 'referral', self::root_domain( $host ), '', $referrer );
	}

	/**
	 * Whether a referrer is from this site.
	 *
	 * Internal navigation is not attribution, and counting it inflates
	 * every referral figure you have.
	 *
	 * @since 1.0.0
	 *
	 * @param string $referrer Referrer URL.
	 * @param string $site_url Your own site URL.
	 *
	 * @return bool
	 */
	public static function is_internal( string $referrer, string $site_url ): bool {
		$from = strtolower( (string) parse_url( $referrer, PHP_URL_HOST ) );
		$here = strtolower( (string) parse_url( $site_url, PHP_URL_HOST ) );

		if ( '' === $from || '' === $here ) {
			return false;
		}

		return $from === $here || self::root_domain( $from ) === self::root_domain( $here );
	}

	/**
	 * The campaign described by UTM parameters, if any.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $query Query parameters.
	 *
	 * @return Source|null
	 */
	public static function campaign( array $query ): ?Source {
		$campaign = self::clean( $query['utm_campaign'] ?? '' );
		$source   = self::clean( $query['utm_source'] ?? '' );
		$medium   = self::clean( $query['utm_medium'] ?? '' );

		if ( '' === $campaign && '' === $source ) {
			return null;
		}

		// utm_medium is the channel the marketer declared; fall back to
		// classifying utm_source when they left it out, which is common.
		$channel = 'campaign';

		foreach ( self::MEDIUM_CHANNELS as $name => $mediums ) {
			if ( in_array( $medium, $mediums, true ) ) {
				$channel = $name;
				break;
			}
		}

		return new Source( $channel, '' !== $campaign ? $campaign : $source, $source, '', $medium );
	}

	/**
	 * Extract the search term from a search-engine URL.
	 *
	 * Usually absent: Google has withheld it since 2011, and the others
	 * have mostly followed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url       Referrer URL.
	 * @param string $parameter Query parameter carrying the term.
	 *
	 * @return string
	 */
	private static function search_term( string $url, string $parameter ): string {
		if ( '' === $parameter ) {
			return '';
		}

		$query = (string) parse_url( $url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return '';
		}

		parse_str( $query, $parts );

		return self::clean( $parts[ $parameter ] ?? '' );
	}

	/**
	 * The registrable part of a hostname.
	 *
	 * A naive last-two-labels split, so `example.co.uk` comes back as
	 * `co.uk`. Fine for display and grouping; use a Public Suffix List
	 * implementation if you need exact eTLD+1 for anything security- or
	 * billing-related.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Hostname.
	 *
	 * @return string
	 */
	public static function root_domain( string $host ): string {
		$host  = strtolower( ltrim( $host, '.' ) );
		$host  = preg_replace( '/^www\./', '', $host ) ?? $host;
		$parts = explode( '.', $host );

		if ( count( $parts ) <= 2 ) {
			return $host;
		}

		return implode( '.', array_slice( $parts, -2 ) );
	}

	/**
	 * Trim, strip tags, and cap a caller-supplied string.
	 *
	 * These values reach dashboards and database columns, and they arrive
	 * from the query string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function clean( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return '';
		}

		$value = strip_tags( $value );

		// `strip_tags` alone is not enough. It removes `<script>` but
		// leaves `" onmouseover=alert(1)` untouched, and a campaign name
		// rendered into an attribute — `<td title="{name}">` — is then an
		// injection point in any dashboard that forgets to escape.
		//
		// These characters have no legitimate place in a campaign name,
		// a utm_source, or a search term, so they are dropped rather than
		// encoded: encoding here would double-escape for every caller
		// that does escape properly.
		$value = (string) preg_replace( '/[<>"\'`\\\\]/u', '', $value );

		// Control characters, including the CR and LF that would split a
		// log line or a header downstream. Replaced with a space rather
		// than removed, so "spring\r\n2026" stays two words.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );

		// Collapse whitespace runs so a padded value can't push the rest
		// of a table cell out of view.
		$value = (string) preg_replace( '/\s+/u', ' ', $value );

		return mb_substr( trim( $value ), 0, 200 );
	}

	/**
	 * Reduce a referrer to a URL that is safe to store and to render.
	 *
	 * The `Referer` header is attacker-controlled, and it is routinely
	 * persisted against an order and then printed into an admin table as
	 * a link. A `javascript:` or `data:` value surviving that round trip
	 * is stored cross-site scripting the moment somebody clicks it — so
	 * only the two schemes a browser can actually navigate from are kept.
	 *
	 * Anything else — including a malformed URL, invalid UTF-8, or one
	 * carrying control characters — comes back empty, and the visit is
	 * treated as direct.
	 *
	 * @since 1.0.0
	 *
	 * @param string $referrer Raw header value.
	 *
	 * @return string Empty when unusable.
	 */
	public static function safe_url( string $referrer ): string {
		$referrer = trim( $referrer );

		if ( '' === $referrer || ! mb_check_encoding( $referrer, 'UTF-8' ) ) {
			return '';
		}

		// A control character inside a URL is either an encoding bug or an
		// attempt to break whatever the value is written into.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $referrer ) ) {
			return '';
		}

		// A header this long is not a real referrer. Truncating instead
		// of rejecting would leave a cut-off URL in the database that
		// still looks legitimate.
		if ( strlen( $referrer ) > self::MAX_URL_LENGTH ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $referrer, PHP_URL_SCHEME ) );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		if ( '' === (string) parse_url( $referrer, PHP_URL_HOST ) ) {
			return '';
		}

		// RFC 3986 does not permit these raw in a URI — a real referrer
		// arrives with them percent-encoded. Left as-is, they are the
		// exact characters that break out of an `href="…"` attribute, so
		// they are encoded rather than dropped: the URL still resolves,
		// and an admin table that forgot to escape is no longer an
		// injection point.
		return str_replace(
			array( '"', "'", '<', '>', '`', ' ', '\\' ),
			array( '%22', '%27', '%3C', '%3E', '%60', '%20', '%5C' ),
			$referrer
		);
	}
}
