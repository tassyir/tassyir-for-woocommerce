<?php
/**
 * Suggested privacy policy text.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Admin\Privacy;

final class PrivacyTest extends Integration_TestCase {

	public function test_policy_text_is_added_to_the_privacy_guide(): void {
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		}
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// WordPress accepts policy text only in wp-admin, during admin_init: run admin_init with
		// nothing but our callback hooked.
		global $wp_filter;
		set_current_screen( 'dashboard' );
		$saved = $wp_filter['admin_init'] ?? null;
		unset( $wp_filter['admin_init'] );
		Privacy::register_hooks();
		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$wp_filter['admin_init']   = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the hooks removed above.
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset what set_current_screen() set.

		$content = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		$ours    = wp_list_filter( $content, array( 'plugin_name' => 'Tassyir for WooCommerce' ) );
		$this->assertCount( 1, $ours );
		$policy = (string) current( $ours )['policy_text'];
		$this->assertStringContainsString( 'https://tassyir.io/en/privacy-policy', $policy );
		$this->assertStringContainsString( '<p>', $policy );
		$this->assertStringContainsString( 'phone number', $policy );
	}
}
