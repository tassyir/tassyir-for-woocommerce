<?php
/**
 * After activation (§G): one redirect to the Tassyir page, then a dismissible Connect notice.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Admin\Onboarding;
use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Plugin;

final class OnboardingTest extends Integration_TestCase {

	/**
	 * Onboarding under test.
	 *
	 * @var Onboarding
	 */
	private $onboarding;

	public function set_up(): void {
		parent::set_up();
		( new Connection() )->forget();
		delete_transient( Onboarding::REDIRECT_TRANSIENT );
		$this->onboarding = new Onboarding( new Connection() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		unset( $_GET['activate-multi'], $_REQUEST['_wpnonce'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset what set_current_screen() set.
		parent::tear_down();
	}

	/**
	 * Connects the site (ids + secret).
	 */
	private function connect(): void {
		update_option( 'tassyir_connection_id', '777', false );
		update_option( 'tassyir_secret', self::SECRET, false );
	}

	public function test_activation_redirects_to_the_tassyir_page_once(): void {
		Onboarding::queue_redirect( false, false );

		$this->assertSame( admin_url( 'admin.php?page=tassyir' ), $this->onboarding->redirect_target() );
		$this->assertNull( $this->onboarding->redirect_target(), 'only once' );
		$this->assertFalse( get_transient( Onboarding::REDIRECT_TRANSIENT ) );
	}

	public function test_no_redirect_for_bulk_cli_network_or_connected_sites(): void {
		Onboarding::queue_redirect( false, true );
		$this->assertFalse( get_transient( Onboarding::REDIRECT_TRANSIENT ), 'WP-CLI' );

		Onboarding::queue_redirect( true, false );
		$this->assertFalse( get_transient( Onboarding::REDIRECT_TRANSIENT ), 'network-wide' );

		Onboarding::queue_redirect( false, false );
		$_GET['activate-multi'] = 'true';
		$this->assertNull( $this->onboarding->redirect_target(), 'bulk activation' );
		$this->assertFalse( get_transient( Onboarding::REDIRECT_TRANSIENT ), 'consumed, not kept for later' );
		unset( $_GET['activate-multi'] );

		Onboarding::queue_redirect( false, false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNull( $this->onboarding->redirect_target(), 'user without manage_woocommerce' );

		$this->connect();
		Plugin::activate( false );
		$this->assertFalse( get_transient( Onboarding::REDIRECT_TRANSIENT ), 're-activation of a connected site' );
		$this->assertNotEmpty( $this->pending( 'tassyir_heartbeat' ) );
	}

	public function test_notice_until_connected_or_dismissed(): void {
		$this->assertTrue( $this->onboarding->notice_visible() );
		ob_start();
		$this->onboarding->render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=tassyir' ) ) . '"', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );

		set_current_screen( 'woocommerce_page_tassyir' );
		$this->assertFalse( $this->onboarding->notice_visible(), 'not on the Tassyir page itself' );
		set_current_screen( 'dashboard' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $this->onboarding->notice_visible(), 'not for users without manage_woocommerce' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->connect();
		$this->assertFalse( $this->onboarding->notice_visible(), 'hidden once connected' );
	}

	public function test_notice_script_is_a_file_enqueued_only_with_the_notice(): void {
		$this->onboarding->enqueue_assets();
		$this->assertTrue( wp_script_is( 'tassyir-connect-notice', 'enqueued' ) );
		$this->assertStringEndsWith( 'assets/js/connect-notice.js', wp_scripts()->registered['tassyir-connect-notice']->src );
		wp_dequeue_script( 'tassyir-connect-notice' );

		$this->connect();
		$this->onboarding->enqueue_assets();
		$this->assertFalse( wp_script_is( 'tassyir-connect-notice', 'enqueued' ), 'no notice, no script' );

		ob_start();
		( new Onboarding( new Connection() ) )->render_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_dismissal_is_per_user(): void {
		$first                = get_current_user_id();
		$_REQUEST['_wpnonce'] = wp_create_nonce( Onboarding::DISMISS_ACTION );
		$redirect             = static function ( $location ) {
			throw new \RuntimeException( esc_html( (string) $location ) );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			$this->onboarding->handle_dismiss();
			$this->fail( 'handle_dismiss() redirects back' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}

		$this->assertSame( '1', get_user_meta( $first, Onboarding::DISMISSED_META, true ) );
		$this->assertFalse( $this->onboarding->notice_visible(), 'dismissed for this user' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->onboarding->notice_visible(), 'still shown to another admin' );
	}
}
