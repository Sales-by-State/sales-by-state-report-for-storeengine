<?php
/**
 * Removes the plugin's data when it is deleted.
 *
 * @package SalesByStateReportForStoreEngine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$sbsse_options = array(
	'sbsse_db_version',
	'sbsse_backfill_cursor',
	'sbsse_year_start',
);

foreach ( $sbsse_options as $sbsse_option ) {
	delete_option( $sbsse_option );
}

if ( is_multisite() ) {
	foreach ( $sbsse_options as $sbsse_option ) {
		delete_site_option( $sbsse_option );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sbsse_order_state" );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'sbsse_backfill_batch', array(), 'sales-by-state-report-for-storeengine' );
}
