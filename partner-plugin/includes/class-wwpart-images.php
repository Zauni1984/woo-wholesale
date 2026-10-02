<?php
/**
 * Product images: download, queue and the checker that catches up later.
 *
 * Images are the part of a sync that fails most often - a slow supplier, a
 * timeout, a temporarily missing file. So an image is never required for the
 * sync to succeed: whatever could not be fetched stays in a queue on the
 * product, is retried with a growing delay in the background, and can be
 * pushed again by hand from the admin screen.
 *
 * On top of the queue there is a checker. It walks the products that came from
 * the supplier and compares what should be there against what is really in the
 * media library, so an image that was deleted locally, or never arrived at all,
 * is queued again instead of silently staying missing.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Image handling (static).
 */
class WWPart_Images {

	/**
	 * Cron hook of the background retry.
	 */
	const CRON_HOOK = 'wwpart_image_check';

	/**
	 * Images downloaded per product and call.
	 */
	const PER_PRODUCT = 4;

	/**
	 * Products handled per cron run.
	 */
	const CRON_PRODUCTS = 10;

	/**
	 * Products verified per cron run.
	 */
	const VERIFY_PRODUCTS = 25;

	/**
	 * Attempts after which an image is reported as failed.
	 */
	const MAX_ATTEMPTS = 8;

	/**
	 * Largest image accepted, in bytes.
	 */
	const MAX_BYTES = 12582912;

	/**
	 * Option that remembers how far the checker has walked.
	 */
	const CURSOR_OPTION = 'wwpart_image_cursor';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	/**
	 * Delay before the next attempt of an image.
	 *
	 * @param int $attempts Attempts made so far.
	 * @return int Seconds.
	 */
	public static function backoff( $attempts ) {
		$steps = array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS );
		$index = max( 0, (int) $attempts - 1 );

		return isset( $steps[ $index ] ) ? $steps[ $index ] : DAY_IN_SECONDS;
	}

	/**
	 * Image map (hash => attachment id) of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function map( $product_id ) {
		$map = get_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_MAP, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Store the image map of a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $map        Map.
	 */
	private static function set_map( $product_id, array $map ) {
		update_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_MAP, $map );
	}

	/**
	 * Download queue of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array[]
	 */
	public static function queue( $product_id ) {
		$queue = get_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_QUEUE, true );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Store the queue of a product and keep the state meta in sync.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $queue      Queue.
	 */
	private static function set_queue( $product_id, array $queue ) {
		$queue = array_values( $queue );

		if ( empty( $queue ) ) {
			delete_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_QUEUE );
			update_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_STATE, 'ok' );
			return;
		}

		$failed = true;
		foreach ( $queue as $entry ) {
			if ( (int) $entry['attempts'] < self::MAX_ATTEMPTS ) {
				$failed = false;
				break;
			}
		}

		update_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_QUEUE, $queue );
		update_post_meta( (int) $product_id, WWPart_Product::META_IMAGE_STATE, $failed ? 'failed' : 'pending' );
	}

	/**
	 * Every image a product should have, flattened from its payload.
	 *
	 * @param array $payload Product payload.
	 * @return array[]
	 */
	public static function wanted_images( array $payload ) {
		$wanted = array();

		foreach ( (array) ( isset( $payload['images'] ) ? $payload['images'] : array() ) as $image ) {
			if ( empty( $image['src'] ) || empty( $image['hash'] ) ) {
				continue;
			}
			$wanted[] = array(
				'src'       => (string) $image['src'],
				'hash'      => (string) $image['hash'],
				'alt'       => isset( $image['alt'] ) ? (string) $image['alt'] : '',
				'title'     => isset( $image['title'] ) ? (string) $image['title'] : '',
				'position'  => isset( $image['position'] ) ? (int) $image['position'] : 0,
				'variation' => 0,
			);
		}

		foreach ( (array) ( isset( $payload['variations'] ) ? $payload['variations'] : array() ) as $variation ) {
			if ( empty( $variation['image']['src'] ) || empty( $variation['image']['hash'] ) ) {
				continue;
			}
			$wanted[] = array(
				'src'       => (string) $variation['image']['src'],
				'hash'      => (string) $variation['image']['hash'],
				'alt'       => isset( $variation['image']['alt'] ) ? (string) $variation['image']['alt'] : '',
				'title'     => isset( $variation['image']['title'] ) ? (string) $variation['image']['title'] : '',
				'position'  => 0,
				'variation' => isset( $variation['master_id'] ) ? (int) $variation['master_id'] : 0,
			);
		}

		return $wanted;
	}

	/**
	 * Queue the images of a product that are not here yet.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $payload    Product payload.
	 * @return int Number of queued images.
	 */
	public static function enqueue_missing( $product_id, array $payload ) {
		$map     = self::map( $product_id );
		$queue   = self::queue( $product_id );
		$indexed = array();

		foreach ( $queue as $entry ) {
			$indexed[ $entry['hash'] ] = $entry;
		}

		foreach ( self::wanted_images( $payload ) as $image ) {
			$attachment_id = isset( $map[ $image['hash'] ] ) ? (int) $map[ $image['hash'] ] : 0;

			if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
				continue;
			}

			if ( $attachment_id ) {
				// The attachment is gone, so the hash has to be fetched again.
				unset( $map[ $image['hash'] ] );
			}

			if ( isset( $indexed[ $image['hash'] ] ) ) {
				// Keep the attempt counter of an entry that is already waiting.
				$indexed[ $image['hash'] ]['src']       = $image['src'];
				$indexed[ $image['hash'] ]['alt']       = $image['alt'];
				$indexed[ $image['hash'] ]['position']  = $image['position'];
				$indexed[ $image['hash'] ]['variation'] = $image['variation'];
				continue;
			}

			$indexed[ $image['hash'] ] = array_merge(
				$image,
				array(
					'attempts'    => 0,
					'retry_after' => 0,
					'last_error'  => '',
				)
			);
		}

		self::set_map( $product_id, $map );
		self::set_queue( $product_id, array_values( $indexed ) );

		return count( $indexed );
	}

	/**
	 * Download the queued images of one product.
	 *
	 * @param int  $product_id Product ID.
	 * @param int  $limit      Images per call.
	 * @param bool $force      Ignore the retry delay and reset failed entries.
	 * @return array downloaded, failed, remaining
	 */
	public static function process_product( $product_id, $limit = self::PER_PRODUCT, $force = false ) {
		$queue = self::queue( $product_id );

		if ( empty( $queue ) ) {
			return array(
				'downloaded' => 0,
				'failed'     => 0,
				'remaining'  => 0,
			);
		}

		$map        = self::map( $product_id );
		$now        = time();
		$downloaded = 0;
		$failed     = 0;
		$handled    = 0;

		foreach ( $queue as $index => $entry ) {
			if ( $handled >= $limit ) {
				break;
			}

			if ( ! $force && (int) $entry['retry_after'] > $now ) {
				continue;
			}

			if ( ! $force && (int) $entry['attempts'] >= self::MAX_ATTEMPTS ) {
				continue;
			}

			++$handled;

			$attachment_id = self::sideload( $entry, $product_id );

			if ( is_wp_error( $attachment_id ) ) {
				++$failed;
				$attempts                     = (int) $entry['attempts'] + 1;
				$queue[ $index ]['attempts']  = $attempts;
				$queue[ $index ]['last_error'] = $attachment_id->get_error_message();
				$queue[ $index ]['retry_after'] = $now + self::backoff( $attempts );
				continue;
			}

			$map[ $entry['hash'] ] = (int) $attachment_id;
			unset( $queue[ $index ] );
			++$downloaded;
		}

		self::set_map( $product_id, $map );
		self::set_queue( $product_id, $queue );

		if ( $downloaded > 0 ) {
			self::assign( $product_id );
		}

		return array(
			'downloaded' => $downloaded,
			'failed'     => $failed,
			'remaining'  => count( $queue ),
		);
	}

	/**
	 * Download one image into the media library.
	 *
	 * @param array $entry      Queue entry.
	 * @param int   $product_id Product the image belongs to.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function sideload( array $entry, $product_id ) {
		$url = (string) $entry['src'];

		$check = self::validate_url( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$size = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $size > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'wwpart_image_too_large', __( 'The image is larger than the allowed maximum.', 'woo-wholesale-partner' ) );
		}

		$name = self::filename( $url );
		$type = wp_check_filetype_and_ext( $tmp, $name );

		if ( empty( $type['type'] ) || 0 !== strpos( (string) $type['type'], 'image/' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'wwpart_image_type', __( 'The downloaded file is not an image.', 'woo-wholesale-partner' ) );
		}

		if ( ! empty( $type['proper_filename'] ) ) {
			$name = (string) $type['proper_filename'];
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			(int) $product_id,
			'' !== (string) $entry['title'] ? (string) $entry['title'] : null
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		if ( '' !== (string) $entry['alt'] ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $entry['alt'] ) );
		}

		// Lets the checker recognise images this plugin brought in.
		update_post_meta( (int) $attachment_id, '_wwpart_image_hash', (string) $entry['hash'] );
		update_post_meta( (int) $attachment_id, '_wwpart_source_url', esc_url_raw( $url ) );

		return (int) $attachment_id;
	}

	/**
	 * Only allow downloads from the supplier shop.
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	public static function validate_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'wwpart_image_url', __( 'The image address is invalid.', 'woo-wholesale-partner' ) );
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wwpart_image_scheme', __( 'Images are only downloaded over HTTP or HTTPS.', 'woo-wholesale-partner' ) );
		}

		$host   = strtolower( $parts['host'] );
		$master = WWPart_Client::master_host();

		/**
		 * Filter the hosts images may be downloaded from.
		 *
		 * Useful when the supplier serves its media from a CDN on another host.
		 *
		 * @param string[] $hosts Allowed host names.
		 */
		$allowed = apply_filters( 'wwpart_allowed_image_hosts', array_filter( array( $master ) ) );

		if ( empty( $allowed ) || ! in_array( $host, array_map( 'strtolower', $allowed ), true ) ) {
			return new WP_Error(
				'wwpart_image_host',
				sprintf(
					/* translators: %s: host name */
					__( 'Images from the host "%s" are not downloaded, it is not the supplier shop.', 'woo-wholesale-partner' ),
					$host
				)
			);
		}

		return true;
	}

	/**
	 * File name of an image URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function filename( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );

		return '' !== $name ? $name : 'image.jpg';
	}

	/**
	 * Put the downloaded images in their place: featured image, gallery, variations.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function assign( $product_id ) {
		$payload = WWPart_Product::payload( $product_id );

		if ( empty( $payload ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$map     = self::map( $product_id );
		$ordered = array();

		foreach ( (array) ( isset( $payload['images'] ) ? $payload['images'] : array() ) as $image ) {
			$hash = isset( $image['hash'] ) ? (string) $image['hash'] : '';
			if ( '' === $hash || empty( $map[ $hash ] ) ) {
				continue;
			}
			$attachment_id = (int) $map[ $hash ];
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				$ordered[] = $attachment_id;
			}
		}

		if ( ! empty( $ordered ) ) {
			$product->set_image_id( (int) array_shift( $ordered ) );
			$product->set_gallery_image_ids( $ordered );
			$product->save();
		}

		// Variation images.
		foreach ( (array) ( isset( $payload['variations'] ) ? $payload['variations'] : array() ) as $variation_payload ) {
			$hash = isset( $variation_payload['image']['hash'] ) ? (string) $variation_payload['image']['hash'] : '';
			if ( '' === $hash || empty( $map[ $hash ] ) ) {
				continue;
			}

			$variation_id = WWPart_Sync::find_variation( $product_id, $variation_payload );
			if ( ! $variation_id ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$attachment_id = (int) $map[ $hash ];
			if ( (int) $variation->get_image_id( 'edit' ) !== $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
				$variation->set_image_id( $attachment_id );
				$variation->save();
			}
		}

		wc_delete_product_transients( $product_id );
	}

	/**
	 * Check one product and queue whatever is missing again.
	 *
	 * @param int $product_id Product ID.
	 * @return array queued, has_image
	 */
	public static function verify_product( $product_id ) {
		$payload = WWPart_Product::payload( $product_id );

		if ( empty( $payload ) ) {
			return array(
				'queued'    => 0,
				'has_image' => true,
			);
		}

		$queued  = self::enqueue_missing( $product_id, $payload );
		$product = wc_get_product( $product_id );

		$has_image = false;
		if ( $product instanceof WC_Product ) {
			$image_id  = (int) $product->get_image_id( 'edit' );
			$has_image = $image_id > 0 && 'attachment' === get_post_type( $image_id );

			// The files are there but the product lost its featured image.
			if ( ! $has_image && 0 === $queued ) {
				self::assign( $product_id );
				$product   = wc_get_product( $product_id );
				$image_id  = $product instanceof WC_Product ? (int) $product->get_image_id( 'edit' ) : 0;
				$has_image = $image_id > 0 && 'attachment' === get_post_type( $image_id );
			}
		}

		return array(
			'queued'    => $queued,
			'has_image' => $has_image,
		);
	}

	/**
	 * Products that still wait for images.
	 *
	 * @param int  $offset Offset.
	 * @param int  $limit  Limit.
	 * @param bool $failed Only products whose images gave up.
	 * @return int[]
	 */
	public static function waiting_products( $offset = 0, $limit = 20, $failed = false ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => max( 1, (int) $limit ),
				'offset'         => max( 0, (int) $offset ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => WWPart_Product::META_IMAGE_STATE,
						'value'   => $failed ? 'failed' : array( 'pending', 'failed' ),
						'compare' => $failed ? '=' : 'IN',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Count products in a given image state.
	 *
	 * @param string $state ok|pending|failed.
	 * @return int
	 */
	public static function count_in_state( $state ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => WWPart_Product::META_IMAGE_STATE,
						'value' => $state,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Images still queued across all products.
	 *
	 * @return int
	 */
	public static function count_queued_images() {
		global $wpdb;

		$total = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 5000",
				WWPart_Product::META_IMAGE_QUEUE
			)
		);

		foreach ( (array) $rows as $row ) {
			$queue  = maybe_unserialize( $row );
			$total += is_array( $queue ) ? count( $queue ) : 0;
		}

		return $total;
	}

	/**
	 * Numbers for the admin screen and the heartbeat.
	 *
	 * @return array
	 */
	public static function stats() {
		return array(
			'pending_products' => self::count_in_state( 'pending' ),
			'failed_products'  => self::count_in_state( 'failed' ),
			'queued_images'    => self::count_queued_images(),
			'managed'          => WWPart_Product::count_managed(),
		);
	}

	/**
	 * Run one batch of the checker.
	 *
	 * First the products that are known to be waiting, then a rolling pass over
	 * the whole catalogue so images that vanished later are found as well.
	 *
	 * @param int  $offset Offset into the waiting list.
	 * @param int  $limit  Products per batch.
	 * @param bool $force  Retry even images that have given up.
	 * @return array
	 */
	public static function run_batch( $offset = 0, $limit = 5, $force = false ) {
		$ids = self::waiting_products( $offset, $limit );

		$downloaded = 0;
		$failed     = 0;
		$checked    = 0;
		$stuck      = 0;

		foreach ( $ids as $product_id ) {
			$result      = self::process_product( $product_id, self::PER_PRODUCT, $force );
			$downloaded += $result['downloaded'];
			$failed     += $result['failed'];
			++$checked;

			// A product that finished leaves the waiting list, so only the ones
			// that still have something queued move the offset forward. Without
			// that the batch would read the same rows again and never advance.
			if ( $result['remaining'] > 0 ) {
				++$stuck;
			}
		}

		return array(
			'checked'    => $checked,
			'downloaded' => $downloaded,
			'failed'     => $failed,
			'offset'     => $offset + $stuck,
			'done'       => $checked < $limit,
		);
	}

	/**
	 * Background run: retry queued images and verify a slice of the catalogue.
	 */
	public static function run_cron() {
		if ( ! WWPart_Settings::is( 'auto_images' ) ) {
			return;
		}

		$ids = self::waiting_products( 0, self::CRON_PRODUCTS );

		foreach ( $ids as $product_id ) {
			self::process_product( $product_id, self::PER_PRODUCT, false );
		}

		self::verify_slice();
	}

	/**
	 * Verify the next slice of managed products.
	 *
	 * @return array checked, queued
	 */
	public static function verify_slice() {
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$ids    = WWPart_Product::managed_ids( $cursor, self::VERIFY_PRODUCTS );

		if ( empty( $ids ) ) {
			update_option( self::CURSOR_OPTION, 0, false );
			return array(
				'checked' => 0,
				'queued'  => 0,
			);
		}

		$queued = 0;
		foreach ( $ids as $product_id ) {
			$result  = self::verify_product( $product_id );
			$queued += (int) $result['queued'];
		}

		update_option( self::CURSOR_OPTION, $cursor + count( $ids ), false );

		return array(
			'checked' => count( $ids ),
			'queued'  => $queued,
		);
	}

	/**
	 * Reset the rolling cursor of the checker.
	 */
	public static function reset_cursor() {
		update_option( self::CURSOR_OPTION, 0, false );
	}
}
