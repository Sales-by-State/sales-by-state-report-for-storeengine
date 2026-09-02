<?php
/**
 * The report page and its assets.
 *
 * @package SalesByStateReportForStoreEngine
 */

namespace SBSSE\Admin;

use SBSSE\Filters;
use SBSSE\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the report under StoreEngine.
 *
 * The submenu uses our own render callback. Adding a slug to StoreEngine's
 * admin menu list would route the page into StoreEngine's React SPA.
 */
class Page {

	/**
	 * Menu slug.
	 */
	const SLUG = 'sbsse-sales-by-state';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_head', array( $this, 'print_css' ) );
	}

	/**
	 * Add the report under StoreEngine.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'storeengine',
			__( 'Sales by State', 'sales-by-state-report-for-storeengine' ),
			__( 'Sales by State', 'sales-by-state-report-for-storeengine' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the root element for the standalone page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Plugin::can_view() ) {
			return;
		}

		$this->register_assets();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'sbsse-report' );
		wp_enqueue_script( 'sbsse-report' );

		printf(
			'<div class="wrap sbsse-wrap">
				<div class="sbsse-page-header"><h1 class="sbsse-page-header__title">%s</h1></div>
				<div id="sbsse-root"></div>
			</div>',
			esc_html__( 'Sales by State', 'sales-by-state-report-for-storeengine' )
		);

		wp_print_scripts( array( 'sbsse-report' ) );
	}

	/**
	 * Register script and style handles.
	 *
	 * @return void
	 */
	private function register_assets() {
		$script = SBSSE_DIR . 'assets/js/report.js';
		$style  = SBSSE_DIR . 'assets/css/report.css';

		wp_register_script(
			'sbsse-report',
			SBSSE_URL . 'assets/js/report.js',
			array(
				'wp-hooks',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
				'wp-url',
				'wp-components',
			),
			file_exists( $script ) ? (string) filemtime( $script ) : SBSSE_VERSION,
			true
		);

		wp_set_script_translations( 'sbsse-report', 'sales-by-state-report-for-storeengine', SBSSE_DIR . 'languages' );
		wp_localize_script( 'sbsse-report', 'sbsseConfig', $this->config() );

		wp_register_style(
			'sbsse-report',
			SBSSE_URL . 'assets/css/report.css',
			array( 'wp-components' ),
			file_exists( $style ) ? (string) filemtime( $style ) : SBSSE_VERSION
		);
	}

	/**
	 * Enqueue the report bundle on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! $this->is_screen( $hook ) ) {
			return;
		}

		$this->register_assets();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'sbsse-report' );
		wp_enqueue_script( 'sbsse-report' );
	}

	/**
	 * Print styles in the head if the enqueue hook did not run.
	 *
	 * @return void
	 */
	public function print_css() {
		if ( ! $this->is_screen() ) {
			return;
		}

		$this->register_assets();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'sbsse-report' );
		wp_print_styles( array( 'wp-components', 'sbsse-report' ) );
	}

	/**
	 * Whether this screen is showing.
	 *
	 * @param string $hook Optional enqueue hook.
	 * @return bool
	 */
	private function is_screen( $hook = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the current screen, not acting on it.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::SLUG === $page ) {
			return true;
		}

		return is_string( $hook ) && false !== strpos( $hook, self::SLUG );
	}

	/**
	 * Data the bundle needs to draw its controls.
	 *
	 * @return array
	 */
	private function config() {
		$measures = array();

		foreach ( Filters::measures() as $key => $measure ) {
			$measures[] = array(
				'key'   => $key,
				'label' => $measure['label'],
				'type'  => $measure['type'],
			);
		}

		$statuses = array();

		foreach ( Filters::order_statuses() as $key => $label ) {
			$statuses[] = array(
				'value' => (string) $key,
				'label' => $label,
			);
		}

		$years = array();

		foreach ( Filters::years() as $year ) {
			$years[] = array(
				'value' => (string) $year,
				'label' => (string) $year,
			);
		}

		$countries = array();

		foreach ( Filters::countries_with_states() as $code => $label ) {
			$countries[] = array(
				'value' => $code,
				'label' => $label,
			);
		}

		return array(
			'measures'        => $measures,
			'statuses'        => $statuses,
			'years'           => $years,
			'countries'       => $countries,
			'defaultCountry'  => Filters::default_country(),
			'defaultYear'     => (string) Filters::default_year(),
			'defaultStatuses' => Filters::default_statuses(),
			'perPageOptions'  => array( 10, 25, 50, 100 ),
			'title'           => __( 'Sales by State', 'sales-by-state-report-for-storeengine' ),
			'canBuild'        => Plugin::can_manage(),
			'mode'            => 'standalone',
		);
	}
}
