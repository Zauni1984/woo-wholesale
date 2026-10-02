<?php
/**
 * Activation, upgrade and deactivation.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install helper (static).
 */
class WWPart_Install {

	/**
	 * Activation callback.
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, WWPART_MIN_PHP, '<' ) ) {
			return;
		}

		require_once WWPART_PATH . 'includes/class-wwpart-settings.php';
		require_once WWPART_PATH . 'includes/class-wwpart-images.php';

		load_plugin_textdomain( 'woo-wholesale-partner', false, dirname( WWPART_BASENAME ) . '/languages' );

		if ( false === get_option( WWPart_Settings::OPTION, false ) ) {
			add_option( WWPart_Settings::OPTION, WWPart_Settings::defaults(), '', false );
		}

		self::schedule();

		if ( false === get_option( 'wwpart_version', false ) ) {
			add_option( 'wwpart_version', WWPART_VERSION, '', false );
		} else {
			update_option( 'wwpart_version', WWPART_VERSION, false );
		}
	}

	/**
	 * Make sure the image checker runs in the background.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( WWPart_Images::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', WWPart_Images::CRON_HOOK );
		}
	}

	/**
	 * Run pending upgrade routines.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'wwpart_version', '' );

		if ( WWPART_VERSION === $stored ) {
			return;
		}

		// A new version may have added the background check.
		self::schedule();

		update_option( 'wwpart_version', WWPART_VERSION, false );
	}

	/**
	 * Deactivation callback. Products and settings are kept.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( WWPart_Images::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WWPart_Images::CRON_HOOK );
		}

		wp_clear_scheduled_hook( WWPart_Images::CRON_HOOK );
	}
}
