<?php
/**
 * Settings page WooCommerce → Tassyir (plan §3.4) and its actions.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Api_Client;
use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Failure_Log;
use Tassyir\WooCommerce\Heartbeat;
use Tassyir\WooCommerce\Manual_Webhooks;
use Tassyir\WooCommerce\Order_Sync;
use Tassyir\WooCommerce\Plugin;
use Tassyir\WooCommerce\Product_Sync;
use Tassyir\WooCommerce\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Not connected: one Connect button. Connected: status, queue, buttons, failures log.
 * Every action is a POST to admin-post.php with a nonce and a manage_woocommerce check.
 */
final class Settings_Page {

	const SLUG       = 'tassyir';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Online = a successful heartbeat within this many seconds (same rule as the dashboard).
	 */
	const ONLINE_WINDOW = 45 * MINUTE_IN_SECONDS;

	/**
	 * Admin-post actions handled here (action => method).
	 */
	const ACTIONS = array(
		'tassyir_connect'         => 'handle_connect',
		'tassyir_disconnect'      => 'handle_disconnect',
		'tassyir_sync_products'   => 'handle_sync_products',
		'tassyir_backfill'        => 'handle_backfill',
		'tassyir_delete_webhooks' => 'handle_delete_webhooks',
		'tassyir_clear_log'       => 'handle_clear_log',
	);

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * HTTP client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Order sync.
	 *
	 * @var Order_Sync
	 */
	private $orders;

	/**
	 * Product sync.
	 *
	 * @var Product_Sync
	 */
	private $products;

	/**
	 * Heartbeat.
	 *
	 * @var Heartbeat
	 */
	private $heartbeat;

	/**
	 * Builds the page.
	 *
	 * @param Connection   $connection Stored connection.
	 * @param Api_Client   $api        HTTP client.
	 * @param Order_Sync   $orders     Order sync.
	 * @param Product_Sync $products   Product sync.
	 * @param Heartbeat    $heartbeat  Heartbeat.
	 */
	public function __construct( Connection $connection, Api_Client $api, Order_Sync $orders, Product_Sync $products, Heartbeat $heartbeat ) {
		$this->connection = $connection;
		$this->api        = $api;
		$this->orders     = $orders;
		$this->products   = $products;
		$this->heartbeat  = $heartbeat;
	}

	/**
	 * Menu entry, page-load handler and admin-post actions.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( TASSYIR_WC_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds WooCommerce → Tassyir.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page( 'woocommerce', 'Tassyir', 'Tassyir', self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * The page's stylesheet and the confirm() script of its destructive buttons.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'tassyir-settings', plugins_url( 'assets/css/settings.css', TASSYIR_WC_FILE ), array(), TASSYIR_WC_VERSION );
		wp_enqueue_script( 'tassyir-settings', plugins_url( 'assets/js/settings.js', TASSYIR_WC_FILE ), array(), TASSYIR_WC_VERSION, true );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( Connection::return_url() ) . '">' . esc_html__( 'Settings', 'tassyir-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Before the page prints: its assets, finish a connect redirect, and ask for a fresh heartbeat.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the browser comes back from Tassyir; `state` (checked below) protects this request like a nonce.
		if ( isset( $_GET['state'] ) ) {
			$this->handle_return();
			return;
		}
		$last = (int) $this->connection->get( 'lastHeartbeatAt', 0 );
		if ( time() - $last > MINUTE_IN_SECONDS ) {
			$this->heartbeat->run_soon();
		}
	}

	/**
	 * The dashboard sent the browser back with `code` + `state` (approved) or `error` (cancelled).
	 * We check `state` against the one we stored, then exchange the code for the secret (§B3).
	 */
	private function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- `state` is the CSRF token of this OAuth-style redirect.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'tassyir-for-woocommerce' ), 403 );
		}

		$state_ok = $this->connection->check_state( $state );
		if ( '' !== $error ) {
			$this->redirect( 'CANCELLED' );
		}
		if ( ! $state_ok || '' === $code ) {
			Failure_Log::add( 'connect', 0, 'STATE_INVALID' );
			$this->redirect( 'STATE_INVALID' );
		}

		$response = $this->api->exchange(
			array_merge(
				array(
					'code'    => $code,
					'state'   => $state,
					'siteUrl' => home_url(),
				),
				Plugin::environment()
			)
		);

		if ( ! $response->ok() ) {
			Failure_Log::add( 'connect', 0, $response->code, $response->detail );
			$this->redirect( $response->code );
		}
		if ( ! $this->connection->save_exchange( $response->body ) ) {
			Failure_Log::add( 'connect', 0, 'EXCHANGE_INVALID' );
			$this->redirect( 'EXCHANGE_INVALID' );
		}

		Heartbeat::schedule( $this->connection->heartbeat_seconds() );
		$this->heartbeat->run_soon();
		Manual_Webhooks::refresh_count( $this->connection );
		$this->redirect( 'connected' );
	}

	/**
	 * Connect / Reconnect: create a `state` and send the browser to the dashboard approval page.
	 */
	public function handle_connect(): void {
		$this->check( 'tassyir_connect' );
		if ( ! Connection::same_site_host( home_url(), Connection::return_url() ) ) {
			$this->redirect( 'HOST_MISMATCH' );
		}
		$url  = Connection::connect_url( $this->connection->create_state() );
		$host = (string) wp_parse_url( Plugin::app_url(), PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Disconnect: tell Tassyir (best effort), drop the queue and forget the secret.
	 */
	public function handle_disconnect(): void {
		$this->check( 'tassyir_disconnect' );
		$this->api->post( '/woo-plugin/disconnect', new \stdClass() );
		Queue::cancel_all();
		$this->connection->forget();
		delete_option( Heartbeat::OPTION_ACKS );
		delete_option( Heartbeat::OPTION_DONE );
		$this->redirect( 'disconnected' );
	}

	/**
	 * Sync all products.
	 */
	public function handle_sync_products(): void {
		$this->check( 'tassyir_sync_products' );
		$this->products->start_full_sync();
		$this->redirect( 'sync_started' );
	}

	/**
	 * Send orders since a date (≤ 90 days).
	 */
	public function handle_backfill(): void {
		$this->check( 'tassyir_backfill' );
		$date = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check() above.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$this->redirect( 'DATE_INVALID' );
		}
		$since = ( new \DateTimeImmutable( $date . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		$this->orders->start_backfill( $since );
		$this->redirect( 'backfill_started' );
	}

	/**
	 * Delete the leftover manual webhooks.
	 */
	public function handle_delete_webhooks(): void {
		$this->check( 'tassyir_delete_webhooks' );
		Manual_Webhooks::delete_all( $this->connection );
		$this->redirect( 'webhooks_deleted' );
	}

	/**
	 * Empty the failures log.
	 */
	public function handle_clear_log(): void {
		$this->check( 'tassyir_clear_log' );
		Failure_Log::clear();
		$this->redirect( 'log_cleared' );
	}

	/**
	 * Nonce + capability check of an admin-post action; dies on failure.
	 *
	 * @param string $action Action name (also the nonce action).
	 */
	private function check( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'tassyir-for-woocommerce' ), 403 );
		}
	}

	/**
	 * Back to the settings page with a notice code, and stop.
	 *
	 * @param string $notice Notice or error code.
	 * @return never
	 */
	private function redirect( string $notice ) {
		wp_safe_redirect( add_query_arg( 'tassyir_notice', rawurlencode( $notice ), Connection::return_url() ) );
		exit;
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$connected = $this->connection->is_connected();
		$problem   = $connected ? (string) $this->connection->get( 'problem', '' ) : '';
		$status    = $connected
			? $this->status( $problem )
			: array(
				'state'  => 'idle',
				'label'  => __( 'Not connected', 'tassyir-for-woocommerce' ),
				'detail' => '',
			);

		echo '<div class="wrap tassyir-settings">';
		$this->print_header( $connected, $status );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks a tab.
		$tab = isset( $_GET['tab'] ) && Cod_Settings_Page::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? Cod_Settings_Page::TAB : '';
		$this->print_tabs( $tab );
		$this->print_notice();

		if ( Cod_Settings_Page::TAB === $tab ) {
			( new Cod_Settings_Page() )->render();
		} elseif ( $connected ) {
			$this->render_connected( $status, $problem );
		} else {
			$this->render_not_connected();
		}
		echo '</div>';
	}

	/**
	 * Brand mark, title, subtitle, status pill and the link to the dashboard.
	 * The `wp-header-end` rule right after it is where core moves admin notices to.
	 *
	 * @param bool                 $connected Whether the store is connected.
	 * @param array<string,string> $status    Result of status().
	 */
	private function print_header( bool $connected, array $status ): void {
		echo '<header class="tassyir-header"><div class="tassyir-brand">';
		Admin_Ui::print_brand_mark( 32 );
		echo '<div><h1>Tassyir</h1>';
		echo '<p class="tassyir-subtitle">' . esc_html__( 'Send this store\'s orders and products to your Tassyir dashboard.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '</div></div><div class="tassyir-header__end">';
		Admin_Ui::print_pill( $status['state'], $status['label'] );
		if ( $connected ) {
			echo '<a class="tassyir-external" href="' . esc_url( Plugin::app_url() ) . '" target="_blank" rel="noopener noreferrer">';
			echo esc_html__( 'Open Tassyir', 'tassyir-for-woocommerce' );
			Admin_Ui::print_icon( 'external' );
			echo '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'tassyir-for-woocommerce' ) . '</span></a>';
		}
		echo '</div></header>';
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Connection | Checkout form tabs.
	 *
	 * @param string $current Current tab ('' = connection).
	 */
	private function print_tabs( string $current ): void {
		$tabs = array(
			''                     => __( 'Connection', 'tassyir-for-woocommerce' ),
			Cod_Settings_Page::TAB => __( 'Checkout form', 'tassyir-for-woocommerce' ),
		);
		echo '<nav class="tassyir-tabs" aria-label="' . esc_attr__( 'Settings', 'tassyir-for-woocommerce' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			$url = '' === $tab ? Connection::return_url() : add_query_arg( 'tab', $tab, Connection::return_url() );
			printf(
				'<a href="%1$s" class="tassyir-tab%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				$tab === $current ? ' is-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * The notice named by ?tassyir_notice= (codes only, never free text from the URL).
	 * `inline` keeps core from moving it above the tabs; it stays dismissible.
	 */
	private function print_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the value is mapped to fixed texts.
		$code = isset( $_GET['tassyir_notice'] ) ? sanitize_key( wp_unslash( $_GET['tassyir_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$success = array(
			'connected'        => __( 'Connected to Tassyir. New orders are now sent automatically.', 'tassyir-for-woocommerce' ),
			'disconnected'     => __( 'Disconnected from Tassyir.', 'tassyir-for-woocommerce' ),
			'sync_started'     => __( 'Product sync started. It runs in the background, 20 products at a time.', 'tassyir-for-woocommerce' ),
			'backfill_started' => __( 'Orders are being queued in the background.', 'tassyir-for-woocommerce' ),
			'webhooks_deleted' => __( 'The old manual webhooks were deleted.', 'tassyir-for-woocommerce' ),
			'log_cleared'      => __( 'The problems log was cleared.', 'tassyir-for-woocommerce' ),
			'cod_saved'        => __( 'Checkout form settings saved.', 'tassyir-for-woocommerce' ),
		);
		if ( isset( $success[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( $success[ $code ] ) . '</p></div>';
			return;
		}
		$text = 'date_invalid' === $code ? __( 'Please choose a valid date.', 'tassyir-for-woocommerce' ) : Labels::reason( strtoupper( $code ) );
		echo '<div class="notice notice-error is-dismissible inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Not connected: the Connect button first (merchants arrive here right after activation),
	 * then how it works and what is shared.
	 */
	private function render_not_connected(): void {
		$host = (string) wp_parse_url( Plugin::app_url(), PHP_URL_HOST );

		Admin_Ui::card_open( array( 'class' => 'tassyir-narrow tassyir-hero' ) );
		Admin_Ui::print_brand_mark( 56 );
		echo '<h2 class="tassyir-hero__title">' . esc_html__( 'Connect your store to Tassyir', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<p class="tassyir-hero__text">' . esc_html__( 'One last step: connect this store to start sending your orders to Tassyir.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<div class="tassyir-actions tassyir-actions--center">';
		$this->button_form( 'tassyir_connect', __( 'Connect to Tassyir', 'tassyir-for-woocommerce' ), 'primary button-hero' );
		echo '</div>';
		echo '<p class="tassyir-muted">';
		/* translators: %s: Tassyir dashboard host, e.g. app.tassyir.io. */
		echo esc_html( sprintf( __( 'You will choose your business and store on %s, then come back here.', 'tassyir-for-woocommerce' ), $host ) );
		echo '</p>';
		Admin_Ui::card_close();

		Admin_Ui::card_open( array( 'class' => 'tassyir-narrow' ) );
		echo '<ol class="tassyir-steps">';
		echo '<li>' . esc_html__( 'Press Connect.', 'tassyir-for-woocommerce' ) . '</li>';
		/* translators: %s: Tassyir dashboard host, e.g. app.tassyir.io. */
		echo '<li>' . esc_html( sprintf( __( 'Choose your business and store on %s.', 'tassyir-for-woocommerce' ), $host ) ) . '</li>';
		echo '<li>' . esc_html__( 'Come back here: new orders are sent automatically.', 'tassyir-for-woocommerce' ) . '</li>';
		echo '</ol>';
		echo '<h3 class="tassyir-subhead">' . esc_html__( 'Once connected, this plugin sends to your Tassyir store:', 'tassyir-for-woocommerce' ) . '</h3>';
		echo '<ul class="tassyir-sends">';
		$shared = array(
			'cart'  => __( 'Orders: every new order, kept in a queue and retried until Tassyir confirms it.', 'tassyir-for-woocommerce' ),
			'box'   => __( 'Products: names, prices, SKUs, variations and product photos, so Tassyir can create them.', 'tassyir-for-woocommerce' ),
			'clock' => __( 'Store health: plugin, WordPress and WooCommerce versions, and the queue size.', 'tassyir-for-woocommerce' ),
		);
		foreach ( $shared as $icon => $text ) {
			echo '<li><span class="tassyir-sends__icon">';
			Admin_Ui::print_icon( $icon );
			echo '</span><span>' . esc_html( $text ) . '</span></li>';
		}
		echo '</ul>';
		Admin_Ui::card_close();
	}

	/**
	 * Connected: problem, status, stats, actions, webhooks, failures, danger zone.
	 *
	 * @param array<string,string> $status  Result of status().
	 * @param string               $problem Stored problem ('' = none).
	 */
	private function render_connected( array $status, string $problem ): void {
		$this->render_problem( $status, $problem );
		$this->render_status_card( $status, $problem );
		$this->render_stats();

		echo '<div class="tassyir-grid">';
		$this->render_products_card();
		$this->render_backfill();
		echo '</div>';

		$this->render_webhooks();
		$this->render_failures();

		$queue_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=tassyir' );
		echo '<p class="tassyir-footnote"><a href="' . esc_url( $queue_url ) . '">' . esc_html__( 'See every Tassyir job in Scheduled Actions', 'tassyir-for-woocommerce' ) . '</a></p>';

		$this->render_danger_zone( $problem );
	}

	/**
	 * The alert card when the connection has a stored problem.
	 * Reconnecting only repairs a refused signature, so only that problem offers the button.
	 *
	 * @param array<string,string> $status  Result of status().
	 * @param string               $problem Stored problem.
	 */
	private function render_problem( array $status, string $problem ): void {
		if ( '' === $problem ) {
			return;
		}
		Admin_Ui::card_open( array( 'class' => 'tassyir-card--alert' ) );
		echo '<div class="tassyir-alert"><span class="tassyir-alert__icon">';
		Admin_Ui::print_icon( 'alert' );
		echo '</span><p class="tassyir-alert__text">' . esc_html( $status['detail'] ) . '</p>';
		if ( Connection::PROBLEM_RECONNECT === $problem ) {
			echo '<div class="tassyir-alert__action">';
			$this->button_form( 'tassyir_connect', __( 'Reconnect', 'tassyir-for-woocommerce' ), 'primary' );
			echo '</div>';
		}
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * Business, store, plugin version and last check-in.
	 *
	 * @param array<string,string> $status  Result of status().
	 * @param string               $problem Stored problem.
	 */
	private function render_status_card( array $status, string $problem ): void {
		$business = (array) $this->connection->get( 'business', array() );
		$store    = (array) $this->connection->get( 'store', array() );
		$latest   = $this->newer_version();
		$last     = (int) $this->connection->get( 'lastHeartbeatAt', 0 );

		Admin_Ui::card_open(
			array(
				'title'   => __( 'Connection', 'tassyir-for-woocommerce' ),
				'actions' => static function () use ( $status ) {
					Admin_Ui::print_pill( $status['state'], $status['label'] );
				},
			)
		);
		echo '<dl class="tassyir-dl">';
		$this->definition( __( 'Business', 'tassyir-for-woocommerce' ), esc_html( (string) ( $business['name'] ?? '' ) ) );
		$this->definition( __( 'Store', 'tassyir-for-woocommerce' ), esc_html( '' !== (string) ( $store['name'] ?? '' ) ? (string) $store['name'] : '—' ) );

		$version = esc_html( TASSYIR_WC_VERSION );
		if ( '' !== $latest ) {
			/* translators: %s: latest plugin version, e.g. 1.2.0. */
			$version .= ' <span class="tassyir-badge">' . esc_html( sprintf( __( 'Update available: %s', 'tassyir-for-woocommerce' ), $latest ) ) . '</span>';
		}
		$this->definition( __( 'Plugin version', 'tassyir-for-woocommerce' ), $version );

		/* translators: %s: time ago, e.g. 3 hours. */
		$checkin = $last > 0 ? sprintf( __( '%s ago', 'tassyir-for-woocommerce' ), human_time_diff( $last ) ) : __( 'Checking…', 'tassyir-for-woocommerce' );
		$this->definition( __( 'Last check-in', 'tassyir-for-woocommerce' ), esc_html( $checkin ) );
		echo '</dl>';

		// A stored problem has its own alert card above: the detail line is for a failed check-in only.
		if ( '' === $problem && '' !== $status['detail'] ) {
			echo '<p class="tassyir-status-detail">' . esc_html( $status['detail'] ) . '</p>';
		}
		Admin_Ui::card_close();
	}

	/**
	 * Four tiles: orders waiting, failed, last order sent, products synced.
	 */
	private function render_stats(): void {
		$pending   = Queue::count_pending( Order_Sync::HOOK );
		$failed    = $this->orders->failed_count();
		$last_sent = (int) $this->connection->get( 'lastOrderSentAt', 0 );
		$products  = $this->product_sync_stat();

		echo '<ul class="tassyir-stats">';
		Admin_Ui::print_stat( __( 'Orders waiting', 'tassyir-for-woocommerce' ), number_format_i18n( $pending ) );
		Admin_Ui::print_stat( __( 'Failed orders', 'tassyir-for-woocommerce' ), number_format_i18n( $failed ), '', $failed > 0 ? 'danger' : '' );
		if ( $last_sent > 0 ) {
			/* translators: %s: time ago, e.g. 3 hours. */
			$ago = sprintf( __( '%s ago', 'tassyir-for-woocommerce' ), human_time_diff( $last_sent ) );
			Admin_Ui::print_stat( __( 'Last order sent', 'tassyir-for-woocommerce' ), $ago, '', '', Order_Admin::local_time( $last_sent ) );
		} else {
			Admin_Ui::print_stat( __( 'Last order sent', 'tassyir-for-woocommerce' ), __( 'None yet', 'tassyir-for-woocommerce' ) );
		}
		Admin_Ui::print_stat( __( 'Products synced', 'tassyir-for-woocommerce' ), $products['value'], $products['sub'] );
		echo '</ul>';
	}

	/**
	 * Products: automatic sync note, state of the last sync, "Sync all products".
	 */
	private function render_products_card(): void {
		Admin_Ui::card_open(
			array(
				'title' => __( 'Products', 'tassyir-for-woocommerce' ),
				'icon'  => 'box',
			)
		);
		echo '<p>' . esc_html__( 'New and edited products are sent automatically. Sync all to send every product again.', 'tassyir-for-woocommerce' ) . '</p>';
		// Before a first full sync the text above already says everything the "never synced" line would.
		if ( ! empty( ( (array) $this->connection->get( 'productSync', array() ) )['startedAt'] ) ) {
			echo '<p class="tassyir-muted">' . esc_html( $this->product_sync_text() ) . '</p>';
		}
		echo '<div class="tassyir-card__foot">';
		$this->button_form( 'tassyir_sync_products', __( 'Sync all products', 'tassyir-for-woocommerce' ) );
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * "Send older orders" form.
	 */
	private function render_backfill(): void {
		$min = wp_date( 'Y-m-d', time() - ( Order_Sync::BACKFILL_MAX_DAYS - 1 ) * DAY_IN_SECONDS );
		$max = wp_date( 'Y-m-d' );

		Admin_Ui::card_open(
			array(
				'title' => __( 'Send older orders', 'tassyir-for-woocommerce' ),
				'icon'  => 'cart',
			)
		);
		echo '<p>' . esc_html__( 'Orders placed before you connected are not sent automatically. Send every order since a date, up to 90 days back. Orders Tassyir already has are skipped.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<div class="tassyir-card__foot">';
		echo '<form method="post" class="tassyir-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tassyir_backfill' );
		echo '<input type="hidden" name="action" value="tassyir_backfill">';
		echo '<label for="tassyir-since" class="screen-reader-text">' . esc_html__( 'Date', 'tassyir-for-woocommerce' ) . '</label>';
		echo '<input type="date" id="tassyir-since" name="since" required min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $max ) . '"> ';
		submit_button( __( 'Send orders', 'tassyir-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * Leftover manual webhooks, with "Delete them".
	 */
	private function render_webhooks(): void {
		$webhooks = Manual_Webhooks::find( (string) $this->connection->get( 'webhookPrefix', '' ) );
		if ( empty( $webhooks ) ) {
			return;
		}
		Admin_Ui::card_open(
			array(
				'class' => 'tassyir-card--warning',
				'title' => __( 'Old manual webhooks', 'tassyir-for-woocommerce' ),
				'icon'  => 'alert',
			)
		);
		echo '<p>' . esc_html__( 'Before this plugin, these WooCommerce webhooks sent your orders to Tassyir. The plugin now does it, so they are no longer needed (duplicates are ignored until you delete them).', 'tassyir-for-woocommerce' ) . '</p><ul class="tassyir-webhooks">';
		foreach ( $webhooks as $webhook ) {
			echo '<li>' . esc_html( $webhook->get_name() ) . ' — <code>' . esc_html( $webhook->get_delivery_url() ) . '</code></li>';
		}
		echo '</ul><div class="tassyir-actions">';
		$this->button_form( 'tassyir_delete_webhooks', __( 'Delete them', 'tassyir-for-woocommerce' ), 'primary', __( 'Delete these webhooks permanently?', 'tassyir-for-woocommerce' ) );
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * Last 20 failures.
	 */
	private function render_failures(): void {
		$entries = Failure_Log::all();
		$clear   = function () {
			$this->button_form( 'tassyir_clear_log', __( 'Clear', 'tassyir-for-woocommerce' ) );
		};

		Admin_Ui::card_open(
			array(
				'title'      => __( 'Recent problems', 'tassyir-for-woocommerce' ),
				'actions'    => empty( $entries ) ? null : $clear,
				'body_class' => empty( $entries ) ? '' : 'tassyir-card__body--flush',
			)
		);
		if ( empty( $entries ) ) {
			echo '<p class="tassyir-empty"><span class="tassyir-empty__icon">';
			Admin_Ui::print_icon( 'check' );
			echo '</span>' . esc_html__( 'No problems. Orders and products are going through.', 'tassyir-for-woocommerce' ) . '</p>';
			Admin_Ui::card_close();
			return;
		}
		echo '<div class="tassyir-table-wrap"><table class="tassyir-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'tassyir-for-woocommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What', 'tassyir-for-woocommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Problem', 'tassyir-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$what = Labels::type( (string) $entry['type'] );
			if ( 'order' === $entry['type'] && $entry['id'] > 0 ) {
				$order = wc_get_order( (int) $entry['id'] );
				$what  = $order instanceof \WC_Order
					? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $what . ' #' . $order->get_order_number() ) . '</a>'
					: esc_html( $what . ' #' . $entry['id'] );
			} else {
				$what = esc_html( $entry['id'] > 0 ? $what . ' #' . $entry['id'] : $what );
			}
			echo '<tr><td>' . esc_html( Order_Admin::local_time( (int) $entry['time'] ) ) . '</td>';
			echo '<td>' . $what . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '<td>' . esc_html( Labels::reason( (string) $entry['code'] ) );
			if ( '' !== (string) $entry['detail'] ) {
				echo '<br><small><code>' . esc_html( (string) $entry['detail'] ) . '</code></small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		Admin_Ui::card_close();
	}

	/**
	 * Reconnect and Disconnect, kept apart from the everyday actions.
	 *
	 * @param string $problem Stored problem.
	 */
	private function render_danger_zone( string $problem ): void {
		Admin_Ui::card_open(
			array(
				'class'      => 'tassyir-card--danger',
				'title'      => __( 'Danger zone', 'tassyir-for-woocommerce' ),
				'body_class' => 'tassyir-card__body--rows',
			)
		);
		Admin_Ui::row_open(
			array(
				'label' => __( 'Reconnect', 'tassyir-for-woocommerce' ),
				'help'  => __( 'Choose another business or store, or repair the connection.', 'tassyir-for-woocommerce' ),
			)
		);
		$this->button_form( 'tassyir_connect', __( 'Reconnect', 'tassyir-for-woocommerce' ), Connection::PROBLEM_RECONNECT === $problem ? 'primary' : 'secondary' );
		Admin_Ui::row_close();
		Admin_Ui::row_open(
			array(
				'label' => __( 'Disconnect', 'tassyir-for-woocommerce' ),
				'help'  => __( 'Orders stop being sent. Orders already in Tassyir stay there.', 'tassyir-for-woocommerce' ),
			)
		);
		$this->button_form( 'tassyir_disconnect', __( 'Disconnect', 'tassyir-for-woocommerce' ), 'secondary', __( 'Disconnect this site from Tassyir? Orders will stop being sent.', 'tassyir-for-woocommerce' ), 'tassyir-btn-danger' );
		Admin_Ui::row_close();
		Admin_Ui::card_close();
	}

	/**
	 * Connection state, for the header pill and the status card.
	 *
	 * `label` is the short pill text; `detail` is the full reason when there is one.
	 *
	 * @param string $problem Stored problem.
	 * @return array{state: string, label: string, detail: string} state is online | offline | idle.
	 */
	private function status( string $problem ): array {
		if ( '' !== $problem ) {
			$code = array(
				Connection::PROBLEM_RECONNECT => 'WOO_PLUGIN_SIGNATURE_INVALID',
				Connection::PROBLEM_OUTDATED  => 'WOO_PLUGIN_VERSION_UNSUPPORTED',
				Connection::PROBLEM_CLOCK     => 'CLOCK_SKEW',
			);
			return array(
				'state'  => 'offline',
				'label'  => __( 'Needs attention', 'tassyir-for-woocommerce' ),
				'detail' => Labels::reason( $code[ $problem ] ?? $problem ),
			);
		}

		$last  = (int) $this->connection->get( 'lastHeartbeatAt', 0 );
		$error = (string) $this->connection->get( 'heartbeatError', '' );
		if ( '' !== $error ) {
			return array(
				'state'  => 'offline',
				'label'  => __( 'Needs attention', 'tassyir-for-woocommerce' ),
				'detail' => Labels::reason( $error ),
			);
		}
		if ( 0 === $last ) {
			return array(
				'state'  => 'idle',
				'label'  => __( 'Checking…', 'tassyir-for-woocommerce' ),
				'detail' => '',
			);
		}
		if ( time() - $last <= self::ONLINE_WINDOW ) {
			return array(
				'state'  => 'online',
				'label'  => __( 'Online', 'tassyir-for-woocommerce' ),
				'detail' => '',
			);
		}
		return array(
			'state'  => 'offline',
			/* translators: %s: time ago, e.g. 3 hours. */
			'label'  => sprintf( __( 'Last seen %s ago', 'tassyir-for-woocommerce' ), human_time_diff( $last ) ),
			'detail' => '',
		);
	}

	/**
	 * State of the last "Sync all products".
	 */
	private function product_sync_text(): string {
		$sync = (array) $this->connection->get( 'productSync', array() );
		if ( empty( $sync['startedAt'] ) ) {
			return __( 'Never synced. New and edited products are sent automatically.', 'tassyir-for-woocommerce' );
		}
		$sent = (int) ( $sync['sent'] ?? 0 );
		if ( empty( $sync['finishedAt'] ) ) {
			/* translators: %d: number of products. */
			return sprintf( _n( 'Sync running: %d product sent so far.', 'Sync running: %d products sent so far.', $sent, 'tassyir-for-woocommerce' ), $sent );
		}
		return sprintf(
			/* translators: 1: number of products, 2: date and time. */
			_n( '%1$d product synced on %2$s.', '%1$d products synced on %2$s.', $sent, 'tassyir-for-woocommerce' ),
			$sent,
			Order_Admin::local_time( (int) $sync['finishedAt'] )
		);
	}

	/**
	 * The "Products synced" tile: the count and a line under it.
	 *
	 * @return array{value: string, sub: string}
	 */
	private function product_sync_stat(): array {
		$sync = (array) $this->connection->get( 'productSync', array() );
		if ( empty( $sync['startedAt'] ) ) {
			return array(
				'value' => '—',
				'sub'   => __( 'Not synced yet', 'tassyir-for-woocommerce' ),
			);
		}
		$sent = number_format_i18n( (int) ( $sync['sent'] ?? 0 ) );
		if ( empty( $sync['finishedAt'] ) ) {
			return array(
				'value' => $sent,
				'sub'   => __( 'Running…', 'tassyir-for-woocommerce' ),
			);
		}
		return array(
			'value' => $sent,
			'sub'   => Order_Admin::local_time( (int) $sync['finishedAt'] ),
		);
	}

	/**
	 * The latest plugin version when it is newer than this one, '' otherwise.
	 */
	private function newer_version(): string {
		$latest = (string) $this->connection->get( 'latestPluginVersion', '' );
		return '' !== $latest && version_compare( TASSYIR_WC_VERSION, $latest, '<' ) ? $latest : '';
	}

	/**
	 * One term/value pair of the status card; $value_html must already be escaped.
	 *
	 * @param string $label      Term.
	 * @param string $value_html Escaped HTML.
	 */
	private function definition( string $label, string $value_html ): void {
		echo '<div class="tassyir-dl__item"><dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
	}

	/**
	 * A one-button form posting an admin-post action with its nonce.
	 *
	 * @param string $action      Action (also the nonce action).
	 * @param string $label       Button text.
	 * @param string $type        primary | secondary (submit_button() classes).
	 * @param string $confirm     Confirmation question ('' = none).
	 * @param string $extra_class Extra CSS class(es) for the button, e.g. tassyir-btn-danger.
	 */
	private function button_form( string $action, string $label, string $type = 'secondary', string $confirm = '', string $extra_class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' === $confirm ? '' : ' data-tassyir-confirm="' . esc_attr( $confirm ) . '"' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, trim( $type . ' ' . $extra_class ), 'submit', false );
		echo '</form>';
	}
}
