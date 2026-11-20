<?php
/**
 * Everything that is not a person in a browser: crawlers, AI fetchers, link previewers and tools.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\UserAgent;

use SugarCommerce\Network\UserAgent\Agents;

/**
 * Everything that is not a person in a browser: crawlers, AI fetchers, link previewers and tools.
 *
 * @since 1.0.0
 */
trait Bots {

	/**
	 * Whether the agent is a crawler, scraper, link previewer or
	 * programmatic client, rather than a browser.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_bot( string $agent ): bool {
		if ( '' === trim( $agent ) ) {
			// An absent user agent is not a browser.
			return true;
		}

		return null !== self::find_name( $agent, 'NAMED_BOTS' )
			|| null !== self::find_name( $agent, 'GENERIC_BOT_WORDS' )
			|| null !== self::tool( $agent );
	}

	/**
	 * Whether the agent is one of the AI or LLM crawlers.
	 *
	 * Separated because the decision about them is usually different from
	 * the one about Googlebot — you may want search indexing while
	 * refusing training crawlers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_ai_bot( string $agent ): bool {
		return null !== self::find_name( $agent, 'AI_BOTS' );
	}

	/**
	 * Whether the agent is an AI crawler fetching on a person's behalf.
	 *
	 * The distinction that matters when deciding what to block. A
	 * training-corpus crawler taking your catalogue is a licensing
	 * question; `ChatGPT-User` or `Perplexity-User` is somebody who asked
	 * to see your page, and refusing them is refusing a visitor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_ai_user_agent( string $agent ): bool {
		return null !== self::find_name( $agent, 'AI_USER_AGENTS' );
	}

	/**
	 * The link previewer fetching a pasted link to draw its card.
	 *
	 * WhatsApp, Slack, Discord, iMessage, Telegram and the rest. One
	 * person's link, not their visit: show it a page, but do not count it
	 * as a view or a download.
	 *
	 * @since 2.1.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null e.g. `WhatsApp`, `Slackbot`,
	 *                     `facebookexternalhit` (iMessage too).
	 */
	public static function link_preview( string $agent ): ?string {
		return self::find_name( $agent, 'LINK_PREVIEWS' );
	}

	/**
	 * Whether the agent is a link previewer.
	 *
	 * @since 2.1.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_link_preview( string $agent ): bool {
		return null !== self::link_preview( $agent );
	}

	/**
	 * The command-line client or HTTP library making the request.
	 *
	 * Matched at the start of the agent, where each puts its name:
	 * anywhere else would call a browser "Java" for a word in a plugin
	 * list.
	 *
	 * @since 2.1.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null e.g. `curl`, `Python`, `Go`, `PowerShell`.
	 */
	public static function tool( string $agent ): ?string {
		$start = strtolower( ltrim( $agent ) );

		foreach ( Agents::TOOLS as list( $token, $name ) ) {
			if ( str_starts_with( $start, strtolower( $token ) ) ) {
				return $name;
			}
		}

		// PowerShell announces itself inside a Mozilla-shaped string.
		return false !== stripos( $agent, 'WindowsPowerShell' ) ? 'PowerShell' : null;
	}

	/**
	 * The name of the crawler, when it is a recognised one.
	 *
	 * The leftmost name in the agent, and the longest at that spot:
	 * "Applebot-Extended" is not "Applebot".
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null
	 */
	public static function bot_name( string $agent ): ?string {
		return self::find_name( $agent, 'NAMED_BOTS' );
	}

	/**
	 * The first name from one of the lists found in the agent as a whole
	 * token.
	 *
	 * Whole token, case-insensitively: "bot" does not match "Cubot",
	 * while "Slackbot" does match "Slackbot-LinkExpanding".
	 *
	 * Built once per process and per list. The naive implementation
	 * recompiles an alternation of every name on every call, which is
	 * measurable when you classify a request per page view.
	 *
	 * @since 2.1.0
	 *
	 * @param string $agent User-agent string.
	 * @param string $table The constant holding the list.
	 *
	 * @return string|null The name as listed, or null.
	 */
	private static function find_name( string $agent, string $table ): ?string {
		static $matchers = array();

		if ( ! isset( $matchers[ $table ] ) ) {
			$names     = constant( Agents::class . '::' . $table );
			$canonical = array();

			foreach ( $names as $name ) {
				$canonical[ strtolower( $name ) ] = $name;
			}

			// Longest first, so "Applebot-Extended" wins over "Applebot"
			// at the same spot.
			usort( $names, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

			$quoted = array_map( static fn( string $name ): string => preg_quote( $name, '/' ), $names );

			$matchers[ $table ] = array(
				'pattern'   => '/(?<![a-z0-9])(' . implode( '|', $quoted ) . ')(?![a-z0-9])/i',
				'canonical' => $canonical,
			);
		}

		if ( 1 !== preg_match( $matchers[ $table ]['pattern'], $agent, $found ) ) {
			return null;
		}

		return $matchers[ $table ]['canonical'][ strtolower( $found[1] ) ] ?? $found[1];
	}
}
