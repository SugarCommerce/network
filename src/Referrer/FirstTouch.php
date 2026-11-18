<?php
/**
 * First-touch attribution
 *
 * Remembers where a visitor first came from, in a cookie, until they convert.
 *
 * @package SugarCommerce\Network
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.2.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Referrer;

use SugarCommerce\Network\Referrer;

/**
 * Class FirstTouch
 *
 * The site or campaign that sent a visitor, remembered until they convert.
 *
 * Somebody arrives from a search or a link in a newsletter, looks around,
 * and buys or signs up some minutes or days later -- often on another
 * service's page, recorded from a webhook that never saw the visit. By then
 * the request that said where they came from is long gone. So the first
 * visit is noted in a cookie, and whatever is made later (a cart, an order,
 * an account) copies the cookie.
 *
 * First touch, not last: a visitor sent by a review who comes back through a
 * search for the site's name was sent by the review.
 *
 * What a visit is -- a search, an advert, a link in an email, a page of this
 * site -- is decided by Referrer::analyse(). What is decided here is the
 * cookie, the switch, and what of the answer is kept.
 *
 * Nothing about the person is kept. The cookie holds the referring site's
 * host, the campaign tags that were on the link and the path landed on --
 * which describe the link, not whoever followed it. A click ID is read for
 * the network it names and its value is never kept.
 *
 * Everything read back -- from the cookie, a cart, a payment's metadata --
 * has been somewhere a visitor could change it, so it goes through clean()
 * every time: only the configured fields, each cut to its length, the tag
 * fields cut to tags (see Referrer::to_tag()), none of the empty ones.
 *
 * A page served from a full-page cache never reaches PHP, so a visit that
 * only ever sees cached pages is not noted. That is a conversion read as
 * direct rather than one read wrongly.
 *
 * Configure one instance per plugin; several plugins can each keep their own
 * cookie on the same site.
 */
class FirstTouch {

	/**
	 * Every field a visit can be recorded under, and its default length.
	 *
	 * - `source`   Referrer::analyse()'s `source`: "Google", "Google Ads",
	 *              the `utm_source` tag, or a referring domain.
	 * - `medium`   The link's own `utm_medium` as it was tagged, otherwise
	 *              the channel; never `campaign` (a tagged link of no known
	 *              kind, which the campaign field already says).
	 * - `campaign` `utm_campaign`.
	 * - `term`     `utm_term`, or the search a search engine passed on.
	 * - `content`  `utm_content`.
	 * - `channel`  One of Referrer::CHANNELS, never `direct`.
	 * - `click_id` The click ID's parameter (`gclid`), never its value.
	 * - `referrer` The referring host, unless it was internal.
	 * - `landing`  The path landed on, without its query.
	 *
	 * @since 1.2.0
	 * @var array<string, int>
	 */
	public const ALL_FIELDS = [
		'source'   => 100,
		'medium'   => 100,
		'campaign' => 150,
		'term'     => 100,
		'content'  => 100,
		'channel'  => 20,
		'click_id' => 40,
		'referrer' => 255,
		'landing'  => 255,
	];

	/**
	 * The fields kept unless others are configured.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	public const DEFAULT_FIELDS = [ 'source', 'medium', 'campaign', 'referrer', 'landing' ];

	/**
	 * The fields that are typed by whoever made a link, and so are cut to
	 * tags on the way in: a tag has room for a campaign name and not for an
	 * instruction to the AI agent that later reads it back.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	public const TAG_FIELDS = [ 'source', 'medium', 'campaign', 'term', 'content' ];

	/**
	 * How long a first visit is remembered by default: 30 days.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const LIFETIME = 2592000;

	/**
	 * The longest stored payload that is parsed.
	 *
	 * Ours is a few hundred bytes. Anything much longer was not written here
	 * and is not worth parsing to find that out.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_STORED = 2048;

	/**
	 * The cookie's name.
	 *
	 * @var string
	 */
	protected string $cookie;

	/**
	 * How long the cookie lasts, in seconds.
	 *
	 * @var int
	 */
	protected int $lifetime;

	/**
	 * Whether it is on: a bool, or a callable returning one.
	 *
	 * @var bool|callable
	 */
	protected $enabled;

	/**
	 * Field => the longest value kept.
	 *
	 * @var array<string, int>
	 */
	protected array $fields;

	/**
	 * The fields cut to tags.
	 *
	 * @var string[]
	 */
	protected array $tags;

	/**
	 * The filter a visit's payload goes through, or '' for none.
	 *
	 * @var string
	 */
	protected string $filter;

	/**
	 * Returns the app whose in-app browser this is, or null.
	 *
	 * @var callable|null
	 */
	protected $in_app;

	/**
	 * More domains that are never a source.
	 *
	 * @var string[]
	 */
	protected array $ignore;

	/**
	 * Configure it.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args {
	 *     All optional.
	 *
	 *     @type string        $prefix   Names the cookie and the filter: `{prefix}_first_touch`. Default ''.
	 *     @type string        $cookie   The cookie's name. Default `{prefix}_first_touch`, or `first_touch`.
	 *     @type int           $lifetime Seconds the first visit is remembered. Default LIFETIME (30 days).
	 *     @type bool|callable $enabled  Whether it is on. A callable is asked every time, so a setting
	 *                                   switched off stops the cookie being set and being read. Default true.
	 *     @type array         $fields   The fields kept: a list of names from ALL_FIELDS, or name => length.
	 *                                   Default DEFAULT_FIELDS.
	 *     @type string[]      $tags     Of those, the ones cut to tags. Default TAG_FIELDS. Pass [] to keep
	 *                                   what a filter or a stored payload says, sanitised as text only.
	 *     @type string        $filter   The filter each visit's payload goes through. Default
	 *                                   `{prefix}_first_touch` when there is a prefix, otherwise none.
	 *     @type callable|null $in_app   Returns the app whose in-app browser opened the page ("TikTok"),
	 *                                   or null -- e.g. [ Request::class, 'in_app_browser' ]
	 *                                   from this library. The app is then the source of a
	 *                                   visit that sent no referrer. Default none.
	 *     @type string[]      $ignore   More domains that are never a source. Default none.
	 * }
	 */
	public function __construct( array $args = [] ) {
		$prefix = trim( (string) ( $args['prefix'] ?? '' ), '_' );
		$named  = '' !== $prefix ? $prefix . '_first_touch' : '';

		$this->cookie   = (string) ( $args['cookie'] ?? ( '' !== $named ? $named : 'first_touch' ) );
		$this->lifetime = max( 1, (int) ( $args['lifetime'] ?? self::LIFETIME ) );
		$this->enabled  = $args['enabled'] ?? true;
		$this->fields   = self::read_fields( (array) ( $args['fields'] ?? self::DEFAULT_FIELDS ) );
		$this->tags     = array_values( array_intersect( (array) ( $args['tags'] ?? self::TAG_FIELDS ), array_keys( $this->fields ) ) );
		$this->filter   = (string) ( $args['filter'] ?? $named );
		$this->in_app   = is_callable( $args['in_app'] ?? null ) ? $args['in_app'] : null;
		$this->ignore   = array_map( 'strval', (array) ( $args['ignore'] ?? [] ) );
	}

	/**
	 * Note the first visit of every front-end page request.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function init(): void {
		// template_redirect, because it only fires for a page somebody is
		// about to be shown -- not the admin, REST, AJAX or cron -- and it
		// fires before any of the page has gone out, which a cookie needs.
		add_action( 'template_redirect', [ $this, 'capture' ] );
	}

	/**
	 * The cookie's name.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function cookie(): string {
		return $this->cookie;
	}

	/**
	 * The fields kept, and the longest value of each.
	 *
	 * The columns a store needs.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, int>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Whether any of this is switched on.
	 *
	 * Off sets no cookie, reads none that is already there, and leaves
	 * everything made from now on without a source.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return is_callable( $this->enabled ) ? (bool) ( $this->enabled )() : (bool) $this->enabled;
	}

	/**
	 * Note where this visitor came from, unless it is already noted.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function capture(): void {
		// The first touch wins, so a cookie of any kind ends it here -- even
		// one that no longer reads as ours, which is replaced by nothing
		// rather than by the visit that happens to be looking.
		if ( ! $this->enabled() || isset( $_COOKIE[ $this->cookie ] ) ) {
			return;
		}

		// Feeds and the like are fetched by machines that keep no cookies.
		if ( is_feed() || is_robots() || is_favicon() || is_trackback() ) {
			return;
		}

		// The header itself and not wp_get_raw_referer(), which believes a
		// `_wp_http_referer` field in the request first: that is a form's
		// note of the page it was on, not where the visitor came from.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to a host by analyse(); nothing else of it is kept.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Its path is kept and its tags are read; both are cleaned.
		$landing = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$in_app  = null !== $this->in_app ? ( $this->in_app )() : null;
		$payload = $this->from_visit( $referrer, $landing, home_url(), is_string( $in_app ) ? $in_app : null );

		if ( [] === $payload ) {
			return;
		}

		$this->send( $this->encode( $payload ) );
	}

	/**
	 * What a visit says about where its visitor came from.
	 *
	 * Referrer::analyse() decides, and this only writes its answer into the
	 * configured fields (see ALL_FIELDS). Its `direct` channel -- an address
	 * typed, a bookmark, an app that sends no referrer, a return from a
	 * payment page -- is an empty answer: "direct" is the absence of a
	 * source, not a source called direct.
	 *
	 * The `landing` path is only ever kept alongside something else. Where
	 * somebody landed is worth knowing about a visit that was sent, and is
	 * not a reason to set a cookie for one that was not.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $referrer The Referer header, if any.
	 * @param string      $landing  The address asked for, with its query string.
	 * @param string      $site     This site's address. A referrer from it is not an arrival.
	 * @param string|null $in_app   Optional. The app whose in-app browser opened the page.
	 *
	 * @return array<string, string> Only what has a value; empty when there is nothing to record.
	 */
	public function from_visit( string $referrer, string $landing, string $site, ?string $in_app = null ): array {
		$found = Referrer::analyse(
			[
				'referrer' => $referrer,
				'url'      => $landing,
				'site_url' => $site,
				'in_app'   => $in_app,
				'ignore'   => $this->ignore,
			]
		);

		$payload = [];

		if ( 'direct' !== $found['channel'] ) {
			$payload = [
				'source'   => (string) $found['source'],
				'medium'   => self::medium( $found ),
				'campaign' => (string) $found['campaign'],
				'term'     => (string) $found['term'],
				'content'  => (string) $found['content'],
				'channel'  => (string) $found['channel'],
				'click_id' => (string) ( $found['click_id']['param'] ?? '' ),
				'referrer' => $found['internal'] ? '' : (string) $found['referrer_host'],
			];
		}

		if ( '' !== $this->filter ) {
			/**
			 * Filter what a visit is recorded as having come from.
			 *
			 * Named from the configured prefix: `{prefix}_first_touch`. Where
			 * a tag of the site's own is read. Return an empty array to record
			 * nothing for this visit. What comes back is cleaned.
			 *
			 * @param array<string, string> $payload  The fields; empty for a direct visit.
			 * @param array<string, mixed>  $found    Everything Referrer::analyse() decided.
			 * @param string                $referrer The Referer header.
			 * @param string                $landing  The address asked for.
			 */
			$payload = (array) apply_filters( $this->filter, $payload, $found, $referrer, $landing );
		}

		$payload = $this->clean( $payload );

		if ( [] === $payload ) {
			return [];
		}

		return $payload + $this->clean( [ 'landing' => (string) Referrer::landing_path( $landing ) ] );
	}

	/**
	 * Only the configured fields, each cut to its length, the tag fields
	 * cut to tags, and none of the empty ones.
	 *
	 * Everything stored or read back goes through here, because all of it
	 * has been somewhere a visitor could change it: a query string, a
	 * cookie, a payment's metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $values Whatever was read.
	 *
	 * @return array<string, string> In the configured field order.
	 */
	public function clean( array $values ): array {
		$clean = [];

		foreach ( $this->fields as $field => $length ) {
			if ( ! is_scalar( $values[ $field ] ?? null ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $values[ $field ] );

			if ( in_array( $field, $this->tags, true ) ) {
				$value = (string) Referrer::to_tag( $value );
			}

			$value = mb_substr( $value, 0, $length );

			if ( '' !== $value ) {
				$clean[ $field ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * A payload, as it is kept in the cookie and anywhere it is copied to.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string JSON, or empty when there is nothing in it.
	 */
	public function encode( array $payload ): string {
		$payload = $this->clean( $payload );

		return [] === $payload ? '' : (string) wp_json_encode( $payload );
	}

	/**
	 * A payload read back.
	 *
	 * @since 1.2.0
	 *
	 * @param string $stored What the cookie, or a copy of it, holds.
	 *
	 * @return array<string, string>
	 */
	public function decode( string $stored ): array {
		if ( '' === $stored || strlen( $stored ) > self::MAX_STORED ) {
			return [];
		}

		$payload = json_decode( $stored, true );

		return is_array( $payload ) ? $this->clean( $payload ) : [];
	}

	/**
	 * What this browser's cookie says.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Empty when it is off, unset, or not ours.
	 */
	public function current(): array {
		if ( ! $this->enabled() || ! isset( $_COOKIE[ $this->cookie ] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, which a sanitiser would break; every value in it is sanitised by decode().
		$stored = wp_unslash( $_COOKIE[ $this->cookie ] );

		return is_string( $stored ) ? $this->decode( $stored ) : [];
	}

	/**
	 * This browser's cookie, as something made now should carry it.
	 *
	 * @since 1.2.0
	 *
	 * @return string The payload as JSON, or empty.
	 */
	public function stored(): string {
		return $this->encode( $this->current() );
	}

	/**
	 * Forget this browser's first touch.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->send( '' );
	}

	/**
	 * A payload as flat metadata, for a payment provider that keeps
	 * string pairs (a Stripe Checkout Session, say), so it survives where
	 * the copy it was read from may not.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string               $prefix  Put before each key: `myplugin_` makes `myplugin_source`.
	 *
	 * @return array<string, string> No empty keys.
	 */
	public function to_metadata( array $payload, string $prefix ): array {
		$metadata = [];

		foreach ( $this->clean( $payload ) as $field => $value ) {
			$metadata[ $prefix . $field ] = $value;
		}

		return $metadata;
	}

	/**
	 * A payload read back from metadata written by to_metadata().
	 *
	 * Reads an array, an object's properties, or an object that keeps its
	 * values behind ArrayAccess and magic properties rather than in public
	 * ones -- as a Stripe SDK object does, where get_object_vars() sees
	 * nothing at all.
	 *
	 * @since 1.2.0
	 *
	 * @param array|object|null $metadata The metadata.
	 * @param string            $prefix   The prefix it was written with.
	 *
	 * @return array<string, string>
	 */
	public function from_metadata( $metadata, string $prefix ): array {
		if ( ! is_array( $metadata ) && ! is_object( $metadata ) ) {
			return [];
		}

		$read = [];

		foreach ( array_keys( $this->fields ) as $field ) {
			$read[ $field ] = self::entry( $metadata, $prefix . $field );
		}

		return $this->clean( $read );
	}

	/**
	 * One entry of some metadata, or ''.
	 *
	 * @since 1.2.0
	 *
	 * @param array|object $metadata The metadata.
	 * @param string       $key      The key.
	 *
	 * @return mixed
	 */
	private static function entry( $metadata, string $key ) {
		if ( is_array( $metadata ) || $metadata instanceof \ArrayAccess ) {
			return isset( $metadata[ $key ] ) ? $metadata[ $key ] : '';
		}

		return isset( $metadata->{$key} ) ? $metadata->{$key} : '';
	}

	/**
	 * Every field, empty: a direct visit, as a row's columns.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	public function blank(): array {
		return array_fill_keys( array_keys( $this->fields ), '' );
	}

	/**
	 * Send the cookie, and have it readable for the rest of this request.
	 *
	 * The superglobal first, and unconditionally: something made later in
	 * this same request reads it from there. Slashed, because that is how
	 * WordPress hands over every cookie that arrived with the request, and
	 * current() takes the slashes off again.
	 *
	 * The header can only go out before the body; afterwards nothing can be
	 * done about it, and it is not worth a warning.
	 *
	 * Never readable by script, and Lax rather than Strict: the visitor
	 * comes back from a payment provider's domain, and Strict would drop the
	 * cookie on that navigation -- the one journey that matters most.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value What it holds; empty to clear it.
	 *
	 * @return void
	 */
	protected function send( string $value ): void {
		$clearing = '' === $value;

		if ( $clearing ) {
			unset( $_COOKIE[ $this->cookie ] );
		} else {
			$_COOKIE[ $this->cookie ] = wp_slash( $value );
		}

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			$this->cookie,
			$value,
			[
				'expires'  => $clearing ? time() - 3600 : time() + $this->lifetime,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * The medium of a visit Referrer::analyse() has looked at.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $found What analyse() returned.
	 *
	 * @return string The link's own `utm_medium`, or the channel.
	 */
	protected static function medium( array $found ): string {
		$tagged = (string) $found['medium'];

		if ( '' !== $tagged ) {
			return $tagged;
		}

		return 'campaign' === $found['channel'] ? '' : (string) $found['channel'];
	}

	/**
	 * The configured fields as name => length.
	 *
	 * @since 1.2.0
	 *
	 * @param array $fields Names, or name => length.
	 *
	 * @return array<string, int> Only names from ALL_FIELDS.
	 */
	protected static function read_fields( array $fields ): array {
		$read = [];

		foreach ( $fields as $key => $value ) {
			$name   = is_int( $key ) ? (string) $value : (string) $key;
			$length = is_int( $key ) ? null : (int) $value;

			if ( isset( self::ALL_FIELDS[ $name ] ) ) {
				$read[ $name ] = max( 1, $length ?? self::ALL_FIELDS[ $name ] );
			}
		}

		return $read;
	}
}
