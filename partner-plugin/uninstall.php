<?php
/**
 * Uninstall routine. Runs only when the plugin is deleted via the admin.
 *
 * Products, categories and images stay in the shop - they are real articles the
 * partner sells. What goes is the plugin's own bookkeeping, so the products are
 * plain WooCommerce products afterwards.
 *
 * @package WooWholesalePartner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'wwpart_image_check' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wwpart_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wwpart_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_wwpart_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_wwpart_' ) . '%' ) );
// phpcs:enable

delete_option( 'wwpart_settings' );
delete_option( 'wwpart_version' );
delete_option( 'wwpart_image_cursor' );

wp_cache_flush();
