<?php
/**
 * Wholesale role registry.
 *
 * Every wholesale role is a real WordPress role, so it can be assigned
 * through Users > Edit user like any other role. Role configuration
 * (discounts, display options) lives in a single option.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Roles helper (static).
 */
class WWPro_Roles {

	const OPTION = 'wwpro_roles';

	/**
	 * Request cache of configured roles.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Per-request cache of user => role lookups.
	 *
	 * @var array
	 */
	private static $user_role_cache = array();

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_wp_roles' ), 5 );
	}

	/**
	 * Default configuration for a role.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'key'              => '',
			'name'             => '',
			'description'      => '',
			// Store-wide discount in percent ('' = none).
			'global_discount'  => '',
			// Tax display override: '' (shop default) | incl | excl.
			'tax_display'      => '',
			// Secondary price next to the main price: none | net | gross.
			'secondary_price'  => 'none',
			// Show price/discount fields for this role in the product editor.
			'show_in_product'  => 'yes',
			// Disable coupon usage for this role.
			'disable_coupons'  => 'no',
			// Minimum order subtotal ('' = none).
			'min_order_amount' => '',
			// Show tier table on product pages for this role.
			'show_tiers'       => 'yes',
		);
	}

	/**
	 * Roles that may never be used as wholesale roles.
	 *
	 * @return string[]
	 */
	public static function reserved_keys() {
		return array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager', 'super_admin' );
	}

	/**
	 * Whether a key is reserved.
	 *
	 * @param string $key Role key.
	 * @return bool
	 */
	public static function is_reserved( $key ) {
		return in_array( $key, self::reserved_keys(), true );
	}

	/**
	 * Get all configured roles keyed by role key.
	 *
	 * @return array[]
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$roles  = array();
			if ( is_array( $stored ) ) {
				foreach ( $stored as $key => $role ) {
					if ( ! is_array( $role ) ) {
						continue;
					}
					$key = sanitize_key( $key );
					if ( '' === $key ) {
						continue;
					}
					$role['key']   = $key;
					$roles[ $key ] = wp_parse_args( $role, self::defaults() );
				}
			}
			self::$cache = $roles;
		}
		return self::$cache;
	}

	/**
	 * Configured role keys in priority order.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * Get one role.
	 *
	 * @param string $key Role key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a role key is configured.
	 *
	 * @param string $key Role key.
	 * @return bool
	 */
	public static function exists( $key ) {
		return null !== self::get( $key );
	}

	/**
	 * Display name of a role.
	 *
	 * @param string $key Role key.
	 * @return string
	 */
	public static function label( $key ) {
		$role = self::get( $key );
		return $role ? $role['name'] : $key;
	}

	/**
	 * Roles that show fields in the product editor.
	 *
	 * @return array[]
	 */
	public static function product_roles() {
		return array_filter(
			self::all(),
			function ( $role ) {
				return 'yes' === $role['show_in_product'];
			}
		);
	}

	/**
	 * Validate and sanitize role input.
	 *
	 * @param array $raw    Raw (unslashed) input.
	 * @param bool  $is_new Whether the role is being created.
	 * @return array|WP_Error
	 */
	public static function sanitize( array $raw, $is_new ) {
		$clean = self::defaults();

		$key = isset( $raw['key'] ) ? strtolower( trim( (string) $raw['key'] ) ) : '';
		$key = preg_replace( '/[^a-z0-9_]/', '', str_replace( '-', '_', $key ) );

		if ( '' === $key || strlen( $key ) < 2 || strlen( $key ) > 40 || ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
			return new WP_Error( 'wwpro_invalid_key', __( 'The role key must consist of 2-40 lowercase letters, digits or underscores and start with a letter.', 'woo-wholesale' ) );
		}

		if ( self::is_reserved( $key ) ) {
			return new WP_Error( 'wwpro_reserved_key', __( 'This role key is reserved by WordPress or WooCommerce and cannot be used.', 'woo-wholesale' ) );
		}

		if ( $is_new && self::exists( $key ) ) {
			return new WP_Error( 'wwpro_duplicate_key', __( 'A wholesale role with this key already exists.', 'woo-wholesale' ) );
		}

		if ( ! $is_new && ! self::exists( $key ) ) {
			return new WP_Error( 'wwpro_missing_role', __( 'The wholesale role could not be found.', 'woo-wholesale' ) );
		}

		$clean['key']  = $key;
		$clean['name'] = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
		if ( '' === $clean['name'] ) {
			return new WP_Error( 'wwpro_missing_name', __( 'Please enter a role name.', 'woo-wholesale' ) );
		}

		$clean['description']     = isset( $raw['description'] ) ? sanitize_textarea_field( $raw['description'] ) : '';
		$clean['global_discount'] = self::sanitize_percent( isset( $raw['global_discount'] ) ? $raw['global_discount'] : '' );
		$clean['tax_display']     = ( isset( $raw['tax_display'] ) && in_array( $raw['tax_display'], array( 'incl', 'excl' ), true ) ) ? $raw['tax_display'] : '';
		$clean['secondary_price'] = ( isset( $raw['secondary_price'] ) && in_array( $raw['secondary_price'], array( 'net', 'gross' ), true ) ) ? $raw['secondary_price'] : 'none';
		$clean['show_in_product'] = ( isset( $raw['show_in_product'] ) && 'yes' === $raw['show_in_product'] ) ? 'yes' : 'no';
		$clean['disable_coupons'] = ( isset( $raw['disable_coupons'] ) && 'yes' === $raw['disable_coupons'] ) ? 'yes' : 'no';
		$clean['show_tiers']      = ( isset( $raw['show_tiers'] ) && 'yes' === $raw['show_tiers'] ) ? 'yes' : 'no';

		$min = isset( $raw['min_order_amount'] ) ? self::to_decimal( $raw['min_order_amount'] ) : '';
		$clean['min_order_amount'] = ( '' !== $min && is_numeric( $min ) && (float) $min > 0 ) ? (string) (float) $min : '';

		return $clean;
	}

	/**
	 * Locale-tolerant decimal parsing that also works while WooCommerce is not loaded.
	 *
	 * @param mixed $value Raw value.
	 * @return string Decimal string or ''.
	 */
	public static function to_decimal( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( $value );
		}
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? $value : '';
	}

	/**
	 * Sanitize a percentage value (0-100) or empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_percent( $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = self::to_decimal( $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$value = max( 0, min( 100, (float) $value ) );
		return (string) $value;
	}

	/**
	 * Persist a role (create or update) and sync the WordPress role.
	 *
	 * @param array $role     Role data (raw or sanitized).
	 * @param bool  $is_new   Whether this is a new role.
	 * @param bool  $sanitize Run sanitization (default true).
	 * @return array|WP_Error Stored role or error.
	 */
	public static function save( array $role, $is_new = false, $sanitize = true ) {
		if ( $sanitize ) {
			$role = self::sanitize( $role, $is_new );
			if ( is_wp_error( $role ) ) {
				return $role;
			}
		}

		$all                 = self::all();
		$all[ $role['key'] ] = $role;

		update_option( self::OPTION, $all, false );
		self::$cache           = null;
		self::$user_role_cache = array();

		self::sync_wp_role( $role['key'], $role['name'] );
		WWPro_Settings::bump_cache_version();

		/**
		 * Fires after a wholesale role has been saved.
		 *
		 * @param array $role   Role data.
		 * @param bool  $is_new Whether it was created.
		 */
		do_action( 'wwpro_role_saved', $role, $is_new );

		return $role;
	}

	/**
	 * Delete a role.
	 *
	 * @param string $key         Role key.
	 * @param bool   $delete_data Also delete product/category data of this role.
	 * @param string $reassign_to Role that affected users receive (default customer).
	 * @return bool
	 */
	public static function delete( $key, $delete_data = false, $reassign_to = 'customer' ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}

		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
		self::$cache           = null;
		self::$user_role_cache = array();

		// Move users to a safe role before removing the WP role.
		$reassign_to = sanitize_key( $reassign_to );
		if ( ! wp_roles()->is_role( $reassign_to ) ) {
			$reassign_to = 'customer';
		}

		self::reassign_users( $key, $reassign_to );

		if ( wp_roles()->is_role( $key ) ) {
			remove_role( $key );
		}

		if ( $delete_data ) {
			self::delete_role_data( $key );
		}

		WWPro_Settings::bump_cache_version();

		/**
		 * Fires after a wholesale role has been deleted.
		 *
		 * @param string $key Role key.
		 */
		do_action( 'wwpro_role_deleted', $key );

		return true;
	}

	/**
	 * Move every user from one role to another.
	 *
	 * @param string $from Source role key.
	 * @param string $to   Target role key.
	 * @param int    $limit Max users per call (0 = all).
	 * @return int Number of users moved.
	 */
	public static function reassign_users( $from, $to, $limit = 0 ) {
		if ( ! wp_roles()->is_role( $from ) ) {
			return 0;
		}

		$args = array(
			'role'   => $from,
			'fields' => 'ID',
			'number' => $limit > 0 ? $limit : -1,
		);

		$ids   = get_users( $args );
		$moved = 0;
		foreach ( $ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}
			if ( $to && wp_roles()->is_role( $to ) && ! in_array( $to, (array) $user->roles, true ) ) {
				$user->add_role( $to );
			}
			$user->remove_role( $from );
			++$moved;
		}

		return $moved;
	}

	/**
	 * Delete all product/variation/category meta for a role.
	 *
	 * @param string $key Role key.
	 */
	public static function delete_role_data( $key ) {
		global $wpdb;

		$keys = array(
			WWPro_Pricing::META_PRICE . $key,
			WWPro_Pricing::META_DISCOUNT . $key,
			WWPro_Tiers::META . $key,
		);

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", $keys ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ($placeholders)", $keys ) );

		wp_cache_flush();
	}

	/**
	 * Make sure a WordPress role exists for the given key and carries the display name.
	 *
	 * Roles that already exist (e.g. created by another plugin) keep their capabilities.
	 *
	 * @param string $key  Role key.
	 * @param string $name Display name.
	 */
	public static function sync_wp_role( $key, $name ) {
		$wp_roles = wp_roles();

		if ( ! $wp_roles->is_role( $key ) ) {
			add_role( $key, $name, array( 'read' => true ) );
			return;
		}

		if ( isset( $wp_roles->roles[ $key ]['name'] ) && $wp_roles->roles[ $key ]['name'] !== $name ) {
			$wp_roles->roles[ $key ]['name']  = $name;
			$wp_roles->role_names[ $key ]     = $name;
			$wp_roles->role_objects[ $key ]   = new WP_Role( $key, $wp_roles->roles[ $key ]['capabilities'] );
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}

	/**
	 * Ensure all configured wholesale roles exist as WP roles.
	 */
	public static function ensure_wp_roles() {
		foreach ( self::all() as $key => $role ) {
			if ( ! wp_roles()->is_role( $key ) ) {
				add_role( $key, $role['name'], array( 'read' => true ) );
			}
		}
	}

	/**
	 * Number of users in a role.
	 *
	 * @param string $key Role key.
	 * @return int
	 */
	public static function user_count( $key ) {
		static $counts = null;
		if ( null === $counts ) {
			$data   = count_users();
			$counts = isset( $data['avail_roles'] ) ? $data['avail_roles'] : array();
		}
		return isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
	}

	/**
	 * Wholesale role of a user (first configured role wins), or null.
	 *
	 * @param int|WP_User|null $user User ID or object; null = current user.
	 * @return string|null
	 */
	public static function get_user_role( $user = null ) {
		if ( null === $user ) {
			if ( ! function_exists( 'wp_get_current_user' ) ) {
				return null;
			}
			$user = wp_get_current_user();
		} elseif ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', (int) $user );
		}

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return null;
		}

		$uid = $user->ID;
		if ( isset( self::$user_role_cache[ $uid ] ) ) {
			$cached = self::$user_role_cache[ $uid ];
			return false === $cached ? null : $cached;
		}

		$found = null;
		foreach ( self::keys() as $key ) {
			if ( in_array( $key, (array) $user->roles, true ) ) {
				$found = $key;
				break;
			}
		}

		/**
		 * Filter the wholesale role resolved for a user.
		 *
		 * @param string|null $found Role key or null.
		 * @param WP_User     $user  User.
		 */
		$found = apply_filters( 'wwpro_user_wholesale_role', $found, $user );

		if ( $found && ! self::exists( $found ) ) {
			$found = null;
		}

		// Only cache once WordPress has fully set up the current user.
		if ( did_action( 'init' ) ) {
			self::$user_role_cache[ $uid ] = $found ? $found : false;
		}

		return $found;
	}

	/**
	 * Clear caches (used after imports).
	 */
	public static function flush_cache() {
		self::$cache           = null;
		self::$user_role_cache = array();
	}
}
