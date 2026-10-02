<?php
/**
 * Partner shop registry.
 *
 * A partner shop is an external shop that receives products and wholesale
 * prices from this shop. Two kinds exist:
 *
 *   woocommerce  the partner runs the companion plugin "Woo Wholesale Partner"
 *                and pulls the data through the REST API of this plugin.
 *   shopify      this shop pushes the data into the partner's Shopify store
 *                through the Shopify Admin API.
 *
 * Every partner carries a policy. The policy is what the partner shop may and
 * may not change locally, so the administrator of this shop stays in control of
 * the product data.
 *
 * The policy deliberately cannot set a lowest or a fixed resale price. A
 * partner shop is an independent reseller, and prescribing its minimum price is
 * resale price maintenance - a hardcore restriction under Art. 101 TFEU and
 * § 1 GWB. What a supplier may do is cap the price (a maximum resale price) and
 * recommend one, so that is what the policy carries: an optional ceiling and a
 * non-binding recommendation.
 *
 * Credentials are never stored in plain text: the API key of a partner is kept
 * as an HMAC, the Shopify token is encrypted with a key derived from the site
 * salts.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Partner registry (static).
 */
class WWPro_Partners {

	const OPTION = 'wwpro_partners';

	/**
	 * Prefix of a generated API key. Makes leaked keys recognisable.
	 */
	const KEY_PREFIX = 'wwp_';

	/**
	 * Fields a partner shop can be forced to leave alone.
	 *
	 * @return array key => label
	 */
	public static function lockable_fields() {
		return array(
			'title'       => __( 'Product name', 'woo-wholesale' ),
			'description' => __( 'Description and short description', 'woo-wholesale' ),
			'images'      => __( 'Product images', 'woo-wholesale' ),
			'categories'  => __( 'Category assignment', 'woo-wholesale' ),
			'sku'         => __( 'SKU', 'woo-wholesale' ),
			'attributes'  => __( 'Attributes and variations', 'woo-wholesale' ),
			'delete'      => __( 'Deleting and trashing products', 'woo-wholesale' ),
		);
	}

	/**
	 * Partner types.
	 *
	 * @return array key => label
	 */
	public static function types() {
		return array(
			'woocommerce' => __( 'WooCommerce (partner plugin)', 'woo-wholesale' ),
			'shopify'     => __( 'Shopify (Admin API)', 'woo-wholesale' ),
		);
	}

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default partner record.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'                 => '',
			'name'               => '',
			'type'               => 'woocommerce',
			'status'             => 'active',
			// Shop URL of a WooCommerce partner (informational, used for the admin link).
			'site_url'           => '',
			// Wholesale role whose prices this partner receives.
			'role'               => '',
			// Product categories the partner may receive (term IDs, empty = all).
			'categories'         => array(),
			// Only publish products that are in stock.
			'in_stock_only'      => 'no',
			// Also send stock quantities.
			'sync_stock'         => 'yes',
			// Credentials.
			'key_hash'           => '',
			'key_hint'           => '',
			'key_created'        => 0,
			// Policy for the partner shop.
			'lock_fields'        => array( 'title', 'description', 'images', 'categories', 'sku', 'attributes', 'delete' ),
			// Highest markup the partner may apply ('' = no ceiling). A ceiling is
			// a maximum resale price, which is allowed.
			'markup_max'         => '',
			// Non-binding price recommendation ('' = none).
			'markup_recommended' => '',
			// Shopify.
			'shop_domain'        => '',
			'token'              => '',
			'token_hint'         => '',
			// Runtime state.
			'last_sync'          => 0,
			'last_seen'          => 0,
			'last_error'         => '',
			'stats'              => array(),
		);
	}

	/**
	 * All partners keyed by id.
	 *
	 * @return array[]
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored   = get_option( self::OPTION, array() );
			$partners = array();

			if ( is_array( $stored ) ) {
				foreach ( $stored as $id => $partner ) {
					if ( ! is_array( $partner ) ) {
						continue;
					}
					$id = sanitize_key( $id );
					if ( '' === $id ) {
						continue;
					}
					$partner['id']    = $id;
					$partners[ $id ] = wp_parse_args( $partner, self::defaults() );
				}
			}

			self::$cache = $partners;
		}

		return self::$cache;
	}

	/**
	 * One partner.
	 *
	 * @param string $id Partner id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		$id  = sanitize_key( (string) $id );
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Partners of one type.
	 *
	 * @param string $type Type key.
	 * @return array[]
	 */
	public static function by_type( $type ) {
		return array_filter(
			self::all(),
			function ( $partner ) use ( $type ) {
				return $type === $partner['type'];
			}
		);
	}

	/**
	 * Whether a partner is usable for a sync run.
	 *
	 * @param array $partner Partner.
	 * @return bool
	 */
	public static function is_active( $partner ) {
		return is_array( $partner ) && 'active' === $partner['status'] && WWPro_Roles::exists( $partner['role'] );
	}

	/**
	 * Validate and sanitize partner input.
	 *
	 * @param array $raw    Raw (unslashed) input.
	 * @param bool  $is_new Whether the partner is being created.
	 * @return array|WP_Error
	 */
	public static function sanitize( array $raw, $is_new ) {
		$clean = self::defaults();

		if ( $is_new ) {
			$clean['id'] = self::generate_id();
		} else {
			$id       = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
			$existing = self::get( $id );
			if ( ! $existing ) {
				return new WP_Error( 'wwpro_partner_missing', __( 'The partner shop could not be found.', 'woo-wholesale' ) );
			}
			// Keep everything that is not part of the form (credentials, runtime state).
			$clean = $existing;
		}

		$clean['name'] = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
		if ( '' === $clean['name'] ) {
			return new WP_Error( 'wwpro_partner_name', __( 'Please enter a name for the partner shop.', 'woo-wholesale' ) );
		}

		$type          = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'woocommerce';
		$clean['type'] = array_key_exists( $type, self::types() ) ? $type : 'woocommerce';

		$clean['status'] = ( isset( $raw['status'] ) && 'paused' === $raw['status'] ) ? 'paused' : 'active';

		$role = isset( $raw['role'] ) ? sanitize_key( $raw['role'] ) : '';
		if ( '' === $role || ! WWPro_Roles::exists( $role ) ) {
			return new WP_Error( 'wwpro_partner_role', __( 'Please choose the wholesale role whose prices this partner receives.', 'woo-wholesale' ) );
		}
		$clean['role'] = $role;

		$site_url = isset( $raw['site_url'] ) ? trim( (string) $raw['site_url'] ) : '';
		if ( '' !== $site_url ) {
			$site_url = esc_url_raw( $site_url );
			if ( '' === $site_url || ! wp_http_validate_url( $site_url ) ) {
				return new WP_Error( 'wwpro_partner_url', __( 'Please enter a valid shop URL (including https://).', 'woo-wholesale' ) );
			}
			$site_url = untrailingslashit( $site_url );
		}
		$clean['site_url'] = $site_url;

		$categories = array();
		if ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ) {
			foreach ( $raw['categories'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id && term_exists( $term_id, 'product_cat' ) ) {
					$categories[] = $term_id;
				}
			}
		}
		$clean['categories'] = array_values( array_unique( $categories ) );

		$clean['in_stock_only'] = ( isset( $raw['in_stock_only'] ) && 'yes' === $raw['in_stock_only'] ) ? 'yes' : 'no';
		$clean['sync_stock']    = ( isset( $raw['sync_stock'] ) && 'yes' === $raw['sync_stock'] ) ? 'yes' : 'no';

		$lock = array();
		if ( isset( $raw['lock_fields'] ) && is_array( $raw['lock_fields'] ) ) {
			foreach ( $raw['lock_fields'] as $field ) {
				$field = sanitize_key( $field );
				if ( array_key_exists( $field, self::lockable_fields() ) ) {
					$lock[] = $field;
				}
			}
		}
		$clean['lock_fields'] = array_values( array_unique( $lock ) );

		// There is deliberately no lowest markup: a supplier may cap the resale
		// price and may recommend one, but a minimum or fixed resale price is
		// resale price maintenance and not permitted.
		$max         = self::sanitize_markup( isset( $raw['markup_max'] ) ? $raw['markup_max'] : '' );
		$recommended = self::sanitize_markup( isset( $raw['markup_recommended'] ) ? $raw['markup_recommended'] : '' );

		if ( '' !== $max && (float) $max < 0 ) {
			return new WP_Error( 'wwpro_partner_markup_max', __( 'The highest markup cannot be negative - that would force the partner to sell below the wholesale price.', 'woo-wholesale' ) );
		}

		if ( '' !== $max && '' !== $recommended && (float) $recommended > (float) $max ) {
			return new WP_Error( 'wwpro_partner_markup_recommended', __( 'The recommended markup must not be higher than the highest allowed markup.', 'woo-wholesale' ) );
		}

		$clean['markup_max']         = $max;
		$clean['markup_recommended'] = $recommended;

		if ( 'shopify' === $clean['type'] ) {
			$domain = isset( $raw['shop_domain'] ) ? self::sanitize_shop_domain( $raw['shop_domain'] ) : '';
			if ( '' === $domain ) {
				return new WP_Error( 'wwpro_partner_shop_domain', __( 'Please enter the Shopify domain of the partner, for example partner.myshopify.com.', 'woo-wholesale' ) );
			}
			$clean['shop_domain'] = $domain;

			// An empty token field means "keep the stored token".
			$token = isset( $raw['token'] ) ? trim( (string) $raw['token'] ) : '';
			if ( '' !== $token ) {
				if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,255}$/', $token ) ) {
					return new WP_Error( 'wwpro_partner_token', __( 'The Shopify Admin API access token looks invalid.', 'woo-wholesale' ) );
				}
				$clean['token']      = self::encrypt( $token );
				$clean['token_hint'] = self::hint( $token );
			} elseif ( '' === $clean['token'] ) {
				return new WP_Error( 'wwpro_partner_token_missing', __( 'Please enter the Shopify Admin API access token.', 'woo-wholesale' ) );
			}
		}

		return $clean;
	}

	/**
	 * Sanitize a markup percentage ('' allowed).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_markup( $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = WWPro_Roles::to_decimal( $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return (string) max( -90, min( 1000, (float) $value ) );
	}

	/**
	 * Normalise a Shopify shop domain.
	 *
	 * @param string $value Raw value.
	 * @return string '' when invalid.
	 */
	public static function sanitize_shop_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '#^https?://#', '', $value );
		$value = trim( (string) $value, '/' );
		$value = preg_replace( '#/.*$#', '', (string) $value );

		if ( '' === $value || ! preg_match( '/^[a-z0-9][a-z0-9.\-]{1,100}\.[a-z]{2,20}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Persist a partner.
	 *
	 * @param array $partner  Partner data (raw or sanitized).
	 * @param bool  $is_new   Whether this is a new partner.
	 * @param bool  $sanitize Run sanitization (default true).
	 * @return array|WP_Error
	 */
	public static function save( array $partner, $is_new = false, $sanitize = true ) {
		if ( $sanitize ) {
			$partner = self::sanitize( $partner, $is_new );
			if ( is_wp_error( $partner ) ) {
				return $partner;
			}
		}

		$all                     = self::all();
		$all[ $partner['id'] ]   = $partner;

		update_option( self::OPTION, $all, false );
		self::$cache = null;

		/**
		 * Fires after a partner shop has been saved.
		 *
		 * @param array $partner Partner data.
		 * @param bool  $is_new  Whether it was created.
		 */
		do_action( 'wwpro_partner_saved', $partner, $is_new );

		return $partner;
	}

	/**
	 * Update single fields of a partner without running the form validation.
	 *
	 * @param string $id     Partner id.
	 * @param array  $fields Fields to merge.
	 * @return bool
	 */
	public static function touch( $id, array $fields ) {
		$partner = self::get( $id );
		if ( ! $partner ) {
			return false;
		}

		$allowed = array( 'last_sync', 'last_seen', 'last_error', 'stats' );
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$partner[ $key ] = $value;
			}
		}

		self::save( $partner, false, false );

		return true;
	}

	/**
	 * Delete a partner.
	 *
	 * @param string $id Partner id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$all = self::all();
		$id  = sanitize_key( (string) $id );

		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		self::$cache = null;

		/**
		 * Fires after a partner shop has been deleted.
		 *
		 * @param string $id Partner id.
		 */
		do_action( 'wwpro_partner_deleted', $id );

		return true;
	}

	/**
	 * A new unique partner id.
	 *
	 * @return string
	 */
	public static function generate_id() {
		$all = self::all();
		do {
			$id = 'p' . substr( md5( uniqid( 'wwpro', true ) ), 0, 9 );
		} while ( isset( $all[ $id ] ) );

		return $id;
	}

	/**
	 * Generate, store and return a new API key for a partner.
	 *
	 * The plain key is returned exactly once and never stored.
	 *
	 * @param string $id Partner id.
	 * @return string|WP_Error Plain key.
	 */
	public static function issue_key( $id ) {
		$partner = self::get( $id );
		if ( ! $partner ) {
			return new WP_Error( 'wwpro_partner_missing', __( 'The partner shop could not be found.', 'woo-wholesale' ) );
		}

		try {
			$secret = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'wwpro_partner_random', __( 'This server cannot generate a secure key.', 'woo-wholesale' ) );
		}

		$key = self::KEY_PREFIX . $partner['id'] . '_' . $secret;

		$partner['key_hash']    = self::hash_key( $key );
		$partner['key_hint']    = self::hint( $key );
		$partner['key_created'] = time();

		self::save( $partner, false, false );

		return $key;
	}

	/**
	 * Revoke the API key of a partner.
	 *
	 * @param string $id Partner id.
	 * @return bool
	 */
	public static function revoke_key( $id ) {
		$partner = self::get( $id );
		if ( ! $partner ) {
			return false;
		}

		$partner['key_hash']    = '';
		$partner['key_hint']    = '';
		$partner['key_created'] = 0;

		self::save( $partner, false, false );

		return true;
	}

	/**
	 * HMAC of an API key.
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function hash_key( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'wwpro_partner_key' ) );
	}

	/**
	 * Find the partner a plain API key belongs to.
	 *
	 * The partner id is part of the key, so the lookup is a single comparison
	 * instead of a scan, and the comparison itself is timing safe.
	 *
	 * @param string $key Plain key.
	 * @return array|null
	 */
	public static function find_by_key( $key ) {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key || 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			return null;
		}

		$rest = substr( $key, strlen( self::KEY_PREFIX ) );
		$pos  = strpos( $rest, '_' );
		if ( false === $pos ) {
			return null;
		}

		$partner = self::get( substr( $rest, 0, $pos ) );
		if ( ! $partner || '' === $partner['key_hash'] ) {
			return null;
		}

		return hash_equals( $partner['key_hash'], self::hash_key( $key ) ) ? $partner : null;
	}

	/**
	 * Short hint of a secret for the admin UI ("wwp_p1234…9abc").
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function hint( $secret ) {
		$secret = (string) $secret;
		if ( strlen( $secret ) < 12 ) {
			return str_repeat( '*', max( 0, strlen( $secret ) ) );
		}
		return substr( $secret, 0, 8 ) . '…' . substr( $secret, -4 );
	}

	/**
	 * Policy that is handed to the partner shop.
	 *
	 * @param array $partner Partner.
	 * @return array
	 */
	public static function policy( $partner ) {
		return array(
			'locked_fields'      => array_values( (array) $partner['lock_fields'] ),
			// null means the partner has no ceiling at all.
			'markup_max'         => '' === $partner['markup_max'] ? null : (float) $partner['markup_max'],
			// A recommendation only: it prefills the field in the partner shop and
			// is never enforced.
			'markup_recommended' => '' === $partner['markup_recommended'] ? null : (float) $partner['markup_recommended'],
			'sync_stock'         => 'yes' === $partner['sync_stock'],
			'price_decimals'     => wc_get_price_decimals(),
			'currency'           => get_woocommerce_currency(),
		);
	}

	/**
	 * Encryption key derived from the site salts.
	 *
	 * @return string
	 */
	private static function crypto_key() {
		return hash( 'sha256', wp_salt( 'wwpro_partner_token' ), true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * Falls back to a marked base64 value when OpenSSL is unavailable, so the
	 * value stays usable instead of breaking the integration.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	public static function encrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = '';
			try {
				$iv = random_bytes( 16 );
			} catch ( Exception $e ) {
				$iv = '';
			}

			if ( 16 === strlen( $iv ) ) {
				$cipher = openssl_encrypt( $value, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
				if ( false !== $cipher ) {
					return 'enc1:' . base64_encode( $iv . $cipher );
				}
			}
		}

		return 'raw1:' . base64_encode( $value );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'raw1:' ) ) {
			return (string) base64_decode( substr( $stored, 5 ), true );
		}

		if ( 0 === strpos( $stored, 'enc1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$blob = base64_decode( substr( $stored, 5 ), true );
			if ( false === $blob || strlen( $blob ) <= 16 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $blob, 16 ), 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, substr( $blob, 0, 16 ) );
			return false === $plain ? '' : $plain;
		}

		return '';
	}

	/**
	 * Shopify access token of a partner in plain text.
	 *
	 * @param array $partner Partner.
	 * @return string
	 */
	public static function shopify_token( $partner ) {
		return isset( $partner['token'] ) ? self::decrypt( $partner['token'] ) : '';
	}

	/**
	 * Clear the request cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
