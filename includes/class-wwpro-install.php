<?php
/**
 * Activation / deactivation routines.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install helper.
 */
class WWPro_Install {

	/**
	 * Activation callback.
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, WWPRO_MIN_PHP, '<' ) ) {
			return;
		}

		require_once WWPRO_PATH . 'includes/class-wwpro-settings.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-roles.php';

		load_plugin_textdomain( 'woo-wholesale', false, dirname( WWPRO_BASENAME ) . '/languages' );

		// Store defaults without overwriting existing settings.
		if ( false === get_option( WWPro_Settings::OPTION, false ) ) {
			add_option( WWPro_Settings::OPTION, WWPro_Settings::defaults(), '', false );
		}

		// Seed two example roles on first activation only.
		if ( false === get_option( WWPro_Roles::OPTION, false ) ) {
			add_option( WWPro_Roles::OPTION, array(), '', false );

			WWPro_Roles::save(
				array(
					'key'             => 'b2b_customer',
					'name'            => __( 'B2B customer', 'woo-wholesale' ),
					'description'     => __( 'Business customers (retailers). Sees wholesale prices with the net price next to it.', 'woo-wholesale' ),
					'secondary_price' => 'net',
				),
				true
			);

			WWPro_Roles::save(
				array(
					'key'         => 'anbauverein',
					'name'        => __( 'Growers association', 'woo-wholesale' ),
					'description' => __( 'Cannabis social clubs / growers associations.', 'woo-wholesale' ),
				),
				true
			);
		}

		WWPro_Roles::ensure_wp_roles();

		if ( false === get_option( 'wwpro_version', false ) ) {
			add_option( 'wwpro_version', WWPRO_VERSION, '', false );
		} else {
			update_option( 'wwpro_version', WWPRO_VERSION, false );
		}

		WWPro_Settings::bump_cache_version();
	}

	/**
	 * Deactivation callback. Roles and data are intentionally kept.
	 */
	public static function deactivate() {
		if ( class_exists( 'WWPro_Settings' ) ) {
			WWPro_Settings::bump_cache_version();
		}
	}
}
