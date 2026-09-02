<?php
/**
 * The values the report's three filters can take.
 *
 * @package SalesByStateReportForStoreEngine
 */

namespace SBSSE;

use StoreEngine\Classes\Countries;
use StoreEngine\Classes\OrderStatus\OrderStatus;
use StoreEngine\Utils\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the filter options and validates what comes back from the browser.
 */
class Filters {

	/**
	 * Option holding the first year offered by the year filter.
	 */
	const YEAR_START_OPTION = 'sbsse_year_start';

	/**
	 * Number of years offered when the list is first created.
	 */
	const YEAR_WINDOW = 10;

	/**
	 * The columns shown in the report table and summary.
	 *
	 * @return array<string,array{label:string,type:string}>
	 */
	public static function measures() {
		return array(
			'net_revenue'   => array(
				'label' => __( 'Net Sales', 'sales-by-state-report-for-storeengine' ),
				'type'  => 'currency',
			),
			'gross_revenue' => array(
				'label' => __( 'Gross Sales', 'sales-by-state-report-for-storeengine' ),
				'type'  => 'currency',
			),
		);
	}

	/**
	 * Measure keys.
	 *
	 * @return string[]
	 */
	public static function measure_keys() {
		return array_keys( self::measures() );
	}

	/**
	 * Order statuses the filter offers.
	 *
	 * Drafts and trash are omitted. Keys are StoreEngine status slugs.
	 *
	 * @return array<string,string>
	 */
	public static function order_statuses() {
		$hidden  = array( 'auto-draft', 'draft', 'trash' );
		$offered = array();

		if ( class_exists( OrderStatus::class ) ) {
			$statuses = OrderStatus::get_order_statuses();
		} else {
			$statuses = array(
				'pending_payment'   => __( 'Pending payment', 'sales-by-state-report-for-storeengine' ),
				'payment_confirmed' => __( 'Payment confirmed', 'sales-by-state-report-for-storeengine' ),
				'processing'        => __( 'Processing', 'sales-by-state-report-for-storeengine' ),
				'on_hold'           => __( 'On hold', 'sales-by-state-report-for-storeengine' ),
				'completed'         => __( 'Completed', 'sales-by-state-report-for-storeengine' ),
				'cancelled'         => __( 'Cancelled', 'sales-by-state-report-for-storeengine' ),
				'refunded'          => __( 'Refunded', 'sales-by-state-report-for-storeengine' ),
				'payment_failed'    => __( 'Failed', 'sales-by-state-report-for-storeengine' ),
			);
		}

		foreach ( (array) $statuses as $key => $label ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || in_array( $key, $hidden, true ) ) {
				continue;
			}

			$offered[ $key ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		}

		return $offered;
	}

	/**
	 * The statuses ticked when the report is opened with no explicit filter.
	 *
	 * Defaults to StoreEngine paid statuses (processing and completed).
	 *
	 * @return string[]
	 */
	public static function default_statuses() {
		$defaults = array( 'processing', 'completed' );

		if ( class_exists( OrderStatus::class ) ) {
			$defaults = array_map( 'sanitize_key', OrderStatus::get_is_paid_statuses() );
		}

		/**
		 * Filters the order statuses the report starts on.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $statuses Status keys.
		 */
		$statuses = (array) apply_filters( 'sbsse_default_statuses', $defaults );

		return array_values( array_intersect( array_map( 'strval', $statuses ), self::status_keys() ) );
	}

	/**
	 * Status slugs as strings.
	 *
	 * @return string[]
	 */
	public static function status_keys() {
		return array_map( 'strval', array_keys( self::order_statuses() ) );
	}

	/**
	 * Reduce a request value to statuses this report recognises.
	 *
	 * @param string|array $value Comma-separated list or array of statuses.
	 * @return string[]
	 */
	public static function normalize_statuses( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$offered  = self::status_keys();
		$accepted = array();

		foreach ( $value as $status ) {
			$status = sanitize_key( trim( (string) $status ) );

			if ( in_array( $status, $offered, true ) ) {
				$accepted[] = $status;
			}
		}

		return array_values( array_unique( $accepted ) );
	}

	/**
	 * Years the filter offers, newest first.
	 *
	 * @return int[]
	 */
	public static function years() {
		$current  = self::default_year();
		$earliest = (int) get_option( self::YEAR_START_OPTION, 0 );

		if ( $earliest <= 0 ) {
			$earliest = $current - ( self::YEAR_WINDOW - 1 );
			update_option( self::YEAR_START_OPTION, $earliest, false );
		}

		if ( $earliest > $current ) {
			$earliest = $current;
		}

		return array_map( 'intval', range( $current, $earliest ) );
	}

	/**
	 * The year the report opens on.
	 *
	 * @return int
	 */
	public static function default_year() {
		return (int) current_time( 'Y' );
	}

	/**
	 * Reduce a request value to a year the filter offers.
	 *
	 * @param mixed $year Requested year.
	 * @return int
	 */
	public static function normalize_year( $year ) {
		$year = (int) $year;

		return in_array( $year, self::years(), true ) ? $year : self::default_year();
	}

	/**
	 * The country the report opens on.
	 *
	 * @return string Two-letter country code.
	 */
	public static function default_country() {
		$code = 'US';

		if ( class_exists( Helper::class ) ) {
			$base = strtoupper( (string) Helper::get_settings( 'store_country', 'US' ) );

			if ( preg_match( '/^[A-Z]{2}$/', $base ) ) {
				$code = $base;
			}
		}

		if ( self::states_for( $code ) ) {
			return $code;
		}

		foreach ( array_keys( self::countries_with_states() ) as $candidate ) {
			return $candidate;
		}

		return $code;
	}

	/**
	 * Reduce a request value to a two-letter country code.
	 *
	 * @param mixed $country Requested country.
	 * @return string
	 */
	public static function normalize_country( $country ) {
		$country = strtoupper( (string) $country );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : self::default_country();
	}

	/**
	 * Countries the country dropdown always offers.
	 *
	 * @return array<string,string>
	 */
	public static function countries_with_states() {
		return array(
			'US' => __( 'United States', 'sales-by-state-report-for-storeengine' ),
			'CA' => __( 'Canada', 'sales-by-state-report-for-storeengine' ),
			'GB' => __( 'United Kingdom', 'sales-by-state-report-for-storeengine' ),
		);
	}

	/**
	 * State code => name for a country.
	 *
	 * US and Canada come from StoreEngine's locale list. StoreEngine does not
	 * ship UK counties, so those labels live in Regions.
	 *
	 * @param string $country Country code.
	 * @return array<string,string>
	 */
	public static function states_for( $country ) {
		$country = strtoupper( (string) $country );

		if ( class_exists( Countries::class ) && 'GB' !== $country ) {
			$states = Countries::init()->get_states( $country );

			if ( is_array( $states ) && $states ) {
				$out     = array();
				$skipped = array( 'AA', 'AE', 'AP' );

				foreach ( $states as $code => $label ) {
					$code = (string) $code;

					if ( '' === $code || in_array( $code, $skipped, true ) ) {
						continue;
					}

					$out[ $code ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
				}

				if ( $out ) {
					return $out;
				}
			}
		}

		return Regions::states_for( $country );
	}
}
