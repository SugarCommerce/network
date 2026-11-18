<?php
/**
 * Campaign Tags and Click IDs
 *
 * What UTM tags and click IDs mean. Data only; the reading is in Referrer.
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
 * Class Campaigns
 *
 * The same tables as the JavaScript package @flarecart/referrer
 * (src/campaigns.js), in the same order, so the two can be kept in step.
 *
 * @since 1.1.0
 */
final class Campaigns {

	/**
	 * `utm_medium` values, by the channel they mean.
	 *
	 * Marketers type these by hand, so one channel arrives spelled several
	 * ways. Compared in lower case.
	 *
	 * @since 1.1.0
	 * @var array<string, string> Medium => channel.
	 */
	public const MEDIUMS = [
		'cpc'            => 'paid',
		'ppc'            => 'paid',
		'paid'           => 'paid',
		'paidsearch'     => 'paid',
		'paid-search'    => 'paid',
		'paid_search'    => 'paid',
		'paidsocial'     => 'paid',
		'paid-social'    => 'paid',
		'paid_social'    => 'paid',
		'display'        => 'paid',
		'banner'         => 'paid',
		'cpm'            => 'paid',
		'cpv'            => 'paid',
		'email'          => 'email',
		'e-mail'         => 'email',
		'newsletter'     => 'email',
		'mail'           => 'email',
		'social'         => 'social',
		'social-network' => 'social',
		'social-media'   => 'social',
		'sm'             => 'social',
		'organic_social' => 'social',
		'social_organic' => 'social',
		'organic'        => 'search',
		'search'         => 'search',
		'seo'            => 'search',
		'referral'       => 'referral',
	];

	/**
	 * Common `utm_source` spellings, as a channel and a named source.
	 *
	 * ChatGPT adds `utm_source=chatgpt.com` to the links it cites; a source
	 * that looks like a host is also classified as one, so that needs no
	 * entry here.
	 *
	 * @since 1.1.0
	 * @var array<string, array{0: string, 1: string}> Source, lower case => channel, name.
	 */
	public const UTM_SOURCES = [
		'tiktok'     => [ 'social', 'TikTok' ],
		'tt'         => [ 'social', 'TikTok' ],
		'facebook'   => [ 'social', 'Facebook' ],
		'fb'         => [ 'social', 'Facebook' ],
		'meta'       => [ 'social', 'Facebook' ],
		'instagram'  => [ 'social', 'Instagram' ],
		'ig'         => [ 'social', 'Instagram' ],
		'threads'    => [ 'social', 'Threads' ],
		'x'          => [ 'social', 'X' ],
		'twitter'    => [ 'social', 'X' ],
		'youtube'    => [ 'social', 'YouTube' ],
		'yt'         => [ 'social', 'YouTube' ],
		'reddit'     => [ 'social', 'Reddit' ],
		'pinterest'  => [ 'social', 'Pinterest' ],
		'linkedin'   => [ 'social', 'LinkedIn' ],
		'bluesky'    => [ 'social', 'Bluesky' ],
		'bsky'       => [ 'social', 'Bluesky' ],
		'discord'    => [ 'social', 'Discord' ],
		'twitch'     => [ 'social', 'Twitch' ],
		'soundcloud' => [ 'social', 'SoundCloud' ],
		'snapchat'   => [ 'social', 'Snapchat' ],
		'line'       => [ 'social', 'LINE' ],
		'telegram'   => [ 'social', 'Telegram' ],
		'whatsapp'   => [ 'social', 'WhatsApp' ],
		'google'     => [ 'search', 'Google' ],
		'bing'       => [ 'search', 'Bing' ],
		'duckduckgo' => [ 'search', 'DuckDuckGo' ],
		'newsletter' => [ 'email', 'Email' ],
		'email'      => [ 'email', 'Email' ],
		'mailchimp'  => [ 'email', 'Mailchimp' ],
		'convertkit' => [ 'email', 'Kit' ],
		'kit'        => [ 'email', 'Kit' ],
		'beehiiv'    => [ 'email', 'beehiiv' ],
		'buttondown' => [ 'email', 'Buttondown' ],
		'klaviyo'    => [ 'email', 'Klaviyo' ],
		'chatgpt'    => [ 'ai', 'ChatGPT' ],
		'openai'     => [ 'ai', 'ChatGPT' ],
		'perplexity' => [ 'ai', 'Perplexity' ],
		'claude'     => [ 'ai', 'Claude' ],
		'gemini'     => [ 'ai', 'Gemini' ],
		'copilot'    => [ 'ai', 'Copilot' ],
	];

	/**
	 * Click IDs: the parameters ad platforms and apps add to the links they
	 * send people through.
	 *
	 * They survive when the referrer is stripped, and say which network sent
	 * the visit. Checked in this order; the first one present wins.
	 *
	 * Most mean a paid click. Two do not: Meta adds `fbclid` to every
	 * outbound link, organic posts included, and Instagram's `igsh` marks a
	 * shared link. Those count as social unless `utm_medium` says paid.
	 *
	 * Tracking::PARAMS strips every one of these, which is the other half of
	 * the job: read the click ID here, then clean the URL there.
	 *
	 * @since 1.1.0
	 * @var array<int, array{0: string, 1: string, 2: string}> Parameter, channel, network.
	 */
	public const CLICK_IDS = [
		[ 'gclid', 'paid', 'Google Ads' ],
		[ 'gbraid', 'paid', 'Google Ads' ],
		[ 'wbraid', 'paid', 'Google Ads' ],
		[ 'dclid', 'paid', 'Google Display & Video' ],
		[ 'msclkid', 'paid', 'Microsoft Ads' ],
		[ 'ttclid', 'paid', 'TikTok Ads' ],
		[ 'twclid', 'paid', 'X Ads' ],
		[ 'li_fat_id', 'paid', 'LinkedIn Ads' ],
		[ 'rdt_cid', 'paid', 'Reddit Ads' ],
		[ 'ScCid', 'paid', 'Snapchat Ads' ],
		[ 'epik', 'paid', 'Pinterest Ads' ],
		[ 'yclid', 'paid', 'Yandex Ads' ],
		[ 'fbclid', 'social', 'Facebook' ],
		[ 'igsh', 'social', 'Instagram' ],
		[ 'igshid', 'social', 'Instagram' ],
		// Google's organic Shopping results.
		[ 'srsltid', 'search', 'Google' ],
		// Email platforms' tracking parameters.
		[ 'mc_cid', 'email', 'Mailchimp' ],
		[ '_hsenc', 'email', 'HubSpot' ],
		[ 'mkt_tok', 'email', 'Marketo' ],
	];
}
