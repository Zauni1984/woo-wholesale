<?php
/**
 * Canonical product payload for partner shops.
 *
 * One builder serves both transports: the REST API that WooCommerce partners
 * pull from, and the Shopify push. Partner shops recreate products from this
 * payload, so it carries everything they need - including the image URLs the
 * partner downloads and the checksum that lets a partner skip a product that
 * has not changed since the last sync.
 *
 * The price in the payload is the price of the partner's wholesale role,
 * resolved through the normal pricing engine. A category or store-wide
 * discount therefore reaches the partner as well.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payload builder (static).
 */
class WWPro_Product_Payload {

	/**
	 * Maximum variations serialised for one product.
	 */
	const MAX_VARIATIONS = 100;

	/**
	 * Query arguments for the products a partner receives.
	 *
	 * @param array $partner  Partner.
	 * @param int   $page     Page (1 based).
	 * @param int   $per_page Page size.
	 * @param int   $since    Only products modified at or after this timestamp (0 = all).
	 * @return array
	 */
	public static function query_args( $partner, $page = 1, $per_page = 20, $since = 0 ) {
		$args = array(
			'status'   => 'publish',
			'type'     => array( 'simple', 'variable' ),
			'limit'    => max( 1, (int) $per_page ),
			'page'     => max( 1, (int) $page ),
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'paginate' => true,
			'return'   => 'ids',
		);

		if ( ! empty( $partner['categories'] ) ) {
			$args['category'] = self::category_slugs( $partner['categories'] );
			// A partner with categories that no longer exist must not receive the whole catalogue.
			if ( empty( $args['category'] ) ) {
				$args['category'] = array( '__wwpro_no_match__' );
			}
		}

		if ( 'yes' === $partner['in_stock_only'] ) {
			$args['stock_status'] = 'instock';
		}

		if ( $since > 0 ) {
			$args['date_modified'] = '>=' . (int) $since;
		}

		/**
		 * Filter the product query of a partner sync.
		 *
		 * @param array $args    Query args for wc_get_products().
		 * @param array $partner Partner.
		 */
		return apply_filters( 'wwpro_partner_query_args', $args, $partner );
	}

	/**
	 * Slugs of the given category term IDs, including their children.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @return string[]
	 */
	public static function category_slugs( $term_ids ) {
		$slugs = array();

		foreach ( (array) $term_ids as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$slugs[] = $term->slug;

			$children = get_term_children( $term->term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				foreach ( $children as $child_id ) {
					$child = get_term( (int) $child_id, 'product_cat' );
					if ( $child instanceof WP_Term ) {
						$slugs[] = $child->slug;
					}
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Paged product payloads for a partner.
	 *
	 * @param array $partner  Partner.
	 * @param int   $page     Page (1 based).
	 * @param int   $per_page Page size.
	 * @param int   $since    Modified-since timestamp.
	 * @return array page, pages, total, products
	 */
	public static function page( $partner, $page = 1, $per_page = 20, $since = 0 ) {
		$result = wc_get_products( self::query_args( $partner, $page, $per_page, $since ) );

		$ids   = isset( $result->products ) ? (array) $result->products : array();
		$items = array();

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$payload = self::build( $product, $partner );
			if ( $payload ) {
				$items[] = $payload;
			}
		}

		return array(
			'page'     => max( 1, (int) $page ),
			'pages'    => isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1,
			'total'    => isset( $result->total ) ? (int) $result->total : count( $items ),
			'products' => $items,
		);
	}

	/**
	 * Number of products a partner receives.
	 *
	 * @param array $partner Partner.
	 * @param int   $since   Modified-since timestamp.
	 * @return int
	 */
	public static function count( $partner, $since = 0 ) {
		$args          = self::query_args( $partner, 1, 1, $since );
		$result        = wc_get_products( $args );
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Build the payload of one product.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $partner Partner.
	 * @return array|null
	 */
	public static function build( $product, $partner ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$role = $partner['role'];

		$payload = array(
			'master_id'         => $product->get_id(),
			'type'              => $product->is_type( 'variable' ) ? 'variable' : 'simple',
			'sku'               => (string) $product->get_sku( 'edit' ),
			'name'              => $product->get_name( 'edit' ),
			'slug'              => $product->get_slug( 'edit' ),
			'description'       => $product->get_description( 'edit' ),
			'short_description' => $product->get_short_description( 'edit' ),
			'price'             => self::price_for( $product, $role ),
			'retail_price'      => self::decimal( $product->get_regular_price( 'edit' ) ),
			'tax_status'        => $product->get_tax_status( 'edit' ),
			'tax_class'         => $product->get_tax_class( 'edit' ),
			'weight'            => (string) $product->get_weight( 'edit' ),
			'length'            => (string) $product->get_length( 'edit' ),
			'width'             => (string) $product->get_width( 'edit' ),
			'height'            => (string) $product->get_height( 'edit' ),
			'stock_status'      => $product->get_stock_status( 'edit' ),
			'manage_stock'      => $product->get_manage_stock( 'edit' ) ? 'yes' : 'no',
			'stock_quantity'    => null,
			'categories'        => self::categories_of( $product ),
			'tags'              => self::tags_of( $product ),
			'images'            => self::images_of( $product ),
			'attributes'        => array(),
			'variations'        => array(),
			'modified'          => $product->get_date_modified( 'edit' ) ? $product->get_date_modified( 'edit' )->getTimestamp() : 0,
		);

		if ( 'yes' === $partner['sync_stock'] ) {
			$qty                       = $product->get_stock_quantity( 'edit' );
			$payload['stock_quantity'] = ( null === $qty || '' === $qty ) ? null : (int) $qty;
		} else {
			$payload['manage_stock'] = 'no';
		}

		if ( $product->is_type( 'variable' ) ) {
			$payload['attributes'] = self::attributes_of( $product );
			$payload['variations'] = self::variations_of( $product, $partner );
		}

		/**
		 * Filter the product payload handed to a partner shop.
		 *
		 * @param array      $payload Payload.
		 * @param WC_Product $product Product.
		 * @param array      $partner Partner.
		 */
		$payload = apply_filters( 'wwpro_partner_product_payload', $payload, $product, $partner );

		$payload['checksum'] = self::checksum( $payload );

		return $payload;
	}

	/**
	 * Wholesale price of a product for a role as a decimal string.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return string
	 */
	public static function price_for( $product, $role ) {
		$price = WWPro_Pricing::effective_unit_price( $product, $role );
		return ( null === $price ) ? '' : self::decimal( $price );
	}

	/**
	 * Format a price as a plain decimal string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function decimal( $value ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return (string) wc_format_decimal( $value, wc_get_price_decimals() );
	}

	/**
	 * Category payload of a product (parents first, so the partner can rebuild the tree).
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	public static function categories_of( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $terms as $term ) {
			foreach ( self::term_with_parents( $term ) as $node ) {
				if ( isset( $seen[ $node['slug'] ] ) ) {
					continue;
				}
				$seen[ $node['slug'] ] = true;
				$out[]                 = $node;
			}
		}

		return $out;
	}

	/**
	 * A term and its ancestors, outermost first.
	 *
	 * @param WP_Term $term Term.
	 * @return array[]
	 */
	private static function term_with_parents( $term ) {
		$chain = array();
		$node  = $term;
		$guard = 0;

		while ( $node instanceof WP_Term && $guard < 10 ) {
			$parent = $node->parent ? get_term( $node->parent, $node->taxonomy ) : null;

			array_unshift(
				$chain,
				array(
					'slug'        => $node->slug,
					'name'        => $node->name,
					'parent_slug' => ( $parent instanceof WP_Term ) ? $parent->slug : '',
					'description' => $node->description,
				)
			);

			$node = ( $parent instanceof WP_Term ) ? $parent : null;
			++$guard;
		}

		return $chain;
	}

	/**
	 * Product tags.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	public static function tags_of( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_tag' );
		if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}

		return $out;
	}

	/**
	 * Image payload of a product: featured image first, then the gallery.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	public static function images_of( $product ) {
		$ids = array();

		$featured = (int) $product->get_image_id( 'edit' );
		if ( $featured ) {
			$ids[] = $featured;
		}

		foreach ( (array) $product->get_gallery_image_ids( 'edit' ) as $gallery_id ) {
			$gallery_id = (int) $gallery_id;
			if ( $gallery_id && ! in_array( $gallery_id, $ids, true ) ) {
				$ids[] = $gallery_id;
			}
		}

		$images   = array();
		$position = 0;

		foreach ( $ids as $attachment_id ) {
			$image = self::image_payload( $attachment_id, $position );
			if ( $image ) {
				$images[] = $image;
				++$position;
			}
		}

		return $images;
	}

	/**
	 * Payload of one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $position      Position (0 = featured image).
	 * @return array|null
	 */
	public static function image_payload( $attachment_id, $position ) {
		$src = wp_get_attachment_url( $attachment_id );
		if ( ! $src ) {
			return null;
		}

		$meta     = wp_get_attachment_metadata( $attachment_id );
		$filesize = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
		$modified = (string) get_post_field( 'post_modified_gmt', $attachment_id );

		return array(
			'src'      => $src,
			'name'     => basename( parse_url( $src, PHP_URL_PATH ) ),
			'alt'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'title'    => (string) get_the_title( $attachment_id ),
			'position' => (int) $position,
			// Changes when the file is replaced, so partners re-download only then.
			'hash'     => md5( $src . '|' . $modified . '|' . $filesize ),
		);
	}

	/**
	 * Attribute definitions of a variable product.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	public static function attributes_of( $product ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$options = array();

			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() as $term ) {
					$options[] = array(
						'slug' => $term->slug,
						'name' => $term->name,
					);
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$options[] = array(
						'slug' => sanitize_title( $option ),
						'name' => (string) $option,
					);
				}
			}

			$out[] = array(
				'name'      => wc_attribute_label( $attribute->get_name() ),
				'key'       => $attribute->get_name(),
				'taxonomy'  => $attribute->is_taxonomy(),
				'variation' => (bool) $attribute->get_variation(),
				'visible'   => (bool) $attribute->get_visible(),
				'position'  => (int) $attribute->get_position(),
				'options'   => $options,
			);
		}

		return $out;
	}

	/**
	 * Variation payloads of a variable product.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $partner Partner.
	 * @return array[]
	 */
	public static function variations_of( $product, $partner ) {
		$out = array();

		foreach ( array_slice( (array) $product->get_children(), 0, self::MAX_VARIATIONS ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$attributes = array();
			foreach ( (array) $variation->get_attributes() as $key => $value ) {
				$attributes[ (string) $key ] = (string) $value;
			}

			$image = self::image_payload( (int) $variation->get_image_id( 'edit' ), 0 );

			$row = array(
				'master_id'      => $variation->get_id(),
				'sku'            => (string) $variation->get_sku( 'edit' ),
				'price'          => self::price_for( $variation, $partner['role'] ),
				'retail_price'   => self::decimal( $variation->get_regular_price( 'edit' ) ),
				'attributes'     => $attributes,
				'weight'         => (string) $variation->get_weight( 'edit' ),
				'stock_status'   => $variation->get_stock_status( 'edit' ),
				'manage_stock'   => $variation->get_manage_stock( 'edit' ) ? 'yes' : 'no',
				'stock_quantity' => null,
				'image'          => $image,
				'description'    => $variation->get_description( 'edit' ),
			);

			if ( 'yes' === $partner['sync_stock'] ) {
				$qty                   = $variation->get_stock_quantity( 'edit' );
				$row['stock_quantity'] = ( null === $qty || '' === $qty ) ? null : (int) $qty;
			} else {
				$row['manage_stock'] = 'no';
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Checksum over everything a partner shop would have to write.
	 *
	 * @param array $payload Payload without checksum.
	 * @return string
	 */
	public static function checksum( array $payload ) {
		unset( $payload['checksum'], $payload['modified'] );
		return md5( (string) wp_json_encode( $payload ) );
	}
}
