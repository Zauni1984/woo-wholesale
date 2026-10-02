<?php
/**
 * Shopify connector.
 *
 * Partner shops that run on Shopify cannot install a WordPress plugin, so this
 * shop pushes to them instead of being pulled from. The push uses the Shopify
 * GraphQL Admin API - Shopify has deprecated the REST product endpoints, so
 * products, variants and media are all written through GraphQL.
 *
 * Images are handed over as URLs: Shopify downloads them itself, which keeps
 * large files out of this request entirely.
 *
 * Each pushed product remembers its Shopify id in post meta per partner, so a
 * second run updates instead of duplicating. When the mapping is missing (first
 * run against an existing Shopify catalogue) the product is matched by SKU.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopify client and product push (static).
 */
class WWPro_Shopify {

	/**
	 * Admin API version used for every call.
	 */
	const API_VERSION = '2024-10';

	/**
	 * Products pushed per batch. Every product costs several API calls.
	 */
	const BATCH_SIZE = 3;

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 25;

	/**
	 * Prefix of the meta key that stores the Shopify product id per partner.
	 */
	const MAP_META = '_wwpro_shopify_';

	/**
	 * Meta key that stores the Shopify product id of a partner.
	 *
	 * @param array|string $partner Partner or partner id.
	 * @return string
	 */
	public static function map_key( $partner ) {
		$id = is_array( $partner ) ? $partner['id'] : (string) $partner;
		return self::MAP_META . sanitize_key( $id );
	}

	/**
	 * GraphQL endpoint of a partner.
	 *
	 * @param array $partner Partner.
	 * @return string
	 */
	public static function endpoint( $partner ) {
		return 'https://' . $partner['shop_domain'] . '/admin/api/' . self::API_VERSION . '/graphql.json';
	}

	/**
	 * Run a GraphQL query.
	 *
	 * @param array  $partner   Partner.
	 * @param string $query     GraphQL document.
	 * @param array  $variables Variables.
	 * @param int    $attempt   Internal retry counter.
	 * @return array|WP_Error Decoded "data" on success.
	 */
	public static function graphql( $partner, $query, $variables = array(), $attempt = 1 ) {
		$token = WWPro_Partners::shopify_token( $partner );
		if ( '' === $token ) {
			return new WP_Error( 'wwpro_shopify_token', __( 'No Shopify access token is stored for this partner.', 'woo-wholesale' ) );
		}

		$response = wp_remote_post(
			self::endpoint( $partner ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'           => 'application/json; charset=utf-8',
					'X-Shopify-Access-Token' => $token,
					'Accept'                 => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => (object) $variables,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 429 === $status || 503 === $status ) {
			if ( $attempt < 3 ) {
				sleep( 2 * $attempt );
				return self::graphql( $partner, $query, $variables, $attempt + 1 );
			}
			return new WP_Error( 'wwpro_shopify_throttled', __( 'Shopify is rate limiting the connection. Please start the sync again in a few minutes.', 'woo-wholesale' ) );
		}

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'wwpro_shopify_auth', __( 'Shopify refused the access token. Please check the token and its scopes (write_products).', 'woo-wholesale' ) );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'wwpro_shopify_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Shopify answered with HTTP status %d.', 'woo-wholesale' ),
					$status
				)
			);
		}

		if ( ! empty( $body['errors'] ) ) {
			$messages = array();
			foreach ( (array) $body['errors'] as $error ) {
				if ( isset( $error['message'] ) ) {
					$messages[] = (string) $error['message'];
				}
			}

			$throttled = false;
			foreach ( (array) $body['errors'] as $error ) {
				if ( isset( $error['extensions']['code'] ) && 'THROTTLED' === $error['extensions']['code'] ) {
					$throttled = true;
				}
			}

			if ( $throttled && $attempt < 3 ) {
				sleep( 2 * $attempt );
				return self::graphql( $partner, $query, $variables, $attempt + 1 );
			}

			return new WP_Error( 'wwpro_shopify_graphql', implode( ' ', $messages ) );
		}

		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	}

	/**
	 * Turn the userErrors of a mutation into a WP_Error.
	 *
	 * @param array  $node  Mutation result node.
	 * @param string $field Name of the error field.
	 * @return WP_Error|null
	 */
	private static function user_errors( $node, $field = 'userErrors' ) {
		if ( ! is_array( $node ) || empty( $node[ $field ] ) ) {
			return null;
		}

		$messages = array();
		foreach ( (array) $node[ $field ] as $error ) {
			$messages[] = isset( $error['message'] ) ? (string) $error['message'] : '';
		}

		$messages = array_filter( $messages );

		return empty( $messages ) ? null : new WP_Error( 'wwpro_shopify_user_error', implode( ' ', $messages ) );
	}

	/**
	 * Verify the credentials of a partner.
	 *
	 * @param array $partner Partner.
	 * @return array|WP_Error Shop name and currency.
	 */
	public static function test_connection( $partner ) {
		$data = self::graphql( $partner, 'query { shop { name currencyCode myshopifyDomain } }' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['shop']['name'] ) ) {
			return new WP_Error( 'wwpro_shopify_shop', __( 'Shopify did not return any shop data.', 'woo-wholesale' ) );
		}

		return array(
			'name'     => (string) $data['shop']['name'],
			'currency' => isset( $data['shop']['currencyCode'] ) ? (string) $data['shop']['currencyCode'] : '',
			'domain'   => isset( $data['shop']['myshopifyDomain'] ) ? (string) $data['shop']['myshopifyDomain'] : '',
		);
	}

	/**
	 * Find a Shopify product id by the SKU of one of its variants.
	 *
	 * @param array  $partner Partner.
	 * @param string $sku     SKU.
	 * @return string|null|WP_Error Product gid, null when not found.
	 */
	public static function find_by_sku( $partner, $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return null;
		}

		$data = self::graphql(
			$partner,
			'query FindBySku($q: String!) {
				productVariants(first: 1, query: $q) {
					edges { node { id sku product { id } } }
				}
			}',
			array( 'q' => 'sku:' . self::quote( $sku ) )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$edges = isset( $data['productVariants']['edges'] ) ? (array) $data['productVariants']['edges'] : array();
		if ( empty( $edges ) ) {
			return null;
		}

		$node = isset( $edges[0]['node'] ) ? $edges[0]['node'] : array();

		return isset( $node['product']['id'] ) ? (string) $node['product']['id'] : null;
	}

	/**
	 * Quote a value for a Shopify search query.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
	}

	/**
	 * Variation attributes that make up the Shopify options of a payload.
	 *
	 * @param array $payload Product payload.
	 * @return array[] name => options (list of option names)
	 */
	private static function option_map( array $payload ) {
		$map = array();

		foreach ( (array) $payload['attributes'] as $attribute ) {
			if ( empty( $attribute['variation'] ) ) {
				continue;
			}

			$values = array();
			foreach ( (array) $attribute['options'] as $option ) {
				if ( '' !== (string) $option['name'] ) {
					$values[ (string) $option['slug'] ] = (string) $option['name'];
				}
			}

			if ( empty( $values ) ) {
				continue;
			}

			$map[] = array(
				'key'    => (string) $attribute['key'],
				'name'   => (string) $attribute['name'],
				'values' => $values,
			);
		}

		return $map;
	}

	/**
	 * Option values of one variation, in the order of the option map.
	 *
	 * @param array $variation Variation payload.
	 * @param array $options   Option map.
	 * @return array[] optionName => name
	 */
	private static function variation_options( array $variation, array $options ) {
		$out = array();

		foreach ( $options as $option ) {
			$value = '';

			foreach ( (array) $variation['attributes'] as $key => $slug ) {
				// WooCommerce variation keys are "attribute_pa_size" / "attribute_size".
				$normalised = preg_replace( '/^attribute_/', '', (string) $key );
				if ( strtolower( $normalised ) !== strtolower( $option['key'] ) ) {
					continue;
				}
				if ( isset( $option['values'][ (string) $slug ] ) ) {
					$value = $option['values'][ (string) $slug ];
				} elseif ( '' !== (string) $slug ) {
					$value = (string) $slug;
				}
				break;
			}

			if ( '' === $value ) {
				// "Any" variations have no value for an option; Shopify needs one.
				$value = reset( $option['values'] );
			}

			$out[] = array(
				'optionName' => $option['name'],
				'name'       => (string) $value,
			);
		}

		return $out;
	}

	/**
	 * Push one product payload to a partner's Shopify store.
	 *
	 * @param array $partner Partner.
	 * @param array $payload Product payload.
	 * @return array|WP_Error action (created|updated|skipped), shopify_id
	 */
	public static function push_product( $partner, array $payload ) {
		$master_id  = (int) $payload['master_id'];
		$shopify_id = (string) get_post_meta( $master_id, self::map_key( $partner ), true );

		if ( '' === $shopify_id && '' !== (string) $payload['sku'] ) {
			$found = self::find_by_sku( $partner, $payload['sku'] );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( $found ) {
				$shopify_id = $found;
			}
		}

		if ( '' === $shopify_id ) {
			return self::create_product( $partner, $payload );
		}

		return self::update_product( $partner, $payload, $shopify_id );
	}

	/**
	 * Create a product in the partner's Shopify store.
	 *
	 * @param array $partner Partner.
	 * @param array $payload Product payload.
	 * @return array|WP_Error
	 */
	private static function create_product( $partner, array $payload ) {
		$options = ( 'variable' === $payload['type'] ) ? self::option_map( $payload ) : array();

		$input = array(
			'title'           => (string) $payload['name'],
			'descriptionHtml' => (string) $payload['description'],
			'status'          => 'ACTIVE',
			'vendor'          => get_bloginfo( 'name' ),
			'tags'            => self::tag_list( $payload ),
		);

		if ( ! empty( $options ) ) {
			$input['productOptions'] = array();
			foreach ( $options as $option ) {
				$values = array();
				foreach ( $option['values'] as $name ) {
					$values[] = array( 'name' => (string) $name );
				}
				$input['productOptions'][] = array(
					'name'   => $option['name'],
					'values' => $values,
				);
			}
		}

		$data = self::graphql(
			$partner,
			'mutation CreateProduct($input: ProductInput!) {
				productCreate(input: $input) {
					product {
						id
						variants(first: 1) { edges { node { id } } }
					}
					userErrors { field message }
				}
			}',
			array( 'input' => $input )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$node  = isset( $data['productCreate'] ) ? $data['productCreate'] : array();
		$error = self::user_errors( $node );
		if ( $error ) {
			return $error;
		}

		$shopify_id = isset( $node['product']['id'] ) ? (string) $node['product']['id'] : '';
		if ( '' === $shopify_id ) {
			return new WP_Error( 'wwpro_shopify_create', __( 'Shopify did not return an id for the new product.', 'woo-wholesale' ) );
		}

		update_post_meta( (int) $payload['master_id'], self::map_key( $partner ), $shopify_id );

		$default_variant = '';
		if ( isset( $node['product']['variants']['edges'][0]['node']['id'] ) ) {
			$default_variant = (string) $node['product']['variants']['edges'][0]['node']['id'];
		}

		$result = self::sync_variants( $partner, $payload, $shopify_id, $options, $default_variant );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$media = self::sync_media( $partner, $payload, $shopify_id );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return array(
			'action'     => 'created',
			'shopify_id' => $shopify_id,
		);
	}

	/**
	 * Update an existing Shopify product.
	 *
	 * @param array  $partner    Partner.
	 * @param array  $payload    Product payload.
	 * @param string $shopify_id Shopify product gid.
	 * @return array|WP_Error
	 */
	private static function update_product( $partner, array $payload, $shopify_id ) {
		$data = self::graphql(
			$partner,
			'mutation UpdateProduct($input: ProductInput!) {
				productUpdate(input: $input) {
					product { id }
					userErrors { field message }
				}
			}',
			array(
				'input' => array(
					'id'              => $shopify_id,
					'title'           => (string) $payload['name'],
					'descriptionHtml' => (string) $payload['description'],
					'tags'            => self::tag_list( $payload ),
				),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$error = self::user_errors( isset( $data['productUpdate'] ) ? $data['productUpdate'] : array() );
		if ( $error ) {
			// A product that was deleted in Shopify must be recreated.
			if ( false !== stripos( $error->get_error_message(), 'does not exist' ) ) {
				delete_post_meta( (int) $payload['master_id'], self::map_key( $partner ) );
				return self::create_product( $partner, $payload );
			}
			return $error;
		}

		update_post_meta( (int) $payload['master_id'], self::map_key( $partner ), $shopify_id );

		$options = ( 'variable' === $payload['type'] ) ? self::option_map( $payload ) : array();

		$result = self::sync_variants( $partner, $payload, $shopify_id, $options, '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$media = self::sync_media( $partner, $payload, $shopify_id );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return array(
			'action'     => 'updated',
			'shopify_id' => $shopify_id,
		);
	}

	/**
	 * Tags of a payload as a flat list.
	 *
	 * @param array $payload Payload.
	 * @return string[]
	 */
	private static function tag_list( array $payload ) {
		$tags = array();
		foreach ( (array) $payload['tags'] as $tag ) {
			if ( ! empty( $tag['name'] ) ) {
				$tags[] = (string) $tag['name'];
			}
		}
		return $tags;
	}

	/**
	 * Existing variants of a Shopify product keyed by SKU.
	 *
	 * @param array  $partner    Partner.
	 * @param string $shopify_id Product gid.
	 * @return array|WP_Error sku => variant gid
	 */
	private static function existing_variants( $partner, $shopify_id ) {
		$data = self::graphql(
			$partner,
			'query ProductVariants($id: ID!) {
				product(id: $id) {
					variants(first: 100) { edges { node { id sku } } }
				}
			}',
			array( 'id' => $shopify_id )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out   = array();
		$edges = isset( $data['product']['variants']['edges'] ) ? (array) $data['product']['variants']['edges'] : array();

		foreach ( $edges as $edge ) {
			if ( ! isset( $edge['node']['id'] ) ) {
				continue;
			}
			$sku         = isset( $edge['node']['sku'] ) ? (string) $edge['node']['sku'] : '';
			$out[ $sku ] = (string) $edge['node']['id'];
		}

		return $out;
	}

	/**
	 * Write prices and SKUs of all variants.
	 *
	 * @param array  $partner         Partner.
	 * @param array  $payload         Product payload.
	 * @param string $shopify_id      Product gid.
	 * @param array  $options         Option map.
	 * @param string $default_variant Variant gid created together with the product.
	 * @return true|WP_Error
	 */
	private static function sync_variants( $partner, array $payload, $shopify_id, array $options, $default_variant ) {
		$existing = self::existing_variants( $partner, $shopify_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$rows = array();

		if ( 'variable' === $payload['type'] && ! empty( $payload['variations'] ) ) {
			foreach ( (array) $payload['variations'] as $variation ) {
				if ( '' === (string) $variation['price'] ) {
					continue;
				}
				$rows[] = array(
					'sku'            => (string) $variation['sku'],
					'price'          => (string) $variation['price'],
					'compare'        => (string) $variation['retail_price'],
					'option_values'  => self::variation_options( $variation, $options ),
				);
			}
		} else {
			if ( '' === (string) $payload['price'] ) {
				return true;
			}
			$rows[] = array(
				'sku'           => (string) $payload['sku'],
				'price'         => (string) $payload['price'],
				'compare'       => (string) $payload['retail_price'],
				'option_values' => array(),
			);
		}

		$updates = array();
		$creates = array();

		foreach ( $rows as $index => $row ) {
			$variant_id = '';

			if ( '' !== $row['sku'] && isset( $existing[ $row['sku'] ] ) ) {
				$variant_id = $existing[ $row['sku'] ];
			} elseif ( 0 === $index && '' !== $default_variant ) {
				$variant_id = $default_variant;
			} elseif ( 0 === $index && 1 === count( $rows ) && 1 === count( $existing ) ) {
				// Simple product whose single Shopify variant has no SKU yet.
				$variant_id = (string) reset( $existing );
			}

			$fields = array(
				'price' => $row['price'],
			);

			// A compare-at price above the selling price shows the retail price as crossed out.
			if ( '' !== $row['compare'] && (float) $row['compare'] > (float) $row['price'] ) {
				$fields['compareAtPrice'] = $row['compare'];
			}

			if ( '' !== $variant_id ) {
				$fields['id'] = $variant_id;
				if ( '' !== $row['sku'] ) {
					$fields['inventoryItem'] = array( 'sku' => $row['sku'] );
				}
				$updates[] = $fields;
				continue;
			}

			if ( '' !== $row['sku'] ) {
				$fields['inventoryItem'] = array( 'sku' => $row['sku'] );
			}
			if ( ! empty( $row['option_values'] ) ) {
				$fields['optionValues'] = $row['option_values'];
			}
			$creates[] = $fields;
		}

		if ( ! empty( $creates ) ) {
			$data = self::graphql(
				$partner,
				'mutation CreateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
					productVariantsBulkCreate(productId: $productId, variants: $variants) {
						productVariants { id }
						userErrors { field message }
					}
				}',
				array(
					'productId' => $shopify_id,
					'variants'  => $creates,
				)
			);

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$error = self::user_errors( isset( $data['productVariantsBulkCreate'] ) ? $data['productVariantsBulkCreate'] : array() );
			if ( $error ) {
				return $error;
			}
		}

		if ( ! empty( $updates ) ) {
			$data = self::graphql(
				$partner,
				'mutation UpdateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
					productVariantsBulkUpdate(productId: $productId, variants: $variants) {
						productVariants { id }
						userErrors { field message }
					}
				}',
				array(
					'productId' => $shopify_id,
					'variants'  => $updates,
				)
			);

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$error = self::user_errors( isset( $data['productVariantsBulkUpdate'] ) ? $data['productVariantsBulkUpdate'] : array() );
			if ( $error ) {
				return $error;
			}
		}

		return true;
	}

	/**
	 * Attach images that the Shopify product does not have yet.
	 *
	 * Shopify downloads the files from the given URLs, so the images have to be
	 * publicly reachable on this shop.
	 *
	 * @param array  $partner    Partner.
	 * @param array  $payload    Product payload.
	 * @param string $shopify_id Product gid.
	 * @return true|WP_Error
	 */
	private static function sync_media( $partner, array $payload, $shopify_id ) {
		if ( empty( $payload['images'] ) ) {
			return true;
		}

		$data = self::graphql(
			$partner,
			'query ProductMedia($id: ID!) {
				product(id: $id) {
					media(first: 50) {
						edges { node { ... on MediaImage { id image { url } } } }
					}
				}
			}',
			array( 'id' => $shopify_id )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$have  = array();
		$edges = isset( $data['product']['media']['edges'] ) ? (array) $data['product']['media']['edges'] : array();
		foreach ( $edges as $edge ) {
			if ( isset( $edge['node']['image']['url'] ) ) {
				$have[] = basename( (string) parse_url( $edge['node']['image']['url'], PHP_URL_PATH ) );
			}
		}

		$media = array();
		foreach ( (array) $payload['images'] as $image ) {
			$name = (string) $image['name'];
			// Shopify renames files it already holds, so the basename is the best match we have.
			foreach ( $have as $existing ) {
				if ( 0 === strpos( $existing, pathinfo( $name, PATHINFO_FILENAME ) ) ) {
					continue 2;
				}
			}

			$media[] = array(
				'originalSource' => (string) $image['src'],
				'alt'            => (string) $image['alt'],
				'mediaContentType' => 'IMAGE',
			);
		}

		if ( empty( $media ) ) {
			return true;
		}

		$data = self::graphql(
			$partner,
			'mutation AddMedia($productId: ID!, $media: [CreateMediaInput!]!) {
				productCreateMedia(productId: $productId, media: $media) {
					media { ... on MediaImage { id } }
					mediaUserErrors { field message }
				}
			}',
			array(
				'productId' => $shopify_id,
				'media'     => $media,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$error = self::user_errors( isset( $data['productCreateMedia'] ) ? $data['productCreateMedia'] : array(), 'mediaUserErrors' );

		return $error ? $error : true;
	}

	/**
	 * Push one batch of products to a partner.
	 *
	 * @param array $partner Partner.
	 * @param int   $page    Page of the product query (1 based).
	 * @return array processed, created, updated, failed, pages, total, done, log
	 */
	public static function push_batch( $partner, $page ) {
		$page   = max( 1, (int) $page );
		$result = WWPro_Product_Payload::page( $partner, $page, self::BATCH_SIZE );

		$created = 0;
		$updated = 0;
		$failed  = 0;
		$log     = array();

		foreach ( $result['products'] as $payload ) {
			$push = self::push_product( $partner, $payload );

			if ( is_wp_error( $push ) ) {
				++$failed;
				$log[] = sprintf(
					/* translators: 1: product name, 2: error message */
					__( 'Shopify error for "%1$s": %2$s', 'woo-wholesale' ),
					$payload['name'],
					$push->get_error_message()
				);
				continue;
			}

			if ( 'created' === $push['action'] ) {
				++$created;
			} else {
				++$updated;
			}
		}

		$processed = count( $result['products'] );

		return array(
			'processed' => $processed,
			'created'   => $created,
			'updated'   => $updated,
			'failed'    => $failed,
			'page'      => $page,
			'pages'     => (int) $result['pages'],
			'total'     => (int) $result['total'],
			'done'      => $page >= (int) $result['pages'] || 0 === $processed,
			'log'       => $log,
		);
	}
}
