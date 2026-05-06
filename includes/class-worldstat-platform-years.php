<?php
/**
 * Единый допустимый диапазон календарных лет для полей и селектов (админка и фронт).
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WorldStat_Platform_Years {

	public static function min(): int {
		return defined( 'WSP_CALENDAR_YEAR_MIN' ) ? (int) WSP_CALENDAR_YEAR_MIN : 1990;
	}

	public static function max(): int {
		return defined( 'WSP_CALENDAR_YEAR_MAX' ) ? (int) WSP_CALENDAR_YEAR_MAX : max( 2035, (int) gmdate( 'Y' ) + 5 );
	}

	/**
	 * Ограничить год для UI; при $year <= 0 возвращает без изменений.
	 */
	public static function clamp( int $year ): int {
		if ( $year <= 0 ) {
			return $year;
		}
		return max( self::min(), min( self::max(), $year ) );
	}
}
