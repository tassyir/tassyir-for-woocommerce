<?php
/**
 * Product push, needImages and image upload (§D).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Failure_Log;
use Tassyir\WooCommerce\Plugin;
use Tassyir\WooCommerce\Product_Sync;

final class ProductSyncTest extends Integration_TestCase {

	/**
	 * Product sync under test.
	 *
	 * @var Product_Sync
	 */
	private $sync;

	public function set_up(): void {
		parent::set_up();
		$this->sync = Plugin::instance()->products;
		Failure_Log::clear();
	}

	public function test_saving_a_product_queues_one_delayed_push(): void {
		$product = $this->product();
		$product->set_regular_price( '2600' );
		$product->save();
		$jobs = $this->pending( Product_Sync::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $product->get_id(), 1 ), $jobs[0]['args'] );
		$this->assertEqualsWithDelta( time() + 60, $jobs[0]['at'], 3 );
	}

	public function test_push_sends_product_with_variations_and_queues_images(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'T-shirt' );
		$parent->set_status( 'publish' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '1200' );
		$variation->save();

		$this->answer(
			200,
			array(
				'results'    => array(
					array(
						'wooProductId'     => $parent->get_id(),
						'status'           => 'created',
						'tassyirProductId' => '55',
						'reason'           => null,
					),
				),
				'needImages' => array(
					array(
						'attachmentId'   => 456,
						'wooProductId'   => $parent->get_id(),
						'wooVariationId' => 0,
						'role'           => 'main',
					),
					array(
						'attachmentId'   => 457,
						'wooProductId'   => $parent->get_id(),
						'wooVariationId' => 0,
						'role'           => 'not-a-role',
					),
				),
			)
		);
		$this->sync->push_product( $parent->get_id(), 1 );

		$this->assertSigned();
		$body = $this->sent_body();
		$this->assertSame( 'save', $body['reason'] );
		$this->assertSame( $parent->get_id(), $body['products'][0]['product']['id'] );
		$this->assertSame( $variation->get_id(), $body['products'][0]['variations'][0]['id'] );

		$jobs = $this->pending( Product_Sync::IMAGE_HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( 456, $parent->get_id(), 0, 'main', 1 ), $jobs[0]['args'] );
	}

	public function test_full_sync_sends_batches_of_twenty(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->product( 'P' . $i );
		}
		$this->sync->start_full_sync();
		as_unschedule_all_actions( Product_Sync::BATCH_HOOK ); // We run the batches by hand.
		$this->answer( 200, array( 'results' => array() ) );
		$this->sync->sync_batch( 1, 1 );
		$this->assertCount( 20, $this->sent_body()['products'] );
		$this->assertSame( 'full_sync', $this->sent_body()['reason'] );
		$this->assertSame( array( 2, 1 ), $this->pending( Product_Sync::BATCH_HOOK )[0]['args'] );

		$this->answer( 200, array( 'results' => array() ) );
		$this->sync->sync_batch( 2, 1 );
		$this->assertCount( 1, $this->sent_body( 1 )['products'] );
		$progress = Plugin::instance()->connection->get( 'productSync' );
		$this->assertSame( 21, $progress['sent'] );
		$this->assertGreaterThan( 0, $progress['finishedAt'] );
	}

	public function test_delete_is_pushed(): void {
		$product = $this->product();
		wp_trash_post( $product->get_id() );
		$this->assertCount( 1, $this->pending( Product_Sync::DELETE_HOOK ) );

		$this->answer( 200, array( 'results' => array() ) );
		$this->sync->push_delete( $product->get_id(), 1 );
		$this->assertSame(
			array(
				'products' => array( array( 'product' => array( 'id' => $product->get_id() ) ) ),
				'reason'   => 'delete',
			),
			$this->sent_body()
		);
	}

	public function test_image_upload_sends_original_file_as_multipart(): void {
		$product = $this->product();
		$upload  = wp_upload_bits( 'photo.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertEmpty( $upload['error'] );
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png' ), $upload['file'], $product->get_id() );

		$this->answer(
			200,
			array(
				'status' => 'stored',
				'url'    => 'https://cdn.tassyir.io/x.png',
			)
		);
		$this->sync->upload_image( $attachment, $product->get_id(), 0, 'main', 1 );

		$this->assertSame( 'https://api.tassyir.test/woo-plugin/images', $this->requests[0]['url'] );
		$this->assertSigned();
		$body = $this->requests[0]['args']['body'];
		$this->assertStringContainsString( "name=\"attachmentId\"\r\n\r\n" . $attachment . "\r\n", $body );
		$this->assertStringContainsString( "name=\"wooVariationId\"\r\n\r\n0\r\n", $body );
		$this->assertStringContainsString( "name=\"role\"\r\n\r\nmain\r\n", $body );
		$this->assertMatchesRegularExpression( '/name="modified"\r\n\r\n\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\r\n/', $body );
		$this->assertStringContainsString( 'Content-Type: image/png', $body );
		$this->assertStringContainsString( "\x89PNG", $body );
		$this->assertSame( array(), Failure_Log::all() );

		// 404: the product is not linked on Tassyir's side → final, logged.
		$this->answer( 404, array( 'code' => 'WOO_PLUGIN_PRODUCT_UNKNOWN' ) );
		$this->sync->upload_image( $attachment, $product->get_id(), 0, 'main', 1 );
		$this->assertSame( 'WOO_PLUGIN_PRODUCT_UNKNOWN', Failure_Log::all()[0]['code'] );
		$this->assertCount( 0, $this->pending( Product_Sync::IMAGE_HOOK ) );
	}

	public function test_non_image_attachment_is_refused_locally(): void {
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf' ), '/tmp/x.pdf' );
		$this->sync->upload_image( $attachment, 1, 0, 'main', 1 );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'IMAGE_TYPE_UNSUPPORTED', Failure_Log::all()[0]['code'] );
	}
}
