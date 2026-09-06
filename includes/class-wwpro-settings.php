<?php
/**
 * Plugin settings storage.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper (static).
 */
class WWPro_Settings {

	const OPTION        = 'wwpro_settings';
	const CACHE_VERSION = 'wwpro_cache_version';

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
			// Discounts are calculated from: regular price | current price (incl. active sale).
			'discount_base'            => 'regular',
			// Product in several categories with discounts: highest | lowest discount wins.
			'multi_category'           => 'highest',
			// Wholesale price is capped at the regular customer price (never more expensive than a guest).
			'never_above_retail'       => 'yes',
			// Hide the "Sale!" badge for wholesale users.
			'hide_sale_badge'          => 'yes',
			// Also show the secondary (net/gross) price in cart and checkout.
			'secondary_price_in_cart'  => 'yes',
			// Label templates for the secondary price. %s = formatted amount.
			/* translators: %s: formatted price */
			'secondary_net_label'      => __( 'net %s', 'woo-wholesale' ),
			/* translators: %s: formatted price */
			'secondary_gross_label'    => __( 'incl. VAT %s', 'woo-wholesale' ),
			// Tier quantity basis: line (this cart line only) | product (all variations of the same product).
			'tier_qty_basis'           => 'line',
			// Show the tier table on the single product page.
			'show_tier_table'          => 'yes',
			// Show a small "Your price" note next to wholesale prices.
			'show_role_badge'          => 'no',
			// Remove all plugin data when the plugin is deleted.
			'delete_data_on_uninstall' => 'no',
		);
	}

	/**
	 * Get all settings merged with defaults.
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
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default when missing.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Boolean helper for yes/no settings.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Sanitize and persist settings. Unknown keys are ignored.
	 *
	 * @param array $raw Raw input (already unslashed).
	 * @return array Sanitized values that were stored.
	 */
	public static function update( array $raw ) {
		$defaults = self::defaults();
		$clean    = self::all();

		$yes_no = array( 'never_above_retail', 'hide_sale_badge', 'secondary_price_in_cart', 'show_tier_table', 'show_role_badge', 'delete_data_on_uninstall' );
		foreach ( $yes_no as $key ) {
			$clean[ $key ] = ( isset( $raw[ $key ] ) && 'yes' === $raw[ $key ] ) ? 'yes' : 'no';
		}

		$clean['discount_base']  = ( isset( $raw['discount_base'] ) && 'current' === $raw['discount_base'] ) ? 'current' : 'regular';
		$clean['multi_category'] = ( isset( $raw['multi_category'] ) && 'lowest' === $raw['multi_category'] ) ? 'lowest' : 'highest';
		$clean['tier_qty_basis'] = ( isset( $raw['tier_qty_basis'] ) && 'product' === $raw['tier_qty_basis'] ) ? 'product' : 'line';

		foreach ( array( 'secondary_net_label', 'secondary_gross_label' ) as $key ) {
			$value = isset( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
			if ( '' === $value || false === strpos( $value, '%s' ) ) {
				$value = $defaults[ $key ];
			}
			$clean[ $key ] = $value;
		}

		update_option( self::OPTION, $clean, false );
		self::$cache = null;
		self::bump_cache_version();

		return $clean;
	}

	/**
	 * Invalidate every price cache that depends on wholesale rules.
	 */
	public static function bump_cache_version() {
		update_option( self::CACHE_VERSION, (string) time(), false );

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			// Invalidates WooCommerce variation price transients.
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		if ( class_exists( 'WWPro_Pricing' ) ) {
			WWPro_Pricing::flush_runtime_cache();
		}
	}

	/**
	 * Current cache version token.
	 *
	 * @return string
	 */
	public static function cache_version() {
		return (string) get_option( self::CACHE_VERSION, '0' );
	}
}
