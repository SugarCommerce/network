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
 * the same string gets the same answer on both sides.
 *
 * @since 1.0.0
 */
final readonly class UserAgent {

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
	 * Browsers, most specific first, each with a pattern whose first
	 * non-empty group is the version.
	 *
	 * Order matters more than the patterns do: the first match wins.
	 * Chrome's UA contains "Safari" and Edge's contains "Chrome", so the
	 * generic three come last. Patterns are case-sensitive — these are
	 * tokens, and `Edge` is not `edge`.
	 *
	 * Names are browsers, not devices: Chrome on an iPhone is "Chrome"
	 * (its `CriOS` token) and Chrome on Android is "Chrome". Whether it is
	 * a phone is {@see self::device_type()}'s job, and "Chrome Mobile" as
	 * a browser name split one browser's numbers in two.
	 *
	 * Not listed, because the user agent cannot show them:
	 *
	 * **Brave** sends a plain Chrome UA on every platform. The `Brave/`
	 * token in older lists never fires. Its client hints name it — see
	 * {@see ClientHints::browser()}.
	 *
	 * **Vivaldi** has hidden its token on most sites since 2016, and also
	 * reads as Chrome.
	 */
	private const BROWSERS = array(
		// Desktop apps built on Chromium, which carry Chrome's token too.
		array( 'Electron', '/Electron\/([\d.]+)/' ),

		// Chromium browsers with their own token, before Chrome.
		array( 'Edge', '/Edg(?:e|A|iOS)?\/([\d.]+)/' ),
		array( 'Opera', '/(?:OPR|OPT)\/([\d.]+)|Opera\/([\d.]+)/' ),
		array( 'Samsung Internet', '/SamsungBrowser\/([\d.]+)/' ),
		array( 'Yandex Browser', '/YaBrowser\/([\d.]+)/' ),
		array( 'UC Browser', '/UCBrowser\/([\d.]+)/' ),
		array( 'DuckDuckGo', '/DuckDuckGo\/([\d.]+)|Ddg\/([\d.]+)/' ),

		// iOS browsers: WebKit skins, named by their own token.
		array( 'Chrome', '/CriOS\/([\d.]+)/' ),
		array( 'Firefox', '/FxiOS\/([\d.]+)/' ),
		array( 'Google app', '/\bGSA\/([\d.]+)/' ),

		// Android's embedded browser (apps showing a page), before Chrome:
		// its UA is Chrome's plus `; wv)`.
		array( 'Android WebView', '/;\s?wv\).*Chrome\/([\d.]+)/' ),

		// The generic three, last.
		array( 'Chrome', '/Chrome\/([\d.]+)/' ),
		array( 'Firefox', '/Firefox\/([\d.]+)/' ),
		array( 'Safari', '/Version\/([\d.]+).*Safari\//' ),

		// An iOS app showing a page in its own view: WebKit, no "Safari".
		array( 'iOS WebView', '/(?:iPhone|iPad|iPod).*AppleWebKit(?!.*Safari)/' ),

		array( 'Internet Explorer', '/MSIE ([\d.]+)|Trident\/.*rv:([\d.]+)/' ),
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
	 * the machine is actually running, so no version is read from it.
	 *
	 * **iPads usually claim to be Macs.** "Request Desktop Website" has
	 * been the iPadOS default since 13, so most iPad traffic arrives with
	 * a macOS user agent and is indistinguishable from a laptop.
	 *
	 * Windows Phone sits above Android because its Edge claimed to be
	 * Android as well, and HarmonyOS because Huawei's browser still does.
	 */
	private const OPERATING_SYSTEMS = array(
		array( 'iOS', '/iPhone|iPad|iPod/' ),
		array( 'HarmonyOS', '/HarmonyOS|OpenHarmony/' ),
		array( 'Windows Phone', '/Windows Phone/' ),
		array( 'Android', '/Android/' ),
		// Deliberately not "Windows 10" — see above. Reporting a version
		// this cannot know would be worse than reporting the range.
		array( 'Windows 10/11', '/Windows NT 10\.0/' ),
		array( 'Windows 8.1', '/Windows NT 6\.3/' ),
		array( 'Windows 8', '/Windows NT 6\.2/' ),
		array( 'Windows 7', '/Windows NT 6\.1/' ),
		array( 'Windows', '/Windows/' ),
		array( 'ChromeOS', '/CrOS/' ),
		array( 'macOS', '/Mac OS X|Macintosh/' ),
		array( 'Linux', '/Linux|X11/' ),
	);

	/**
	 * Apps that open links in their own browser, by the token they add to
	 * the user agent.
	 *
	 * A link tapped in TikTok or Instagram opens inside the app, which
	 * often sends no referrer at all. The app's name is still here, which
	 * makes this the best clue there is to where a social visitor came
	 * from: read it, and a "direct" visit becomes a TikTok one.
	 *
	 * Most specific first: Messenger before Facebook (it carries `FBAN`
	 * too), Instagram and Threads before Facebook (Meta's apps share
	 * code).
	 *
	 * Only apps that keep their own web view are detectable. X, YouTube,
	 * Gmail, and Reddit and Telegram on iOS open links in the system's
	 * in-app browser (SFSafariViewController, Chrome Custom Tabs), which
	 * sends the system browser's UA: nothing here can see them, and the
	 * referrer or a click ID has to.
	 *
	 * Tokens marked "likely" are reported by the apps but less well
	 * documented; check them against your own traffic.
	 */
	private const IN_APP_BROWSERS = array(
		// "FBAN/MessengerForiOS" on iOS; "Orca-Android" and "FB_IAB/MESSENGER" on Android.
		array( 'Messenger', '/FBAN\/MessengerForiOS|FB_IAB\/MESSENGER|Orca-Android/' ),
		// "Instagram 350.0.0.0 (iPhone15,2; iOS 18_0; …)".
		array( 'Instagram', '/\bInstagram [\d.]+/' ),
		// Threads' codename, "Barcelona 289.0.0.77.109" (likely).
		array( 'Threads', '/\bBarcelona [\d.]+/' ),
		// "[FBAN/FBIOS;FBAV/…]" on iOS, "[FB_IAB/FB4A;FBAV/…]" on Android.
		array( 'Facebook', '/FBAN\/|FB_IAB\/|FBAV\//' ),
		// "musical_ly_36.5.0", "BytedanceWebview/…"; "trill_" in some regions.
		array( 'TikTok', '/musical_ly|BytedanceWebview|\btrill_|\bTikTok\b/' ),
		// Douyin, TikTok's Chinese sibling: "aweme_" (likely).
		array( 'Douyin', '/\baweme_|\bDouyin\b/' ),
		array( 'Snapchat', '/Snapchat\/?[\d.]*/' ),
		array( 'LinkedIn', '/LinkedInApp/' ),
		// "[Pinterest/iOS]" and "[Pinterest/Android]" (likely); not "Pinterestbot".
		array( 'Pinterest', '/\[Pinterest\/(?:iOS|Android)\]|Pinterest for (?:iOS|Android)/' ),
		// Older X builds; current ones use the system view.
		array( 'X', '/Twitter for iPhone|TwitterAndroid/' ),
		array( 'LINE', '/\bLine\/[\d.]+/' ),
		array( 'WeChat', '/MicroMessenger\/[\d.]+/' ),
		array( 'Telegram', '/Telegram-Android\//' ),
		array( 'KakaoTalk', '/KAKAOTALK/i' ),
		array( 'Naver', '/NAVER\(inapp/' ),
		// Discord's desktop app (Electron): "discord/1.0.9…".
		array( 'Discord', '/\bdiscord\/[\d.]+/i' ),
	);

	/**
	 * Link previewers: the bots that fetch a pasted link to draw its card
	 * in a chat or a post.
	 *
	 * Distinct from crawlers because what they fetch is one person's
	 * link. Answer them with a page, but do not count it as that person's
	 * visit — or, worse, as a download against their limit.
	 *
	 * iMessage previews as "facebookexternalhit/1.1 Facebot
	 * Twitterbot/1.0" and Signal as "WhatsApp/2", both borrowing others'
	 * names; they are caught by those.
	 */
	private const LINK_PREVIEWS = array(
		'facebookexternalhit',
		'Facebot',
		'Twitterbot',
		'LinkedInBot',
		'Slackbot',
		'Slack-ImgProxy',
		'Discordbot',
		'TelegramBot',
		'WhatsApp',
		'SkypeUriPreview',
		'redditbot',
		'Pinterestbot',
		'Embedly',
		'Iframely',
		'vkShare',
		'kakaotalk-scrap',
		'Snap URL Preview Service',
		'Yahoo Link Preview',
		'Bluesky Cardyb',
		'Mastodon',
		'Google-PageRenderer',
	);

	/**
	 * The AI and LLM crawlers. Kept in sync with the ai.robots.txt list —
	 * see https://github.com/ai-robots-txt/ai.robots.txt.
	 *
	 * Note the split most lists miss: several operators run separate
	 * tokens for training-corpus crawling and for fetching a page a
	 * user just asked about. GPTBot and ChatGPT-User, PerplexityBot
	 * and Perplexity-User, ClaudeBot and Claude-User. Blocking the
	 * first is a licensing decision; blocking the second means a
	 * person asked to see your page and got an error. See
	 * {@see self::AI_USER_AGENTS}.
	 */
	private const AI_BOTS = array(
		'GPTBot',
		'ChatGPT-User',
		'ChatGPT Agent',
		'OAI-SearchBot',
		'OpenAI',
		'Operator',
		'ClaudeBot',
		'Claude-Web',
		'Claude-User',
		'Claude-SearchBot',
		'Claude-Code',
		'anthropic-ai',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'GoogleOther',
		'Google-NotebookLM',
		'Gemini-Deep-Research',
		'Google-CloudVertexBot',
		'CloudVertexBot',
		'GoogleAgent-Mariner',
		'Applebot-Extended',
		'Amazonbot',
		'bedrockbot',
		'AzureAI-SearchBot',
		'Meta-ExternalAgent',
		'meta-externalfetcher',
		'meta-webindexer',
		'MistralAI-User',
		'DuckAssistBot',
		'cohere-ai',
		'CCBot',
		'ImagesiftBot',
		'Diffbot',
		'YouBot',
		'ExaBot',
		'TavilyBot',
		'LinerBot',
		'iAskBot',
		'PhindBot',
		'kagi-fetcher',
		'Webzio-Extended',
		'Timpibot',
		'omgilibot',
		'Andibot',
		// Chinese LLM crawlers.
		'Bytespider',
		'TikTokSpider',
		'DeepSeekBot',
		'Kimi-User',
		'TongyiBot',
		'PanguBot',
		'YiyanBot',
		'ChatGLM-Spider',
		'QuillBot',
		// Agentic coding and browsing tools.
		'Devin',
		'Cursor',
		'opencode',
		'Trae',
		'Manus-User',
		'NovaAct',
		// Scraping infrastructure sold as a service.
		'FirecrawlAgent',
		'Crawl4AI',
	);

	/**
	 * The crawlers that fetch a page because a person asked for it.
	 *
	 * A strict subset of {@see self::AI_BOTS}, kept separate because the
	 * decision about them is different — see
	 * {@see self::is_ai_user_agent()}.
	 */
	private const AI_USER_AGENTS = array(
		'ChatGPT-User',
		'ChatGPT Agent',
		'Claude-User',
		'Perplexity-User',
		'MistralAI-User',
		'Kimi-User',
		'DuckAssistBot',
		'Manus-User',
		'OAI-SearchBot',
		'Claude-SearchBot',
	);

	/**
	 * Every named crawler: search engines, the AI list, link previewers,
	 * SEO and monitoring tools, and HTTP libraries.
	 *
	 * Matched as whole tokens, not substrings. These looked distinctive
	 * enough for a substring to be safe, and were not: `Operator` is
	 * inside "OperatorConsole", `Trae` inside "UltraEdge", `Cursor`
	 * inside any app that mentions one — and each of those was a real
	 * browser logged as an AI agent.
	 */
	private const NAMED_BOTS = array(
		// Search engines.
		'Googlebot',
		'Google-InspectionTool',
		'AdsBot-Google',
		'Mediapartners-Google',
		'bingbot',
		'BingPreview',
		'Baiduspider',
		'DuckDuckBot',
		'YandexBot',
		'Slurp',
		'Applebot',

		...self::AI_BOTS,

		// Scraping sold as a service.
		'Scrapy',
		'ApifyBot',
		'Crawlspace',
		'VelenPublicWebCrawler',
		'img2dataset',
		'Panscient',

		...self::LINK_PREVIEWS,

		// SEO and monitoring.
		'SemrushBot',
		'AhrefsBot',
		'MJ12bot',
		'DotBot',
		'DataForSeoBot',
		'PetalBot',
		'Screaming Frog',
		'UptimeRobot',
		'Pingdom',
		'StatusCake',

		// Programmatic clients.
		'python-requests',
		'python-urllib',
		'Go-http-client',
		'node-fetch',
		'libwww-perl',
		'PostmanRuntime',
		'insomnia',
		'HTTPClient',
		'okhttp',
		'axios',
		'Guzzle',
		'aiohttp',
	);

	/**
	 * Command-line clients and HTTP libraries, by the token their user
	 * agent starts with, and what to call each.
	 *
	 * Not crawlers: someone running curl against a file they bought is
	 * using it. They are still not a browser.
	 */
	private const TOOLS = array(
		array( 'curl', 'curl' ),
		array( 'Wget', 'Wget' ),
		array( 'aria2', 'aria2' ),
		array( 'python-requests', 'Python' ),
		array( 'python-urllib', 'Python' ),
		array( 'python-httpx', 'Python' ),
		array( 'aiohttp', 'Python' ),
		array( 'Go-http-client', 'Go' ),
		array( 'okhttp', 'OkHttp' ),
		array( 'axios', 'Node.js' ),
		array( 'node-fetch', 'Node.js' ),
		array( 'undici', 'Node.js' ),
		array( 'Java/', 'Java' ),
		array( 'Apache-HttpClient', 'Java' ),
		array( 'libwww-perl', 'Perl' ),
		array( 'WindowsPowerShell', 'PowerShell' ),
		array( 'PowerShell', 'PowerShell' ),
		array( 'Deno', 'Deno' ),
		array( 'Bun', 'Bun' ),
		array( 'PostmanRuntime', 'Postman' ),
	);

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
		'bot',
		'bots',
		'spider',
		'crawler',
		'crawl',
		'scraper',
		'archiver',
		'curl',
		'wget',
		'java',
		'ruby',
		'perl',
		'php',
		'python',
	);

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
		return self::first_match( $agent, self::BROWSERS )['name'] ?? null;
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
		$found = self::first_match( $agent, self::BROWSERS );

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
		return self::first_match( $agent, self::OPERATING_SYSTEMS )['name'] ?? null;
	}

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

		foreach ( self::TOOLS as list( $token, $name ) ) {
			if ( str_starts_with( $start, strtolower( $token ) ) ) {
				return $name;
			}
		}

		// PowerShell announces itself inside a Mozilla-shaped string.
		return false !== stripos( $agent, 'WindowsPowerShell' ) ? 'PowerShell' : null;
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
		return self::first_match( $agent, self::IN_APP_BROWSERS )['name'] ?? null;
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
		return array_values( array_unique( array_column( self::BROWSERS, 0 ) ) );
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
		return array_column( self::OPERATING_SYSTEMS, 0 );
	}

	/**
	 * Every name {@see self::in_app_browser()} can return.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function in_app_browsers(): array {
		return array_column( self::IN_APP_BROWSERS, 0 );
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
			$names     = constant( self::class . '::' . $table );
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
