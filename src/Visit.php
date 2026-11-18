<?php
/**
 * Visit
 *
 * Who made a request, kept for a log without keeping the person.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Country\Sources\Header;

/**
 * Visit Class
 *
 * The facts a download log or an audit trail keeps about whoever made a
 * request: two keyed hashes, for counting and for spotting one key or
 * link shared between many people, and a summary a person can read -- a
 * country, a device, and the network the address belongs to. Never the
 * address or the agent string itself.
 *
 * Built in one place because every log that records a visitor otherwise
 * grows its own copy, and the copies drift: one hashes the raw agent and
 * another the trimmed one, so the same browser counts as two; one reads
 * the country header without asking who sent it, so a visitor picks their
 * own.
 *
 *     $visit = Visit::capture(
 *         Visit::hmac( wp_salt( 'auth' ) ),   // or a keyed hash of your own
 *         TrustedProxies::cloudflare(),       // whose headers to believe
 *         Visit::CLOUDFLARE_COUNTRY           // where the country is, if anywhere
 *     );
 *
 *     $wpdb->insert( $table, [ 'ip_hash' => $visit->ip_hash, 'ua_hash' => $visit->agent_hash ] + $visit->summary() );
 *
 * @since 1.0.0
 */
final class Visit {

	/**
	 * The header Cloudflare puts the visitor's country in.
	 */
	public const CLOUDFLARE_COUNTRY = 'CF-IPCountry';

	/**
	 * Longest device summary kept, in characters.
	 *
	 * {@see UserAgent::describe()} is short for anything real; a version
	 * number padded to fill a column is cut here.
	 */
	public const DEVICE_LENGTH = 80;

	/**
	 * Describe a visit. Normally made by {@see self::capture()}; public so
	 * a stored row can be read back into one: `new Visit( ...$row )`.
	 *
	 * @param string      $ip_hash    The address through the keyed hash, or
	 *                                '' when there was no usable address.
	 * @param string      $agent_hash The user agent through the keyed hash,
	 *                                or '' when none was sent.
	 * @param string|null $country    ISO 3166-1 alpha-2, upper case, or null
	 *                                when unknown or not believed.
	 * @param string|null $device     e.g. `Chrome 131 on macOS`, `Unknown`
	 *                                for an agent nothing recognised, or null
	 *                                when none was sent.
	 * @param string|null $network    The network the address belongs to, as
	 *                                {@see IP::anonymized_cidr()} writes it
	 *                                (`203.0.113.0/24`), or null when there
	 *                                was no usable address.
	 */
	public function __construct(
		public readonly string $ip_hash,
		public readonly string $agent_hash,
		public readonly ?string $country = null,
		public readonly ?string $device = null,
		public readonly ?string $network = null,
	) {}

	/**
	 * Record a request.
	 *
	 * The address is the one {@see ClientIp::resolve()} finds through
	 * $proxies, so a forwarding header counts only from a proxy you listed,
	 * and it is put in its {@see IP::canonical()} form before it is hashed,
	 * so one IPv6 address is one hash however it was spelt.
	 *
	 * The country is read from $country_header under the same rule -- only
	 * when the connection came from one of $proxies -- because otherwise it
	 * is a header anybody can set. List only proxies that overwrite it: with
	 * Cloudflare's ranges and a balancer of your own, the connection is your
	 * balancer's, which passes on whatever `CF-IPCountry` reached it.
	 *
	 * The agent is hashed as {@see UserAgent::current()} reads it -- trimmed
	 * and capped -- so one browser is one hash however its header was padded.
	 *
	 * @param \Closure            $hash           Keyed and one-way: a value to
	 *                                            its stored form. Never called
	 *                                            with ''. See {@see self::hmac()}.
	 * @param TrustedProxies|null $proxies        Proxies whose forwarding and
	 *                                            country headers are believed.
	 *                                            Null trusts none.
	 * @param string              $country_header e.g. `CF-IPCountry`, or '' to
	 *                                            record no country.
	 * @param array|null          $server         `$_SERVER`, or a stand-in for
	 *                                            it. Null reads the live request.
	 *
	 * @return self
	 */
	public static function capture( \Closure $hash, ?TrustedProxies $proxies = null, string $country_header = '', ?array $server = null ): self {
		$server = Server::get( $server );
		$ip     = IP::canonical( (string) ClientIp::resolve( $server, $proxies ) ) ?? '';
		$agent  = UserAgent::current( $server );

		return new self(
			'' === $ip ? '' : $hash( $ip ),
			'' === $agent ? '' : $hash( $agent ),
			self::country( $server, $proxies, $country_header ),
			'' === $agent ? null : mb_substr( UserAgent::describe( $agent ), 0, self::DEVICE_LENGTH ),
			'' === $ip ? null : IP::anonymized_cidr( $ip )
		);
	}

	/**
	 * A keyed hash for {@see self::capture()}: HMAC-SHA256 under a secret.
	 *
	 * Keyed because an address is not a secret worth hashing otherwise --
	 * all four billion IPv4 addresses go through an unkeyed hash in seconds.
	 * Keep the secret out of the database; changing it makes every stored
	 * hash a stranger.
	 *
	 * @param string $secret A secret of the site's: wp_salt( 'auth' ), say.
	 *
	 * @return \Closure 64 hex characters for each value.
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
	 * Column-shaped, so it can be merged into a row.
	 *
	 * @return array{country: string|null, device: string|null, network: string|null}
	 */
	public function summary(): array {
		return [
			'country' => $this->country,
			'device'  => $this->device,
			'network' => $this->network,
		];
	}

	/**
	 * Everything, hashes first.
	 *
	 * @return array{ip_hash: string, agent_hash: string, country: string|null, device: string|null, network: string|null}
	 */
	public function to_array(): array {
		return [
			'ip_hash'    => $this->ip_hash,
			'agent_hash' => $this->agent_hash,
		] + $this->summary();
	}

	/**
	 * The country header, when the connection is one it is believed from.
	 *
	 * @param array               $server  `$_SERVER`, or a stand-in.
	 * @param TrustedProxies|null $proxies The connections believed.
	 * @param string              $header  The header's name, or ''.
	 *
	 * @return string|null
	 */
	private static function country( array $server, ?TrustedProxies $proxies, string $header ): ?string {
		if ( '' === TrustedProxies::header_name( $header ) || null === $proxies ) {
			return null;
		}

		return ( new Header( $header, 'visit', 'high', $proxies ) )->resolve( $server );
	}
}
