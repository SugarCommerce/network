<?php
/**
 * User-agent parsing and bot detection.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Traits\UserAgent\Bots;
use SugarCommerce\Network\UserAgent\Agents;

/**
 * Class UserAgent
 *
 * Browser, operating system, device type and bot detection from a
 * user-agent string — and everything that is not a person in a browser:
 * crawlers, AI fetchers, link previewers, command-line tools, and the
 * in-app browsers of TikTok, Instagram and Facebook.
 *
 * User agents lie, and have since Netscape. Everything here is a
 * heuristic: fine for analytics, segmentation, and deciding whether to
 * count a hit, and not fine as a security boundary. Nothing in this
 * class should gate access to anything.
 *
 * The tables and the rules are the ones the JavaScript package
 * `@flarecart/user-agent` uses (src/patterns.js), in the same order, so
 * the same string gets the same answer on both sides. The tables are in
 * {@see Agents}; bot detection is the Bots trait, in
 * src/Traits/UserAgent/.
 *
 * @since 1.0.0
 */
final readonly class UserAgent {

	use Bots;

	/**
	 * The longest user agent read.
	 *
	 * Nothing real is longer; anything longer is padding aimed at a log
	 * or a column.
	 *
	 * @since 2.1.0
	 */
	public const MAX_LENGTH = 512;

	/**
	 * Phone-shaped devices.
	 */
	private const MOBILE_PATTERN = '/iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile/i';

	/**
	 * Tablet-shaped devices.
	 *
	 * An Android tablet is identified by the ABSENCE of "Mobile", which
	 * is one of the less helpful decisions in the platform.
	 */
	private const TABLET_PATTERN = '/iPad|Android(?!.*Mobile)|Tablet|Kindle|Silk\/|PlayBook/i';

	/* ─── Reading the request ───────────────────────────────────────── */

	/**
	 * The current request's user-agent string.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *                                          Null uses the superglobal,
	 *                                          unslashed.
	 *
	 * @return string Trimmed and length-capped; '' when absent, or when
	 *                what arrived was not a string.
	 */
	public static function current( ?array $server = null ): string {
		return self::clean( Server::get( $server )['HTTP_USER_AGENT'] ?? null ) ?? '';
	}

	/**
	 * A user agent ready to store: trimmed and capped, or null.
	 *
	 * For an agent that did not come from {@see self::current()} -- passed
	 * in by a caller, read from a queued job -- so it is held to the same
	 * rules before it reaches a log row or a column: trimmed, cut at
	 * MAX_LENGTH characters (never mid-character), and null rather than ''
	 * when there is nothing, so "no agent" is one value in the database,
	 * not two.
	 *
	 *     $row['agent'] = UserAgent::clean( $agent ?? UserAgent::current() );
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $agent The agent, or anything that should have been one.
	 *
	 * @return string|null Null for a non-string, or one that is blank.
	 */
	public static function clean( mixed $agent ): ?string {
		$agent = is_string( $agent ) ? self::normalise( $agent ) : '';

		return '' === $agent ? null : $agent;
	}

	/**
	 * A user agent trimmed and capped, ready to read or store.
	 *
	 * @since 2.1.0
	 *
	 * @param string|null $agent The header's value.
	 *
	 * @return string The agent, or '' when there is none.
	 */
	public static function normalise( ?string $agent ): string {
		// Nothing legitimate is this long, and it ends up in log rows and
		// database columns.
		return mb_substr( trim( (string) $agent ), 0, self::MAX_LENGTH );
	}

	/* ─── Classification ────────────────────────────────────────────── */

	/**
	 * The browser name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null e.g. `Chrome`, `Safari`, `Samsung Internet`,
	 *                     `iOS WebView`. Null when unrecognised.
	 */
	public static function browser( string $agent ): ?string {
		return self::first_match( $agent, Agents::BROWSERS )['name'] ?? null;
	}

	/**
	 * The browser version, as the user agent states it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null Null when unrecognised or unversioned (a web
	 *                     view).
	 */
	public static function browser_version( string $agent ): ?string {
		$found = self::first_match( $agent, Agents::BROWSERS );

		// The first group with something in it: a pattern with two
		// spellings leaves the other one's group empty.
		foreach ( array_slice( $found['match'] ?? array(), 1 ) as $capture ) {
			if ( '' !== $capture ) {
				return $capture;
			}
		}

		return null;
	}

	/**
	 * The operating system name.
	 *
	 * No version: the ones user agents still carry are frozen (macOS
	 * 10.15.7, Windows NT 10.0, Android's reduced "Android 10; K"), so
	 * they would mislead.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null Null when unrecognised.
	 */
	public static function os( string $agent ): ?string {
		return self::first_match( $agent, Agents::OPERATING_SYSTEMS )['name'] ?? null;
	}

	/**
	 * The app whose built-in browser is showing the page.
	 *
	 * @since 2.1.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null e.g. `TikTok`, `Instagram`, `Facebook`,
	 *                     `Messenger`, `LINE`, `WeChat`. Null for a
	 *                     normal browser.
	 */
	public static function in_app_browser( string $agent ): ?string {
		return self::first_match( $agent, Agents::IN_APP_BROWSERS )['name'] ?? null;
	}

	/**
	 * Whether the agent looks like a mobile phone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_mobile( string $agent ): bool {
		if ( self::is_tablet( $agent ) ) {
			return false;
		}

		return 1 === preg_match( self::MOBILE_PATTERN, $agent );
	}

	/**
	 * Whether the agent looks like a tablet.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_tablet( string $agent ): bool {
		return 1 === preg_match( self::TABLET_PATTERN, $agent );
	}

	/**
	 * Whether the agent looks like a desktop browser.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return bool
	 */
	public static function is_desktop( string $agent ): bool {
		return ! self::is_bot( $agent ) && ! self::is_mobile( $agent ) && ! self::is_tablet( $agent );
	}

	/**
	 * The device category.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string `bot`, `tablet`, `mobile`, `desktop`, or `unknown`.
	 */
	public static function device_type( string $agent ): string {
		return match ( true ) {
			'' === trim( $agent )    => 'unknown',
			self::is_bot( $agent )    => 'bot',
			self::is_tablet( $agent ) => 'tablet',
			self::is_mobile( $agent ) => 'mobile',
			default                   => 'desktop',
		};
	}

	/**
	 * Everything this class can tell about a user agent, at once.
	 *
	 * @since 2.1.0
	 *
	 * @param string|null $agent User-agent string.
	 *
	 * @return array{browser: string|null, version: string|null, os: string|null, device: string, bot: bool, bot_name: string|null, ai_bot: bool, ai_user_agent: bool, tool: string|null, in_app: string|null, link_preview: string|null, label: string}
	 */
	public static function parse( ?string $agent ): array {
		$agent = self::normalise( $agent );

		return array(
			'browser'       => self::browser( $agent ),
			'version'       => self::browser_version( $agent ),
			'os'            => self::os( $agent ),
			'device'        => self::device_type( $agent ),
			'bot'           => self::is_bot( $agent ),
			'bot_name'      => self::bot_name( $agent ),
			'ai_bot'        => self::is_ai_bot( $agent ),
			'ai_user_agent' => self::is_ai_user_agent( $agent ),
			'tool'          => self::tool( $agent ),
			'in_app'        => self::in_app_browser( $agent ),
			'link_preview'  => self::link_preview( $agent ),
			'label'         => self::describe( $agent ),
		);
	}

	/**
	 * A short human-readable summary, for a sign-in history or a log.
	 *
	 * The least like a browser first: a tool is named as the tool, a
	 * link previewer as a preview, any other bot by its name. Then the
	 * app, because "Instagram in-app browser" says more than the web
	 * view it happens to be built on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string e.g. `Chrome 131 on macOS`, `Instagram in-app
	 *                browser on iOS`, `WhatsApp link preview`, `curl`,
	 *                or `Unknown`.
	 */
	public static function describe( string $agent ): string {
		$agent = self::normalise( $agent );
		$tool  = self::tool( $agent );

		if ( null !== $tool ) {
			return $tool;
		}

		$preview = self::link_preview( $agent );

		if ( null !== $preview ) {
			return $preview . ' link preview';
		}

		if ( self::is_bot( $agent ) ) {
			return self::bot_name( $agent ) ?? 'Bot';
		}

		$os  = self::os( $agent );
		$app = self::in_app_browser( $agent );

		if ( null !== $app ) {
			return null !== $os ? $app . ' in-app browser on ' . $os : $app . ' in-app browser';
		}

		$browser = self::browser( $agent );
		$version = null !== $browser ? self::browser_version( $agent ) : null;
		$label   = null !== $version ? $browser . ' ' . explode( '.', $version )[0] : $browser;

		return match ( true ) {
			null !== $label && null !== $os => $label . ' on ' . $os,
			null !== $label                 => (string) $label,
			default                         => $os ?? 'Unknown',
		};
	}

	/* ─── What can be returned ──────────────────────────────────────── */

	/**
	 * Every name {@see self::browser()} can return, in the order they
	 * are tried.
	 *
	 * For a list of choices. Read off the table rather than typed out a
	 * second time somewhere else, because a second list is the one that
	 * goes on offering "Chrome Mobile" after nothing returns it.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function browsers(): array {
		return array_values( array_unique( array_column( Agents::BROWSERS, 0 ) ) );
	}

	/**
	 * Every name {@see self::os()} can return, in the order they are
	 * tried.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function operating_systems(): array {
		return array_column( Agents::OPERATING_SYSTEMS, 0 );
	}

	/**
	 * Every name {@see self::in_app_browser()} can return.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function in_app_browsers(): array {
		return array_column( Agents::IN_APP_BROWSERS, 0 );
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * The first entry whose pattern matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                   $agent User-agent string.
	 * @param array<int, array{0: string, 1: string}> $table Name and pattern pairs, in order.
	 *
	 * @return array{name: string, match: array<int, string>}|null
	 */
	private static function first_match( string $agent, array $table ): ?array {
		foreach ( $table as list( $name, $pattern ) ) {
			if ( 1 === preg_match( $pattern, $agent, $match ) ) {
				return array(
					'name'  => $name,
					'match' => $match,
				);
			}
		}

		return null;
	}
}
