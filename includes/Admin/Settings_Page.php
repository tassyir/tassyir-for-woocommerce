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
		echo '<div class="wrap tassyir-settings">';
		echo '<h1>Tassyir</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks a tab.
		$tab = isset( $_GET['tab'] ) && Cod_Settings_Page::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? Cod_Settings_Page::TAB : '';
		$this->print_tabs( $tab );
		$this->print_notice();

		if ( Cod_Settings_Page::TAB === $tab ) {
			( new Cod_Settings_Page() )->render();
		} elseif ( $this->connection->is_connected() ) {
			$this->render_connected();
		} else {
			$this->render_not_connected();
		}
		echo '</div>';
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
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab => $label ) {
			$url = '' === $tab ? Connection::return_url() : add_query_arg( 'tab', $tab, Connection::return_url() );
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * The notice named by ?tassyir_notice= (codes only, never free text from the URL).
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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success[ $code ] ) . '</p></div>';
			return;
		}
		$text = 'date_invalid' === $code ? __( 'Please choose a valid date.', 'tassyir-for-woocommerce' ) : Labels::reason( strtoupper( $code ) );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Not connected: the Connect button first (merchants arrive here right after activation),
	 * then what is shared.
	 */
	private function render_not_connected(): void {
		echo '<div class="card tassyir-connect">';
		echo '<h2>' . esc_html__( 'Connect your store to Tassyir', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'One last step: connect this store to start sending your orders to Tassyir.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<div class="tassyir-actions">';
		$this->button_form( 'tassyir_connect', __( 'Connect to Tassyir', 'tassyir-for-woocommerce' ), 'primary button-hero' );
		echo '</div>';
		echo '<p class="description">';
		/* translators: %s: Tassyir dashboard host, e.g. app.tassyir.io. */
		echo esc_html( sprintf( __( 'You will choose your business and store on %s, then come back here.', 'tassyir-for-woocommerce' ), (string) wp_parse_url( Plugin::app_url(), PHP_URL_HOST ) ) );
		echo '</p>';
		echo '<p>' . esc_html__( 'Once connected, this plugin sends to your Tassyir store:', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<ul class="tassyir-shared">';
		echo '<li>' . esc_html__( 'Orders: every new order, kept in a queue and retried until Tassyir confirms it.', 'tassyir-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Products: names, prices, SKUs, variations and product photos, so Tassyir can create them.', 'tassyir-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Store health: plugin, WordPress and WooCommerce versions, and the queue size.', 'tassyir-for-woocommerce' ) . '</li>';
		echo '</ul></div>';
	}

	/**
	 * Connected: status, queue, buttons, webhooks, failures.
	 */
	private function render_connected(): void {
		$business = (array) $this->connection->get( 'business', array() );
		$store    = (array) $this->connection->get( 'store', array() );
		$problem  = (string) $this->connection->get( 'problem', '' );
		$pending  = Queue::count_pending( Order_Sync::HOOK );
		$failed   = $this->orders->failed_count();

		echo '<h2>' . esc_html__( 'Connection', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Business', 'tassyir-for-woocommerce' ), esc_html( (string) ( $business['name'] ?? '' ) ) );
		$this->row( __( 'Store', 'tassyir-for-woocommerce' ), esc_html( '' !== (string) ( $store['name'] ?? '' ) ? (string) $store['name'] : '—' ) );
		$this->row( __( 'Status', 'tassyir-for-woocommerce' ), $this->status_html( $problem ) );
		$this->row(
			__( 'Orders queue', 'tassyir-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: 1: orders waiting, 2: orders failed. */
					__( '%1$d waiting, %2$d failed', 'tassyir-for-woocommerce' ),
					$pending,
					$failed
				)
			)
		);
		$last_sent = (int) $this->connection->get( 'lastOrderSentAt', 0 );
		$this->row(
			__( 'Last order sent', 'tassyir-for-woocommerce' ),
			esc_html( $last_sent > 0 ? Order_Admin::local_time( $last_sent ) : __( 'None yet', 'tassyir-for-woocommerce' ) )
		);
		$this->row( __( 'Products', 'tassyir-for-woocommerce' ), esc_html( $this->product_sync_text() ) );
		$this->row( __( 'Plugin version', 'tassyir-for-woocommerce' ), esc_html( $this->version_text() ) );
		echo '</tbody></table>';

		echo '<div class="tassyir-actions">';
		$this->button_form( 'tassyir_sync_products', __( 'Sync all products', 'tassyir-for-woocommerce' ) );
		$this->button_form( 'tassyir_connect', __( 'Reconnect', 'tassyir-for-woocommerce' ), Connection::PROBLEM_RECONNECT === $problem ? 'primary' : 'secondary' );
		$this->button_form( 'tassyir_disconnect', __( 'Disconnect', 'tassyir-for-woocommerce' ), 'secondary', __( 'Disconnect this site from Tassyir? Orders will stop being sent.', 'tassyir-for-woocommerce' ) );
		echo '</div>';

		$this->render_backfill();
		$this->render_webhooks();
		$this->render_failures();

		$queue_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=tassyir' );
		echo '<p><a href="' . esc_url( $queue_url ) . '">' . esc_html__( 'See every Tassyir job in Scheduled Actions', 'tassyir-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Online / last seen / problem, as HTML.
	 *
	 * @param string $problem Stored problem.
	 */
	private function status_html( string $problem ): string {
		if ( '' !== $problem ) {
			$code = array(
				Connection::PROBLEM_RECONNECT => 'WOO_PLUGIN_SIGNATURE_INVALID',
				Connection::PROBLEM_OUTDATED  => 'WOO_PLUGIN_VERSION_UNSUPPORTED',
				Connection::PROBLEM_CLOCK     => 'CLOCK_SKEW',
			);
			return '<span class="tassyir-offline">' . esc_html( Labels::reason( $code[ $problem ] ?? $problem ) ) . '</span>';
		}

		$last  = (int) $this->connection->get( 'lastHeartbeatAt', 0 );
		$error = (string) $this->connection->get( 'heartbeatError', '' );
		if ( '' !== $error ) {
			return '<span class="tassyir-offline">' . esc_html( Labels::reason( $error ) ) . '</span>';
		}
		if ( 0 === $last ) {
			return esc_html__( 'Checking…', 'tassyir-for-woocommerce' );
		}
		if ( time() - $last <= self::ONLINE_WINDOW ) {
			return '<span class="tassyir-online">' . esc_html__( 'Online', 'tassyir-for-woocommerce' ) . '</span>';
		}
		/* translators: %s: time ago, e.g. 3 hours. */
		return '<span class="tassyir-offline">' . esc_html( sprintf( __( 'Last seen %s ago', 'tassyir-for-woocommerce' ), human_time_diff( $last ) ) ) . '</span>';
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
	 * This version, and the latest one when newer.
	 */
	private function version_text(): string {
		$latest = (string) $this->connection->get( 'latestPluginVersion', '' );
		if ( '' !== $latest && version_compare( TASSYIR_WC_VERSION, $latest, '<' ) ) {
			/* translators: 1: installed version, 2: latest version. */
			return sprintf( __( '%1$s (version %2$s is available)', 'tassyir-for-woocommerce' ), TASSYIR_WC_VERSION, $latest );
		}
		return TASSYIR_WC_VERSION;
	}

	/**
	 * "Send orders since…" form.
	 */
	private function render_backfill(): void {
		$min = wp_date( 'Y-m-d', time() - ( Order_Sync::BACKFILL_MAX_DAYS - 1 ) * DAY_IN_SECONDS );
		$max = wp_date( 'Y-m-d' );
		echo '<h2>' . esc_html__( 'Send orders since…', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Queues every order created since this date (90 days at most) that Tassyir has not received yet.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tassyir_backfill' );
		echo '<input type="hidden" name="action" value="tassyir_backfill">';
		echo '<label for="tassyir-since" class="screen-reader-text">' . esc_html__( 'Date', 'tassyir-for-woocommerce' ) . '</label>';
		echo '<input type="date" id="tassyir-since" name="since" required min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $max ) . '"> ';
		submit_button( __( 'Send orders', 'tassyir-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Leftover manual webhooks, with "Delete them".
	 */
	private function render_webhooks(): void {
		$webhooks = Manual_Webhooks::find( (string) $this->connection->get( 'webhookPrefix', '' ) );
		if ( empty( $webhooks ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Old manual webhooks', 'tassyir-for-woocommerce' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Before this plugin, these WooCommerce webhooks sent your orders to Tassyir. The plugin now does it, so they are no longer needed (duplicates are ignored until you delete them).', 'tassyir-for-woocommerce' ) . '</p><ul>';
		foreach ( $webhooks as $webhook ) {
			echo '<li>' . esc_html( $webhook->get_name() ) . ' — <code>' . esc_html( $webhook->get_delivery_url() ) . '</code></li>';
		}
		echo '</ul>';
		$this->button_form( 'tassyir_delete_webhooks', __( 'Delete them', 'tassyir-for-woocommerce' ), 'primary', __( 'Delete these webhooks permanently?', 'tassyir-for-woocommerce' ) );
		echo '</div>';
	}

	/**
	 * Last 20 failures.
	 */
	private function render_failures(): void {
		$entries = Failure_Log::all();
		echo '<h2>' . esc_html__( 'Recent problems', 'tassyir-for-woocommerce' ) . '</h2>';
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No problems.', 'tassyir-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'tassyir-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'tassyir-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Problem', 'tassyir-for-woocommerce' ) . '</th>';
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
		echo '</tbody></table>';
		echo '<div class="tassyir-actions">';
		$this->button_form( 'tassyir_clear_log', __( 'Clear', 'tassyir-for-woocommerce' ) );
		echo '</div>';
	}

	/**
	 * One table row; $value_html must already be escaped.
	 *
	 * @param string $label      Row label.
	 * @param string $value_html Escaped HTML.
	 */
	private function row( string $label, string $value_html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
	}

	/**
	 * A one-button form posting an admin-post action with its nonce.
	 *
	 * @param string $action  Action (also the nonce action).
	 * @param string $label   Button text.
	 * @param string $type    primary | secondary (submit_button() classes).
	 * @param string $confirm Confirmation question ('' = none).
	 */
	private function button_form( string $action, string $label, string $type = 'secondary', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' === $confirm ? '' : ' data-tassyir-confirm="' . esc_attr( $confirm ) . '"' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}
}
