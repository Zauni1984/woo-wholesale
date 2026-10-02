<?php
/**
 * Plugin loader.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class (singleton).
 */
final class WWPart_Plugin {

	/**
	 * Instance.
	 *
	 * @var WWPart_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the runtime requirements are met.
	 *
	 * @var bool
	 */
	private $ready = false;

	/**
	 * Singleton accessor.
	 *
	 * @return WWPart_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
	}

	/**
	 * Declare compatibility with WooCommerce features (HPOS, block checkout).
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WWPART_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WWPART_FILE, true );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'woo-wholesale-partner', false, dirname( WWPART_BASENAME ) . '/languages' );
	}

	/**
	 * Bootstrap once all plugins are loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_wc' ) );
			return;
		}

		if ( version_compare( WC_VERSION, WWPART_MIN_WC, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_old_wc' ) );
			return;
		}

		if ( defined( 'WWPRO_VERSION' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_master_present' ) );
		}

		$this->includes();
		$this->ready = true;

		// Protects the supplier's data, in the editor and through the REST API.
		WWPart_Lock::init();
		WWPart_Images::init();

		if ( is_admin() ) {
			require_once WWPART_PATH . 'includes/class-wwpart-install.php';
			add_action( 'admin_init', array( 'WWPart_Install', 'maybe_upgrade' ), 5 );

			WWPart_Admin::init();
		}

		/**
		 * Fires once Woo Wholesale Partner has been fully loaded.
		 *
		 * @param WWPart_Plugin $plugin Plugin instance.
		 */
		do_action( 'wwpart_loaded', $this );
	}

	/**
	 * Whether the plugin is fully initialised.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->ready;
	}

	/**
	 * Include class files.
	 */
	private function includes() {
		require_once WWPART_PATH . 'includes/class-wwpart-settings.php';
		require_once WWPART_PATH . 'includes/class-wwpart-product.php';
		require_once WWPART_PATH . 'includes/class-wwpart-client.php';
		require_once WWPART_PATH . 'includes/class-wwpart-markup.php';
		require_once WWPART_PATH . 'includes/class-wwpart-images.php';
		require_once WWPART_PATH . 'includes/class-wwpart-sync.php';
		require_once WWPART_PATH . 'includes/class-wwpart-lock.php';

		if ( is_admin() ) {
			require_once WWPART_PATH . 'includes/admin/class-wwpart-admin.php';
		}
	}

	/**
	 * Admin notice: WooCommerce missing.
	 */
	public function notice_missing_wc() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Woo Wholesale Partner requires WooCommerce to be installed and active.', 'woo-wholesale-partner' );
		echo '</p></div>';
	}

	/**
	 * Admin notice: WooCommerce too old.
	 */
	public function notice_old_wc() {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: minimum WooCommerce version */
			esc_html__( 'Woo Wholesale Partner requires WooCommerce %s or newer.', 'woo-wholesale-partner' ),
			esc_html( WWPART_MIN_WC )
		);
		echo '</p></div>';
	}

	/**
	 * Admin notice: this is the partner half, not the supplier half.
	 */
	public function notice_master_present() {
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Woo Wholesale Pro is active in this shop as well. The partner plugin belongs in the partner shop, the Pro plugin in the supplier shop - running both in one shop only makes sense while you are testing.', 'woo-wholesale-partner' );
		echo '</p></div>';
	}
}
