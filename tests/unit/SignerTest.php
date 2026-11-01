<?php
/**
 * Contract §A signing, against a fixed vector the backend tests too.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tassyir\WooCommerce\Signer;

/**
 * Test vector (also in the README "Signing test vector" section):
 * secret     = AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8   (base64url of bytes 0x00..0x1f)
 * timestamp  = 1761700000
 * nonce      = 00112233445566778899aabbccddeeff
 * POST /woo-plugin/orders
 * body       = {"order":{"id":123},"channel":"plugin","attempt":1}
 * signature  = iXSgCBkAihTSN9916OERE9b/ERJD5Skske6K1riEV9s=
 */
final class SignerTest extends TestCase {

	const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
	const TS     = 1761700000;
	const NONCE  = '00112233445566778899aabbccddeeff';
	const BODY   = '{"order":{"id":123},"channel":"plugin","attempt":1}';

	public function test_canonical_string_has_five_lines_and_body_hash(): void {
		$expected = "1761700000\n00112233445566778899aabbccddeeff\nPOST\n/woo-plugin/orders\n"
			. '01cd852686520798d2345b743eee4097bcddc4a7e5f0dde072cef33afec0f980';
		$this->assertSame( $expected, Signer::canonical( self::TS, self::NONCE, 'post', '/woo-plugin/orders', self::BODY ) );
	}

	public function test_known_vector(): void {
		$canonical = Signer::canonical( self::TS, self::NONCE, 'POST', '/woo-plugin/orders', self::BODY );
		$this->assertSame( 'iXSgCBkAihTSN9916OERE9b/ERJD5Skske6K1riEV9s=', Signer::signature( self::SECRET, $canonical ) );
	}

	/**
	 * The vector published by the backend (PluginSignatureGuard spec).
	 */
	public function test_backend_vector(): void {
		$canonical = Signer::canonical( 1761742800, '0123456789abcdef0123456789abcdef', 'POST', '/woo-plugin/orders', '{"order":{"id":48954},"channel":"plugin","attempt":1}' );
		$this->assertSame( 'fa3MFlIj4Upaw0lSvj6PEBOXcgasHfyqn1RnhCf8g/s=', Signer::signature( self::SECRET, $canonical ) );
	}

	public function test_known_vector_empty_object_body(): void {
		$canonical = Signer::canonical( self::TS, self::NONCE, 'POST', '/woo-plugin/disconnect', '{}' );
		$this->assertSame( '9BDzs3ZLva2OhiCuw0bsZcO4DzjvEiV2v9K7WMOAXKs=', Signer::signature( self::SECRET, $canonical ) );
	}

	public function test_empty_body_hashes_the_empty_string(): void {
		$lines = explode( "\n", Signer::canonical( self::TS, self::NONCE, 'GET', '/x', '' ) );
		$this->assertSame( 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $lines[4] );
	}

	public function test_headers(): void {
		$headers = Signer::headers( '1234', self::SECRET, 'POST', '/woo-plugin/orders', self::BODY, self::TS, self::NONCE );
		$this->assertSame(
			array(
				'X-Tassyir-Connection' => '1234',
				'X-Tassyir-Timestamp'  => '1761700000',
				'X-Tassyir-Nonce'      => self::NONCE,
				'X-Tassyir-Signature'  => 'iXSgCBkAihTSN9916OERE9b/ERJD5Skske6K1riEV9s=',
				'X-Tassyir-Plugin'     => TASSYIR_WC_VERSION,
			),
			$headers
		);
		$this->assertStringNotContainsString( self::SECRET, implode( '|', $headers ) );
	}

	public function test_nonce_is_32_lowercase_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Signer::new_nonce() );
		$this->assertNotSame( Signer::new_nonce(), Signer::new_nonce() );
	}

	public function test_base64url_decode_with_and_without_padding(): void {
		$this->assertSame( range( 0, 31 ), array_values( unpack( 'C*', Signer::base64url_decode( self::SECRET ) ) ) );
		$this->assertSame( "\xfb\xff", Signer::base64url_decode( '-_8' ) );
		$this->assertSame( "\xfb\xff", Signer::base64url_decode( '-_8=' ) );
		$this->assertSame( '', Signer::base64url_decode( '%%%' ) );
	}

	public function test_random_token_is_url_safe(): void {
		$token = Signer::random_token( 32 );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $token );
	}
}
