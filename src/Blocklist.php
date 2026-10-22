<?php
/**
 * Blocking by IP address, email address or email domain.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Blocklist
 *
 * The rules a blocklist runs on, without the storage: which kinds of entry
 * there are, what an operator's typed pattern becomes once stored, and which
 * keys a visitor is looked up by. Kept in one place because the add form,
 * the command line and the check that enforces them otherwise each grow
 * their own copy — and the copies drift, so an entry one of them accepts is
 * one the check can never match.
 *
 * An entry is a type and a pattern:
 *
 * - `ip`: the stored form of an address, made by the $ip_key closure —
 *   usually a keyed hash, so the table holds no raw addresses.
 * - `email`: the address, normalised by $email_key (lower-cased and
 *   validated, by default).
 * - `email_domain`: `@` and the domain, lower-cased: `@spam.example`.
 *   Matches that exact domain; a subdomain is a different domain.
 *
 * ```php
 * $rules = new Blocklist( fn( string $ip ): string => $hash->ip( $ip ) );
 *
 * $stored = $rules->pattern( 'email_domain', ' @Spam.Example ' );  // '@spam.example', or '' when invalid
 *
 * foreach ( $rules->keys( $request_ip, $email ) as $type => $pattern ) {
 *     // SELECT reason FROM blocklist WHERE type = :type AND pattern = :pattern
 * }
 * ```
 *
 * @since 1.5.0
 */
final readonly class Blocklist {

	/**
	 * An entry for one IP address.
	 */
	public const string IP = 'ip';

	/**
	 * An entry for one email address.
	 */
	public const string EMAIL = 'email';

	/**
	 * An entry for every address at one domain.
	 */
	public const string DOMAIN = 'email_domain';

	/**
	 * Every type, in the order keys() checks them.
	 *
	 * @var list<string>
	 */
	public const array TYPES = array( self::IP, self::EMAIL, self::DOMAIN );

	/**
	 * @since 1.5.0
	 *
	 * @param \Closure(string): string      $ip_key    A valid address to its
	 *                                                 stored form.
	 * @param (\Closure(string): string)|null $email_key An address to its stored
	 *                                                 form, '' when invalid.
	 *                                                 Null lower-cases and
	 *                                                 validates.
	 */
	public function __construct(
		private \Closure $ip_key,
		private ?\Closure $email_key = null,
	) {}

	/**
	 * What a typed pattern is stored as, or '' when it is not a valid one
	 * for its type (or the type is unknown).
	 *
	 * @since 1.5.0
	 *
	 * @param string $type  One of TYPES.
	 * @param string $typed What the operator typed.
	 *
	 * @return string
	 */
	public function pattern( string $type, string $typed ): string {
		$typed = trim( $typed );

		return match ( $type ) {
			self::IP     => Ip::is_valid( $typed ) ? ( $this->ip_key )( trim( $typed, '[]' ) ) : '',
			self::EMAIL  => $this->email( $typed ),
			self::DOMAIN => str_starts_with( $typed, '@' ) && '' !== ( $host = Host::normalize( substr( $typed, 1 ) ) ) ? '@' . $host : '',
			default      => '',
		};
	}

	/**
	 * The entries a visitor would match, as type => stored pattern, in the
	 * order to check them.
	 *
	 * Lookups are lenient where entries are strict: what is stored was
	 * validated, but what is checked is whatever arrived. An address too
	 * odd to pass validation — `a..b@spam.example`, a legacy row — is still
	 * looked up, lower-cased, and its domain still matches an
	 * `email_domain` entry; refusing to look it up would let exactly the
	 * strangest addresses through. A missing IP, an invalid one, or an email
	 * without an `@` between two parts adds nothing.
	 *
	 * @since 1.5.0
	 *
	 * @param string|null $ip    The visitor's address.
	 * @param string|null $email The address they gave, if any.
	 *
	 * @return array<string, string>
	 */
	public function keys( ?string $ip, ?string $email = null ): array {
		$keys = array();

		if ( null !== $ip && Ip::is_valid( $ip ) ) {
			$keys[ self::IP ] = ( $this->ip_key )( trim( trim( $ip ), '[]' ) );
		}

		$plain = null === $email ? '' : strtolower( trim( $email ) );
		$at    = strrpos( $plain, '@' );

		if ( false !== $at && $at > 0 && $at < strlen( $plain ) - 1 ) {
			$stored               = $this->email( (string) $email );
			$keys[ self::EMAIL ]  = '' !== $stored ? $stored : $plain;
			$keys[ self::DOMAIN ] = substr( $plain, $at );
		}

		return array_filter( $keys, static fn( string $pattern ): bool => '' !== $pattern );
	}

	/**
	 * An email address's stored form, or ''.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email As given.
	 *
	 * @return string
	 */
	private function email( string $email ): string {
		if ( null !== $this->email_key ) {
			return ( $this->email_key )( $email );
		}

		$email = strtolower( trim( $email ) );

		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? '' : $email;
	}
}
