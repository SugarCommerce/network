<?php
/**
 * The current request's agent, read the WordPress way.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Request
 *
 * UserAgent and ClientHints both take what they are given, which is what makes
 * them testable. This is the layer that decides what to give them, and it is
 * where WordPress has opinions.
 *
 * Two of them. Core slashes `$_SERVER` on load, so the header has to be
 * unslashed before it is read. And core already answers "is this a phone" --
 * `wp_is_mobile()` consults the `Sec-CH-UA-Mobile` client hint before it
 * sniffs the agent string, and it is filterable, so a site that has already
 * corrected the answer for its own traffic has done so there.
 *
 * @since 2.0.0
 */
final class Request {

	/**
	 * The agent string for this request.
	 *
	 * @since 2.0.0
	 *
	 * @return string Empty when the header is absent.
	 */
	public static function agent(): string {
		// Unslashed before stripping, or a quoted agent keeps its backslashes;
		// and an array where the header should be is no agent, not "Array".
		$agent = Server::value( Server::get(), 'HTTP_USER_AGENT' );

		if ( '' === $agent ) {
			return '';
		}

		$agent = wp_strip_all_tags( $agent );

		// Trimmed and capped as every agent is, at UserAgent::MAX_LENGTH: one
		// number for how much of an agent is read, wherever it is read from.
		return UserAgent::normalise( $agent );
	}

	/**
	 * Whether this request is from a phone.
	 *
	 * Defers to core, which checks the `Sec-CH-UA-Mobile` client hint first
	 * and falls back to the agent string -- and which is filterable, so a site
	 * that has already corrected this for its own traffic gets its answer
	 * rather than a second opinion.
	 *
	 * Note that core's answer includes tablets. Use UserAgent::is_mobile() on
	 * the agent string when the two need telling apart.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_mobile(): bool {
		return function_exists( 'wp_is_mobile' ) ? wp_is_mobile() : UserAgent::is_mobile( self::agent() );
	}

	/**
	 * What kind of device this request is from.
	 *
	 * The client hint wins where the browser sent one, because it is stated
	 * rather than inferred. Everything else falls back to the agent string.
	 *
	 * @since 2.0.0
	 *
	 * @return string 'bot', 'tablet', 'mobile', 'desktop' or 'unknown'.
	 */
	public static function device_type(): string {
		$agent = self::agent();

		if ( '' === $agent ) {
			return 'unknown';
		}

		// A bot is a bot whatever the hints say, and bots do not send hints.
		if ( UserAgent::is_bot( $agent ) ) {
			return 'bot';
		}

		$hinted = ClientHints::is_mobile();

		if ( false === $hinted ) {
			// Stated: not a phone. Still possibly a tablet, which the hint
			// does not distinguish.
			return UserAgent::is_tablet( $agent ) ? 'tablet' : 'desktop';
		}

		if ( true === $hinted ) {
			return UserAgent::is_tablet( $agent ) ? 'tablet' : 'mobile';
		}

		return UserAgent::device_type( $agent );
	}

	/**
	 * Whether this request is an AI crawler.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_ai_bot(): bool {
		return UserAgent::is_ai_bot( self::agent() );
	}

	/**
	 * Whether this request is an AI agent acting for a person.
	 *
	 * Distinct from a crawler: somebody asked their assistant to fetch this
	 * page, which is a visit rather than an indexing pass. Worth counting
	 * differently, and worth not blocking with the crawler rules.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_ai_user_agent(): bool {
		return UserAgent::is_ai_user_agent( self::agent() );
	}

	/**
	 * Whether this request is a chat app drawing a link's preview card.
	 *
	 * Somebody pasted the link; nobody has visited yet. Serve it the page,
	 * and do not count it as a view or spend a download on it.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function is_link_preview(): bool {
		return UserAgent::is_link_preview( self::agent() );
	}

	/**
	 * The app whose built-in browser this request came from.
	 *
	 * The one thing that says where a social visitor came from when the
	 * app sent no referrer, which is most of the time. {@see Referrer::from_request()} reads
	 * it itself; hand it to {@see Referrer::analyse()} as `in_app`.
	 *
	 * @since 2.1.0
	 *
	 * @return string|null e.g. `TikTok`, `Instagram`. Null for a normal browser.
	 */
	public static function in_app_browser(): ?string {
		return UserAgent::in_app_browser( self::agent() );
	}

	/**
	 * "Chrome on macOS", for a log row.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public static function describe(): string {
		return UserAgent::describe( self::agent() );
	}
}
