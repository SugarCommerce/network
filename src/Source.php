<?php
/**
 * Where a visitor came from.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class Source
 *
 * The result of {@see Referrer::analyse()}.
 *
 * @since 1.0.0
 */
final readonly class Source {

	/**
	 * Channels that represent a deliberately tagged campaign.
	 *
	 * @since 1.0.0
	 */
	private const array CAMPAIGN_CHANNELS = array( 'campaign', 'paid' );

	/**
	 * Describe a source. Normally built by {@see Referrer::analyse()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel  `search`, `social`, `email`, `paid`,
	 *                         `campaign`, `referral`, or `direct`.
	 * @param string $name     Human-readable source — `Google`,
	 *                         `Twitter`, a campaign name, a domain.
	 * @param string $term     Search term or `utm_source`, when known.
	 * @param string $referrer Raw referrer URL, when there was one.
	 * @param string $medium   `utm_medium`, when supplied.
	 */
	public function __construct(
		public string $channel,
		public string $name,
		public string $term = '',
		public string $referrer = '',
		public string $medium = '',
	) {}

	/**
	 * Whether the visitor arrived without any attribution.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_direct(): bool {
		return 'direct' === $this->channel;
	}

	/**
	 * Whether this came from a tagged campaign.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_campaign(): bool {
		return in_array( $this->channel, self::CAMPAIGN_CHANNELS, true );
	}

	/**
	 * A short sentence for an admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string e.g. `Google search`, `campaign "spring-2026"`.
	 */
	public function label(): string {
		return match ( $this->channel ) {
			'direct'   => 'Direct',
			'search'   => '' !== $this->term
				? $this->name . ' search for "' . $this->term . '"'
				: $this->name . ' search',
			'social'   => $this->name,
			'email'    => 'Email',
			'paid'     => 'paid campaign "' . $this->name . '"',
			'campaign' => 'campaign "' . $this->name . '"',
			default    => $this->name,
		};
	}

	/**
	 * The shape to persist against an order or a visit.
	 *
	 * @since 1.0.0
	 *
	 * @return array{channel:string, name:string, term:string, medium:string, referrer:string}
	 */
	public function to_array(): array {
		return array(
			'channel'  => $this->channel,
			'name'     => $this->name,
			'term'     => $this->term,
			'medium'   => $this->medium,
			'referrer' => $this->referrer,
		);
	}
}
