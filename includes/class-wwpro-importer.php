<?php
/**
 * Importer for "WooCommerce Wholesale Prices" (Rymera / Wholesale Suite) data.
 *
 * Wholesale Suite stores one post meta per role and product:
 *   {role_key}_wholesale_price            fixed wholesale price (free + premium)
 *   {role_key}_wholesale_percentage_discount / {role_key}_wholesale_discount
 *                                         product level percentage (premium, best effort)
 * Category discounts (premium) live in term meta {role_key}_wholesale_discount and
 * store-wide discounts in the option wwpp_option_wholesale_role_general_discount_mapping.
 *
 * Instead of hard-coding role keys the importer scans the database for
 * "*_wholesale_price" meta keys, so custom roles are discovered as well.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Importer (static).
 */
class WWPro_Importer {

	const SUFFIX_PRICE      = '_wholesale_price';
	const SUFFIX_HAVE_PRICE = '_have_wholesale_price';
	const BATCH_SIZE        = 200;
	const USER_BATCH_SIZE   = 100;

	/**
	 * Percentage meta suffixes that are checked when no fixed price exists.
	 *
	 * @return string[]
	 */
	public static function discount_suffixes() {
		return array( '_wholesale_percentage_discount', '_wholesale_discount' );
	}

	/**
	 * Whether the Wholesale Suite plugin is currently active.
	 *
	 * @return bool
	 */
	public static function source_plugin_active() {
		return class_exists( 'WooCommerceWholeSalePrices' ) || class_exists( 'WooCommerceWholeSalePricesPremium' ) || function_exists( 'wwp_get_wholesale_price' );
	}

	/**
	 * Role names registered by Wholesale Suite.
	 *
	 * @return array key => name
	 */
	public static function registered_role_names() {
		$names  = array();
		$option = get_option( 'wwp_options_registered_custom_roles', array() );

		if ( is_string( $option ) ) {
			$maybe = maybe_unserialize( $option );
			$option = is_array( $maybe ) ? $maybe : array();
		}

		if ( is_array( $option ) ) {
			foreach ( $option as $key => $data ) {
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}
				$names[ $key ] = ( is_array( $data ) && ! empty( $data['roleName'] ) ) ? sanitize_text_field( $data['roleName'] ) : $key;
			}
		}

		return $names;
	}

	/**
	 * Scan post meta for wholesale price keys and count products per role key.
	 *
	 * @return array key => product count
	 */
	public static function scan_meta_roles() {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( self::SUFFIX_PRICE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key, COUNT(*) AS cnt
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key LIKE %s
				   AND pm.meta_value <> ''
				   AND pm.meta_value <> '0'
				   AND p.post_type IN ('product','product_variation')
				 GROUP BY pm.meta_key",
				$like
			),
			ARRAY_A
		);

		$roles = array();
		foreach ( (array) $rows as $row ) {
			$meta_key = (string) $row['meta_key'];

			if ( substr( $meta_key, -strlen( self::SUFFIX_HAVE_PRICE ) ) === self::SUFFIX_HAVE_PRICE ) {
				continue;
			}
			if ( 0 === strpos( $meta_key, '_wwpro_' ) || 0 === strpos( $meta_key, '_' ) ) {
				continue;
			}

			$key = substr( $meta_key, 0, -strlen( self::SUFFIX_PRICE ) );
			if ( '' === $key || ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
				continue;
			}

			$roles[ $key ] = (int) $row['cnt'];
		}

		return $roles;
	}

	/**
	 * Count product_cat terms with a discount for a role.
	 *
	 * @param string $src Source role key.
	 * @return int
	 */
	public static function count_categories( $src ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta} tm
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				 WHERE tm.meta_key = %s AND tm.meta_value <> '' AND tt.taxonomy = 'product_cat'",
				$src . '_wholesale_discount'
			)
		);
	}

	/**
	 * Store-wide discount mapping of the source plugin.
	 *
	 * @return array key => percent
	 */
	public static function global_discounts() {
		$mapping = get_option( 'wwpp_option_wholesale_role_general_discount_mapping', array() );
		$out     = array();
		if ( is_array( $mapping ) ) {
			foreach ( $mapping as $key => $value ) {
				$key = sanitize_key( $key );
				if ( '' !== $key && '' !== $value && is_numeric( $value ) ) {
					$out[ $key ] = (float) $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Detect importable data.
	 *
	 * @return array
	 */
	public static function detect() {
		$names   = self::registered_role_names();
		$counts  = self::scan_meta_roles();
		$globals = self::global_discounts();
		$keys    = array_unique( array_merge( array_keys( $names ), array_keys( $counts ), array_keys( $globals ) ) );

		if ( empty( $keys ) && wp_roles()->is_role( 'wholesale_customer' ) ) {
			$keys[] = 'wholesale_customer';
		}

		$roles = array();
		foreach ( $keys as $key ) {
			if ( WWPro_Roles::is_reserved( $key ) ) {
				continue;
			}

			$wp_name = isset( wp_roles()->role_names[ $key ] ) ? translate_user_role( wp_roles()->role_names[ $key ] ) : '';
			$name    = isset( $names[ $key ] ) ? $names[ $key ] : ( $wp_name ? $wp_name : ucwords( str_replace( '_', ' ', $key ) ) );

			$roles[ $key ] = array(
				'key'             => $key,
				'name'            => $name,
				'wp_role_exists'  => wp_roles()->is_role( $key ),
				'users'           => WWPro_Roles::user_count( $key ),
				'products'        => isset( $counts[ $key ] ) ? $counts[ $key ] : 0,
				'categories'      => self::count_categories( $key ),
				'global_discount' => isset( $globals[ $key ] ) ? $globals[ $key ] : null,
				'already_managed' => WWPro_Roles::exists( $key ),
			);
		}

		return array(
			'plugin_active' => self::source_plugin_active(),
			'roles'         => $roles,
			'available'     => ! empty( $roles ),
		);
	}

	/**
	 * Register the target role for a source role.
	 *
	 * @param string $src    Source role key.
	 * @param string $target 'same' (reuse key) or an existing wholesale role key.
	 * @param string $name   Display name for a newly created role.
	 * @return string|WP_Error Target role key.
	 */
	public static function register_role( $src, $target, $name ) {
		if ( 'same' !== $target ) {
			return WWPro_Roles::exists( $target ) ? $target : new WP_Error( 'wwpro_import_target', __( 'Target role does not exist.', 'woo-wholesale' ) );
		}

		if ( WWPro_Roles::exists( $src ) ) {
			return $src;
		}

		$saved = WWPro_Roles::save(
			array(
				'key'  => $src,
				'name' => '' !== $name ? $name : ucwords( str_replace( '_', ' ', $src ) ),
			),
			true
		);

		return is_wp_error( $saved ) ? $saved : $saved['key'];
	}

	/**
	 * Import one batch of product prices.
	 *
	 * @param string $src       Source role key.
	 * @param string $dst       Target role key.
	 * @param int    $offset    Offset.
	 * @param bool   $overwrite Overwrite existing values.
	 * @return array processed, imported, skipped, done, total
	 */
	public static function import_products( $src, $dst, $offset, $overwrite ) {
		global $wpdb;

		$price_key = $src . self::SUFFIX_PRICE;
		$total     = self::count_products( $price_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value <> '' AND pm.meta_value <> '0'
				   AND p.post_type IN ('product','product_variation')
				 ORDER BY pm.post_id ASC
				 LIMIT %d OFFSET %d",
				$price_key,
				self::BATCH_SIZE,
				(int) $offset
			),
			ARRAY_A
		);

		$imported = 0;
		$skipped  = 0;
		$dst_key  = WWPro_Pricing::price_key( $dst );

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$value   = wc_format_decimal( $row['meta_value'] );

			if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
				++$skipped;
				continue;
			}

			$existing = get_post_meta( $post_id, $dst_key, true );
			if ( '' !== $existing && ! $overwrite ) {
				++$skipped;
				continue;
			}

			update_post_meta( $post_id, $dst_key, $value );
			++$imported;
		}

		$processed = count( (array) $rows );

		return array(
			'processed' => $processed,
			'imported'  => $imported,
			'skipped'   => $skipped,
			'total'     => $total,
			'done'      => $processed < self::BATCH_SIZE,
		);
	}

	/**
	 * Import product level percentage discounts (best effort) for products without a fixed price.
	 *
	 * @param string $src       Source role key.
	 * @param string $dst       Target role key.
	 * @param bool   $overwrite Overwrite existing values.
	 * @return int Imported count.
	 */
	public static function import_product_discounts( $src, $dst, $overwrite ) {
		global $wpdb;

		$imported  = 0;
		$dst_key   = WWPro_Pricing::discount_key( $dst );
		$price_key = WWPro_Pricing::price_key( $dst );

		foreach ( self::discount_suffixes() as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s AND pm.meta_value <> ''
					   AND p.post_type IN ('product','product_variation')
					 LIMIT 5000",
					$src . $suffix
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$post_id = (int) $row['post_id'];
				$pct     = WWPro_Roles::sanitize_percent( $row['meta_value'] );
				if ( '' === $pct || (float) $pct <= 0 ) {
					continue;
				}
				if ( '' !== get_post_meta( $post_id, $price_key, true ) ) {
					continue; // A fixed price wins.
				}
				if ( '' !== get_post_meta( $post_id, $dst_key, true ) && ! $overwrite ) {
					continue;
				}
				update_post_meta( $post_id, $dst_key, $pct );
				++$imported;
			}
		}

		return $imported;
	}

	/**
	 * Count products with a source price key.
	 *
	 * @param string $price_key Meta key.
	 * @return int
	 */
	public static function count_products( $price_key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value <> '' AND pm.meta_value <> '0'
				   AND p.post_type IN ('product','product_variation')",
				$price_key
			)
		);
	}

	/**
	 * Import category discounts.
	 *
	 * @param string $src       Source role key.
	 * @param string $dst       Target role key.
	 * @param bool   $overwrite Overwrite existing values.
	 * @return int Imported count.
	 */
	public static function import_categories( $src, $dst, $overwrite ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.term_id, tm.meta_value FROM {$wpdb->termmeta} tm
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				 WHERE tm.meta_key = %s AND tm.meta_value <> '' AND tt.taxonomy = 'product_cat'",
				$src . '_wholesale_discount'
			),
			ARRAY_A
		);

		$imported = 0;
		$dst_key  = WWPro_Pricing::discount_key( $dst );

		foreach ( (array) $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$pct     = WWPro_Roles::sanitize_percent( $row['meta_value'] );
			if ( '' === $pct ) {
				continue;
			}
			if ( '' !== get_term_meta( $term_id, $dst_key, true ) && ! $overwrite ) {
				continue;
			}
			update_term_meta( $term_id, $dst_key, $pct );
			++$imported;
		}

		return $imported;
	}

	/**
	 * Import the store-wide discount.
	 *
	 * @param string $src       Source role key.
	 * @param string $dst       Target role key.
	 * @param bool   $overwrite Overwrite an existing value.
	 * @return bool Whether a value was imported.
	 */
	public static function import_global( $src, $dst, $overwrite ) {
		$globals = self::global_discounts();
		if ( ! isset( $globals[ $src ] ) ) {
			return false;
		}

		$role = WWPro_Roles::get( $dst );
		if ( ! $role ) {
			return false;
		}
		if ( '' !== $role['global_discount'] && ! $overwrite ) {
			return false;
		}

		$role['global_discount'] = WWPro_Roles::sanitize_percent( $globals[ $src ] );
		$saved                   = WWPro_Roles::save( $role, false, false );

		return ! is_wp_error( $saved );
	}

	/**
	 * Move a batch of users from the source role to the target role.
	 *
	 * @param string $src Source role key.
	 * @param string $dst Target role key.
	 * @return int Users moved in this batch.
	 */
	public static function migrate_users( $src, $dst ) {
		if ( $src === $dst ) {
			return 0;
		}
		return WWPro_Roles::reassign_users( $src, $dst, self::USER_BATCH_SIZE );
	}

	/**
	 * Finalise: clear caches.
	 */
	public static function finish() {
		WWPro_Roles::flush_cache();
		WWPro_Settings::bump_cache_version();
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		update_option( 'wwpro_last_import', time(), false );
	}
}
