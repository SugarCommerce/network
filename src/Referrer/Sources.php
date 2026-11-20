<?php
/**
 * Referrer Sources
 *
 * The tables of where visits come from. Data only; the matching is in Referrer.
 *
 * @package SugarCommerce\Network
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.1.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Referrer;

/**
 * Class Sources
 *
 * Webmail, AI assistants, search engines, social networks, Android apps and
 * the hosts that are never a source.
 *
 * These are the same tables as the JavaScript package @flarecart/referrer
 * (src/sources.js), in the same order, so the two can be read side by side
 * and kept in step. An entry the JavaScript package does not have yet is
 * marked "PHP only".
 *
 * A matcher is one of two things. A domain (`reddit.com`) matches that host
 * and any subdomain of it, and never a host that merely contains it: `t.co`
 * is inside `microsoft.com` and `x.com` is inside `netflix.com`, which is how
 * substring matching files half the web under Twitter. A matcher that starts
 * with `/` is a pattern, for a brand that runs one site under many country
 * domains (google.com, google.co.uk, google.com.au).
 *
 * @since 1.1.0
 */
final class Sources {

	/**
	 * A country or generic top-level domain: `com`, `de`, `co.uk`, `com.au`.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private const string TLD = '(?:[a-z]{2,3}|(?:co|com|net|org|ne|or|ac)\.[a-z]{2})';

	/**
	 * Webmail, checked first.
	 *
	 * Its hosts overlap with search and AI (`mail.google.com`,
	 * `outlook.live.com`, `mail.yandex.ru`), and a click there is an email,
	 * not a search.
	 *
	 * @since 1.1.0
	 * @var array<int, array{0: string, 1: string}> Matcher, name.
	 */
	public const array WEBMAIL = [
		[ 'mail.google.com', 'Gmail' ],
		[ 'outlook.live.com', 'Outlook' ],
		[ 'outlook.office.com', 'Outlook' ],
		[ 'outlook.office365.com', 'Outlook' ],
		[ '/(?:^|\.)mail\.yahoo\.' . self::TLD . '$/D', 'Yahoo Mail' ],
		[ 'mail.proton.me', 'Proton Mail' ],
		[ 'mail.protonmail.com', 'Proton Mail' ],
		[ 'mail.aol.com', 'AOL Mail' ],
		[ '/(?:^|\.)mail\.zoho\.' . self::TLD . '$/D', 'Zoho Mail' ],
		[ 'icloud.com', 'iCloud Mail' ],
		[ 'fastmail.com', 'Fastmail' ],
		[ 'app.hey.com', 'HEY' ],
		[ '/(?:^|\.)mail\.yandex\.' . self::TLD . '$/D', 'Yandex Mail' ],
		[ 'mail.qq.com', 'QQ Mail' ],
		[ 'gmx.net', 'GMX' ],
		[ 'gmx.com', 'GMX' ],
		[ 'web.de', 'WEB.DE' ],
		[ 'app.tuta.com', 'Tuta' ],
	];

	/**
	 * AI assistants, checked before search.
	 *
	 * `gemini.google.com` is an assistant, not a Google search, and somebody
	 * who clicked a link ChatGPT cited did not search for anything.
	 *
	 * @since 1.1.0
	 * @var array<int, array{0: string, 1: string}> Matcher, name.
	 */
	public const array AI_ASSISTANTS = [
		[ 'chatgpt.com', 'ChatGPT' ],
		[ 'chat.openai.com', 'ChatGPT' ],
		[ 'claude.ai', 'Claude' ],
		[ 'perplexity.ai', 'Perplexity' ],
		[ 'gemini.google.com', 'Gemini' ],
		[ 'bard.google.com', 'Gemini' ],
		[ 'copilot.microsoft.com', 'Copilot' ],
		[ 'grok.com', 'Grok' ],
		[ 'chat.deepseek.com', 'DeepSeek' ],
		// PHP only: the bare domain, which this library already recognised.
		[ 'deepseek.com', 'DeepSeek' ],
		[ 'chat.mistral.ai', 'Le Chat' ],
		[ 'meta.ai', 'Meta AI' ],
		[ 'you.com', 'You.com' ],
		[ 'phind.com', 'Phind' ],
		[ 'poe.com', 'Poe' ],
		[ 'kimi.com', 'Kimi' ],
		[ 'kimi.moonshot.cn', 'Kimi' ],
		[ 'chat.qwen.ai', 'Qwen' ],
		[ 'doubao.com', 'Doubao' ],
	];

	/**
	 * Search engines, with the query parameter that carries the search.
	 *
	 * Where they still send it: Google has not since 2011, and most followed.
	 * An empty parameter means the engine never does.
	 *
	 * Google is its exact host on any country domain -- `google.com`,
	 * `www.google.de` -- and not its other subdomains: `docs.google.com` is a
	 * document somebody shared, not a search.
	 *
	 * @since 1.1.0
	 * @var array<int, array{0: string, 1: string, 2: string}> Matcher, name, query parameter.
	 */
	public const array SEARCH_ENGINES = [
		[ '/^(?:www\.)?google\.' . self::TLD . '$/D', 'Google', 'q' ],
		[ 'news.google.com', 'Google News', '' ],
		[ 'bing.com', 'Bing', 'q' ],
		[ 'duckduckgo.com', 'DuckDuckGo', 'q' ],
		[ '/(?:^|\.)search\.yahoo\.' . self::TLD . '$/D', 'Yahoo', 'p' ],
		[ '/(?:^|\.)yandex\.' . self::TLD . '$/D', 'Yandex', 'text' ],
		[ 'ya.ru', 'Yandex', 'text' ],
		[ 'baidu.com', 'Baidu', 'wd' ],
		[ 'ecosia.org', 'Ecosia', 'q' ],
		[ 'search.brave.com', 'Brave Search', 'q' ],
		[ 'startpage.com', 'Startpage', 'query' ],
		[ 'qwant.com', 'Qwant', 'q' ],
		[ 'ask.com', 'Ask', 'q' ],
		[ 'search.naver.com', 'Naver', 'query' ],
		[ 'seznam.cz', 'Seznam', 'q' ],
		[ 'kagi.com', 'Kagi', 'q' ],
		[ 'search.daum.net', 'Daum', 'q' ],
		[ 'sogou.com', 'Sogou', 'query' ],
		[ 'mojeek.com', 'Mojeek', 'q' ],
		// PHP only: three engines this library already recognised. AOL is its
		// search host or the bare domain, never mail.aol.com (which is webmail
		// and was caught above) or the rest of the portal.
		[ 'search.aol.com', 'AOL', 'q' ],
		[ '/^aol\.com$/D', 'AOL', 'q' ],
		[ 'searx.org', 'Searx', 'q' ],
		[ 'andisearch.com', 'Andi', 'q' ],
	];

	/**
	 * Social networks, communities, chat apps and creator platforms.
	 *
	 * Mastodon is the well-known servers by name, then any host that starts
	 * `mastodon.` or `mstdn.`; a smaller server reads as a referral under its
	 * own name.
	 *
	 * @since 1.1.0
	 * @var array<int, array{0: string, 1: string}> Matcher, name.
	 */
	public const array SOCIAL_NETWORKS = [
		[ 'facebook.com', 'Facebook' ],
		[ 'fb.com', 'Facebook' ],
		[ 'fb.me', 'Facebook' ],
		[ 'messenger.com', 'Messenger' ],
		// PHP only: Messenger's short links.
		[ 'm.me', 'Messenger' ],
		[ 'instagram.com', 'Instagram' ],
		// PHP only: Instagram's short links.
		[ 'ig.me', 'Instagram' ],
		[ 'threads.net', 'Threads' ],
		[ 'threads.com', 'Threads' ],
		[ 'x.com', 'X' ],
		[ 'twitter.com', 'X' ],
		[ 't.co', 'X' ],
		[ 'linkedin.com', 'LinkedIn' ],
		[ 'lnkd.in', 'LinkedIn' ],
		[ 'youtube.com', 'YouTube' ],
		[ 'youtu.be', 'YouTube' ],
		[ 'reddit.com', 'Reddit' ],
		[ 'redd.it', 'Reddit' ],
		[ '/(?:^|\.)pinterest\.' . self::TLD . '$/D', 'Pinterest' ],
		[ 'pin.it', 'Pinterest' ],
		[ 'tiktok.com', 'TikTok' ],
		[ 'douyin.com', 'Douyin' ],
		[ 'lemon8-app.com', 'Lemon8' ],
		[ 'bsky.app', 'Bluesky' ],
		[ 'discord.com', 'Discord' ],
		[ 'discord.gg', 'Discord' ],
		[ 'discordapp.com', 'Discord' ],
		[ 't.me', 'Telegram' ],
		[ 'telegram.org', 'Telegram' ],
		// PHP only: Telegram's older short links.
		[ 'telegram.me', 'Telegram' ],
		[ 'whatsapp.com', 'WhatsApp' ],
		[ 'wa.me', 'WhatsApp' ],
		[ 'snapchat.com', 'Snapchat' ],
		// PHP only: Snap's corporate domain.
		[ 'snap.com', 'Snapchat' ],
		[ 'line.me', 'LINE' ],
		[ 'wechat.com', 'WeChat' ],
		[ 'weixin.qq.com', 'WeChat' ],
		[ 'weibo.com', 'Weibo' ],
		[ 'weibo.cn', 'Weibo' ],
		[ 'xiaohongshu.com', 'Xiaohongshu' ],
		[ 'xhslink.com', 'Xiaohongshu' ],
		[ 'vk.com', 'VK' ],
		[ 'kakao.com', 'KakaoTalk' ],
		[ 'blog.naver.com', 'Naver Blog' ],
		[ 'cafe.naver.com', 'Naver Cafe' ],
		[ 'slack.com', 'Slack' ],
		[ 'slack-redir.net', 'Slack' ],
		[ 'teams.microsoft.com', 'Microsoft Teams' ],
		[ 'kick.com', 'Kick' ],
		[ 'twitch.tv', 'Twitch' ],
		[ 'soundcloud.com', 'SoundCloud' ],
		[ 'bandcamp.com', 'Bandcamp' ],
		[ 'spotify.com', 'Spotify' ],
		[ 'patreon.com', 'Patreon' ],
		[ 'behance.net', 'Behance' ],
		[ 'dribbble.com', 'Dribbble' ],
		[ 'medium.com', 'Medium' ],
		[ 'substack.com', 'Substack' ],
		[ 'tumblr.com', 'Tumblr' ],
		[ 'quora.com', 'Quora' ],
		[ 'news.ycombinator.com', 'Hacker News' ],
		[ 'producthunt.com', 'Product Hunt' ],
		[ 'mastodon.social', 'Mastodon' ],
		[ 'mastodon.online', 'Mastodon' ],
		[ 'mastodon.world', 'Mastodon' ],
		[ 'mstdn.social', 'Mastodon' ],
		[ 'mas.to', 'Mastodon' ],
		[ 'fosstodon.org', 'Mastodon' ],
		[ 'hachyderm.io', 'Mastodon' ],
		[ 'infosec.exchange', 'Mastodon' ],
		[ 'techhub.social', 'Mastodon' ],
		[ 'universeodon.com', 'Mastodon' ],
		[ '/^(?:mastodon|mstdn)\./', 'Mastodon' ],
	];

	/**
	 * Hosts that are never a source.
	 *
	 * The payment and sign-in pages a visitor passes through and comes back
	 * from. A return from Stripe Checkout is the same visit, not a referral
	 * from Stripe -- and without this, Stripe is every shop's best referrer.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	public const array IGNORED_HOSTS = [
		'stripe.com',
		'paypal.com',
		'paddle.com',
		'lemonsqueezy.com',
		'accounts.google.com',
		'appleid.apple.com',
		'login.microsoftonline.com',
	];

	/**
	 * Android apps, by package, for `android-app://` referrers.
	 *
	 * What Android sends when an app, not a browser, opened the link.
	 *
	 * @since 1.1.0
	 * @var array<string, array{0: string, 1: string}> Package => channel, name.
	 */
	public const array ANDROID_APPS = [
		'com.google.android.googlequicksearchbox' => [ 'search', 'Google' ],
		'com.google.android.gm'                   => [ 'email', 'Gmail' ],
		'com.microsoft.office.outlook'            => [ 'email', 'Outlook' ],
		'com.google.android.youtube'              => [ 'social', 'YouTube' ],
		'com.facebook.katana'                     => [ 'social', 'Facebook' ],
		'com.facebook.orca'                       => [ 'social', 'Messenger' ],
		'com.instagram.android'                   => [ 'social', 'Instagram' ],
		'com.instagram.barcelona'                 => [ 'social', 'Threads' ],
		'com.zhiliaoapp.musically'                => [ 'social', 'TikTok' ],
		'com.ss.android.ugc.trill'                => [ 'social', 'TikTok' ],
		'com.twitter.android'                     => [ 'social', 'X' ],
		'com.linkedin.android'                    => [ 'social', 'LinkedIn' ],
		'com.reddit.frontpage'                    => [ 'social', 'Reddit' ],
		'com.pinterest'                           => [ 'social', 'Pinterest' ],
		'com.snapchat.android'                    => [ 'social', 'Snapchat' ],
		'com.whatsapp'                            => [ 'social', 'WhatsApp' ],
		'org.telegram.messenger'                  => [ 'social', 'Telegram' ],
		'com.discord'                             => [ 'social', 'Discord' ],
		'jp.naver.line.android'                   => [ 'social', 'LINE' ],
		'com.tencent.mm'                          => [ 'social', 'WeChat' ],
		'com.Slack'                               => [ 'social', 'Slack' ],
		'com.openai.chatgpt'                      => [ 'ai', 'ChatGPT' ],
		'com.anthropic.claude'                    => [ 'ai', 'Claude' ],
		'ai.perplexity.app.android'               => [ 'ai', 'Perplexity' ],
	];

	/**
	 * Apps' in-app browsers, as sources.
	 *
	 * Keyed by the name {@see \SugarCommerce\Network\UserAgent::in_app_browser()}
	 * gives the app. An app that is not listed counts as
	 * social under its own name, which is right for nearly all of them.
	 *
	 * @since 1.1.0
	 * @var array<string, array{0: string, 1: string}> App => channel, name.
	 */
	public const array IN_APP_SOURCES = [
		'Naver' => [ 'search', 'Naver' ],
	];
}
