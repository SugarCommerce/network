<?php
/**
 * The agents UserAgent knows.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\UserAgent;

use SugarCommerce\Network\ClientHints;
use SugarCommerce\Network\UserAgent;

/**
 * Class Agents
 *
 * Browsers, operating systems, in-app browsers, link previewers, crawlers
 * and tools: the tables {@see UserAgent} reads an
 * agent against. The same tables as the JavaScript package
 * `@flarecart/user-agent` (src/patterns.js), in the same order, so the two
 * can be read side by side and kept in step.
 *
 * @internal Ask UserAgent; these are its working, and change with it.
 *
 * @since 2.1.0
 */
final class Agents {

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
	 * a phone is {@see UserAgent::device_type()}'s job, and "Chrome Mobile" as
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
	public const array BROWSERS = array(
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
	public const array OPERATING_SYSTEMS = array(
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
	public const array IN_APP_BROWSERS = array(
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
	public const array LINK_PREVIEWS = array(
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
	public const array AI_BOTS = array(
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
	 * {@see UserAgent::is_ai_user_agent()}.
	 */
	public const array AI_USER_AGENTS = array(
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
	public const array NAMED_BOTS = array(
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
	public const array TOOLS = array(
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
	 * Generic crawler words, matched on word boundaries.
	 *
	 * A substring match on these is what produces the classic false
	 * positives: "bot" appears in **Cubot**, a real Android phone brand,
	 * so every Cubot owner gets logged as a crawler. Anchoring to word
	 * boundaries keeps `Foo bot/1.0` and `Foo-Bot` while releasing
	 * `Cubot_NOTE_20`.
	 */
	public const array GENERIC_BOT_WORDS = array(
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
}
