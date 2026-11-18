<?php
/**
 * Blocklist
 *
 * The rules a blocklist runs on -- by IP address, email address or email
 * domain -- without the storage.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Blocklist Class
 *
 * Which kinds of entry there are, what an operator's typed pattern becomes
 * once stored, and which keys a visitor is looked up by. Kept in one place
 * because the add form, the command line and the check that enforces them
 * otherwise each grow their own copy -- and the copies drift, so an entry
 * one of them accepts is one the check can never match.
 *
 * An entry is a type and a pattern:
 *
 *  - `ip`: the stored form of one address, made by the $ip_key callback --
 *    usually a keyed hash ({@see IP::hash()}), so the table holds no raw
 *    addresses. The address is in its {@see IP::canonical()} spelling
 *    first, so one IPv6 address is one entry.
 *  - `email`: the address, as {@see Email} normalises it, or by an
 *    $email_key callback of your own.
 *  - `email_domain`: `@` and the domain, lower-cased: `@spam.example`.
 *    Matches that exact domain; a subdomain is a different domain.
 *
 *     $rules = new Blocklist( fn( string $ip ): string => IP::hash( $ip, wp_salt( 'auth' ) ) );
 *
 *     $stored = $rules->pattern( Blocklist::DOMAIN, ' @Spam.Example ' );  // '@spam.example', or '' when invalid
 *
 *     foreach ( $rules->keys( IP::get(), $email ) as $type => $pattern ) {
 *         // SELECT reason FROM blocklist WHERE type = %s AND pattern = %s
 *     }
 *
 * Ranges are not entries here: a hashed address cannot be looked up by
 * range. Keep those as {@see Range} patterns and test them with
 * {@see Range::contains_any()}.
 *
 * @since 1.0.0
 */
final class Blocklist {

	/**
	 * An entry for one IP address.
	 */
	public const IP = 'ip';

	/**
	 * An entry for one email address.
	 */
	public const EMAIL = 'email';

	/**
	 * An entry for every address at one domain.
	 */
	public const DOMAIN = 'email_domain';

	/**
	 * Every type, in the order keys() checks them.
	 */
	public const TYPES = [ self::IP, self::EMAIL, self::DOMAIN ];

	/**
	 * An address to its stored form.
	 *
	 * @var \Closure
	 */
	private \Closure $ip_key;

	/**
	 * An email address to its stored form, or null for the default.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $email_key;

	/**
	 * Say how entries are stored.
	 *
	 * @param \Closure      $ip_key    A valid address, canonical, to its
	 *                                 stored form.
	 * @param \Closure|null $email_key An address to its stored form, '' when
	 *                                 it is not one. Null normalises it as
	 *                                 {@see Email::parse()} does.
	 */
	public function __construct( \Closure $ip_key, ?\Closure $email_key = null ) {
		$this->ip_key    = $ip_key;
		$this->email_key = $email_key;
	}

	/**
	 * What a typed pattern is stored as.
	 *
	 * @param string $type  One of TYPES.
	 * @param string $typed What the operator typed.
	 *
	 * @return string '' when it is not a valid pattern for its type, or the
	 *                type is unknown.
	 */
	public function pattern( string $type, string $typed ): string {
		$typed = trim( $typed );

		return match ( $type ) {
			self::IP     => $this->ip( $typed ),
			self::EMAIL  => $this->email( $typed ),
			self::DOMAIN => $this->domain( $typed ),
			default      => '',
		};
	}

	/**
	 * The entries a visitor would match, as type => stored pattern, in the
	 * order to check them.
	 *
	 * Lookups are lenient where entries are strict: what is stored was
	 * validated, but what is checked is whatever arrived. An address too odd
	 * to pass validation -- a legacy row, something typed past a form -- is
	 * still looked up, lower-cased, and its domain still matches an
	 * `email_domain` entry; refusing to look it up would let exactly the
	 * strangest addresses through. A missing IP, an invalid one, or an email
	 * without an `@` between two parts adds nothing.
	 *
	 * @param string|null $ip    The visitor's address: {@see IP::get()}.
	 * @param string|null $email The address they gave, if any.
	 *
	 * @return array<string, string>
	 */
	public function keys( ?string $ip, ?string $email = null ): array {
		$keys = [ self::IP => $this->ip( (string) $ip ) ];

		$plain = strtolower( trim( (string) $email ) );
		$at    = strrpos( $plain, '@' );

		if ( false !== $at && $at > 0 && $at < strlen( $plain ) - 1 ) {
			$stored = $this->email( $plain );

			$keys[ self::EMAIL ]  = '' !== $stored ? $stored : $plain;
			$keys[ self::DOMAIN ] = substr( $plain, $at );
		}

		return array_filter( $keys, static fn( string $pattern ): bool => '' !== $pattern );
	}

	/**
	 * An address's stored form, or ''.
	 *
	 * @param string $ip As given.
	 *
	 * @return string
	 */
	private function ip( string $ip ): string {
		$ip = IP::canonical( $ip );

		return null === $ip ? '' : (string) ( $this->ip_key )( $ip );
	}

	/**
	 * An email address's stored form, or ''.
	 *
	 * @param string $email As given.
	 *
	 * @return string
	 */
	private function email( string $email ): string {
		if ( null !== $this->email_key ) {
			return (string) ( $this->email_key )( $email );
		}

		return Email::parse( $email )?->normalized() ?? '';
	}

	/**
	 * A domain entry's stored form, or ''.
	 *
	 * @param string $typed `@` and a domain.
	 *
	 * @return string
	 */
	private function domain( string $typed ): string {
		$host = str_starts_with( $typed, '@' ) ? Host::normalize( substr( $typed, 1 ) ) : '';

		return '' === $host ? '' : '@' . $host;
	}
}
