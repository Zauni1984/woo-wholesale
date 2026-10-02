<?php
/**
 * Settings and cached supplier policy.
 *
 * Everything the partner shop knows about its supplier lives here: the
 * connection, the markup the partner applies on top of the wholesale price and
 * the policy the supplier handed over on the last connect. The policy is the
 * part the partner cannot edit - it is overwritten on every sync.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper (static).
 */
class WWPart_Settings {

	const OPTION = 'wwpart_settings';

	/**
	 * Term meta that holds the markup of a product category.
	 */
	const TERM_MARKUP = '_wwpart_markup';

	/**
	 * Deepest markup the shop accepts, so a sales price keeps a remainder.
	 *
	 * This is a technical floor, not a rule of the supplier: a supplier cannot
	 * prescribe a minimum resale price.
	 */
	const MIN_MARKUP = -90.0;

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection.
			'master_url'     => '',
			'api_key'        => '',
			'key_hint'       => '',
			// Markup the partner puts on the wholesale price, in percent.
			'markup'         => '30',
			'rounding'       => 'none',
			// Status of newly created products.
			'new_status'     => 'draft',
			// Download product images from the supplier.
			'sync_images'    => 'yes',
			// What happens to products the supplier no longer delivers.
			'on_removed'     => 'draft',
			// Automatically retry missing images in the background.
			'auto_images'    => 'yes',
			// Supplier information from the last connect.
			'master_name'    => '',
			'partner_name'   => '',
			'role_name'      => '',
			'currency'       => '',
			// Policy from the supplier.
			'policy'         => array(),
			// Runtime state.
			'last_sync'      => 0,
			'last_error'     => '',
			'last_summary'   => array(),
		);
	}

	/**
	 * Default policy, used while no supplier has been contacted yet.
	 *
	 * A supplier can cap this shop's markup and recommend one, but it cannot
	 * prescribe a lowest or a fixed resale price - that would be resale price
	 * maintenance. Lowering the price is therefore always this shop's decision.
	 *
	 * @return array
	 */
	public static function default_policy() {
		return array(
			'locked_fields'      => array(),
			// null = the supplier set no ceiling.
			'markup_max'         => null,
			// A recommendation, never enforced.
			'markup_recommended' => null,
			'sync_stock'         => true,
			'price_decimals'     => 2,
			'currency'           => '',
		);
	}

	/**
	 * All settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Boolean helper for yes/no settings.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Write settings without validation (internal state updates).
	 *
	 * @param array $fields Fields to merge.
	 */
	public static function set( array $fields ) {
		$all = self::all();
		foreach ( $fields as $key => $value ) {
			$all[ $key ] = $value;
		}
		update_option( self::OPTION, $all, false );
		self::$cache = null;
	}

	/**
	 * Whether a supplier connection is configured.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::get( 'master_url' ) && '' !== self::get( 'api_key' );
	}

	/**
	 * Supplier policy with defaults filled in.
	 *
	 * @return array
	 */
	public static function policy() {
		$stored = self::get( 'policy' );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_policy() );
	}

	/**
	 * Whether a field may not be edited locally.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function is_locked( $field ) {
		$policy = self::policy();
		return in_array( $field, (array) $policy['locked_fields'], true );
	}

	/**
	 * Store the policy that came from the supplier.
	 *
	 * @param array $policy Raw policy from the API.
	 */
	public static function store_policy( $policy ) {
		if ( ! is_array( $policy ) ) {
			return;
		}

		$clean = self::default_policy();

		if ( isset( $policy['locked_fields'] ) && is_array( $policy['locked_fields'] ) ) {
			$clean['locked_fields'] = array_values( array_filter( array_map( 'sanitize_key', $policy['locked_fields'] ) ) );
		}

		// A ceiling only counts when the supplier really sent one, and only when
		// it is not negative - a supplier cannot force a sale below its own price.
		$clean['markup_max'] = ( isset( $policy['markup_max'] ) && null !== $policy['markup_max'] && (float) $policy['markup_max'] >= 0 )
			? (float) $policy['markup_max']
			: null;

		$clean['markup_recommended'] = ( isset( $policy['markup_recommended'] ) && null !== $policy['markup_recommended'] )
			? (float) $policy['markup_recommended']
			: null;

		$clean['sync_stock']     = ! empty( $policy['sync_stock'] );
		$clean['price_decimals'] = isset( $policy['price_decimals'] ) ? max( 0, min( 4, (int) $policy['price_decimals'] ) ) : 2;
		$clean['currency']       = isset( $policy['currency'] ) ? sanitize_text_field( (string) $policy['currency'] ) : '';

		self::set( array( 'policy' => $clean ) );
	}

	/**
	 * Sanitize and store the connection form.
	 *
	 * @param array $raw Raw (unslashed) input.
	 * @return true|WP_Error
	 */
	public static function update_connection( array $raw ) {
		$url = isset( $raw['master_url'] ) ? trim( (string) $raw['master_url'] ) : '';
		$url = esc_url_raw( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'wwpart_url', __( 'Please enter the address of the supplier shop, including https://.', 'woo-wholesale-partner' ) );
		}

		$fields = array( 'master_url' => untrailingslashit( $url ) );

		$key = isset( $raw['api_key'] ) ? trim( (string) $raw['api_key'] ) : '';
		if ( '' !== $key ) {
			if ( ! preg_match( '/^wwp_[A-Za-z0-9_]{10,200}$/', $key ) ) {
				return new WP_Error( 'wwpart_key', __( 'That does not look like a partner key. A key starts with "wwp_".', 'woo-wholesale-partner' ) );
			}
			$fields['api_key']  = WWPart_Settings::encrypt( $key );
			$fields['key_hint'] = substr( $key, 0, 12 ) . '…';
		} elseif ( '' === self::get( 'api_key' ) ) {
			return new WP_Error( 'wwpart_key_missing', __( 'Please enter the partner key you received from the supplier.', 'woo-wholesale-partner' ) );
		}

		self::set( $fields );

		return true;
	}

	/**
	 * Sanitize and store the price form.
	 *
	 * @param array $raw Raw (unslashed) input.
	 * @return true|WP_Error
	 */
	public static function update_prices( array $raw ) {
		$policy = self::policy();

		$markup = self::sanitize_percent( isset( $raw['markup'] ) ? $raw['markup'] : '' );
		if ( '' === $markup ) {
			return new WP_Error( 'wwpart_markup', __( 'Please enter a markup in percent.', 'woo-wholesale-partner' ) );
		}

		// Only the ceiling is enforced. Going lower is always this shop's call.
		if ( null !== $policy['markup_max'] && (float) $markup > $policy['markup_max'] ) {
			return new WP_Error(
				'wwpart_markup_range',
				sprintf(
					/* translators: %s: highest allowed markup */
					__( 'Your supplier caps the markup at %s %%.', 'woo-wholesale-partner' ),
					wc_format_localized_decimal( $policy['markup_max'] )
				)
			);
		}

		$rounding = isset( $raw['rounding'] ) && array_key_exists( $raw['rounding'], self::rounding_modes() ) ? $raw['rounding'] : 'none';

		self::set(
			array(
				'markup'   => $markup,
				'rounding' => $rounding,
			)
		);

		return true;
	}

	/**
	 * Sanitize and store the sync options form.
	 *
	 * @param array $raw Raw (unslashed) input.
	 * @return true
	 */
	public static function update_options( array $raw ) {
		$fields = array(
			'new_status'  => ( isset( $raw['new_status'] ) && 'publish' === $raw['new_status'] ) ? 'publish' : 'draft',
			'sync_images' => ( isset( $raw['sync_images'] ) && 'yes' === $raw['sync_images'] ) ? 'yes' : 'no',
			'auto_images' => ( isset( $raw['auto_images'] ) && 'yes' === $raw['auto_images'] ) ? 'yes' : 'no',
			'on_removed'  => ( isset( $raw['on_removed'] ) && in_array( $raw['on_removed'], array( 'ignore', 'draft', 'trash' ), true ) ) ? $raw['on_removed'] : 'draft',
		);

		self::set( $fields );

		return true;
	}

	/**
	 * Rounding modes for the sales price.
	 *
	 * @return array key => label
	 */
	public static function rounding_modes() {
		return array(
			'none' => __( 'Shop decimals (no extra rounding)', 'woo-wholesale-partner' ),
			'005'  => __( 'Round to 0.05', 'woo-wholesale-partner' ),
			'010'  => __( 'Round to 0.10', 'woo-wholesale-partner' ),
			'full' => __( 'Round to whole currency units', 'woo-wholesale-partner' ),
			'99'   => __( 'Price ending in .99', 'woo-wholesale-partner' ),
			'95'   => __( 'Price ending in .95', 'woo-wholesale-partner' ),
		);
	}

	/**
	 * Sanitize a percentage, keeping the sign.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_percent( $value ) {
		$value = is_string( $value ) ? trim( str_replace( ',', '.', $value ) ) : $value;

		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return (string) max( self::MIN_MARKUP, min( 1000, (float) $value ) );
	}

	/**
	 * Markup stored on a product category ('' when the category inherits).
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public static function category_markup( $term_id ) {
		$value = get_term_meta( (int) $term_id, self::TERM_MARKUP, true );
		return ( '' === $value || null === $value || ! is_numeric( $value ) ) ? '' : (string) $value;
	}

	/**
	 * Store the markup of a product category ('' removes it).
	 *
	 * @param int   $term_id Term ID.
	 * @param mixed $value   Raw value.
	 * @return string Stored value.
	 */
	public static function set_category_markup( $term_id, $value ) {
		$clean = self::sanitize_percent( $value );

		if ( '' === $clean ) {
			delete_term_meta( (int) $term_id, self::TERM_MARKUP );
			return '';
		}

		update_term_meta( (int) $term_id, self::TERM_MARKUP, $clean );

		return $clean;
	}

	/**
	 * Encryption key derived from the site salts.
	 *
	 * @return string
	 */
	private static function crypto_key() {
		return hash( 'sha256', wp_salt( 'wwpart_key' ), true );
	}

	/**
	 * Encrypt a secret for storage.
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
	 * The partner key in plain text.
	 *
	 * @return string
	 */
	public static function api_key() {
		return self::decrypt( self::get( 'api_key' ) );
	}

	/**
	 * Clear the request cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
