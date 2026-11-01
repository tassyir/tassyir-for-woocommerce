<?php
/**
 * Product queue and image uploads (contract §D). Phase 1 is create-only on Tassyir's side.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Saved products are pushed (REST v3 JSON + variations); "Sync all products" pushes every
 * published product in batches of 20; images Tassyir asks for (`needImages`) are uploaded one
 * job per image, from the original file on disk.
 */
class Product_Sync {

	const HOOK        = 'tassyir_push_product';    // Args: [product_id, attempt].
	const DELETE_HOOK = 'tassyir_delete_product';  // Args: [product_id, attempt].
	const BATCH_HOOK  = 'tassyir_sync_products';   // Args: [page, attempt].
	const IMAGE_HOOK  = 'tassyir_upload_image';    // Args: [attachment_id, product_id, variation_id, role, attempt].

	const BATCH_SIZE      = 20;
	const MAX_ATTEMPTS    = 8; // Tries of one product/image job (≈ 20 h of back-off).
	const MAX_IMAGE_BYTES = 10485760; // 10 MB.
	const IMAGE_TYPES     = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
	const IMAGE_ROLES     = array( 'main', 'gallery', 'variation' );

	/**
	 * Saves are pushed after this many seconds, so the several save hooks of one edit collapse
	 * into a single push.
	 */
	const SAVE_DELAY = 60;

	/**
	 * HTTP client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the product sync.
	 *
	 * @param Api_Client $api        HTTP client.
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Api_Client $api, Connection $connection ) {
		$this->api        = $api;
		$this->connection = $connection;
	}

	/**
	 * Product save/new/delete hooks and the queue jobs.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'untrashed_post', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_product_deleted' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_deleted' ), 20, 1 );

		add_action( self::HOOK, array( $this, 'push_product' ), 10, 2 );
		add_action( self::DELETE_HOOK, array( $this, 'push_delete' ), 10, 2 );
		add_action( self::BATCH_HOOK, array( $this, 'sync_batch' ), 10, 2 );
		add_action( self::IMAGE_HOOK, array( $this, 'upload_image' ), 10, 5 );
	}

	/**
	 * A product or variation was saved: queue its (parent) product.
	 *
	 * @param int $id Product, variation or post id.
	 */
	public function on_product_saved( $id ): void {
		if ( ! $this->connection->is_connected() || ! in_array( get_post_type( (int) $id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$product = wc_get_product( (int) $id );
		if ( $product instanceof \WC_Product_Variation ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( $product instanceof \WC_Product && 'publish' === $product->get_status() ) {
			Queue::schedule_once( self::HOOK, array( $product->get_id(), 1 ), self::SAVE_DELAY );
		}
	}

	/**
	 * A product was trashed or deleted: tell Tassyir (its link is closed, the Tassyir product kept).
	 *
	 * @param int $post_id Post id.
	 */
	public function on_product_deleted( $post_id ): void {
		if ( ! $this->connection->is_connected() || 'product' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		as_unschedule_all_actions( self::HOOK, array( (int) $post_id, 1 ), Plugin::GROUP );
		Queue::schedule_once( self::DELETE_HOOK, array( (int) $post_id, 1 ) );
	}

	/**
	 * Starts "Sync all products".
	 */
	public function start_full_sync(): void {
		as_unschedule_all_actions( self::BATCH_HOOK, array(), Plugin::GROUP );
		$this->connection->set(
			array(
				'productSync' => array(
					'startedAt'  => time(),
					'finishedAt' => 0,
					'sent'       => 0,
				),
			)
		);
		Queue::schedule( self::BATCH_HOOK, array( 1, 1 ) );
	}

	/**
	 * Job: push one saved product.
	 *
	 * @param int $product_id Product id.
	 * @param int $attempt    Try number, from 1.
	 */
	public function push_product( $product_id, $attempt = 1 ): void {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return;
		}
		$response = $this->send( array( $product ), 'save' );
		$this->maybe_retry( $response, self::HOOK, array( (int) $product_id ), (int) $attempt, 'product', (int) $product_id );
	}

	/**
	 * Job: tell Tassyir a product was deleted.
	 *
	 * @param int $product_id Product id.
	 * @param int $attempt    Try number, from 1.
	 */
	public function push_delete( $product_id, $attempt = 1 ): void {
		if ( ! $this->connection->is_connected() ) {
			return;
		}
		$response = $this->api->post(
			'/woo-plugin/products',
			array(
				'products' => array( array( 'product' => array( 'id' => (int) $product_id ) ) ),
				'reason'   => 'delete',
			)
		);
		$this->maybe_retry( $response, self::DELETE_HOOK, array( (int) $product_id ), (int) $attempt, 'product', (int) $product_id );
	}

	/**
	 * Job: one batch of "Sync all products", then the next batch.
	 *
	 * @param int $page    Page number, from 1.
	 * @param int $attempt Try number, from 1.
	 */
	public function sync_batch( $page, $attempt = 1 ): void {
		$page     = max( 1, (int) $page );
		$ids      = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::BATCH_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		$ids      = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$products = array_filter( array_map( 'wc_get_product', $ids ) );

		$progress = (array) $this->connection->get( 'productSync', array() );
		if ( ! empty( $products ) ) {
			$response = $this->send( array_values( $products ), 'full_sync' );
			if ( ! $response->ok() ) {
				$this->maybe_retry( $response, self::BATCH_HOOK, array( $page ), (int) $attempt, 'product_sync', $page );
				return;
			}
			$progress['sent'] = (int) ( $progress['sent'] ?? 0 ) + count( $products );
		}

		if ( count( $ids ) === self::BATCH_SIZE ) {
			Queue::schedule( self::BATCH_HOOK, array( $page + 1, 1 ), 2 );
		} else {
			$progress['finishedAt'] = time();
		}
		$this->connection->set( array( 'productSync' => $progress ) );
	}

	/**
	 * Posts products with their variations (§D1) and queues the images Tassyir asks for.
	 *
	 * @param \WC_Product[] $products Products (not variations), at most 20.
	 * @param string        $reason   save | full_sync.
	 */
	public function send( array $products, string $reason ): Api_Response {
		$items = array();
		foreach ( $products as $product ) {
			$variations = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( $variation instanceof \WC_Product_Variation ) {
						$variations[] = Rest_Renderer::variation( $variation );
					}
				}
			}
			$items[] = array(
				'product'    => Rest_Renderer::product( $product ),
				'variations' => $variations,
			);
		}

		$response = $this->api->post(
			'/woo-plugin/products',
			array(
				'products' => $items,
				'reason'   => $reason,
			)
		);

		if ( $response->ok() ) {
			$this->handle_results( $response->body );
		}
		return $response;
	}

	/**
	 * Logs failed products and queues image uploads from a §D1 answer.
	 *
	 * @param array<string, mixed> $body Decoded answer.
	 */
	public function handle_results( array $body ): void {
		$results = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
		foreach ( $results as $result ) {
			if ( is_array( $result ) && 'failed' === ( $result['status'] ?? '' ) ) {
				Failure_Log::add( 'product', (int) ( $result['wooProductId'] ?? 0 ), (string) ( $result['reason'] ?? 'FAILED' ) );
			}
		}

		$images = isset( $body['needImages'] ) && is_array( $body['needImages'] ) ? array_slice( $body['needImages'], 0, 50 ) : array();
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) || ! in_array( $image['role'] ?? '', self::IMAGE_ROLES, true ) ) {
				continue;
			}
			$args = array(
				absint( $image['attachmentId'] ?? 0 ),
				absint( $image['wooProductId'] ?? 0 ),
				absint( $image['wooVariationId'] ?? 0 ),
				(string) $image['role'],
				1,
			);
			if ( $args[0] > 0 && $args[1] > 0 ) {
				Queue::schedule_once( self::IMAGE_HOOK, $args );
			}
		}
	}

	/**
	 * Job: upload one image's bytes (§D2).
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param int    $product_id    Product id.
	 * @param int    $variation_id  Variation id (0 = the product itself).
	 * @param string $role          main | gallery | variation.
	 * @param int    $attempt       Try number, from 1.
	 */
	public function upload_image( $attachment_id, $product_id, $variation_id, $role, $attempt = 1 ): void {
		$attachment_id = (int) $attachment_id;
		$file          = self::image_file( $attachment_id );
		if ( is_string( $file ) ) {
			Failure_Log::add( 'image', $attachment_id, $file );
			return;
		}

		$modified = (int) get_post_modified_time( 'U', true, $attachment_id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file from the uploads folder, not a URL.
		$bytes = file_get_contents( $file['path'] );
		if ( false === $bytes ) {
			Failure_Log::add( 'image', $attachment_id, 'IMAGE_FILE_UNREADABLE' );
			return;
		}

		$response = $this->api->post_multipart(
			'/woo-plugin/images',
			array(
				'attachmentId'   => $attachment_id,
				'wooProductId'   => (int) $product_id,
				'wooVariationId' => (int) $variation_id,
				'role'           => (string) $role,
				'modified'       => Plugin::iso( $modified ),
			),
			wp_basename( $file['path'] ),
			$file['mime'],
			$bytes
		);
		$this->maybe_retry(
			$response,
			self::IMAGE_HOOK,
			array( $attachment_id, (int) $product_id, (int) $variation_id, (string) $role ),
			(int) $attempt,
			'image',
			$attachment_id
		);
	}

	/**
	 * The file to upload for an attachment: the original upload when it is ≤ 10 MB, else
	 * WordPress's scaled copy. Returns an error code string when there is nothing valid.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{path: string, mime: string}|string
	 */
	public static function image_file( int $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::IMAGE_TYPES, true ) ) {
			return 'IMAGE_TYPE_UNSUPPORTED';
		}

		$candidates = array( wp_get_original_image_path( $attachment_id ), get_attached_file( $attachment_id ) );
		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && '' !== $path && is_readable( $path ) && filesize( $path ) <= self::MAX_IMAGE_BYTES ) {
				return array(
					'path' => $path,
					'mime' => $mime,
				);
			}
		}
		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
				return 'IMAGE_TOO_LARGE';
			}
		}
		return 'IMAGE_FILE_MISSING';
	}

	/**
	 * After a product/image job: schedule the next try of a transient failure, log a final one.
	 *
	 * @param Api_Response      $response  Answer.
	 * @param string            $hook      Job hook.
	 * @param array<int, mixed> $args      Job args without the attempt.
	 * @param int               $attempt   This try's number.
	 * @param string            $type      Failure-log type.
	 * @param int               $object_id Failure-log id.
	 */
	private function maybe_retry( Api_Response $response, string $hook, array $args, int $attempt, string $type, int $object_id ): void {
		if ( $response->ok() ) {
			return;
		}
		if ( 429 === $response->status ) {
			$args[] = $attempt; // A rate limit is not a failure: same attempt, later.
			Queue::schedule( $hook, $args, $response->retry_after > 0 ? $response->retry_after : 60 );
			return;
		}
		if ( $response->retryable() && $attempt < self::MAX_ATTEMPTS ) {
			$args[] = $attempt + 1;
			Queue::schedule( $hook, $args, Backoff::delay( $attempt ) );
			return;
		}
		Failure_Log::add( $type, $object_id, $response->code, $response->detail );
	}
}
