<?php
/**
 * Source Icons
 *
 * A picture and a colour for each named source.
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
use SugarCommerce\Plugin\Assets;

/**
 * Class Icons
 *
 * Referrer::analyse() says a visit came from "Reddit". This says what that
 * looks like in a list: the brand's mark, and the colour a bar for it is
 * drawn in.
 *
 * Keyed by the names the Sources and Campaigns tables give -- "ChatGPT",
 * "Hacker News", "Brave Search" -- exactly as analyse() returns them in
 * `source`. The marks are in assets/sources/, from Simple Icons (CC0 1.0;
 * see the README beside them). A brand whose mark is not there is not
 * invented: every source has a picture all the same, because a list where
 * some rows have one and some do not is a ragged list.
 *
 * The fallback, in order:
 *
 *   - no source at all (an empty string, or "Direct") is `direct`, an arrow;
 *   - an email source without a mark of its own -- a webmail host from
 *     Sources::WEBMAIL, an email platform from Campaigns, or any name with
 *     "mail" or "newsletter" in it -- is `email`, an envelope;
 *   - anything else, which is mostly a referring site's own domain, is
 *     `website`, a globe.
 *
 * @since 1.2.0
 */
final class Icons {

	/**
	 * The picture for no source at all.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const string DIRECT = 'direct';

	/**
	 * The picture for an email source with no mark of its own.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const string EMAIL = 'email';

	/**
	 * The picture for every other source with no mark of its own.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const string WEBSITE = 'website';

	/**
	 * Which file each named source has, without its extension.
	 *
	 * A network's ads are drawn with the network's mark: "Reddit Ads" is
	 * still Reddit. Three names here are not ones the tables produce --
	 * "Brave", "GitHub" and "Vimeo" -- and are kept for whoever stores a
	 * source under them.
	 *
	 * @since 1.2.0
	 * @var array<string, string> Source => file name.
	 */
	public const array FILES = [
		'Baidu'         => 'baidu',
		'Bandcamp'      => 'bandcamp',
		'Behance'       => 'behance',
		'Bluesky'       => 'bluesky',
		'Brave'         => 'brave',
		'Brave Search'  => 'brave',
		'ChatGPT'       => 'openai',
		'Claude'        => 'claude',
		'Discord'       => 'discord',
		'Douyin'        => 'tiktok',
		'Dribbble'      => 'dribbble',
		'DuckDuckGo'    => 'duckduckgo',
		'Ecosia'        => 'ecosia',
		'Facebook'      => 'facebook',
		'GMX'           => 'gmx',
		'Gemini'        => 'googlegemini',
		'GitHub'        => 'github',
		'Gmail'         => 'gmail',
		'Google'        => 'google',
		'Google Ads'    => 'googleads',
		'HEY'           => 'hey',
		'Hacker News'   => 'ycombinator',
		'Instagram'     => 'instagram',
		'KakaoTalk'     => 'kakaotalk',
		'Kick'          => 'kick',
		'LINE'          => 'line',
		'Le Chat'       => 'mistralai',
		'Mastodon'      => 'mastodon',
		'Medium'        => 'medium',
		'Messenger'     => 'messenger',
		'Meta AI'       => 'meta',
		'Naver'         => 'naver',
		'Naver Blog'    => 'naver',
		'Naver Cafe'    => 'naver',
		'Patreon'       => 'patreon',
		'Perplexity'    => 'perplexity',
		'Pinterest'     => 'pinterest',
		'Pinterest Ads' => 'pinterest',
		'Poe'           => 'poe',
		'Product Hunt'  => 'producthunt',
		'Proton Mail'   => 'protonmail',
		'QQ Mail'       => 'qq',
		'Quora'         => 'quora',
		'Reddit'        => 'reddit',
		'Reddit Ads'    => 'reddit',
		'Slack'         => 'slack',
		'Snapchat'      => 'snapchat',
		'Snapchat Ads'  => 'snapchat',
		'SoundCloud'    => 'soundcloud',
		'Spotify'       => 'spotify',
		'Substack'      => 'substack',
		'Telegram'      => 'telegram',
		'Threads'       => 'threads',
		'TikTok'        => 'tiktok',
		'TikTok Ads'    => 'tiktok',
		'Tumblr'        => 'tumblr',
		'Tuta'          => 'tuta',
		'Twitch'        => 'twitch',
		'VK'            => 'vk',
		'Vimeo'         => 'vimeo',
		'WeChat'        => 'wechat',
		'Weibo'         => 'sinaweibo',
		'WhatsApp'      => 'whatsapp',
		'X'             => 'x',
		'X Ads'         => 'x',
		'Xiaohongshu'   => 'xiaohongshu',
		'YouTube'       => 'youtube',
		'iCloud Mail'   => 'icloud',
	];

	/**
	 * The colour each named source is drawn in.
	 *
	 * The brand's own, as Simple Icons records it, with two kinds adjusted
	 * for a bar on a white card: a black mark is a grey bar (#646970), and a
	 * colour too pale to see is darkened.
	 *
	 * @since 1.2.0
	 * @var array<string, string> Source => hex colour, lower case.
	 */
	public const array COLORS = [
		'Baidu'         => '#2932e1',
		'Bandcamp'      => '#408294',
		'Behance'       => '#1769ff',
		'Bluesky'       => '#1185fe',
		'Brave'         => '#fb542b',
		'Brave Search'  => '#fb542b',
		'ChatGPT'       => '#412991',
		'Claude'        => '#d97757',
		'Discord'       => '#5865f2',
		'Douyin'        => '#646970',
		'Dribbble'      => '#ea4c89',
		'DuckDuckGo'    => '#de5833',
		'Ecosia'        => '#008009',
		'Facebook'      => '#0866ff',
		'GMX'           => '#1c449b',
		'Gemini'        => '#8e75b2',
		'GitHub'        => '#646970',
		'Gmail'         => '#ea4335',
		'Google'        => '#4285f4',
		'Google Ads'    => '#4285f4',
		'HEY'           => '#5522fa',
		'Hacker News'   => '#f0652f',
		'Instagram'     => '#ff0069',
		'KakaoTalk'     => '#b79300',
		'Kick'          => '#3bb512',
		'LINE'          => '#00c300',
		'Le Chat'       => '#fa520f',
		'Mastodon'      => '#6364ff',
		'Medium'        => '#646970',
		'Messenger'     => '#0866ff',
		'Meta AI'       => '#0467df',
		'Naver'         => '#03c75a',
		'Naver Blog'    => '#03c75a',
		'Naver Cafe'    => '#03c75a',
		'Patreon'       => '#646970',
		'Perplexity'    => '#1fb8cd',
		'Pinterest'     => '#bd081c',
		'Pinterest Ads' => '#bd081c',
		'Poe'           => '#5d5cde',
		'Product Hunt'  => '#da552f',
		'Proton Mail'   => '#6d4aff',
		'QQ Mail'       => '#1ebafc',
		'Quora'         => '#b92b27',
		'Reddit'        => '#ff4500',
		'Reddit Ads'    => '#ff4500',
		'Slack'         => '#646970',
		'Snapchat'      => '#b7b500',
		'Snapchat Ads'  => '#b7b500',
		'SoundCloud'    => '#ff5500',
		'Spotify'       => '#1ed760',
		'Substack'      => '#ff6719',
		'Telegram'      => '#26a5e4',
		'Threads'       => '#646970',
		'TikTok'        => '#646970',
		'TikTok Ads'    => '#646970',
		'Tumblr'        => '#36465d',
		'Tuta'          => '#850122',
		'Twitch'        => '#9146ff',
		'VK'            => '#0077ff',
		'Vimeo'         => '#1ab7ea',
		'WeChat'        => '#07c160',
		'Weibo'         => '#e6162d',
		'WhatsApp'      => '#25d366',
		'X'             => '#646970',
		'X Ads'         => '#646970',
		'Xiaohongshu'   => '#ff2442',
		'YouTube'       => '#ff0000',
		'iCloud Mail'   => '#3693f3',
	];

	/**
	 * Every named source that has a mark of its own, with its file.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Source => file name, without extension.
	 */
	public static function all(): array {
		return self::FILES;
	}

	/**
	 * Every named source that has a colour of its own.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Source => hex colour.
	 */
	public static function colors(): array {
		return self::COLORS;
	}

	/**
	 * Whether a source has a mark of its own, and not one of the three
	 * plain pictures.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name.
	 *
	 * @return bool
	 */
	public static function has( string $source ): bool {
		return isset( self::FILES[ trim( $source ) ] );
	}

	/**
	 * Which file a source is drawn with, without its extension.
	 *
	 * Always a file that is in assets/sources/: the source's own, or one of
	 * the three fallbacks.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name, as analyse() gives it. Empty
	 *                       for a visit with no source.
	 *
	 * @return string
	 */
	public static function file( string $source ): string {
		$source = trim( $source );

		return self::FILES[ $source ] ?? self::fallback( $source );
	}

	/**
	 * Which of the three plain pictures a source without a mark gets.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name. Empty for no source.
	 *
	 * @return string self::DIRECT, self::EMAIL or self::WEBSITE.
	 */
	public static function fallback( string $source ): string {
		$source = trim( $source );

		if ( '' === $source || 'direct' === strtolower( $source ) ) {
			return self::DIRECT;
		}

		if ( 1 === preg_match( '/mail|newsletter/i', $source ) || in_array( $source, self::email_names(), true ) ) {
			return self::EMAIL;
		}

		return self::WEBSITE;
	}

	/**
	 * Where a source's picture is, as a URL.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name. Empty for no source.
	 *
	 * @return string Empty only when the library is somewhere WordPress
	 *                cannot serve from, which is for the caller to answer.
	 */
	public static function url( string $source ): string {
		return self::file_url( self::file( $source ) );
	}

	/**
	 * Where one of the files is, as a URL.
	 *
	 * For a caller that has let something else choose the file -- a filter,
	 * a setting. A name is lower-case letters and digits and nothing else,
	 * which is what keeps it inside the folder; anything that is not one of
	 * the files here is the globe.
	 *
	 * @since 1.2.0
	 *
	 * @param string $file A file name without its extension, e.g. `reddit`.
	 *
	 * @return string Empty when the folder has no URL.
	 */
	public static function file_url( string $file ): string {
		$base = self::base_url();

		if ( '' === $base ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[a-z0-9]+\z/', $file ) || ! is_file( self::dir() . '/' . $file . '.svg' ) ) {
			$file = self::WEBSITE;
		}

		return $base . '/' . $file . '.svg';
	}

	/**
	 * Where a source's picture is, on disk.
	 *
	 * For inlining a mark, or checking that one is there.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name. Empty for no source.
	 *
	 * @return string An absolute path.
	 */
	public static function path( string $source ): string {
		return self::dir() . '/' . self::file( $source ) . '.svg';
	}

	/**
	 * The colour a source is drawn in.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source The source's name.
	 *
	 * @return string A hex colour, or empty for a source with none of its
	 *                own, which is for the caller's usual colour.
	 */
	public static function color( string $source ): string {
		return self::COLORS[ trim( $source ) ] ?? '';
	}

	/**
	 * The folder the pictures are in.
	 *
	 * @since 1.2.0
	 *
	 * @return string An absolute path, without a trailing slash.
	 */
	public static function dir(): string {
		return dirname( __DIR__, 2 ) . '/assets/sources';
	}

	/**
	 * The folder's URL.
	 *
	 * The library is installed wherever its host put it -- a vendor/ folder,
	 * a prefixed copy made by Strauss, a plugin of its own -- so the URL is
	 * worked out from where this file is, by {@see Assets}: under the
	 * content directory, the WordPress root, or through the map core keeps
	 * of symlinked plugins. Assets remembers the answer.
	 *
	 * @since 1.2.0
	 *
	 * @return string Without a trailing slash. Empty when the folder is not
	 *                there, or is somewhere WordPress does not serve.
	 */
	public static function base_url(): string {
		return Assets::url( __FILE__, 'sources' ) ?? '';
	}

	/**
	 * Forget the folder's URL, so it is worked out again.
	 *
	 * For a test, and for a host that moves the content directory after
	 * something has already asked. Assets forgets every package's folder
	 * with it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		Assets::clear_cache();
	}

	/**
	 * The names of the email sources: webmail hosts, and email platforms.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private static function email_names(): array {
		static $names = null;

		if ( null !== $names ) {
			return $names;
		}

		$names = array_column( Sources::WEBMAIL, 1 );

		foreach ( Campaigns::UTM_SOURCES as $entry ) {
			if ( 'email' === $entry[0] ) {
				$names[] = $entry[1];
			}
		}

		foreach ( Campaigns::CLICK_IDS as $entry ) {
			if ( 'email' === $entry[1] ) {
				$names[] = $entry[2];
			}
		}

		foreach ( Sources::ANDROID_APPS as $entry ) {
			if ( 'email' === $entry[0] ) {
				$names[] = $entry[1];
			}
		}

		$names = array_values( array_unique( $names ) );

		return $names;
	}
}
