<?php
/**
 * The plugin: builds every component and wires its hooks.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Tassyir\WooCommerce\Admin\Cod_Settings_Page;
use Tassyir\WooCommerce\Admin\Notices;
use Tassyir\WooCommerce\Admin\Onboarding;
use Tassyir\WooCommerce\Admin\Order_Admin;
use Tassyir\WooCommerce\Admin\Privacy;
use Tassyir\WooCommerce\Admin\Settings_Page;
use Tassyir\WooCommerce\Cod\Placement;
use Tassyir\WooCommerce\Cod\Receipt;
use Tassyir\WooCommerce\Cod\Rest;
use Tassyir\WooCommerce\Cod\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point. `Plugin::boot()` runs on `plugins_loaded`.
 */
final class Plugin {

	const DEFAULT_API_URL = 'https://api.tassyir.io';
	const DEFAULT_APP_URL = 'https://app.tassyir.io';

	/**
	 * Action Scheduler group of every job this plugin schedules.
	 */
	const GROUP = 'tassyir';

	/**
	 * The running instance (null until boot()).
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Stored connection (ids, secret, settings).
	 *
	 * @var Connection
	 */
	public $connection;

	/**
	 * The one HTTP door to Tassyir.
	 *
	 * @var Api_Client
	 */
	public $api;

	/**
	 * Order queue and push job.
	 *
	 * @var Order_Sync
	 */
	public $orders;

	/**
	 * Product queue, push job and image uploads.
	 *
	 * @var Product_Sync
	 */
	public $products;

	/**
	 * Heartbeat job and command handling.
	 *
	 * @var Heartbeat
	 */
	public $heartbeat;

	/**
	 * Builds the components (no hooks yet).
	 */
	public function __construct() {
		$this->connection = new Connection();
		$this->api        = new Api_Client( $this->connection );
		$this->orders     = new Order_Sync( $this->api, $this->connection );
		$this->products   = new Product_Sync( $this->api, $this->connection );
		$this->heartbeat  = new Heartbeat( $this->api, $this->connection, $this->orders, $this->products );
	}

	/**
	 * Runs on `plugins_loaded`: stops with a notice when WooCommerce is missing, else wires hooks.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		if ( ! self::woocommerce_ready() ) {
			add_action( 'admin_notices', array( Notices::class, 'woocommerce_missing' ) );
			return;
		}

		self::instance()->register_hooks();
	}

	/**
	 * The running instance, built on first use.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether a supported WooCommerce (≥ 8.0, with Action Scheduler) is active.
	 */
	public static function woocommerce_ready(): bool {
		return class_exists( 'WooCommerce' )
			&& defined( 'WC_VERSION' )
			&& version_compare( WC_VERSION, '8.0', '>=' )
			&& function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Loads translations from languages/ (self-hosted builds are not served by translate.wordpress.org).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'tassyir-for-woocommerce', false, dirname( plugin_basename( TASSYIR_WC_FILE ) ) . '/languages' );
	}

	/**
	 * Registers every hook of every component.
	 */
	private function register_hooks(): void {
		$this->orders->register_hooks();
		$this->products->register_hooks();
		$this->heartbeat->register_hooks();

		// Phase 2: the COD form (its REST routes and where it shows).
		( new Rest( $this->connection, new Storefront( $this->api, $this->connection ) ) )->register_hooks();
		( new Placement( $this->connection ) )->register_hooks();
		Receipt::register_hooks();

		if ( is_admin() ) {
			( new Settings_Page( $this->connection, $this->api, $this->orders, $this->products, $this->heartbeat ) )->register_hooks();
			( new Order_Admin( $this->orders, $this->connection ) )->register_hooks();
			( new Notices( $this->connection ) )->register_hooks();
			( new Onboarding( $this->connection ) )->register_hooks();
			Privacy::register_hooks();
			( new Cod_Settings_Page() )->register_hooks();
		}
	}

	/**
	 * Activation: if already connected (re-activation), the heartbeat starts again; if not, the
	 * next admin page opens the Tassyir page so the merchant can click Connect.
	 *
	 * @param bool $network_wide Network-wide activation (multisite).
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! self::woocommerce_ready() ) {
			return;
		}
		$connection = new Connection();
		if ( $connection->is_connected() ) {
			Heartbeat::schedule( $connection->heartbeat_seconds() );
			return;
		}
		Onboarding::queue_redirect( (bool) $network_wide, defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Deactivation: removes every scheduled job (nothing would run them). Orders are not lost:
	 * the first heartbeat after re-activation reports them and Tassyir asks for the missing ones.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Base URL of the Tassyir API, without trailing slash.
	 */
	public static function api_url(): string {
		$url = defined( 'TASSYIR_API_URL' ) ? (string) constant( 'TASSYIR_API_URL' ) : self::DEFAULT_API_URL;
		return untrailingslashit( $url );
	}

	/**
	 * Base URL of the Tassyir dashboard, without trailing slash.
	 */
	public static function app_url(): string {
		$url = defined( 'TASSYIR_APP_URL' ) ? (string) constant( 'TASSYIR_APP_URL' ) : self::DEFAULT_APP_URL;
		return untrailingslashit( $url );
	}

	/**
	 * Formats a Unix timestamp as ISO-8601 UTC, e.g. 2026-10-29T10:00:00Z.
	 *
	 * @param int $timestamp Unix seconds.
	 */
	public static function iso( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Versions and checkout facts sent with connect/exchange and every heartbeat.
	 *
	 * @return array{pluginVersion: string, wpVersion: string, wcVersion: string, phpVersion: string, checkout: string, hpos: bool}
	 */
	public static function environment(): array {
		return array(
			'pluginVersion' => TASSYIR_WC_VERSION,
			'wpVersion'     => (string) get_bloginfo( 'version' ),
			'wcVersion'     => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'phpVersion'    => PHP_VERSION,
			'checkout'      => self::checkout_type(),
			'hpos'          => class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled(),
		);
	}

	/**
	 * Which checkout the store's checkout page uses: classic shortcode, block, both, or unknown
	 * (no checkout page, or a page builder / third-party checkout).
	 */
	public static function checkout_type(): string {
		$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		$content = $page_id > 0 ? (string) get_post_field( 'post_content', $page_id ) : '';
		$blocks  = '' !== $content && has_block( 'woocommerce/checkout', $content );
		$classic = '' !== $content && has_shortcode( $content, 'woocommerce_checkout' );

		if ( $blocks && $classic ) {
			return 'both';
		}
		if ( $blocks ) {
			return 'blocks';
		}
		return $classic ? 'classic' : 'unknown';
	}
}
