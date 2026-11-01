<?php
/**
 * Connect state check, stored secrets, connection health flags and the API client.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Api_Client;
use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Plugin;

final class ConnectionTest extends Integration_TestCase {

	/**
	 * Connection under test.
	 *
	 * @var Connection
	 */
	private $connection;

	public function set_up(): void {
		parent::set_up();
		$this->connection = new Connection();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_state_is_checked_and_single_use(): void {
		$state = $this->connection->create_state();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $state );
		$this->assertTrue( $this->connection->check_state( $state ) );
		$this->assertFalse( $this->connection->check_state( $state ), 'a state works once' );
	}

	public function test_wrong_malformed_or_other_users_state_is_refused(): void {
		$state = $this->connection->create_state();
		$this->assertFalse( $this->connection->check_state( $state . 'x' ) );

		$state = $this->connection->create_state();
		$this->assertFalse( $this->connection->check_state( 'bad state!' ) );

		$state = $this->connection->create_state();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $this->connection->check_state( $state ) );
		$this->assertFalse( $this->connection->check_state( '' ) );
	}

	public function test_connect_url(): void {
		$url = Connection::connect_url( 'abcDEF123_-xyz' );
		$this->assertStringStartsWith( Plugin::app_url() . '/connect/woocommerce?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( home_url(), $query['site'] );
		$this->assertSame( admin_url( 'admin.php?page=tassyir' ), $query['return'] );
		$this->assertSame( 'abcDEF123_-xyz', $query['state'] );
		$this->assertSame( TASSYIR_WC_VERSION, $query['v'] );
	}

	public function test_same_site_host_rule(): void {
		$this->assertTrue( Connection::same_site_host( 'https://shop.example.com', 'https://shop.example.com/wp-admin/admin.php?page=tassyir' ) );
		$this->assertTrue( Connection::same_site_host( 'https://WWW.Shop.example.com/fr', 'https://shop.example.com/wp-admin/admin.php?page=tassyir' ) );
		$this->assertTrue( Connection::same_site_host( 'https://shop.example.com', 'https://www.shop.example.com/wp-admin/' ) );
		$this->assertFalse( Connection::same_site_host( 'https://shop.example.com', 'https://admin.example.com/wp-admin/' ) );
		$this->assertFalse( Connection::same_site_host( 'https://www.www.example.com', 'https://example.com/wp-admin/' ) );
		$this->assertFalse( Connection::same_site_host( '', '' ) );
		// Scheme and port are part of the site (amended 2026-10-30).
		$this->assertTrue( Connection::same_site_host( 'http://localhost:8890', 'http://localhost:8890/wp-admin/admin.php?page=tassyir' ) );
		$this->assertTrue( Connection::same_site_host( 'https://shop.example.com:443', 'https://shop.example.com/wp-admin/' ) );
		$this->assertFalse( Connection::same_site_host( 'http://shop.example.com', 'https://shop.example.com/wp-admin/' ) );
		$this->assertFalse( Connection::same_site_host( 'http://localhost:8890', 'http://localhost:8891/wp-admin/' ) );
		$this->assertTrue( Connection::same_site_host( home_url(), Connection::return_url() ) );
	}

	public function test_exchange_answer_is_stored_with_autoload_off(): void {
		global $wpdb;
		$this->connection->forget();
		$ok = $this->connection->save_exchange(
			array(
				'connectionId'   => '123456789012345678',
				'secret'         => self::SECRET,
				'business'       => array(
					'id'   => '1',
					'name' => 'Demo Business',
				),
				'store'          => array(
					'id'   => '2',
					'name' => 'Electro',
				),
				'upgradedFrom'   => 'webhook_only',
				'settings'       => array(
					'importStatuses'     => array( 'processing' ),
					'importFrom'         => '2026-10-01T00:00:00Z',
					'autoCreateProducts' => true,
					'heartbeatSeconds'   => 900,
				),
				'manualWebhooks' => array( 'deliveryUrlPrefix' => 'https://api.tassyir.io/woocommerce-webhook/123/' ),
			)
		);
		$this->assertTrue( $ok );
		$this->assertTrue( $this->connection->is_connected() );
		$this->assertSame( 'Demo Business', $this->connection->get( 'business' )['name'] );
		$this->assertSame( 'https://api.tassyir.io/woocommerce-webhook/123/', $this->connection->get( 'webhookPrefix' ) );

		foreach ( array( Connection::OPTION_ID, Connection::OPTION_SECRET, Connection::OPTION_INFO, Connection::OPTION_SETTINGS ) as $option ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertContains( $autoload, array( 'no', 'off' ), $option );
		}

		$this->assertFalse( ( new Connection() )->save_exchange( array( 'connectionId' => '1' ) ) );

		// `store` may be null.
		$this->assertTrue(
			$this->connection->save_exchange(
				array(
					'connectionId' => '1',
					'secret'       => self::SECRET,
					'business'     => array(
						'id'   => '1',
						'name' => 'B',
					),
					'store'        => null,
				)
			)
		);
		$this->assertSame(
			array(
				'id'   => '',
				'name' => '',
			),
			$this->connection->get( 'store' )
		);
	}

	public function test_refused_signature_asks_for_reconnect_and_success_clears_it(): void {
		$api = new Api_Client( $this->connection );
		$this->answer( 401, array( 'code' => 'WOO_PLUGIN_SIGNATURE_INVALID' ), array( 'date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ) );
		$this->assertSame( 'WOO_PLUGIN_SIGNATURE_INVALID', $api->post( '/woo-plugin/heartbeat', array() )->code );
		$this->assertSame( Connection::PROBLEM_RECONNECT, $this->connection->get( 'problem' ) );

		$this->answer( 409, array( 'code' => 'WOO_PLUGIN_NOT_PLUGIN_MODE' ) );
		$api->post( '/woo-plugin/heartbeat', array() );
		$this->assertSame( Connection::PROBLEM_RECONNECT, $this->connection->get( 'problem' ) );

		$this->answer( 200, array() );
		$api->post( '/woo-plugin/heartbeat', array() );
		$this->assertNull( $this->connection->get( 'problem' ) );
	}

	public function test_clock_skew_is_detected_from_the_date_header(): void {
		$api = new Api_Client( $this->connection );
		$this->answer( 401, array( 'code' => 'WOO_PLUGIN_SIGNATURE_INVALID' ), array( 'date' => gmdate( 'D, d M Y H:i:s', time() - 900 ) . ' GMT' ) );
		$response = $api->post( '/woo-plugin/orders', array() );
		$this->assertSame( 'CLOCK_SKEW', $response->code );
		$this->assertSame( Connection::PROBLEM_CLOCK, $this->connection->get( 'problem' ) );
	}

	public function test_error_mapping(): void {
		$api = new Api_Client( $this->connection );
		$this->answer( 500, array( 'message' => 'boom' ) );
		$this->assertSame( 'HTTP_500', $api->post( '/woo-plugin/orders', array() )->code );

		$this->answer( 429, array( 'code' => 'RATE_LIMITED' ), array( 'retry-after' => '42' ) );
		$response = $api->post( '/woo-plugin/orders', array() );
		$this->assertSame( 42, $response->retry_after );
		$this->assertTrue( $response->retryable() );

		$this->answer( 400, array( 'code' => 'WOO_PLUGIN_ORDER_INVALID' ) );
		$this->assertFalse( $api->post( '/woo-plugin/orders', array() )->retryable() );

		$this->answer( 503, array( 'code' => 'WOO_PLUGIN_UNAVAILABLE' ) );
		$response = $api->post( '/woo-plugin/orders', array() );
		$this->assertSame( 'WOO_PLUGIN_UNAVAILABLE', $response->code );
		$this->assertTrue( $response->retryable() );

		$this->answer( 400, array( 'code' => 'WOO_PLUGIN_BODY_INVALID' ) );
		$this->assertFalse( $api->post( '/woo-plugin/orders', array() )->retryable() );

		$this->answer( 413 );
		$response = $api->post( '/woo-plugin/images', array() );
		$this->assertSame( 'HTTP_413', $response->code );
		$this->assertFalse( $response->retryable() );

		$this->responses[] = new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		$response          = $api->post( '/woo-plugin/orders', array() );
		$this->assertSame( 'NETWORK_ERROR', $response->code );
		$this->assertSame( 'cURL error 7: Failed to connect', $response->detail );
	}

	public function test_exchange_is_unsigned_and_multipart_is_signed_over_the_whole_body(): void {
		$api = new Api_Client( $this->connection );
		$this->answer( 200, array() );
		$api->exchange( array( 'code' => 'c' ) );
		$this->assertArrayNotHasKey( 'X-Tassyir-Signature', $this->requests[0]['args']['headers'] );
		$this->assertSame( 'https://api.tassyir.test/woo-plugin/connect/exchange', $this->requests[0]['url'] );

		$this->answer( 200, array( 'status' => 'stored' ) );
		$api->post_multipart( '/woo-plugin/images', array( 'attachmentId' => 5 ), 'a.png', 'image/png', "\x89PNG\r\n" );
		$this->assertSigned( 1 );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $this->requests[1]['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( "name=\"attachmentId\"\r\n\r\n5\r\n", $this->requests[1]['args']['body'] );
		$this->assertStringContainsString( "filename=\"a.png\"\r\nContent-Type: image/png", $this->requests[1]['args']['body'] );
	}
}
