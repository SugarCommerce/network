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

/**
 * Class UserAgent
 *
 * Browser, operating system, device type and bot detection from a
 * user-agent string.
 *
 * User agents lie, and have since Netscape. Everything here is a
 * heuristic: fine for analytics, segmentation, and deciding whether to
 * count a hit, and not fine as a security boundary. Nothing in this
 * class should gate access to anything.
 *
 * @since 1.0.0
 */
final readonly class UserAgent {

	/**
	 * Browser patterns, most specific first.
	 *
	 * Order matters more than the patterns do. Chrome's UA contains
	 * "Safari", Edge's contains "Chrome", and Brave's contains both — so
	 * the generic patterns carry negative lookaheads and must be tried
	 * last.
	 */
	private const BROWSERS = array(
		// Electron applications.
		'Electron'          => 'Electron\/([0-9.]+)',

		// Mobile webviews, before the mobile browsers they resemble.
		'Android WebView'   => 'Android.*wv.*Chrome\/([0-9.]+)',
		'iOS WebView'       => 'Mobile.*Safari.*AppleWebKit(?!.*Version)',

		// Specific mobile browsers.
		'Chrome iOS'        => 'CriOS\/([0-9.]+)',
		'Firefox iOS'       => 'FxiOS\/([0-9.]+)',
		'DuckDuckGo iOS'    => 'DuckDuckGo\/([0-9.]+)',
		'Safari Mobile'     => '(?:iPhone|iPad|iPod).+Version\/([0-9.]+).+Safari',
		'Samsung Browser'   => 'SamsungBrowser\/([0-9.]+)',
		'UC Browser'        => 'UCBrowser\/([0-9.]+)',

		// Desktop browsers with their own token, before generic Chrome.
		'Edge'              => 'Edg(?:e|A|iOS)?\/([0-9.]+)',
		'Opera'             => 'OPR\/([0-9.]+)|Opera\/([0-9.]+)',
		'Brave'             => 'Brave\/([0-9.]+)',
		'Vivaldi'           => 'Vivaldi\/([0-9.]+)',
		'Chrome OS'         => 'CrOS.+Chrome\/([0-9.]+)',

		// Generic, and therefore last.
		'Chrome Mobile'     => 'Chrome\/([0-9.]+).*Mobile(?!.*(?:Edge|OPR|Opera|Brave|Vivaldi))',
		'Chrome'            => 'Chrome\/([0-9.]+)(?!.*(?:Edge|OPR|Opera|Brave|Vivaldi|Mobile|wv))',
		'Firefox'           => 'Firefox\/([0-9.]+)',
		'Safari'            => 'Version\/([0-9.]+).+Safari(?!.*Chrome)',

		'Internet Explorer' => 'MSIE ([0-9.]+)|Trident.*rv:([0-9.]+)',
	);

	/**
	 * Operating system patterns, most specific first.
	 *
	 * Three of these are lies the platforms tell, and no pattern can fix
	 * them:
	 *
	 * **Windows 11 is undetectable from a user agent.** It reports
	 * `Windows NT 10.0`, byte-identical to Windows 10. The token was
	 * deliberately frozen — bumping it would break decades of sniffing
	 * code — and Chromium's UA reduction stripped the rest. A pattern
	 * matching `Build 22000` looks reasonable and never fires, because
	 * browsers do not send build numbers. Distinguishing them requires
	 * the `Sec-CH-UA-Platform-Version` client hint; see
	 * {@see ClientHints::windows_version()}.
	 *
	 * **macOS reports 10.15.7 forever.** Frozen at Catalina, whatever
	 * the machine is actually running, so a captured version number is
	 * meaningless.
	 *
	 * **iPads usually claim to be Macs.** "Request Desktop Website" has
	 * been the iPadOS default since 13, so most iPad traffic arrives with
	 * a macOS user agent and is indistinguishable from a laptop.
	 */
	private const OPERATING_SYSTEMS = array(
		'iOS'          => 'iPhone OS ([0-9._]+)|iPad.*OS ([0-9._]+)|iPod.*OS ([0-9._]+)|CPU.*OS ([0-9._]+)',
		'Android'      => 'Android ([0-9.]+)',
		// Deliberately not "Windows 10" — see above. Reporting a version
		// this cannot know would be worse than reporting the range.
		'Windows 10/11' => 'Windows NT 10\.0',
		'Windows 8.1'  => 'Windows NT 6\.3',
		'Windows 8'    => 'Windows NT 6\.2',
		'Windows 7'    => 'Windows NT 6\.1',
		'Windows'      => 'Windows NT ([0-9.]+)',
		'macOS'        => 'Mac OS X ([0-9._]+)|Intel Mac OS X ([0-9._]+)',
		'Chrome OS'    => 'CrOS',
		'Ubuntu'       => 'Ubuntu',
		'Linux'        => 'Linux(?!.*Android)',
	);

	/**
	 * Named crawlers, matched as substrings.
	 *
	 * These are distinctive enough that a substring match is safe.
	 */
	private const NAMED_BOTS = array(
		// Search engines.
		'Googlebot', 'Google-InspectionTool', 'AdsBot-Google', 'Mediapartners-Google',
		'bingbot', 'BingPreview', 'Baiduspider', 'DuckDuckBot', 'YandexBot', 'Slurp',

		// AI and LLM crawlers. Kept in sync with the ai.robots.txt list —
		// see https://github.com/ai-robots-txt/ai.robots.txt.
		//
		// Note the split most lists miss: several operators run separate
		// tokens for training-corpus crawling and for fetching a page a
		// user just asked about. GPTBot and ChatGPT-User, PerplexityBot
		// and Perplexity-User, ClaudeBot and Claude-User. Blocking the
		// first is a licensing decision; blocking the second means a
		// person asked to see your page and got an error.
		'GPTBot', 'ChatGPT-User', 'ChatGPT Agent', 'OAI-SearchBot', 'OpenAI', 'Operator',
		'ClaudeBot', 'Claude-Web', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
		'PerplexityBot', 'Perplexity-User',
		'Google-Extended', 'GoogleOther', 'Google-NotebookLM', 'Gemini-Deep-Research',
		'Google-CloudVertexBot', 'CloudVertexBot',
		'Applebot-Extended', 'Amazonbot', 'bedrockbot', 'AzureAI-SearchBot',
		'Meta-ExternalAgent', 'meta-externalagent', 'meta-externalfetcher', 'meta-webindexer',
		'MistralAI-User', 'DuckAssistBot', 'cohere-ai', 'CCBot', 'ImagesiftBot',
		'Diffbot', 'YouBot', 'ExaBot', 'TavilyBot', 'LinerBot', 'iAskBot', 'PhindBot',
		'kagi-fetcher', 'Webzio-Extended', 'Timpibot', 'omgilibot', 'Andibot',
		// Chinese LLM crawlers.
		'Bytespider', 'TikTokSpider', 'DeepSeekBot', 'Kimi-User', 'TongyiBot',
		'PanguBot', 'YiyanBot', 'ChatGLM-Spider', 'QuillBot',
		// Agentic coding and browsing tools.
		'Claude-Code', 'Devin', 'Cursor', 'opencode', 'Trae', 'Manus-User',
		'NovaAct', 'GoogleAgent-Mariner',
		// Scraping infrastructure sold as a service.
		'FirecrawlAgent', 'Crawl4AI', 'Scrapy', 'ApifyBot', 'Crawlspace',
		'VelenPublicWebCrawler', 'img2dataset', 'Panscient',

		// Social unfurlers.
		'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Pinterestbot',
		'TelegramBot', 'Slackbot', 'Discordbot', 'WhatsApp', 'redditbot',

		// SEO and monitoring.
		'Applebot', 'SemrushBot', 'AhrefsBot', 'MJ12bot', 'DotBot', 'DataForSeoBot',
		'PetalBot', 'Screaming Frog', 'UptimeRobot', 'Pingdom', 'StatusCake',

		// Programmatic clients.
		'python-requests', 'python-urllib', 'Go-http-client', 'node-fetch',
		'libwww-perl', 'PostmanRuntime', 'insomnia', 'HTTPClient', 'okhttp',
		'axios', 'Guzzle', 'aiohttp',
	);

	/**
	 * The AI and LLM subset of {@see self::NAMED_BOTS}.
	 */
	private const AI_BOTS = array(
		'GPTBot', 'ChatGPT-User', 'ChatGPT Agent', 'OAI-SearchBot', 'OpenAI', 'Operator',
		'ClaudeBot', 'Claude-Web', 'Claude-User', 'Claude-SearchBot', 'Claude-Code', 'anthropic-ai',
		'PerplexityBot', 'Perplexity-User',
		'Google-Extended', 'GoogleOther', 'Google-NotebookLM', 'Gemini-Deep-Research',
		'Google-CloudVertexBot', 'CloudVertexBot', 'GoogleAgent-Mariner',
		'Applebot-Extended', 'Amazonbot', 'bedrockbot', 'AzureAI-SearchBot',
		'Meta-ExternalAgent', 'meta-externalagent', 'meta-externalfetcher', 'meta-webindexer',
		'MistralAI-User', 'DuckAssistBot', 'cohere-ai', 'CCBot', 'ImagesiftBot',
		'Diffbot', 'YouBot', 'ExaBot', 'TavilyBot', 'LinerBot', 'iAskBot', 'PhindBot',
		'kagi-fetcher', 'Webzio-Extended', 'Timpibot', 'omgilibot', 'Andibot',
		'Bytespider', 'TikTokSpider', 'DeepSeekBot', 'Kimi-User', 'TongyiBot',
		'PanguBot', 'YiyanBot', 'ChatGLM-Spider', 'QuillBot',
		'Devin', 'Cursor', 'opencode', 'Trae', 'Manus-User', 'NovaAct',
		'FirecrawlAgent', 'Crawl4AI',
	);

	/**
	 * The crawlers that fetch a page because a person asked for it.
	 *
	 * A strict subset of {@see self::AI_BOTS}, kept separate because the
	 * decision about them is different — see
	 * {@see self::is_ai_user_agent()}.
	 */
	private const AI_USER_AGENTS = array(
		'ChatGPT-User', 'ChatGPT Agent', 'Claude-User', 'Perplexity-User',
		'MistralAI-User', 'Kimi-User', 'DuckAssistBot', 'Manus-User',
		'OAI-SearchBot', 'Claude-SearchBot',
	);

	/**
	 * Phone-shaped devices.
	 */
	private const MOBILE_PATTERN = '/(?:iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile)/i';

	/**
	 * Tablet-shaped devices.
	 *
	 * An Android tablet is identified by the ABSENCE of "Mobile", which
	 * is one of the less helpful decisions in the platform.
	 */
	private const TABLET_PATTERN = '/(?:iPad|Android(?!.*Mobile)|Tablet|Kindle|Silk|PlayBook)/i';

	/**
	 * Generic crawler words, matched on word boundaries.
	 *
	 * A substring match on these is what produces the classic false
	 * positives: "bot" appears in **Cubot**, a real Android phone brand,
	 * so every Cubot owner gets logged as a crawler. Anchoring to word
	 * boundaries keeps `Foo bot/1.0` and `Foo-Bot` while releasing
	 * `Cubot_NOTE_20`.
	 */
	private const GENERIC_BOT_WORDS = array(
		'bot', 'bots', 'spider', 'crawler', 'crawl', 'scraper', 'archiver',
		'curl', 'wget', 'java', 'ruby', 'perl', 'php', 'python',
	);

	/* ─── Reading the request ───────────────────────────────────────── */

	/**
	 * The current request's user-agent string.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *                                          Null uses the superglobal.
	 *
	 * @return string Trimmed and length-capped; '' when absent.
	 */
	public static function current( ?array $server = null ): string {
		$server ??= $_SERVER;
		$agent    = trim( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) );

		// Nothing legitimate is this long, and it ends up in log rows and
		// database columns.
		return mb_substr( $agent, 0, 512 );
	}

	/* ─── Classification ────────────────────────────────────────────── */

	/**
	 * The browser name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null Null when unrecognised.
	 */
	public static function browser( string $agent ): ?string {
		return self::first_match( $agent, self::BROWSERS );
	}

	/**
	 * The browser version.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null Null when unrecognised or unversioned.
	 */
	public static function browser_version( string $agent ): ?string {
		foreach ( self::BROWSERS as $pattern ) {
			if ( 1 === preg_match( '/' . $pattern . '/i', $agent, $m ) ) {
				foreach ( array_slice( $m, 1 ) as $capture ) {
					if ( '' !== $capture ) {
						return $capture;
					}
				}

				return null;
			}
		}

		return null;
	}

	/**
	 * The operating system name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null Null when unrecognised.
	 */
	public static function os( string $agent ): ?string {
		return self::first_match( $agent, self::OPERATING_SYSTEMS );
	}

	/**
	 * Whether the agent is a crawler, scraper, or programmatic client.
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

		foreach ( self::NAMED_BOTS as $name ) {
			if ( false !== stripos( $agent, $name ) ) {
				return true;
			}
		}

		return 1 === preg_match( self::generic_bot_pattern(), $agent );
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
		foreach ( self::AI_BOTS as $name ) {
			if ( false !== stripos( $agent, $name ) ) {
				return true;
			}
		}

		return false;
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
		foreach ( self::AI_USER_AGENTS as $name ) {
			if ( false !== stripos( $agent, $name ) ) {
				return true;
			}
		}

		return false;
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
	 * A short human-readable summary.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string e.g. `Chrome 131 on macOS`, or `Unknown`.
	 */
	public static function describe( string $agent ): string {
		if ( self::is_bot( $agent ) ) {
			return self::bot_name( $agent ) ?? 'Bot';
		}

		$browser = self::browser( $agent );
		$os      = self::os( $agent );

		if ( null === $browser && null === $os ) {
			return 'Unknown';
		}

		$version = null !== $browser ? self::browser_version( $agent ) : null;
		$label   = null !== $version ? $browser . ' ' . explode( '.', $version )[0] : $browser;

		return match ( true ) {
			null !== $label && null !== $os => $label . ' on ' . $os,
			null !== $label                 => (string) $label,
			default                         => (string) $os,
		};
	}

	/**
	 * The name of the crawler, when it is a recognised one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent User-agent string.
	 *
	 * @return string|null
	 */
	public static function bot_name( string $agent ): ?string {
		foreach ( self::NAMED_BOTS as $name ) {
			if ( false !== stripos( $agent, $name ) ) {
				return $name;
			}
		}

		return null;
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * The first key whose pattern matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $agent    User-agent string.
	 * @param array<string, string> $patterns Name => regex body.
	 *
	 * @return string|null
	 */
	private static function first_match( string $agent, array $patterns ): ?string {
		foreach ( $patterns as $name => $pattern ) {
			if ( 1 === preg_match( '/' . $pattern . '/i', $agent ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * The compiled word-boundary pattern for generic crawler terms.
	 *
	 * Built once per process. The naive implementation recompiles an
	 * alternation of every pattern on every call, which is measurable
	 * when you classify a request per page view.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function generic_bot_pattern(): string {
		static $pattern = null;

		if ( null === $pattern ) {
			$quoted  = array_map( static fn( string $w ): string => preg_quote( $w, '/' ), self::GENERIC_BOT_WORDS );
			$pattern = '/(?<![a-z0-9])(?:' . implode( '|', $quoted ) . ')(?![a-z0-9])/i';
		}

		return $pattern;
	}
}
