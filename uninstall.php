<?php
/**
 * Uninstall routine. Runs only when the plugin is deleted via the admin.
 *
 * @package WooWholesalePro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wwpro_settings = get_option( 'wwpro_settings', array() );

if ( ! is_array( $wwpro_settings ) || empty( $wwpro_settings['delete_data_on_uninstall'] ) || 'yes' !== $wwpro_settings['delete_data_on_uninstall'] ) {
	return;
}

global $wpdb;

$wwpro_roles = get_option( 'wwpro_roles', array() );

if ( is_array( $wwpro_roles ) ) {
	foreach ( array_keys( $wwpro_roles ) as $wwpro_key ) {
		$wwpro_key = sanitize_key( $wwpro_key );
		if ( '' === $wwpro_key || ! wp_roles()->is_role( $wwpro_key ) ) {
			continue;
		}

		// Move users to "customer" so nobody loses access to their account.
		$wwpro_users = get_users(
			array(
				'role'   => $wwpro_key,
				'fields' => 'ID',
				'number' => -1,
			)
		);
		foreach ( $wwpro_users as $wwpro_user_id ) {
			$wwpro_user = get_user_by( 'id', $wwpro_user_id );
			if ( $wwpro_user ) {
				if ( wp_roles()->is_role( 'customer' ) ) {
					$wwpro_user->add_role( 'customer' );
				}
				$wwpro_user->remove_role( $wwpro_key );
			}
		}

		remove_role( $wwpro_key );
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wwpro_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wwpro_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_wwpro_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_wwpro_' ) . '%' ) );
// phpcs:enable

delete_option( 'wwpro_settings' );
delete_option( 'wwpro_roles' );
delete_option( 'wwpro_version' );
delete_option( 'wwpro_cache_version' );
delete_option( 'wwpro_last_import' );

wp_cache_flush();
