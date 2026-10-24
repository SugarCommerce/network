<?php
/**
 * Who made a request, pseudonymised for a log.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.7.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Visit
 *
 * The facts a download log or an audit trail keeps about whoever made a
 * request, without keeping the person: two keyed hashes, for counting
 * and for spotting one key or link shared between many people, and a
 * summary a person can read — a country, a device, and the address with
 * its host part zeroed. Never the address or the agent string itself.
 *
 * Built in one place because every log that records a visitor otherwise
 * grows its own copy, and the copies drift: one hashes the raw agent and
 * another the trimmed one, so the same browser counts as two; one reads
 * the country header without asking who sent it, so a visitor picks
 * their own.
 *
 * ```php
 * $visit = Visit::capture(
 *     Visit::hmac( $secret ),                 // or your own keyed hash
 *     TrustedProxies::cloudflare(),           // whose headers to believe
 *     Visit::CLOUDFLARE_COUNTRY               // where the country is, if anywhere
 * );
 *
 * $db->insert( 'downloads', [ 'ip_hash' => $visit->ip_hash, 'ua_hash' => $visit->agent_hash ] + $visit->summary() );
 * ```
 *
 * @since 1.7.0
 */
final readonly class Visit {

	/**
	 * The header Cloudflare puts the visitor's country in.
	 *
	 * @since 1.7.0
	 */
	public const string CLOUDFLARE_COUNTRY = 'CF-IPCountry';

	/**
	 * Longest device summary kept, in characters.
	 *
	 * {@see UserAgent::describe()} is short for anything real; a version
	 * number padded to fill a column is cut here.
	 *
	 * @since 1.7.0
	 */
	private const int DEVICE_LENGTH = 80;

	/**
	 * Describe a visit. Normally built by {@see self::capture()}; public so
	 * a stored row can be read back into one.
	 *
	 * @since 1.7.0
	 *
	 * @param string      $ip_hash    The address through the keyed hash, or
	 *                                '' when there was no usable address.
	 * @param string      $agent_hash The user agent through the keyed hash,
	 *                                or '' when none was sent.
	 * @param string|null $country    ISO 3166-1 alpha-2, uppercase, or null
	 *                                when unknown or not believed.
	 * @param string|null $device     e.g. `Chrome 131 on macOS`, `Unknown`
	 *                                for an agent nothing recognised, or
	 *                                null when none was sent.
	 * @param string|null $network    The address with its host part zeroed
	 *                                (`203.0.113.0`, `2001:db8:85a3::`), or
	 *                                null when there was no usable address.
	 */
	public function __construct(
		public string $ip_hash,
		public string $agent_hash,
		public ?string $country = null,
		public ?string $device = null,
		public ?string $network = null,
	) {}

	/**
	 * Record the current request.
	 *
	 * The address is the one {@see ClientIp} resolves through $proxies, so a
	 * forwarding header counts only from a proxy you listed. The country is
	 * read from $country_header under the same rule — only when the
	 * connection came from one of $proxies — because otherwise it is a
	 * header anybody can set. List only proxies that overwrite it: with
	 * `TrustedProxies::cloudflare( [ $balancer ] )` the connection is from
	 * your balancer, which passes on whatever `CF-IPCountry` reached it.
	 *
	 * The agent is hashed as {@see UserAgent::current()} reads it — trimmed
	 * and capped — so one browser is one hash however its header was
	 * padded.
	 *
	 * @since 1.7.0
	 *
	 * @param \Closure(string): string $hash           Keyed, one-way: a value
	 *                                                 to its stored form. Never
	 *                                                 called with ''. See
	 *                                                 {@see self::hmac()}.
	 * @param TrustedProxies|null      $proxies        Proxies whose forwarding
	 *                                                 and country headers are
	 *                                                 believed. Null trusts
	 *                                                 none.
	 * @param string                   $country_header e.g. `CF-IPCountry`, or
	 *                                                 '' to record no country.
	 * @param array<mixed>|null        $server         `$_SERVER`-shaped array.
	 *                                                 Null uses the superglobal.
	 *
	 * @return self
	 */
	public static function capture( \Closure $hash, ?TrustedProxies $proxies = null, string $country_header = '', ?array $server = null ): self {
		$server ??= $_SERVER;

		$ip    = ( new ClientIp( $proxies ) )->resolve( $server ) ?? '';
		$agent = UserAgent::current( $server );

		return new self(
			ip_hash:    '' === $ip ? '' : $hash( $ip ),
			agent_hash: '' === $agent ? '' : $hash( $agent ),
			country:    self::country( $server, $proxies, $country_header ),
			device:     '' === $agent ? null : mb_substr( UserAgent::describe( $agent ), 0, self::DEVICE_LENGTH ),
			network:    Ip::anonymize( $ip ) ?: null,
		);
	}

	/**
	 * A keyed hash for {@see self::capture()}: HMAC-SHA256 under $secret.
	 *
	 * Keyed because an address is not a secret worth hashing otherwise —
	 * all four billion IPv4 addresses go through an unkeyed hash in
	 * seconds. Keep the secret out of the database; rotating it makes
	 * every stored hash a stranger.
	 *
	 * @since 1.7.0
	 *
	 * @param string $secret Application secret.
	 *
	 * @return \Closure(string): string 64 hex characters for each value.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function hmac( #[\SensitiveParameter] string $secret ): \Closure {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'Hashing a visitor without a secret is reversible by brute force.' );
		}

		return static fn( string $value ): string => hash_hmac( 'sha256', $value, $secret );
	}

	/**
	 * What a log may show a person: country, device and network.
	 *
	 * Column-shaped, so it can be merged into a row. Render `network` with
	 * {@see Ip::anonymized_cidr()} so a reader does not take it for an
	 * address.
	 *
	 * @since 1.7.0
	 *
	 * @return array{country: string|null, device: string|null, network: string|null}
	 */
	public function summary(): array {
		return array(
			'country' => $this->country,
			'device'  => $this->device,
			'network' => $this->network,
		);
	}

	/**
	 * Everything, hashes first.
	 *
	 * @since 1.7.0
	 *
	 * @return array{ip_hash: string, agent_hash: string, country: string|null, device: string|null, network: string|null}
	 */
	public function to_array(): array {
		return array(
			'ip_hash'    => $this->ip_hash,
			'agent_hash' => $this->agent_hash,
		) + $this->summary();
	}

	/**
	 * The country header, when the connection is one we believe it from.
	 *
	 * Two letters, uppercased. `XX` — "unknown" to Cloudflare and never a
	 * country to ISO — is no country; so is `T1`, Cloudflare's Tor marker,
	 * which is not two letters.
	 *
	 * @since 1.7.0
	 *
	 * @param array<mixed>        $server  `$_SERVER`-shaped array.
	 * @param TrustedProxies|null $proxies Proxies the header is believed from.
	 * @param string              $header  Header name, or ''.
	 *
	 * @return string|null
	 */
	private static function country( array $server, ?TrustedProxies $proxies, string $header ): ?string {
		$remote = $server['REMOTE_ADDR'] ?? '';

		if ( '' === $header || null === $proxies || ! is_string( $remote ) || ! $proxies->trusts( $remote ) ) {
			return null;
		}

		$value = $server[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] ?? '';
		$value = is_string( $value ) ? strtoupper( trim( $value ) ) : '';

		return 1 === preg_match( '/^[A-Z]{2}$/D', $value ) && 'XX' !== $value ? $value : null;
	}
}
