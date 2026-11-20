<?php
/**
 * The sources and channels as options for a condition, and matching one.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

use SugarCommerce\Network\Referrer\Sources;

/**
 * The sources and channels as options for a condition, and matching one.
 *
 * @since 1.1.0
 */
trait Options {

	/**
	 * Get search engine options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of search engine options.
	 */
	public static function get_search_engine_options( bool $as_value_label = false, ?string $context = null ): array {
		// Read off the table the classifier uses, so an engine added there is
		// selectable here without anyone remembering to list it twice.
		$options = self::names_in( Sources::SEARCH_ENGINES );

		/**
		 * Filters the search engine options.
		 *
		 * @param array       $options The search engine options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_search_engine_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get social platform options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of social platform options.
	 */
	public static function get_social_platform_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::SOCIAL_NETWORKS );

		/**
		 * Filters the social platform options.
		 *
		 * @param array       $options The social platform options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_social_platform_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get AI assistant options.
	 *
	 * @since 1.1.0
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of AI assistant options.
	 */
	public static function get_ai_assistant_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::AI_ASSISTANTS );

		/**
		 * Filters the AI assistant options.
		 *
		 * @since 1.1.0
		 *
		 * @param array       $options The AI assistant options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_ai_assistant_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get webmail provider options.
	 *
	 * @since 1.1.0
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of webmail provider options.
	 */
	public static function get_email_provider_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = self::names_in( Sources::WEBMAIL );

		/**
		 * Filters the webmail provider options.
		 *
		 * @since 1.1.0
		 *
		 * @param array       $options The webmail provider options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_email_provider_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get traffic source options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of traffic source options.
	 */
	public static function get_traffic_source_options( bool $as_value_label = false, ?string $context = null ): array {
		// One for each of CHANNELS, and nothing get_traffic_source() cannot
		// return: an option that never matches is a condition that silently
		// never fires.
		$options = [
			'search'   => __( 'Search Engine', 'sugarcommerce' ),
			'social'   => __( 'Social Media', 'sugarcommerce' ),
			'email'    => __( 'Email', 'sugarcommerce' ),
			'ai'       => __( 'AI Assistant', 'sugarcommerce' ),
			'paid'     => __( 'Paid Advertising', 'sugarcommerce' ),
			'campaign' => __( 'Campaign', 'sugarcommerce' ),
			'referral' => __( 'Referral', 'sugarcommerce' ),
			'direct'   => __( 'Direct', 'sugarcommerce' ),
		];

		/**
		 * Filters the traffic source options.
		 *
		 * @param array       $options The traffic source options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_traffic_source_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Get UTM parameter type options.
	 *
	 * @param bool        $as_value_label Optional. Return as value/label format. Default false.
	 * @param string|null $context        Optional. The context in which the options are being used.
	 *
	 * @return array Array of UTM parameter options.
	 */
	public static function get_utm_parameter_options( bool $as_value_label = false, ?string $context = null ): array {
		$options = [
			'source'   => __( 'Source (utm_source)', 'sugarcommerce' ),
			'medium'   => __( 'Medium (utm_medium)', 'sugarcommerce' ),
			'campaign' => __( 'Campaign (utm_campaign)', 'sugarcommerce' ),
			'term'     => __( 'Term (utm_term)', 'sugarcommerce' ),
			'content'  => __( 'Content (utm_content)', 'sugarcommerce' ),
		];

		/**
		 * Filters the UTM parameter options.
		 *
		 * @param array       $options The UTM parameter options.
		 * @param string|null $context The context in which the options are being used.
		 */
		$options = apply_filters( 'sugarcommerce_referrer_utm_parameter_options', $options, $context );

		return $as_value_label ? self::to_value_label( $options ) : $options;
	}

	/**
	 * Check if referrer matches specific criteria.
	 *
	 * @param string|array $criteria Search engine, social platform, AI assistant, webmail provider or traffic
	 *                               source to match.
	 * @param string|null  $referrer Optional referrer URL.
	 *
	 * @return bool True if referrer matches criteria.
	 */
	public static function is_match( $criteria, ?string $referrer = null ): bool {
		if ( is_string( $criteria ) ) {
			$criteria = [ $criteria ];
		}

		if ( ! is_array( $criteria ) ) {
			return false;
		}

		$referrer = $referrer ?? self::get();

		$matches = [
			self::get_search_engine( $referrer ),
			self::get_social_platform( $referrer ),
			self::get_ai_assistant( $referrer ),
			self::get_email_provider( $referrer ),
			self::get_traffic_source( $referrer ),
		];

		foreach ( $criteria as $criterion ) {
			$criterion = strtolower( trim( $criterion ) );
			$criterion = self::RENAMED_SLUGS[ $criterion ] ?? $criterion;

			if ( in_array( $criterion, $matches, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert key/value array to value/label options format.
	 *
	 * @param array $options The options array in key => value format.
	 *
	 * @return array Options in value/label format.
	 */
	protected static function to_value_label( array $options ): array {
		$formatted = [];
		foreach ( $options as $key => $label ) {
			$formatted[] = [
				'value' => $key,
				'label' => $label,
			];
		}

		return $formatted;
	}

	/**
	 * The sources a table names, as identifier => name.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, array<int, string>> $table One of the Sources tables.
	 *
	 * @return array<string, string>
	 */
	protected static function names_in( array $table ): array {
		$names = [];

		foreach ( $table as $entry ) {
			$names[ self::slug( $entry[1] ) ] = $entry[1];
		}

		return $names;
	}
}
