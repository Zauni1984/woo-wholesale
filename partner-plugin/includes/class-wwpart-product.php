<?php
/**
 * Meta keys and lookups for products that came from the supplier.
 *
 * A product this plugin created is "managed": it carries the id it has in the
 * supplier shop, the wholesale price it was delivered with and the last payload
 * the supplier sent. Everything else in the plugin builds on these keys.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Managed product helper (static).
 */
class WWPart_Product {

	/**
	 * Product id in the supplier shop.
	 */
	const META_MASTER_ID = '_wwpart_master_id';

	/**
	 * Wholesale price as delivered (the price the partner pays).
	 */
	const META_BASE_PRICE = '_wwpart_base_price';

	/**
	 * Recommended retail price of the supplier.
	 */
	const META_RETAIL = '_wwpart_retail_price';

	/**
	 * Checksum of the last payload, used to skip unchanged products.
	 */
	const META_CHECKSUM = '_wwpart_checksum';

	/**
	 * Timestamp of the last sync.
	 */
	const META_SYNCED = '_wwpart_synced';

	/**
	 * Last payload of the supplier (JSON). The lock restores from it.
	 */
	const META_PAYLOAD = '_wwpart_payload';

	/**
	 * Images that still have to be downloaded.
	 */
	const META_IMAGE_QUEUE = '_wwpart_image_queue';

	/**
	 * Image hash => local attachment id.
	 */
	const META_IMAGE_MAP = '_wwpart_image_map';

	/**
	 * ok | pending | failed
	 */
	const META_IMAGE_STATE = '_wwpart_image_state';

	/**
	 * Markup that produced the current price, for the product list column.
	 */
	const META_MARKUP = '_wwpart_markup_used';

	/**
	 * Request cache of the managed lookup, keyed by post ID.
	 *
	 * The capability filter asks this for every row of the product list, so the
	 * answer is remembered for the request.
	 *
	 * @var array
	 */
	private static $managed_cache = array();

	/**
	 * Whether a product is managed by the supplier.
	 *
	 * @param int $post_id Post ID (product or variation).
	 * @return bool
	 */
	public static function is_managed( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! isset( self::$managed_cache[ $post_id ] ) ) {
			self::$managed_cache[ $post_id ] = '' !== (string) get_post_meta( self::parent_id( $post_id ), self::META_MASTER_ID, true );
		}

		return self::$managed_cache[ $post_id ];
	}

	/**
	 * Forget the managed lookup of a product (or of everything).
	 *
	 * @param int $post_id Post ID, 0 clears the whole cache.
	 */
	public static function flush_managed_cache( $post_id = 0 ) {
		if ( $post_id ) {
			unset( self::$managed_cache[ (int) $post_id ] );
			return;
		}

		self::$managed_cache = array();
	}

	/**
	 * Parent product of a variation, or the id itself.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function parent_id( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
			return (int) $post->post_parent;
		}

		return $post_id;
	}

	/**
	 * Supplier id of a product.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function master_id( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::META_MASTER_ID, true );
	}

	/**
	 * Find the local product for a supplier id.
	 *
	 * @param int $master_id Supplier product id.
	 * @return int Local post ID or 0.
	 */
	public static function find_by_master_id( $master_id ) {
		global $wpdb;

		$master_id = (int) $master_id;
		if ( ! $master_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %d
				   AND p.post_type = 'product' AND p.post_status <> 'trash'
				 ORDER BY pm.post_id ASC
				 LIMIT 1",
				self::META_MASTER_ID,
				$master_id
			)
		);

		return $post_id;
	}

	/**
	 * Find a local product by SKU, so a first sync adopts products that already exist.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public static function find_by_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}
		return (int) wc_get_product_id_by_sku( $sku );
	}

	/**
	 * Stored payload of a product.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function payload( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_PAYLOAD, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Store the payload of a product.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $payload Payload.
	 */
	public static function set_payload( $post_id, array $payload ) {
		update_post_meta( (int) $post_id, self::META_PAYLOAD, wp_json_encode( $payload ) );
	}

	/**
	 * Wholesale price a product was delivered with.
	 *
	 * @param int $post_id Post ID (product or variation).
	 * @return float|null
	 */
	public static function base_price( $post_id ) {
		$value = get_post_meta( (int) $post_id, self::META_BASE_PRICE, true );
		return ( '' === $value || null === $value || ! is_numeric( $value ) ) ? null : (float) $value;
	}

	/**
	 * Query arguments for managed products.
	 *
	 * @param int $term_id       Limit to this product category (0 = all).
	 * @param bool $with_children Include subcategories.
	 * @return array
	 */
	public static function query_args( $term_id = 0, $with_children = true ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_MASTER_ID,
					'compare' => 'EXISTS',
				),
			),
		);

		$term_id = (int) $term_id;
		if ( $term_id ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( $term_id ),
					'include_children' => (bool) $with_children,
				),
			);
		}

		return $args;
	}

	/**
	 * Number of managed products.
	 *
	 * @param int  $term_id       Category filter.
	 * @param bool $with_children Include subcategories.
	 * @return int
	 */
	public static function count_managed( $term_id = 0, $with_children = true ) {
		$query = new WP_Query(
			array_merge(
				self::query_args( $term_id, $with_children ),
				array( 'posts_per_page' => 1 )
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * A page of managed product ids.
	 *
	 * @param int  $offset        Offset.
	 * @param int  $limit         Limit.
	 * @param int  $term_id       Category filter.
	 * @param bool $with_children Include subcategories.
	 * @return int[]
	 */
	public static function managed_ids( $offset, $limit, $term_id = 0, $with_children = true ) {
		$args = array_merge(
			self::query_args( $term_id, $with_children ),
			array(
				'posts_per_page' => max( 1, (int) $limit ),
				'offset'         => max( 0, (int) $offset ),
			)
		);

		$query = new WP_Query( $args );

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Mark a product as seen in the current sync run.
	 *
	 * The ids are kept in a transient so the cleanup step knows which products
	 * the supplier no longer delivers.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function mark_seen( $post_id ) {
		$seen = get_transient( 'wwpart_seen_ids' );
		$seen = is_array( $seen ) ? $seen : array();

		$seen[ (int) $post_id ] = 1;

		set_transient( 'wwpart_seen_ids', $seen, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Ids seen in the current run.
	 *
	 * @return int[]
	 */
	public static function seen_ids() {
		$seen = get_transient( 'wwpart_seen_ids' );
		return is_array( $seen ) ? array_map( 'intval', array_keys( $seen ) ) : array();
	}

	/**
	 * Reset the seen list.
	 */
	public static function reset_seen() {
		delete_transient( 'wwpart_seen_ids' );
	}
}
