<?php
/**
 * One referrer's answers: its domain, search engine, platform and tags.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

/**
 * One referrer's answers: its domain, search engine, platform and tags.
 *
 * The older questions, each about a single URL -- the current request's
 * Referer header when none is given. The whole visit is analyse()'s.
 *
 * @since 1.1.0
 */
trait Lookups {

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
}
