<?php
/**
 * The stored link to one Tassyir store: ids, secret, settings, health flags (contract §B, §G).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options. Every option is saved with autoload off, so none of
 * this is loaded on storefront pages.
 */
class Connection {

	const OPTION_ID       = 'tassyir_connection_id';
	const OPTION_SECRET   = 'tassyir_secret';
	const OPTION_INFO     = 'tassyir_connection';
	const OPTION_SETTINGS = 'tassyir_settings';

	/**
	 * Transient prefix of the connect `state` (one per admin user, 15 min).
	 */
	const STATE_TRANSIENT = 'tassyir_connect_state_';

	/**
	 * Health problems the admin must act on (stored in info['problem']).
	 */
	const PROBLEM_RECONNECT = 'reconnect';
	const PROBLEM_OUTDATED  = 'outdated';
	const PROBLEM_CLOCK     = 'clock';

	/**
	 * Tassyir connection id ('' when not connected).
	 */
	public function id(): string {
		return (string) get_option( self::OPTION_ID, '' );
	}

	/**
	 * Base64url signing secret. Only Api_Client should read it; never print or log it.
	 */
	public function secret(): string {
		return (string) get_option( self::OPTION_SECRET, '' );
	}

	/**
	 * True when an id and a secret are stored.
	 */
	public function is_connected(): bool {
		return '' !== $this->id() && '' !== $this->secret();
	}

	/**
	 * Everything else we know about the connection (names, health, versions…).
	 *
	 * @return array<string, mixed>
	 */
	public function info(): array {
		$info = get_option( self::OPTION_INFO, array() );
		return is_array( $info ) ? $info : array();
	}

	/**
	 * One value of info().
	 *
	 * @param string $key     Key.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$info = $this->info();
		return array_key_exists( $key, $info ) ? $info[ $key ] : $fallback;
	}

	/**
	 * Merges values into info().
	 *
	 * @param array<string, mixed> $values Values to set (null removes the key).
	 */
	public function set( array $values ): void {
		$info = $this->info();
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				unset( $info[ $key ] );
			} else {
				$info[ $key ] = $value;
			}
		}
		update_option( self::OPTION_INFO, $info, false );
	}

	/**
	 * Settings sent by Tassyir (B3 / E), with defaults.
	 *
	 * @return array{importStatuses: string[], importFrom: string, autoCreateProducts: bool, heartbeatSeconds: int}
	 */
	public function settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$statuses = isset( $stored['importStatuses'] ) && is_array( $stored['importStatuses'] )
			? array_values( array_map( 'strval', $stored['importStatuses'] ) )
			: array( 'processing', 'on-hold', 'pending' );

		return array(
			'importStatuses'     => $statuses,
			'importFrom'         => isset( $stored['importFrom'] ) ? (string) $stored['importFrom'] : '',
			'autoCreateProducts' => isset( $stored['autoCreateProducts'] ) ? (bool) $stored['autoCreateProducts'] : true,
			'heartbeatSeconds'   => isset( $stored['heartbeatSeconds'] ) ? (int) $stored['heartbeatSeconds'] : 900,
		);
	}

	/**
	 * Stores settings received from Tassyir.
	 *
	 * @param array<string, mixed> $settings Settings object from B3 or E.
	 */
	public function update_settings( array $settings ): void {
		update_option( self::OPTION_SETTINGS, $settings, false );
	}

	/**
	 * Heartbeat interval in seconds, kept between 5 minutes and 1 day whatever Tassyir sends.
	 */
	public function heartbeat_seconds(): int {
		return max( 300, min( DAY_IN_SECONDS, $this->settings()['heartbeatSeconds'] ) );
	}

	/**
	 * Stores the one-time answer of connect/exchange (§B3). The secret is saved with autoload off.
	 *
	 * @param array<string, mixed> $body Decoded 200 body.
	 */
	public function save_exchange( array $body ): bool {
		$id     = isset( $body['connectionId'] ) ? (string) $body['connectionId'] : '';
		$secret = isset( $body['secret'] ) ? (string) $body['secret'] : '';
		if ( '' === $id || '' === $secret || '' === Signer::base64url_decode( $secret ) ) {
			return false;
		}

		update_option( self::OPTION_ID, $id, false );
		update_option( self::OPTION_SECRET, $secret, false );
		$this->update_settings( isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array() );

		$prefix = isset( $body['manualWebhooks']['deliveryUrlPrefix'] ) ? (string) $body['manualWebhooks']['deliveryUrlPrefix'] : '';
		$this->set(
			array(
				'business'     => self::named( $body['business'] ?? null ),
				'store'        => self::named( $body['store'] ?? null ),
				'upgradedFrom' => isset( $body['upgradedFrom'] ) ? (string) $body['upgradedFrom'] : '',
				'connectedAt'  => time(),
				'problem'      => null,
				'problemData'  => null,
			)
		);
		// A reconnect without a prefix keeps the one from the original upgrade.
		if ( '' !== $prefix ) {
			$this->set( array( 'webhookPrefix' => esc_url_raw( $prefix ) ) );
		}
		return true;
	}

	/**
	 * Deletes every option of the connection (disconnect / uninstall). Order meta is kept.
	 */
	public function forget(): void {
		delete_option( self::OPTION_ID );
		delete_option( self::OPTION_SECRET );
		delete_option( self::OPTION_INFO );
		delete_option( self::OPTION_SETTINGS );
	}

	/**
	 * Records what a signed answer says about the connection's health: a success clears any
	 * problem, a refused signature or another mode asks for Reconnect, 426 asks for an update.
	 *
	 * @param Api_Response $response Answer of a signed request.
	 */
	public function note_signed_response( Api_Response $response ): void {
		if ( $response->ok() ) {
			if ( null !== $this->get( 'problem' ) ) {
				$this->set(
					array(
						'problem'     => null,
						'problemData' => null,
					)
				);
			}
			return;
		}

		$problem = null;
		$data    = '';
		if ( Api_Response::CLOCK_SKEW === $response->code ) {
			$problem = self::PROBLEM_CLOCK;
			$data    = $response->detail;
		} elseif ( in_array( $response->code, array( 'WOO_PLUGIN_SIGNATURE_INVALID', 'WOO_PLUGIN_NOT_PLUGIN_MODE' ), true ) ) {
			$problem = self::PROBLEM_RECONNECT;
		} elseif ( 'WOO_PLUGIN_VERSION_UNSUPPORTED' === $response->code ) {
			$problem = self::PROBLEM_OUTDATED;
			$data    = isset( $response->body['minVersion'] ) ? (string) $response->body['minVersion'] : '';
		}

		if ( null !== $problem ) {
			$this->set(
				array(
					'problem'     => $problem,
					'problemData' => $data,
				)
			);
		}
	}

	/**
	 * Creates and remembers (15 min, per admin user) the random `state` of a connect attempt.
	 */
	public function create_state(): string {
		$state = Signer::random_token( 32 ); // 43 chars of [A-Za-z0-9_-].
		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		return $state;
	}

	/**
	 * Checks the `state` returned with the browser redirect against the one we created. Single
	 * use: the stored state is deleted whatever the result.
	 *
	 * @param string $state State from the query string.
	 */
	public function check_state( string $state ): bool {
		$key      = self::STATE_TRANSIENT . get_current_user_id();
		$expected = get_transient( $key );
		delete_transient( $key );

		if ( ! is_string( $expected ) || '' === $expected || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $state ) ) {
			return false;
		}
		return hash_equals( $expected, $state );
	}

	/**
	 * URL of the dashboard approval page (§B1).
	 *
	 * @param string $state State from create_state().
	 */
	public static function connect_url( string $state ): string {
		return add_query_arg(
			array(
				'site'   => rawurlencode( home_url() ),
				'return' => rawurlencode( self::return_url() ),
				'state'  => rawurlencode( $state ),
				'v'      => rawurlencode( TASSYIR_WC_VERSION ),
			),
			Plugin::app_url() . '/connect/woocommerce'
		);
	}

	/**
	 * Whether the return URL (wp-admin) is on the same site as the site URL, with the same rule
	 * as the dashboard and backend (§B1): same scheme and port, hosts lower-cased with one
	 * leading "www." ignored. When they differ (e.g. wp-admin on another subdomain, or on https
	 * while the site is on http), connecting cannot work.
	 *
	 * @param string $site_url   home_url().
	 * @param string $return_url return_url().
	 */
	public static function same_site_host( string $site_url, string $return_url ): bool {
		$normalize = static function ( string $url ): string {
			$parts  = wp_parse_url( $url );
			$host   = strtolower( is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '' );
			$host   = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
			$scheme = strtolower( is_array( $parts ) && isset( $parts['scheme'] ) ? $parts['scheme'] : '' );
			$port   = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
			return '' === $host ? '' : $scheme . '://' . $host . ':' . $port;
		};
		$site      = $normalize( $site_url );
		return '' !== $site && $site === $normalize( $return_url );
	}

	/**
	 * Where the dashboard sends the browser back: our settings page.
	 */
	public static function return_url(): string {
		return admin_url( 'admin.php?page=tassyir' );
	}

	/**
	 * Keeps only {id, name} of a business/store object.
	 *
	 * @param mixed $value Object from the API.
	 * @return array{id: string, name: string}
	 */
	private static function named( $value ): array {
		return array(
			'id'   => is_array( $value ) && isset( $value['id'] ) ? (string) $value['id'] : '',
			'name' => is_array( $value ) && isset( $value['name'] ) ? sanitize_text_field( (string) $value['name'] ) : '',
		);
	}
}
