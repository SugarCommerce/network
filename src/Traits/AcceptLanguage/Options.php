<?php
/**
 * Common languages as a list to choose from.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\AcceptLanguage;

/**
 * Common languages as a list to choose from.
 *
 * @since 1.0.0
 */
trait Options {

	/**
	 * Common languages and locales for a select.
	 *
	 * The same list as conditions' `Options\Request::get_languages()`;
	 * keep the two in step.
	 *
	 * @param bool $as_options `[ [ 'value' => 'en', 'label' => 'English' ], ... ]`
	 *                         rather than `[ 'en' => 'English' ]`.
	 *
	 * @return array
	 */
	public static function get_common_languages( bool $as_options = false ): array {
		$languages = [
			'en'    => __( 'English', 'sugarcommerce' ),
			'en-US' => __( 'English (US)', 'sugarcommerce' ),
			'en-GB' => __( 'English (UK)', 'sugarcommerce' ),
			'es'    => __( 'Spanish', 'sugarcommerce' ),
			'es-ES' => __( 'Spanish (Spain)', 'sugarcommerce' ),
			'es-MX' => __( 'Spanish (Mexico)', 'sugarcommerce' ),
			'fr'    => __( 'French', 'sugarcommerce' ),
			'fr-FR' => __( 'French (France)', 'sugarcommerce' ),
			'fr-CA' => __( 'French (Canada)', 'sugarcommerce' ),
			'de'    => __( 'German', 'sugarcommerce' ),
			'de-DE' => __( 'German (Germany)', 'sugarcommerce' ),
			'de-AT' => __( 'German (Austria)', 'sugarcommerce' ),
			'it'    => __( 'Italian', 'sugarcommerce' ),
			'pt'    => __( 'Portuguese', 'sugarcommerce' ),
			'pt-BR' => __( 'Portuguese (Brazil)', 'sugarcommerce' ),
			'pt-PT' => __( 'Portuguese (Portugal)', 'sugarcommerce' ),
			'nl'    => __( 'Dutch', 'sugarcommerce' ),
			'ru'    => __( 'Russian', 'sugarcommerce' ),
			'ja'    => __( 'Japanese', 'sugarcommerce' ),
			'zh'    => __( 'Chinese', 'sugarcommerce' ),
			'zh-CN' => __( 'Chinese (Simplified)', 'sugarcommerce' ),
			'zh-TW' => __( 'Chinese (Traditional)', 'sugarcommerce' ),
			'ko'    => __( 'Korean', 'sugarcommerce' ),
			'ar'    => __( 'Arabic', 'sugarcommerce' ),
			'hi'    => __( 'Hindi', 'sugarcommerce' ),
			'tr'    => __( 'Turkish', 'sugarcommerce' ),
			'pl'    => __( 'Polish', 'sugarcommerce' ),
			'sv'    => __( 'Swedish', 'sugarcommerce' ),
			'da'    => __( 'Danish', 'sugarcommerce' ),
			'no'    => __( 'Norwegian', 'sugarcommerce' ),
			'fi'    => __( 'Finnish', 'sugarcommerce' ),
			'el'    => __( 'Greek', 'sugarcommerce' ),
			'he'    => __( 'Hebrew', 'sugarcommerce' ),
			'th'    => __( 'Thai', 'sugarcommerce' ),
			'vi'    => __( 'Vietnamese', 'sugarcommerce' ),
			'id'    => __( 'Indonesian', 'sugarcommerce' ),
			'ms'    => __( 'Malay', 'sugarcommerce' ),
			'cs'    => __( 'Czech', 'sugarcommerce' ),
			'hu'    => __( 'Hungarian', 'sugarcommerce' ),
			'ro'    => __( 'Romanian', 'sugarcommerce' ),
			'uk'    => __( 'Ukrainian', 'sugarcommerce' ),
		];

		return $as_options ? self::to_options( $languages ) : $languages;
	}

	/**
	 * Common languages without regions, for a select.
	 *
	 * @param bool $as_options `value`/`label` pairs rather than a map.
	 *
	 * @return array
	 */
	public static function get_base_languages( bool $as_options = false ): array {
		$languages = [
			'en' => __( 'English', 'sugarcommerce' ),
			'es' => __( 'Spanish', 'sugarcommerce' ),
			'fr' => __( 'French', 'sugarcommerce' ),
			'de' => __( 'German', 'sugarcommerce' ),
			'it' => __( 'Italian', 'sugarcommerce' ),
			'pt' => __( 'Portuguese', 'sugarcommerce' ),
			'nl' => __( 'Dutch', 'sugarcommerce' ),
			'ru' => __( 'Russian', 'sugarcommerce' ),
			'ja' => __( 'Japanese', 'sugarcommerce' ),
			'zh' => __( 'Chinese', 'sugarcommerce' ),
			'ko' => __( 'Korean', 'sugarcommerce' ),
			'ar' => __( 'Arabic', 'sugarcommerce' ),
			'hi' => __( 'Hindi', 'sugarcommerce' ),
			'tr' => __( 'Turkish', 'sugarcommerce' ),
			'pl' => __( 'Polish', 'sugarcommerce' ),
			'sv' => __( 'Swedish', 'sugarcommerce' ),
			'da' => __( 'Danish', 'sugarcommerce' ),
			'no' => __( 'Norwegian', 'sugarcommerce' ),
			'fi' => __( 'Finnish', 'sugarcommerce' ),
			'el' => __( 'Greek', 'sugarcommerce' ),
			'he' => __( 'Hebrew', 'sugarcommerce' ),
			'th' => __( 'Thai', 'sugarcommerce' ),
			'vi' => __( 'Vietnamese', 'sugarcommerce' ),
			'id' => __( 'Indonesian', 'sugarcommerce' ),
			'ms' => __( 'Malay', 'sugarcommerce' ),
			'cs' => __( 'Czech', 'sugarcommerce' ),
			'hu' => __( 'Hungarian', 'sugarcommerce' ),
			'ro' => __( 'Romanian', 'sugarcommerce' ),
			'uk' => __( 'Ukrainian', 'sugarcommerce' ),
		];

		return $as_options ? self::to_options( $languages ) : $languages;
	}

	/**
	 * A map as `value`/`label` pairs.
	 *
	 * @param array<string, string> $items The map.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function to_options( array $items ): array {
		$options = [];

		foreach ( $items as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => $label,
			];
		}

		return $options;
	}
}
