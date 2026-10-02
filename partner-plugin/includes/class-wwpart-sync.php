<?php
/**
 * Product sync from the supplier shop.
 *
 * The supplier is the source of truth for product data; this shop is the source
 * of truth for its own sales price. So a sync writes names, descriptions,
 * categories, attributes and the wholesale price, and then asks the markup
 * engine for the price this shop sells at.
 *
 * Attributes arrive as product level attributes, not as global attribute
 * taxonomies: that keeps the partner shop from collecting taxonomies it never
 * asked for, and variable products still come out complete.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sync engine (static).
 */
class WWPart_Sync {

	/**
	 * Products requested per page.
	 */
	const PAGE_SIZE = 10;

	/**
	 * Products handled per cleanup batch.
	 */
	const CLEANUP_SIZE = 40;

	/**
	 * Create or update the product categories of the supplier.
	 *
	 * Parents come first in the payload, so a child always finds its parent.
	 *
	 * @param array $categories Category payloads.
	 * @return array created, updated
	 */
	public static function sync_categories( array $categories ) {
		$created = 0;
		$updated = 0;

		foreach ( $categories as $category ) {
			$slug = isset( $category['slug'] ) ? sanitize_title( (string) $category['slug'] ) : '';
			$name = isset( $category['name'] ) ? sanitize_text_field( (string) $category['name'] ) : '';

			if ( '' === $slug || '' === $name ) {
				continue;
			}

			$parent_id   = 0;
			$parent_slug = isset( $category['parent_slug'] ) ? sanitize_title( (string) $category['parent_slug'] ) : '';

			if ( '' !== $parent_slug ) {
				$parent = get_term_by( 'slug', $parent_slug, 'product_cat' );
				if ( $parent instanceof WP_Term ) {
					$parent_id = (int) $parent->term_id;
				}
			}

			$existing = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $existing instanceof WP_Term ) {
				$args = array();

				if ( $existing->name !== $name ) {
					$args['name'] = $name;
				}
				if ( (int) $existing->parent !== $parent_id ) {
					$args['parent'] = $parent_id;
				}

				if ( ! empty( $args ) ) {
					wp_update_term( (int) $existing->term_id, 'product_cat', $args );
					++$updated;
				}

				continue;
			}

			$result = wp_insert_term(
				$name,
				'product_cat',
				array(
					'slug'        => $slug,
					'parent'      => $parent_id,
					'description' => isset( $category['description'] ) ? wp_kses_post( (string) $category['description'] ) : '',
				)
			);

			if ( ! is_wp_error( $result ) ) {
				++$created;
			}
		}

		return array(
			'created' => $created,
			'updated' => $updated,
		);
	}

	/**
	 * Create or update one product from a payload.
	 *
	 * @param array $payload Product payload.
	 * @return array|WP_Error action (created|updated|skipped), product_id
	 */
	public static function apply_payload( array $payload ) {
		$master_id = isset( $payload['master_id'] ) ? (int) $payload['master_id'] : 0;

		if ( ! $master_id ) {
			return new WP_Error( 'wwpart_payload_id', __( 'The supplier sent a product without an id.', 'woo-wholesale-partner' ) );
		}

		$product_id = WWPart_Product::find_by_master_id( $master_id );
		$adopted    = false;

		// First sync against a shop that already has the article: adopt it
		// instead of creating a duplicate.
		if ( ! $product_id && ! empty( $payload['sku'] ) ) {
			$product_id = WWPart_Product::find_by_sku( (string) $payload['sku'] );
			$adopted    = $product_id > 0;
		}

		$is_new   = ! $product_id;
		$checksum = isset( $payload['checksum'] ) ? (string) $payload['checksum'] : '';

		if ( $product_id ) {
			WWPart_Product::mark_seen( $product_id );

			$stored = (string) get_post_meta( $product_id, WWPart_Product::META_CHECKSUM, true );
			$state  = (string) get_post_meta( $product_id, WWPart_Product::META_IMAGE_STATE, true );

			if ( '' !== $checksum && $stored === $checksum && 'failed' !== $state ) {
				// Nothing changed upstream, but the local markup may have, so the
				// price is still recalculated.
				WWPart_Markup::apply_to_product( $product_id );

				if ( 'pending' === $state ) {
					WWPart_Images::enqueue_missing( $product_id, $payload );
				}

				return array(
					'action'     => 'skipped',
					'product_id' => $product_id,
				);
			}
		}

		$product = self::product_object( $product_id, (string) $payload['type'] );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		// The supplier's own write must not be reverted by the field lock.
		WWPart_Lock::suspend();

		$product->set_name( sanitize_text_field( (string) $payload['name'] ) );
		$product->set_description( wp_kses_post( (string) $payload['description'] ) );
		$product->set_short_description( wp_kses_post( (string) $payload['short_description'] ) );

		if ( $is_new ) {
			$product->set_status( 'publish' === WWPart_Settings::get( 'new_status' ) ? 'publish' : 'draft' );
		}

		self::apply_sku( $product, (string) $payload['sku'] );

		$product->set_tax_status( in_array( $payload['tax_status'], array( 'taxable', 'shipping', 'none' ), true ) ? $payload['tax_status'] : 'taxable' );
		$product->set_weight( (string) $payload['weight'] );
		$product->set_length( (string) $payload['length'] );
		$product->set_width( (string) $payload['width'] );
		$product->set_height( (string) $payload['height'] );

		self::apply_stock( $product, $payload );

		if ( 'variable' === $payload['type'] ) {
			$attributes = self::build_attributes( (array) $payload['attributes'] );
			if ( ! empty( $attributes ) ) {
				$product->set_attributes( $attributes );
			}
		}

		$product->save();
		$product_id = $product->get_id();

		if ( ! $product_id ) {
			WWPart_Lock::resume();
			return new WP_Error( 'wwpart_save_failed', __( 'The product could not be saved.', 'woo-wholesale-partner' ) );
		}

		update_post_meta( $product_id, WWPart_Product::META_MASTER_ID, $master_id );
		WWPart_Product::flush_managed_cache( $product_id );
		update_post_meta( $product_id, WWPart_Product::META_CHECKSUM, $checksum );
		update_post_meta( $product_id, WWPart_Product::META_SYNCED, time() );
		update_post_meta( $product_id, WWPart_Product::META_RETAIL, (string) $payload['retail_price'] );
		WWPart_Product::set_payload( $product_id, $payload );

		if ( 'variable' === $payload['type'] ) {
			delete_post_meta( $product_id, WWPart_Product::META_BASE_PRICE );
			self::sync_variations( $product_id, $payload );
		} else {
			update_post_meta( $product_id, WWPart_Product::META_BASE_PRICE, (string) $payload['price'] );
		}

		self::assign_categories( $product_id, (array) $payload['categories'] );

		if ( WWPart_Settings::is( 'sync_images' ) ) {
			WWPart_Images::enqueue_missing( $product_id, $payload );
		}

		WWPart_Markup::apply_to_product( $product_id );
		WWPart_Product::mark_seen( $product_id );

		WWPart_Lock::resume();

		/**
		 * Fires after a product was written from a supplier payload.
		 *
		 * @param int   $product_id Local product ID.
		 * @param array $payload    Payload.
		 * @param bool  $is_new     Whether the product was created.
		 */
		do_action( 'wwpart_product_synced', $product_id, $payload, $is_new );

		return array(
			'action'     => $is_new ? 'created' : ( $adopted ? 'adopted' : 'updated' ),
			'product_id' => $product_id,
		);
	}

	/**
	 * Product object of the right class for a payload type.
	 *
	 * @param int    $product_id Existing product ID (0 = new).
	 * @param string $type       simple|variable.
	 * @return WC_Product|WP_Error
	 */
	private static function product_object( $product_id, $type ) {
		$variable = ( 'variable' === $type );

		if ( ! $product_id ) {
			return $variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		try {
			// Instantiating the other class and saving converts the product type.
			return $variable ? new WC_Product_Variable( $product_id ) : new WC_Product_Simple( $product_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'wwpart_product_object', $e->getMessage() );
		}
	}

	/**
	 * Set the SKU unless another product already uses it.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $sku     SKU.
	 */
	private static function apply_sku( $product, $sku ) {
		$sku = trim( (string) $sku );

		if ( '' === $sku || $sku === (string) $product->get_sku( 'edit' ) ) {
			return;
		}

		$owner = wc_get_product_id_by_sku( $sku );

		if ( $owner && (int) $owner !== (int) $product->get_id() ) {
			// Another article in this shop owns the SKU; leaving it alone is
			// better than failing the whole product.
			return;
		}

		try {
			$product->set_sku( $sku );
		} catch ( Exception $e ) {
			return;
		}
	}

	/**
	 * Apply stock information, if the supplier sends it.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param array      $payload Payload.
	 */
	private static function apply_stock( $product, array $payload ) {
		$status = isset( $payload['stock_status'] ) ? (string) $payload['stock_status'] : 'instock';
		$product->set_stock_status( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $status : 'instock' );

		$policy = WWPart_Settings::policy();

		if ( empty( $policy['sync_stock'] ) || 'yes' !== ( isset( $payload['manage_stock'] ) ? $payload['manage_stock'] : 'no' ) ) {
			$product->set_manage_stock( false );
			return;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( isset( $payload['stock_quantity'] ) && null !== $payload['stock_quantity'] ? (int) $payload['stock_quantity'] : null );
	}

	/**
	 * Turn payload attributes into product level WooCommerce attributes.
	 *
	 * @param array $attributes Attribute payloads.
	 * @return WC_Product_Attribute[]
	 */
	public static function build_attributes( array $attributes ) {
		$out = array();

		foreach ( $attributes as $index => $attribute ) {
			$name = isset( $attribute['name'] ) ? sanitize_text_field( (string) $attribute['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$options = array();
			foreach ( (array) ( isset( $attribute['options'] ) ? $attribute['options'] : array() ) as $option ) {
				$label = isset( $option['name'] ) ? sanitize_text_field( (string) $option['name'] ) : '';
				if ( '' !== $label ) {
					$options[] = $label;
				}
			}

			if ( empty( $options ) ) {
				continue;
			}

			$object = new WC_Product_Attribute();
			$object->set_id( 0 );
			$object->set_name( $name );
			$object->set_options( $options );
			$object->set_position( isset( $attribute['position'] ) ? (int) $attribute['position'] : $index );
			$object->set_visible( ! isset( $attribute['visible'] ) || ! empty( $attribute['visible'] ) );
			$object->set_variation( ! empty( $attribute['variation'] ) );

			$out[] = $object;
		}

		return $out;
	}

	/**
	 * Local variation attribute map of a supplier variation.
	 *
	 * @param array $variation  Variation payload.
	 * @param array $attributes Attribute payloads of the parent.
	 * @return array attribute_<slug> => option label
	 */
	public static function variation_attribute_map( array $variation, array $attributes ) {
		$map = array();

		foreach ( $attributes as $attribute ) {
			if ( empty( $attribute['variation'] ) || empty( $attribute['name'] ) ) {
				continue;
			}

			$key   = isset( $attribute['key'] ) ? (string) $attribute['key'] : (string) $attribute['name'];
			$value = '';

			foreach ( (array) ( isset( $variation['attributes'] ) ? $variation['attributes'] : array() ) as $raw_key => $raw_value ) {
				$normalised = preg_replace( '/^attribute_/', '', (string) $raw_key );

				if ( strtolower( (string) $normalised ) !== strtolower( $key ) ) {
					continue;
				}

				// Translate the supplier's option slug into the option label.
				foreach ( (array) ( isset( $attribute['options'] ) ? $attribute['options'] : array() ) as $option ) {
					if ( isset( $option['slug'] ) && (string) $option['slug'] === (string) $raw_value ) {
						$value = isset( $option['name'] ) ? (string) $option['name'] : (string) $raw_value;
						break;
					}
				}

				if ( '' === $value ) {
					$value = (string) $raw_value;
				}

				break;
			}

			$map[ 'attribute_' . sanitize_title( (string) $attribute['name'] ) ] = sanitize_text_field( $value );
		}

		return $map;
	}

	/**
	 * Find the local variation of a supplier variation.
	 *
	 * @param int   $product_id Parent product ID.
	 * @param array $variation  Variation payload.
	 * @return int Variation ID or 0.
	 */
	public static function find_variation( $product_id, array $variation ) {
		$master_id = isset( $variation['master_id'] ) ? (int) $variation['master_id'] : 0;
		$parent    = wc_get_product( $product_id );

		if ( ! $parent instanceof WC_Product ) {
			return 0;
		}

		$children = (array) $parent->get_children();

		if ( $master_id ) {
			foreach ( $children as $child_id ) {
				if ( (int) get_post_meta( $child_id, WWPart_Product::META_MASTER_ID, true ) === $master_id ) {
					return (int) $child_id;
				}
			}
		}

		$sku = isset( $variation['sku'] ) ? trim( (string) $variation['sku'] ) : '';
		if ( '' !== $sku ) {
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product && (string) $child->get_sku( 'edit' ) === $sku ) {
					return (int) $child_id;
				}
			}
		}

		$payload = WWPart_Product::payload( $product_id );
		$wanted  = self::variation_attribute_map( $variation, (array) ( isset( $payload['attributes'] ) ? $payload['attributes'] : array() ) );

		if ( ! empty( $wanted ) ) {
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child instanceof WC_Product_Variation ) {
					continue;
				}

				$current = array();
				foreach ( (array) $child->get_attributes() as $key => $value ) {
					$current[ 'attribute_' . $key ] = (string) $value;
				}

				$matches = true;
				foreach ( $wanted as $key => $value ) {
					$have = isset( $current[ $key ] ) ? $current[ $key ] : '';
					if ( sanitize_title( $have ) !== sanitize_title( $value ) ) {
						$matches = false;
						break;
					}
				}

				if ( $matches ) {
					return (int) $child_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Create or update the variations of a variable product.
	 *
	 * @param int   $product_id Parent product ID.
	 * @param array $payload    Product payload.
	 * @return int Number of variations written.
	 */
	public static function sync_variations( $product_id, array $payload ) {
		$variations = (array) ( isset( $payload['variations'] ) ? $payload['variations'] : array() );
		$attributes = (array) ( isset( $payload['attributes'] ) ? $payload['attributes'] : array() );

		if ( empty( $variations ) ) {
			return 0;
		}

		$written = 0;
		$kept    = array();

		foreach ( $variations as $row ) {
			$variation_id = self::find_variation( $product_id, $row );

			$variation = $variation_id ? wc_get_product( $variation_id ) : null;

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( (int) $product_id );
			}

			$variation->set_attributes( self::strip_prefix( self::variation_attribute_map( $row, $attributes ) ) );
			$variation->set_description( wp_kses_post( (string) ( isset( $row['description'] ) ? $row['description'] : '' ) ) );
			$variation->set_weight( (string) ( isset( $row['weight'] ) ? $row['weight'] : '' ) );

			self::apply_sku( $variation, (string) ( isset( $row['sku'] ) ? $row['sku'] : '' ) );
			self::apply_stock( $variation, $row );

			$variation->save();

			$variation_id = $variation->get_id();

			if ( ! $variation_id ) {
				continue;
			}

			if ( ! empty( $row['master_id'] ) ) {
				update_post_meta( $variation_id, WWPart_Product::META_MASTER_ID, (int) $row['master_id'] );
			}

			update_post_meta( $variation_id, WWPart_Product::META_BASE_PRICE, (string) ( isset( $row['price'] ) ? $row['price'] : '' ) );
			update_post_meta( $variation_id, WWPart_Product::META_RETAIL, (string) ( isset( $row['retail_price'] ) ? $row['retail_price'] : '' ) );

			$kept[] = $variation_id;
			++$written;
		}

		// Variations the supplier dropped would keep selling at an old price.
		// Only variations this plugin created carry their own supplier id, so a
		// variation the partner added by hand is never removed here.
		$parent = wc_get_product( $product_id );
		if ( $parent instanceof WC_Product ) {
			foreach ( (array) $parent->get_children() as $child_id ) {
				if ( in_array( (int) $child_id, array_map( 'intval', $kept ), true ) ) {
					continue;
				}
				if ( ! get_post_meta( (int) $child_id, WWPart_Product::META_MASTER_ID, true ) ) {
					continue;
				}
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product ) {
					$child->delete( true );
				}
			}
		}

		WC_Product_Variable::sync( $product_id );

		return $written;
	}

	/**
	 * Drop the "attribute_" prefix from a variation attribute map.
	 *
	 * @param array $map Map with prefixed keys.
	 * @return array
	 */
	private static function strip_prefix( array $map ) {
		$out = array();

		foreach ( $map as $key => $value ) {
			$out[ preg_replace( '/^attribute_/', '', (string) $key ) ] = $value;
		}

		return $out;
	}

	/**
	 * Put a product into the categories the supplier delivered.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $categories Category payloads.
	 * @return int Number of assigned categories.
	 */
	public static function assign_categories( $product_id, array $categories ) {
		if ( empty( $categories ) ) {
			return 0;
		}

		self::sync_categories( $categories );

		$term_ids = array();

		foreach ( $categories as $category ) {
			$slug = isset( $category['slug'] ) ? sanitize_title( (string) $category['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		$term_ids = array_values( array_unique( $term_ids ) );

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( (int) $product_id, $term_ids, 'product_cat', false );
		}

		return count( $term_ids );
	}

	/**
	 * Handle products the supplier no longer delivers.
	 *
	 * @param int $offset Offset into the managed products.
	 * @return array checked, handled, offset, done
	 */
	public static function cleanup( $offset = 0 ) {
		$mode = (string) WWPart_Settings::get( 'on_removed' );

		if ( 'ignore' === $mode ) {
			return array(
				'checked' => 0,
				'handled' => 0,
				'offset'  => 0,
				'done'    => true,
			);
		}

		$seen = WWPart_Product::seen_ids();

		if ( empty( $seen ) ) {
			// Without a seen list a cleanup would hit the whole catalogue.
			return array(
				'checked' => 0,
				'handled' => 0,
				'offset'  => 0,
				'done'    => true,
			);
		}

		$ids     = WWPart_Product::managed_ids( $offset, self::CLEANUP_SIZE );
		$handled = 0;
		$stuck   = 0;

		foreach ( $ids as $product_id ) {
			if ( in_array( (int) $product_id, $seen, true ) ) {
				++$stuck;
				continue;
			}

			if ( 'trash' === $mode ) {
				wp_trash_post( (int) $product_id );
				++$handled;
				continue;
			}

			$post = get_post( (int) $product_id );
			if ( $post && 'draft' !== $post->post_status ) {
				wp_update_post(
					array(
						'ID'          => (int) $product_id,
						'post_status' => 'draft',
					)
				);
				++$handled;
			} else {
				++$stuck;
			}
		}

		return array(
			'checked' => count( $ids ),
			'handled' => $handled,
			// Products that stay in the list move the offset; the ones that were
			// trashed or drafted drop out of it.
			'offset'  => $offset + $stuck,
			'done'    => count( $ids ) < self::CLEANUP_SIZE,
		);
	}

	/**
	 * Report the local state back to the supplier.
	 *
	 * @param string $error Error message to report, if any.
	 * @return array|WP_Error
	 */
	public static function heartbeat( $error = '' ) {
		$stats = WWPart_Images::stats();

		return WWPart_Client::heartbeat(
			array(
				'products'       => (int) $stats['managed'],
				'missing_images' => (int) $stats['pending_products'] + (int) $stats['failed_products'],
				'failed_images'  => (int) $stats['failed_products'],
				'error'          => (string) $error,
			)
		);
	}
}
