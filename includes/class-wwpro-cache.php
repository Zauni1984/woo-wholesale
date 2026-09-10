<?php
/**
 * Page cache compatibility.
 *
 * Wholesale prices are rendered server side, so a full page cache that stores
 * one copy per URL happily serves the guest price to a logged-in B2B customer -
 * LiteSpeed answers such a request from the cache before PHP even runs. Two
 * things are therefore needed: the cache has to keep a separate copy per
 * wholesale role, and it must not store a wholesale page as the public one.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache helper (static).
 */
class WWPro_Cache {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// LiteSpeed: own cache bucket per wholesale role (part of the _lscache_vary cookie).
		add_filter( 'litespeed_vary', array( __CLASS__, 'litespeed_vary' ) );

		// Keep wholesale pages out of the public page cache.
		add_action( 'template_redirect', array( __CLASS__, 'guard_page_cache' ), 1 );

		// Changed rules mean the cached HTML shows yesterday's prices.
		add_action( 'wwpro_cache_version_bumped', array( __CLASS__, 'purge_page_cache' ) );
	}

	/**
	 * Add the wholesale role to LiteSpeed's vary, so every role gets its own copy.
	 *
	 * No value is added for guests: they must keep the shared public copy.
	 *
	 * @param array $vary Vary values.
	 * @return array
	 */
	public static function litespeed_vary( $vary ) {
		if ( ! is_array( $vary ) ) {
			return $vary;
		}

		$role = WWPro_Pricing::current_role();
		if ( $role ) {
			$vary['wwpro_role'] = $role;
		}

		return $vary;
	}

	/**
	 * Tell every page cache to leave this request alone while a wholesale role is active.
	 */
	public static function guard_page_cache() {
		if ( ! WWPro_Settings::is( 'bypass_page_cache' ) ) {
			return;
		}

		if ( ! WWPro_Pricing::current_role() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Honoured by LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache and others.
			define( 'DONOTCACHEPAGE', true );
		}

		do_action( 'litespeed_control_set_nocache', 'Woo Wholesale Pro: role based prices' );

		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Drop cached HTML after a price or rule change.
	 */
	public static function purge_page_cache() {
		do_action( 'litespeed_purge_all' );

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
}
