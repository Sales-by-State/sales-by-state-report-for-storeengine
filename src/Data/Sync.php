<?php
/**
 * Keeps the report table in step with StoreEngine orders.
 *
 * @package SalesByStateReportForStoreEngine
 */

namespace SBSSE\Data;

use SBSSE\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one row per order.
 */
class Sync {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'storeengine/order/after/object_save', array( $this, 'on_save' ), 20, 2 );
		add_action( 'storeengine/order/updated', array( $this, 'on_updated' ), 20, 2 );
		add_action( 'storeengine/order/status_changed', array( $this, 'on_status' ), 20, 4 );
		add_action( 'storeengine/order/deleted', array( $this, 'on_delete' ), 20, 1 );
	}

	/**
	 * Handle an order object after it is saved.
	 *
	 * @param mixed $order  Order object.
	 * @param mixed $create Whether the row was created.
	 * @return void
	 */
	public function on_save( $order, $create = false ) {
		unset( $create );
		$this->upsert( $this->id_from( $order ) );
	}

	/**
	 * Handle an order ID after an update.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order object.
	 * @return void
	 */
	public function on_updated( $order_id, $order = null ) {
		unset( $order );
		$this->upsert( (int) $order_id );
	}

	/**
	 * Handle a status change.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param mixed  $order      Order object.
	 * @return void
	 */
	public function on_status( $order_id, $old_status = '', $new_status = '', $order = null ) {
		unset( $old_status, $new_status, $order );
		$this->upsert( (int) $order_id );
	}

	/**
	 * Remove the row when an order is deleted.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_delete( $order_id ) {
		$this->delete( (int) $order_id );
	}

	/**
	 * Insert or update the row for one order.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public function upsert( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;

		if ( ! $order_id ) {
			return false;
		}

		$row = self::build_row( $order_id );

		if ( ! $row ) {
			$this->delete( $order_id );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->replace(
			Schema::table(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f' )
		);
	}

	/**
	 * Remove the row for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function delete( $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
	}

	/**
	 * Build the row for an order from StoreEngine tables.
	 *
	 * StoreEngine stores money as decimals. The report groups by shipping
	 * region, falling back to billing when the order has no ship-to address.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>|false
	 */
	public static function build_row( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.id, o.status, o.currency, o.tax_amount, o.total_amount, o.date_created_gmt,
				        p.date_paid_gmt, p.shipping_total_amount,
				        billing.country AS billing_country,
				        billing.state AS billing_state,
				        shipping.country AS shipping_country,
				        shipping.state AS shipping_state
				 FROM {$wpdb->prefix}storeengine_orders o
				 LEFT JOIN {$wpdb->prefix}storeengine_order_operational_data p
				        ON p.order_id = o.id
				 LEFT JOIN {$wpdb->prefix}storeengine_order_addresses billing
				        ON billing.order_id = o.id AND billing.address_type = %s
				 LEFT JOIN {$wpdb->prefix}storeengine_order_addresses shipping
				        ON shipping.order_id = o.id AND shipping.address_type = %s
				 WHERE o.id = %d
				   AND o.type = %s",
				'billing',
				'shipping',
				$order_id,
				'order'
			)
		);

		if ( ! $order ) {
			return false;
		}

		$billing_country  = strtoupper( substr( (string) $order->billing_country, 0, 2 ) );
		$billing_state    = (string) $order->billing_state;
		$shipping_country = strtoupper( substr( (string) $order->shipping_country, 0, 2 ) );
		$shipping_state   = (string) $order->shipping_state;

		if ( '' === $shipping_country ) {
			$shipping_country = $billing_country;
			$shipping_state   = $billing_state;
		}

		$total    = round( (float) $order->total_amount, 2 );
		$tax      = round( (float) $order->tax_amount, 2 );
		$shipping = round( (float) $order->shipping_total_amount, 2 );
		$created  = self::normalize_datetime( $order->date_created_gmt );
		$paid     = self::normalize_datetime( $order->date_paid_gmt );

		$currency = strtoupper( substr( (string) $order->currency, 0, 3 ) );

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = 'USD';
		}

		return array(
			'order_id'         => (int) $order->id,
			'status'           => substr( sanitize_key( (string) $order->status ), 0, 32 ),
			'date_created'     => $created ? $created : '0000-00-00 00:00:00',
			'date_paid'        => $paid,
			'billing_country'  => $billing_country,
			'billing_state'    => substr( $billing_state, 0, 50 ),
			'shipping_country' => $shipping_country,
			'shipping_state'   => substr( $shipping_state, 0, 50 ),
			'currency'         => $currency,
			'total_sales'      => $total,
			'tax_total'        => $tax,
			'shipping_total'   => $shipping,
			'net_total'        => $total - $tax - $shipping,
		);
	}

	/**
	 * Pull an order ID from a StoreEngine object or scalar.
	 *
	 * @param mixed $order Order object or ID.
	 * @return int
	 */
	private function id_from( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			return (int) $order->get_id();
		}

		if ( is_object( $order ) && isset( $order->id ) ) {
			return (int) $order->id;
		}

		return (int) $order;
	}

	/**
	 * Normalise a datetime string.
	 *
	 * @param mixed $value Datetime.
	 * @return string|null
	 */
	private static function normalize_datetime( $value ) {
		$value = (string) $value;

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
