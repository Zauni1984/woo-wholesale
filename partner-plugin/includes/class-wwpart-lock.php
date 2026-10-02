<?php
/**
 * Protection for the data the supplier dictates.
 *
 * This is the reason the plugin exists: a partner shop should be able to run its
 * own shop without being able to break the catalogue the supplier maintains. The
 * supplier decides per partner which fields are off limits, and this class
 * enforces that decision.
 *
 * The protection is applied on the server, not only in the browser: whatever
 * arrives through the product editor, a bulk edit or the WooCommerce REST API is
 * overwritten with the value from the last supplier payload. Hiding the fields
 * in the editor on top of that is only there so nobody wastes time typing into a
 * field that will be reset.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field lock (static).
 */
class WWPart_Lock {

	/**
	 * While the sync writes, the lock stays out of the way.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Server side restore, whatever the source of the write is.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'restore_post_fields' ), 99, 2 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'restore_product_object' ), 99 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'restore_rest_object' ), 99, 3 );
		add_action( 'save_post_product', array( __CLASS__, 'restore_terms' ), 99, 1 );

		// Deleting is a capability, so blocking it also removes the links.
		add_filter( 'map_meta_cap', array( __CLASS__, 'block_delete_cap' ), 10, 4 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'block_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'block_delete' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'editor_notice' ) );
			add_action( 'admin_footer', array( __CLASS__, 'editor_script' ) );
			add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_column' ), 20 );
			add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column_content' ), 10, 2 );
		}
	}

	/**
	 * Stop enforcing while the plugin itself writes.
	 */
	public static function suspend() {
		self::$suspended = true;
	}

	/**
	 * Enforce again.
	 */
	public static function resume() {
		self::$suspended = false;
	}

	/**
	 * Whether the lock currently applies.
	 *
	 * @return bool
	 */
	public static function active() {
		return ! self::$suspended;
	}

	/**
	 * Locked fields, labelled for the admin.
	 *
	 * @return array key => label
	 */
	public static function labels() {
		return array(
			'title'       => __( 'Product name', 'woo-wholesale-partner' ),
			'description' => __( 'Description and short description', 'woo-wholesale-partner' ),
			'images'      => __( 'Product images', 'woo-wholesale-partner' ),
			'categories'  => __( 'Category assignment', 'woo-wholesale-partner' ),
			'sku'         => __( 'SKU', 'woo-wholesale-partner' ),
			'attributes'  => __( 'Attributes and variations', 'woo-wholesale-partner' ),
			'delete'      => __( 'Deleting and trashing', 'woo-wholesale-partner' ),
		);
	}

	/**
	 * Locked fields that have a label.
	 *
	 * @return array key => label
	 */
	public static function locked_labels() {
		$policy = WWPart_Settings::policy();
		$labels = self::labels();
		$out    = array();

		foreach ( (array) $policy['locked_fields'] as $field ) {
			if ( isset( $labels[ $field ] ) ) {
				$out[ $field ] = $labels[ $field ];
			}
		}

		return $out;
	}

	/**
	 * Restore post title, content and excerpt of a managed product.
	 *
	 * @param array $data    Post data about to be written.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function restore_post_fields( $data, $postarr ) {
		if ( ! self::active() || empty( $postarr['ID'] ) || 'product' !== ( isset( $data['post_type'] ) ? $data['post_type'] : '' ) ) {
			return $data;
		}

		$post_id = (int) $postarr['ID'];

		if ( ! WWPart_Product::is_managed( $post_id ) ) {
			return $data;
		}

		$payload = WWPart_Product::payload( $post_id );

		if ( empty( $payload ) ) {
			return $data;
		}

		if ( WWPart_Settings::is_locked( 'title' ) && isset( $payload['name'] ) ) {
			$data['post_title'] = sanitize_text_field( (string) $payload['name'] );
		}

		if ( WWPart_Settings::is_locked( 'description' ) ) {
			if ( isset( $payload['description'] ) ) {
				$data['post_content'] = wp_kses_post( (string) $payload['description'] );
			}
			if ( isset( $payload['short_description'] ) ) {
				$data['post_excerpt'] = wp_kses_post( (string) $payload['short_description'] );
			}
		}

		return $data;
	}

	/**
	 * Restore locked values on a product object before it is saved.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function restore_product_object( $product ) {
		if ( ! self::active() || ! $product instanceof WC_Product ) {
			return;
		}

		self::restore( $product );
	}

	/**
	 * Restore locked values on a product that arrives through the REST API.
	 *
	 * @param WC_Product      $product  Product.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether the product is being created.
	 * @return WC_Product
	 */
	public static function restore_rest_object( $product, $request, $creating ) {
		if ( ! self::active() || ! $product instanceof WC_Product || $creating ) {
			return $product;
		}

		self::restore( $product );

		return $product;
	}

	/**
	 * Write the supplier values of every locked field back onto a product object.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function restore( $product ) {
		$product_id = WWPart_Product::parent_id( $product->get_id() );

		if ( ! $product_id || ! WWPart_Product::is_managed( $product_id ) ) {
			return;
		}

		$payload = WWPart_Product::payload( $product_id );

		if ( empty( $payload ) ) {
			return;
		}

		$is_variation = $product->is_type( 'variation' );

		if ( WWPart_Settings::is_locked( 'title' ) && ! $is_variation && isset( $payload['name'] ) ) {
			$product->set_name( sanitize_text_field( (string) $payload['name'] ) );
		}

		if ( WWPart_Settings::is_locked( 'description' ) && ! $is_variation ) {
			if ( isset( $payload['description'] ) ) {
				$product->set_description( wp_kses_post( (string) $payload['description'] ) );
			}
			if ( isset( $payload['short_description'] ) ) {
				$product->set_short_description( wp_kses_post( (string) $payload['short_description'] ) );
			}
		}

		if ( WWPart_Settings::is_locked( 'sku' ) ) {
			$expected = self::expected_sku( $product, $payload );

			if ( '' !== $expected && (string) $product->get_sku( 'edit' ) !== $expected ) {
				$owner = wc_get_product_id_by_sku( $expected );
				if ( ! $owner || (int) $owner === (int) $product->get_id() ) {
					try {
						$product->set_sku( $expected );
					} catch ( Exception $e ) {
						// A SKU clash is not worth blocking the save over.
						unset( $e );
					}
				}
			}
		}

		if ( WWPart_Settings::is_locked( 'images' ) && ! $is_variation ) {
			self::restore_images( $product, $product_id, $payload );
		}

		if ( WWPart_Settings::is_locked( 'attributes' ) && ! $is_variation && 'variable' === ( isset( $payload['type'] ) ? $payload['type'] : 'simple' ) ) {
			$attributes = WWPart_Sync::build_attributes( (array) ( isset( $payload['attributes'] ) ? $payload['attributes'] : array() ) );
			if ( ! empty( $attributes ) ) {
				$product->set_attributes( $attributes );
			}
		}
	}

	/**
	 * SKU the supplier delivered for a product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $payload Payload of the parent product.
	 * @return string
	 */
	private static function expected_sku( $product, array $payload ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return isset( $payload['sku'] ) ? (string) $payload['sku'] : '';
		}

		$master_id = (int) get_post_meta( $product->get_id(), WWPart_Product::META_MASTER_ID, true );

		foreach ( (array) ( isset( $payload['variations'] ) ? $payload['variations'] : array() ) as $variation ) {
			if ( $master_id && isset( $variation['master_id'] ) && (int) $variation['master_id'] === $master_id ) {
				return isset( $variation['sku'] ) ? (string) $variation['sku'] : '';
			}
		}

		return '';
	}

	/**
	 * Restore featured image and gallery from the downloaded images.
	 *
	 * @param WC_Product $product    Product.
	 * @param int        $product_id Product ID.
	 * @param array      $payload    Payload.
	 */
	private static function restore_images( $product, $product_id, array $payload ) {
		$map     = WWPart_Images::map( $product_id );
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

		if ( empty( $ordered ) ) {
			return;
		}

		$product->set_image_id( (int) array_shift( $ordered ) );
		$product->set_gallery_image_ids( $ordered );
	}

	/**
	 * Restore the category assignment after a product was saved.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function restore_terms( $post_id ) {
		if ( ! self::active() || ! WWPart_Settings::is_locked( 'categories' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! WWPart_Product::is_managed( $post_id ) ) {
			return;
		}

		$payload = WWPart_Product::payload( $post_id );

		if ( empty( $payload['categories'] ) ) {
			return;
		}

		$expected = array();

		foreach ( (array) $payload['categories'] as $category ) {
			$slug = isset( $category['slug'] ) ? sanitize_title( (string) $category['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$expected[] = (int) $term->term_id;
			}
		}

		if ( empty( $expected ) ) {
			return;
		}

		$current = wp_get_object_terms( (int) $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

		sort( $current );
		$expected = array_values( array_unique( $expected ) );
		sort( $expected );

		if ( $current !== $expected ) {
			wp_set_object_terms( (int) $post_id, $expected, 'product_cat', false );
		}
	}

	/**
	 * Whether a product may not be deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function delete_blocked( $post_id ) {
		if ( ! self::active() || ! WWPart_Settings::is_locked( 'delete' ) ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}

		return WWPart_Product::is_managed( $post_id );
	}

	/**
	 * Take the delete capability away for protected products.
	 *
	 * @param array  $caps    Primitive capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Arguments; [0] is the post ID.
	 * @return array
	 */
	public static function block_delete_cap( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' !== $cap && 'delete_page' !== $cap ) {
			return $caps;
		}

		if ( empty( $args[0] ) || ! self::delete_blocked( (int) $args[0] ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Block trashing a protected product.
	 *
	 * @param bool|null $check   Short circuit value.
	 * @param WP_Post   $post    Post.
	 * @return bool|null
	 */
	public static function block_trash( $check, $post ) {
		if ( null !== $check ) {
			return $check;
		}

		if ( $post instanceof WP_Post && self::delete_blocked( $post->ID ) ) {
			return false;
		}

		return $check;
	}

	/**
	 * Block deleting a protected product.
	 *
	 * @param WP_Post|false|null $check Short circuit value.
	 * @param WP_Post            $post  Post.
	 * @param bool               $force Whether this bypasses the trash.
	 * @return WP_Post|false|null
	 */
	public static function block_delete( $check, $post, $force ) {
		if ( null !== $check ) {
			return $check;
		}

		if ( $post instanceof WP_Post && self::delete_blocked( $post->ID ) ) {
			return false;
		}

		return $check;
	}

	/**
	 * Whether the current screen edits a managed product.
	 *
	 * @return int Product ID or 0.
	 */
	private static function current_managed_product() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return 0;
		}

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ? (int) $GLOBALS['post']->ID : 0;

		return ( $post_id && WWPart_Product::is_managed( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * Explain on the product screen why fields are not editable.
	 */
	public static function editor_notice() {
		if ( ! self::current_managed_product() ) {
			return;
		}

		$locked = self::locked_labels();

		echo '<div class="notice notice-info"><p><strong>';
		esc_html_e( 'This product comes from your supplier.', 'woo-wholesale-partner' );
		echo '</strong> ';

		if ( empty( $locked ) ) {
			esc_html_e( 'Your supplier has not locked any fields, but everything it delivers is overwritten on the next sync.', 'woo-wholesale-partner' );
		} else {
			printf(
				/* translators: %s: comma separated list of locked fields */
				esc_html__( 'These fields are maintained by your supplier and are reset when you save: %s.', 'woo-wholesale-partner' ),
				esc_html( implode( ', ', $locked ) )
			);
		}

		echo ' ';
		printf(
			/* translators: %s: link to the price screen */
			wp_kses_post( __( 'Your sales price is calculated from the wholesale price and your markup: %s', 'woo-wholesale-partner' ) ),
			'<a href="' . esc_url( WWPart_Admin::url( 'prices' ) ) . '">' . esc_html__( 'Prices', 'woo-wholesale-partner' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Make the locked fields look locked.
	 *
	 * The save handlers are what actually protect the data; this only keeps
	 * someone from typing into a field whose value will be thrown away.
	 */
	public static function editor_script() {
		if ( ! self::current_managed_product() ) {
			return;
		}

		$policy = WWPart_Settings::policy();
		$locked = array_values( (array) $policy['locked_fields'] );

		if ( empty( $locked ) ) {
			return;
		}

		?>
		<script>
		( function () {
			var locked = <?php echo wp_json_encode( $locked ); ?>;

			function lock( selector ) {
				document.querySelectorAll( selector ).forEach( function ( node ) {
					node.setAttribute( 'readonly', 'readonly' );
					node.setAttribute( 'aria-disabled', 'true' );
					node.style.opacity = '0.6';
					if ( 'SELECT' === node.tagName || 'checkbox' === node.type || 'radio' === node.type ) {
						node.setAttribute( 'disabled', 'disabled' );
					}
				} );
			}

			function hide( selector ) {
				document.querySelectorAll( selector ).forEach( function ( node ) {
					node.style.display = 'none';
				} );
			}

			if ( -1 !== locked.indexOf( 'title' ) ) {
				lock( '#title' );
			}
			if ( -1 !== locked.indexOf( 'sku' ) ) {
				lock( '#_sku' );
			}
			if ( -1 !== locked.indexOf( 'images' ) ) {
				hide( '#postimagediv .hndle + .inside a, #woocommerce-product-images .add_product_images' );
			}
			if ( -1 !== locked.indexOf( 'categories' ) ) {
				lock( '#product_catchecklist input' );
				hide( '#product_cat-adder' );
			}
			if ( -1 !== locked.indexOf( 'attributes' ) ) {
				lock( '.woocommerce_attribute input, .woocommerce_attribute select, .woocommerce_attribute textarea' );
				hide( '.add_attribute, .woocommerce_attribute .remove_row' );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Add a supplier column to the product list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function product_column( $columns ) {
		$columns['wwpart'] = __( 'Supplier', 'woo-wholesale-partner' );
		return $columns;
	}

	/**
	 * Supplier column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public static function product_column_content( $column, $post_id ) {
		if ( 'wwpart' !== $column ) {
			return;
		}

		if ( ! WWPart_Product::is_managed( $post_id ) ) {
			echo '<span class="wwpart-col-empty">&ndash;</span>';
			return;
		}

		$base   = WWPart_Product::base_price( $post_id );
		$markup = get_post_meta( $post_id, WWPart_Product::META_MARKUP, true );
		$state  = (string) get_post_meta( $post_id, WWPart_Product::META_IMAGE_STATE, true );

		echo '<span class="wwpart-badge">' . esc_html__( 'managed', 'woo-wholesale-partner' ) . '</span>';

		if ( null !== $base ) {
			echo '<br><span class="wwpart-col-note">' . esc_html__( 'Purchase:', 'woo-wholesale-partner' ) . ' ' . wp_kses_post( wc_price( $base ) ) . '</span>';
		}

		if ( '' !== (string) $markup && is_numeric( $markup ) ) {
			echo '<br><span class="wwpart-col-note">' . esc_html__( 'Markup:', 'woo-wholesale-partner' ) . ' ' . esc_html( wc_format_localized_decimal( $markup ) ) . ' %</span>';
		}

		if ( 'pending' === $state ) {
			echo '<br><span class="wwpart-col-warn">' . esc_html__( 'images pending', 'woo-wholesale-partner' ) . '</span>';
		} elseif ( 'failed' === $state ) {
			echo '<br><span class="wwpart-col-error">' . esc_html__( 'images failed', 'woo-wholesale-partner' ) . '</span>';
		}
	}
}
