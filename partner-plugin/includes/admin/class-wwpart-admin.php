<?php
/**
 * Admin screens of the partner plugin.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin (static).
 */
class WWPart_Admin {

	const PAGE       = 'wwpart';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Products verified per batch of the manual image check.
	 */
	const VERIFY_BATCH = 20;

	/**
	 * Products downloaded per batch of the manual image check.
	 */
	const DOWNLOAD_BATCH = 5;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_action( 'admin_post_wwpart_save_connection', array( __CLASS__, 'handle_save_connection' ) );
		add_action( 'admin_post_wwpart_save_prices', array( __CLASS__, 'handle_save_prices' ) );
		add_action( 'admin_post_wwpart_save_options', array( __CLASS__, 'handle_save_options' ) );
		add_action( 'admin_post_wwpart_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_wwpart_disconnect', array( __CLASS__, 'handle_disconnect' ) );

		add_action( 'wp_ajax_wwpart_sync_step', array( __CLASS__, 'ajax_sync_step' ) );
		add_action( 'wp_ajax_wwpart_markup_step', array( __CLASS__, 'ajax_markup_step' ) );
		add_action( 'wp_ajax_wwpart_image_step', array( __CLASS__, 'ajax_image_step' ) );

		add_filter( 'plugin_action_links_' . WWPART_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entry under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Supplier', 'woo-wholesale-partner' ),
			__( 'Supplier', 'woo-wholesale-partner' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * URL of an admin tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function url( $tab = 'connection', $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'connection' => __( 'Supplier', 'woo-wholesale-partner' ),
			'sync'       => __( 'Sync', 'woo-wholesale-partner' ),
			'prices'     => __( 'Prices', 'woo-wholesale-partner' ),
			'images'     => __( 'Images', 'woo-wholesale-partner' ),
			'help'       => __( 'Help', 'woo-wholesale-partner' ),
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'connection';
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-wholesale-partner' ) );
		}

		$tab = self::current_tab();
		?>
		<div class="wrap wwpart-wrap">
			<h1><?php esc_html_e( 'Woo Wholesale Partner', 'woo-wholesale-partner' ); ?></h1>
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php include WWPART_PATH . 'includes/admin/views/' . $tab . '.php'; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue assets on the plugin screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_our = ( false !== strpos( $hook, self::PAGE ) );
		$is_pro = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );

		if ( ! $is_our && ! $is_pro ) {
			return;
		}

		wp_enqueue_style( 'wwpart-admin', WWPART_URL . 'assets/css/admin.css', array(), WWPART_VERSION );

		if ( ! $is_our ) {
			return;
		}

		wp_enqueue_script( 'wwpart-admin', WWPART_URL . 'assets/js/admin.js', array( 'jquery' ), WWPART_VERSION, true );

		wp_localize_script(
			'wwpart-admin',
			'wwpartAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'syncNonce'    => wp_create_nonce( 'wwpart_sync' ),
				'markupNonce'  => wp_create_nonce( 'wwpart_markup' ),
				'imageNonce'   => wp_create_nonce( 'wwpart_image' ),
				'i18n'         => array(
					'working'       => __( 'Working…', 'woo-wholesale-partner' ),
					'error'         => __( 'The run stopped because of an error. Please check the log and try again.', 'woo-wholesale-partner' ),
					'syncDone'      => __( 'Sync finished.', 'woo-wholesale-partner' ),
					'markupDone'    => __( 'Prices updated.', 'woo-wholesale-partner' ),
					'imagesDone'    => __( 'Image check finished.', 'woo-wholesale-partner' ),
					'confirmSync'   => __( 'Start the sync now? Products from your supplier are created or updated.', 'woo-wholesale-partner' ),
					'confirmMarkup' => __( 'Apply this markup now? The sales prices of the selected products are recalculated.', 'woo-wholesale-partner' ),
					'pickCategory'  => __( 'Please choose a product category.', 'woo-wholesale-partner' ),
				),
			)
		);
	}

	/**
	 * Plugin list action links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'connection' ) ) . '">' . esc_html__( 'Settings', 'woo-wholesale-partner' ) . '</a>' );
		return $links;
	}

	/**
	 * Store a one-time admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning|info.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$notices   = get_transient( 'wwpart_notices_' . get_current_user_id() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( 'wwpart_notices_' . get_current_user_id(), $notices, 120 );
	}

	/**
	 * Print notices.
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notices = get_transient( 'wwpart_notices_' . get_current_user_id() );

		if ( is_array( $notices ) && ! empty( $notices ) ) {
			delete_transient( 'wwpart_notices_' . get_current_user_id() );
			foreach ( $notices as $notice ) {
				$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			}
		}

		$screen = get_current_screen();

		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}

		// A shop that waits for images should see it without opening the plugin.
		if ( $screen && 'product' === $screen->post_type && 'edit' === $screen->base ) {
			$failed = WWPart_Images::count_in_state( 'failed' );

			if ( $failed > 0 ) {
				echo '<div class="notice notice-warning"><p>';
				printf(
					/* translators: 1: number of products, 2: opening link tag, 3: closing link tag */
					esc_html__( '%1$d products from your supplier are still missing images. %2$sRun the image check%3$s.', 'woo-wholesale-partner' ),
					(int) $failed,
					'<a href="' . esc_url( self::url( 'images' ) ) . '">',
					'</a>'
				);
				echo '</p></div>';
			}
		}
	}

	/**
	 * Capability and nonce guard for admin_post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wholesale-partner' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Handler: save the supplier connection.
	 */
	public static function handle_save_connection() {
		self::guard( 'wwpart_save_connection' );

		$raw    = isset( $_POST['connection'] ) && is_array( $_POST['connection'] ) ? wp_unslash( $_POST['connection'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPart_Settings.
		$result = WWPart_Settings::update_connection( $raw );

		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
		} else {
			self::add_notice( __( 'Connection saved. Use "Test connection" to fetch your supplier policy.', 'woo-wholesale-partner' ) );
		}

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * Handler: test the connection and store the policy.
	 */
	public static function handle_connect() {
		self::guard( 'wwpart_connect' );

		$manifest = WWPart_Client::manifest();

		if ( is_wp_error( $manifest ) ) {
			WWPart_Settings::set( array( 'last_error' => $manifest->get_error_message() ) );
			self::add_notice( $manifest->get_error_message(), 'error' );
			wp_safe_redirect( self::url( 'connection' ) );
			exit;
		}

		self::store_manifest( $manifest );

		self::add_notice(
			sprintf(
				/* translators: 1: supplier shop name, 2: number of products */
				__( 'Connected to "%1$s". %2$d products are available for you.', 'woo-wholesale-partner' ),
				isset( $manifest['master']['name'] ) ? (string) $manifest['master']['name'] : '',
				isset( $manifest['catalog']['products'] ) ? (int) $manifest['catalog']['products'] : 0
			)
		);

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * Store what a manifest told us about the supplier.
	 *
	 * @param array $manifest Manifest response.
	 */
	public static function store_manifest( array $manifest ) {
		WWPart_Settings::store_policy( isset( $manifest['policy'] ) ? $manifest['policy'] : array() );

		$fields = array( 'last_error' => '' );

		if ( isset( $manifest['master']['name'] ) ) {
			$fields['master_name'] = sanitize_text_field( (string) $manifest['master']['name'] );
		}
		if ( isset( $manifest['master']['currency'] ) ) {
			$fields['currency'] = sanitize_text_field( (string) $manifest['master']['currency'] );
		}
		if ( isset( $manifest['partner']['name'] ) ) {
			$fields['partner_name'] = sanitize_text_field( (string) $manifest['partner']['name'] );
		}
		if ( isset( $manifest['partner']['role_name'] ) ) {
			$fields['role_name'] = sanitize_text_field( (string) $manifest['partner']['role_name'] );
		}

		WWPart_Settings::set( $fields );

		// A fresh connection may come with a markup the supplier recommends. It is
		// only used to prefill an empty field - the price stays this shop's call.
		$policy = WWPart_Settings::policy();
		$markup = WWPart_Settings::get( 'markup' );

		if ( null !== $policy['markup_recommended'] && ( '' === $markup || ! is_numeric( $markup ) ) ) {
			WWPart_Settings::set( array( 'markup' => (string) $policy['markup_recommended'] ) );
		}
	}

	/**
	 * Handler: forget the supplier connection.
	 */
	public static function handle_disconnect() {
		self::guard( 'wwpart_disconnect' );

		WWPart_Settings::set(
			array(
				'api_key'    => '',
				'key_hint'   => '',
				'last_error' => '',
			)
		);

		self::add_notice( __( 'The partner key was removed. Products already in this shop are kept.', 'woo-wholesale-partner' ) );

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * Handler: save the price settings and the category markups.
	 */
	public static function handle_save_prices() {
		self::guard( 'wwpart_save_prices' );

		$raw    = isset( $_POST['prices'] ) && is_array( $_POST['prices'] ) ? wp_unslash( $_POST['prices'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPart_Settings.
		$result = WWPart_Settings::update_prices( $raw );

		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( self::url( 'prices' ) );
			exit;
		}

		$categories = isset( $_POST['category_markup'] ) && is_array( $_POST['category_markup'] ) ? wp_unslash( $_POST['category_markup'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$policy     = WWPart_Settings::policy();
		$rejected   = 0;

		foreach ( $categories as $term_id => $value ) {
			$term_id = absint( $term_id );
			if ( ! $term_id || ! term_exists( $term_id, 'product_cat' ) ) {
				continue;
			}

			$clean = WWPart_Settings::sanitize_percent( $value );

			// Only the supplier's ceiling is enforced; a lower markup is allowed.
			if ( '' !== $clean && null !== $policy['markup_max'] && (float) $clean > $policy['markup_max'] ) {
				++$rejected;
				continue;
			}

			WWPart_Settings::set_category_markup( $term_id, $clean );
		}

		if ( $rejected > 0 ) {
			self::add_notice(
				sprintf(
					/* translators: %d: number of categories */
					__( '%d category markups were above the ceiling your supplier set and were not saved.', 'woo-wholesale-partner' ),
					(int) $rejected
				),
				'warning'
			);
		}

		self::add_notice( __( 'Prices saved. Use "Apply to products" so the sales prices are recalculated.', 'woo-wholesale-partner' ) );

		wp_safe_redirect( self::url( 'prices' ) );
		exit;
	}

	/**
	 * Handler: save the sync options.
	 */
	public static function handle_save_options() {
		self::guard( 'wwpart_save_options' );

		$raw = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPart_Settings.
		WWPart_Settings::update_options( $raw );

		self::add_notice( __( 'Settings saved.', 'woo-wholesale-partner' ) );

		wp_safe_redirect( self::url( 'sync' ) );
		exit;
	}

	/**
	 * Guard for the AJAX endpoints.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function ajax_guard( $nonce_action ) {
		check_ajax_referer( $nonce_action, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'woo-wholesale-partner' ) ), 403 );
		}
	}

	/**
	 * AJAX: one step of the sync.
	 *
	 * connect -> categories -> products -> images -> cleanup -> report -> done
	 */
	public static function ajax_sync_step() {
		self::ajax_guard( 'wwpart_sync' );

		if ( ! WWPart_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'No supplier shop is configured yet.', 'woo-wholesale-partner' ) ) );
		}

		$step   = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'connect';
		$page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$total  = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;

		$counts = array(
			'created' => isset( $_POST['created'] ) ? absint( $_POST['created'] ) : 0,
			'updated' => isset( $_POST['updated'] ) ? absint( $_POST['updated'] ) : 0,
			'skipped' => isset( $_POST['skipped'] ) ? absint( $_POST['skipped'] ) : 0,
			'failed'  => isset( $_POST['failed'] ) ? absint( $_POST['failed'] ) : 0,
		);

		$log  = array();
		$next = array(
			'step'   => $step,
			'page'   => $page,
			'offset' => 0,
			'total'  => $total,
		);

		switch ( $step ) {
			case 'connect':
				$manifest = WWPart_Client::manifest();

				if ( is_wp_error( $manifest ) ) {
					WWPart_Settings::set( array( 'last_error' => $manifest->get_error_message() ) );
					wp_send_json_error( array( 'message' => $manifest->get_error_message() ) );
				}

				self::store_manifest( $manifest );
				WWPart_Product::reset_seen();

				$total        = isset( $manifest['catalog']['products'] ) ? (int) $manifest['catalog']['products'] : 0;
				$next['total'] = $total;
				$next['step']  = 'categories';

				$log[] = sprintf(
					/* translators: 1: supplier name, 2: number of products */
					__( 'Connected to "%1$s", %2$d products to check.', 'woo-wholesale-partner' ),
					isset( $manifest['master']['name'] ) ? (string) $manifest['master']['name'] : '',
					$total
				);
				break;

			case 'categories':
				$response = WWPart_Client::categories();

				if ( is_wp_error( $response ) ) {
					wp_send_json_error( array( 'message' => $response->get_error_message() ) );
				}

				$result = WWPart_Sync::sync_categories( isset( $response['categories'] ) ? (array) $response['categories'] : array() );

				$log[] = sprintf(
					/* translators: 1: created categories, 2: updated categories */
					__( 'Categories: %1$d created, %2$d updated.', 'woo-wholesale-partner' ),
					(int) $result['created'],
					(int) $result['updated']
				);

				$next['step'] = 'products';
				$next['page'] = 1;
				break;

			case 'products':
				$response = WWPart_Client::products( $page, WWPart_Sync::PAGE_SIZE );

				if ( is_wp_error( $response ) ) {
					wp_send_json_error( array( 'message' => $response->get_error_message() ) );
				}

				$products = isset( $response['products'] ) ? (array) $response['products'] : array();
				$pages    = isset( $response['pages'] ) ? (int) $response['pages'] : 1;
				$total    = isset( $response['total'] ) ? (int) $response['total'] : $total;

				foreach ( $products as $payload ) {
					$result = WWPart_Sync::apply_payload( (array) $payload );

					if ( is_wp_error( $result ) ) {
						++$counts['failed'];
						$log[] = sprintf(
							/* translators: 1: product name, 2: error message */
							__( 'Error for "%1$s": %2$s', 'woo-wholesale-partner' ),
							isset( $payload['name'] ) ? (string) $payload['name'] : '?',
							$result->get_error_message()
						);
						continue;
					}

					if ( 'created' === $result['action'] || 'adopted' === $result['action'] ) {
						++$counts['created'];
					} elseif ( 'skipped' === $result['action'] ) {
						++$counts['skipped'];
					} else {
						++$counts['updated'];
					}
				}

				$log[] = sprintf(
					/* translators: 1: page, 2: total pages */
					__( 'Products: page %1$d of %2$d done.', 'woo-wholesale-partner' ),
					$page,
					max( 1, $pages )
				);

				$next['total'] = $total;

				if ( $page >= $pages || empty( $products ) ) {
					$next['step'] = WWPart_Settings::is( 'sync_images' ) ? 'images' : 'cleanup';
					$next['page'] = 1;
				} else {
					$next['page'] = $page + 1;
				}
				break;

			case 'images':
				$batch = WWPart_Images::run_batch( $offset, self::DOWNLOAD_BATCH, false );

				$log[] = sprintf(
					/* translators: 1: downloaded images, 2: failed images */
					__( 'Images: %1$d downloaded, %2$d could not be fetched yet.', 'woo-wholesale-partner' ),
					(int) $batch['downloaded'],
					(int) $batch['failed']
				);

				if ( $batch['done'] ) {
					$next['step'] = 'cleanup';
				} else {
					$next['offset'] = (int) $batch['offset'];
				}
				break;

			case 'cleanup':
				$batch = WWPart_Sync::cleanup( $offset );

				if ( $batch['handled'] > 0 ) {
					$log[] = sprintf(
						/* translators: %d: number of products */
						__( '%d products are no longer delivered and were set aside.', 'woo-wholesale-partner' ),
						(int) $batch['handled']
					);
				}

				if ( $batch['done'] ) {
					$next['step'] = 'report';
				} else {
					$next['offset'] = (int) $batch['offset'];
				}
				break;

			case 'report':
			default:
				$report = WWPart_Sync::heartbeat();
				$stats  = WWPart_Images::stats();

				if ( is_wp_error( $report ) ) {
					$log[] = sprintf(
						/* translators: %s: error message */
						__( 'The supplier could not be informed about this run: %s', 'woo-wholesale-partner' ),
						$report->get_error_message()
					);
				}

				WWPart_Product::reset_seen();
				WWPart_Markup::finish();

				WWPart_Settings::set(
					array(
						'last_sync'    => time(),
						'last_error'   => '',
						'last_summary' => array(
							'created' => $counts['created'],
							'updated' => $counts['updated'],
							'skipped' => $counts['skipped'],
							'failed'  => $counts['failed'],
							'images'  => (int) $stats['queued_images'],
						),
					)
				);

				$log[] = sprintf(
					/* translators: 1: created, 2: updated, 3: unchanged, 4: failed */
					__( 'Done: %1$d new, %2$d updated, %3$d unchanged, %4$d failed.', 'woo-wholesale-partner' ),
					$counts['created'],
					$counts['updated'],
					$counts['skipped'],
					$counts['failed']
				);

				if ( (int) $stats['queued_images'] > 0 ) {
					$log[] = sprintf(
						/* translators: %d: number of images */
						__( '%d images are still missing. They are fetched automatically in the background; you can also start the check by hand.', 'woo-wholesale-partner' ),
						(int) $stats['queued_images']
					);
				}

				wp_send_json_success(
					array(
						'done'    => true,
						'log'     => $log,
						'percent' => 100,
					)
				);
		}

		wp_send_json_success(
			array(
				'done'    => false,
				'log'     => $log,
				'percent' => self::sync_percent( $next['step'], $next['page'], $total ),
				'next'    => array_merge( $next, $counts ),
			)
		);
	}

	/**
	 * Rough progress of a sync run.
	 *
	 * @param string $step  Next step.
	 * @param int    $page  Next page.
	 * @param int    $total Total products.
	 * @return int
	 */
	private static function sync_percent( $step, $page, $total ) {
		switch ( $step ) {
			case 'categories':
				return 5;

			case 'products':
				$pages = $total > 0 ? max( 1, (int) ceil( $total / WWPart_Sync::PAGE_SIZE ) ) : 1;
				return min( 75, 10 + (int) round( ( $page - 1 ) / $pages * 65 ) );

			case 'images':
				return 80;

			case 'cleanup':
				return 90;

			case 'report':
				return 95;
		}

		return 0;
	}

	/**
	 * AJAX: one batch of a markup run.
	 */
	public static function ajax_markup_step() {
		self::ajax_guard( 'wwpart_markup' );

		$raw = isset( $_POST['job'] ) ? wp_unslash( $_POST['job'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized below.
		$job = WWPart_Markup::sanitize_job( json_decode( (string) $raw, true ) );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$changed = isset( $_POST['changed'] ) ? absint( $_POST['changed'] ) : 0;

		$log = array();

		if ( 0 === $offset ) {
			// The markup is stored first, so later syncs keep using it.
			WWPart_Markup::store_job( $job );
			$log[] = WWPart_Markup::describe( $job );
		}

		$total = WWPart_Markup::count( $job );
		$batch = WWPart_Markup::run_batch( $job, $offset );

		$changed += $batch['changed'];

		$log[] = sprintf(
			/* translators: 1: processed products, 2: total products */
			__( '%1$d of %2$d products recalculated.', 'woo-wholesale-partner' ),
			min( (int) $batch['offset'], $total ),
			$total
		);

		if ( $batch['done'] ) {
			WWPart_Markup::finish();

			$log[] = sprintf(
				/* translators: %d: number of products */
				__( 'Done: %d sales prices changed.', 'woo-wholesale-partner' ),
				$changed
			);

			wp_send_json_success(
				array(
					'done'    => true,
					'log'     => $log,
					'percent' => 100,
				)
			);
		}

		wp_send_json_success(
			array(
				'done'    => false,
				'log'     => $log,
				'percent' => $total > 0 ? min( 99, (int) round( $batch['offset'] / $total * 100 ) ) : 0,
				'next'    => array(
					'offset'  => (int) $batch['offset'],
					'changed' => $changed,
				),
			)
		);
	}

	/**
	 * AJAX: one batch of the image check.
	 *
	 * verify -> download -> done
	 */
	public static function ajax_image_step() {
		self::ajax_guard( 'wwpart_image' );

		$step       = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'verify';
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$queued     = isset( $_POST['queued'] ) ? absint( $_POST['queued'] ) : 0;
		$downloaded = isset( $_POST['downloaded'] ) ? absint( $_POST['downloaded'] ) : 0;
		$failed     = isset( $_POST['failed'] ) ? absint( $_POST['failed'] ) : 0;
		$force      = ! empty( $_POST['force'] );

		$managed = WWPart_Product::count_managed();
		$log     = array();

		if ( 'verify' === $step ) {
			$ids   = WWPart_Product::managed_ids( $offset, self::VERIFY_BATCH );
			$found = 0;

			foreach ( $ids as $product_id ) {
				$result = WWPart_Images::verify_product( $product_id );
				$found += (int) $result['queued'];
			}

			$queued += $found;

			$log[] = sprintf(
				/* translators: 1: checked products, 2: total products */
				__( 'Checked %1$d of %2$d products.', 'woo-wholesale-partner' ),
				min( $offset + count( $ids ), $managed ),
				$managed
			);

			if ( count( $ids ) < self::VERIFY_BATCH ) {
				WWPart_Images::reset_cursor();

				$log[] = sprintf(
					/* translators: %d: number of images */
					__( '%d images have to be fetched.', 'woo-wholesale-partner' ),
					$queued
				);

				wp_send_json_success(
					array(
						'done'    => false,
						'log'     => $log,
						'percent' => 50,
						'next'    => array(
							'step'       => 'download',
							'offset'     => 0,
							'queued'     => $queued,
							'downloaded' => $downloaded,
							'failed'     => $failed,
							'force'      => $force ? 1 : 0,
						),
					)
				);
			}

			wp_send_json_success(
				array(
					'done'    => false,
					'log'     => $log,
					'percent' => $managed > 0 ? min( 49, (int) round( ( $offset + count( $ids ) ) / $managed * 50 ) ) : 49,
					'next'    => array(
						'step'       => 'verify',
						'offset'     => $offset + count( $ids ),
						'queued'     => $queued,
						'downloaded' => $downloaded,
						'failed'     => $failed,
						'force'      => $force ? 1 : 0,
					),
				)
			);
		}

		$batch = WWPart_Images::run_batch( $offset, self::DOWNLOAD_BATCH, $force );

		$downloaded += (int) $batch['downloaded'];
		$failed     += (int) $batch['failed'];

		$log[] = sprintf(
			/* translators: 1: downloaded images, 2: images that failed again */
			__( 'Downloaded %1$d images, %2$d failed.', 'woo-wholesale-partner' ),
			(int) $batch['downloaded'],
			(int) $batch['failed']
		);

		if ( $batch['done'] ) {
			$stats = WWPart_Images::stats();

			$log[] = sprintf(
				/* translators: 1: downloaded images, 2: products still waiting, 3: products that gave up */
				__( 'Done: %1$d images fetched. %2$d products are still waiting, %3$d gave up for now.', 'woo-wholesale-partner' ),
				$downloaded,
				(int) $stats['pending_products'],
				(int) $stats['failed_products']
			);

			WWPart_Sync::heartbeat();

			wp_send_json_success(
				array(
					'done'    => true,
					'log'     => $log,
					'percent' => 100,
				)
			);
		}

		wp_send_json_success(
			array(
				'done'    => false,
				'log'     => $log,
				'percent' => min( 99, 50 + (int) round( $downloaded / max( 1, $queued ) * 49 ) ),
				'next'    => array(
					'step'       => 'download',
					'offset'     => (int) $batch['offset'],
					'queued'     => $queued,
					'downloaded' => $downloaded,
					'failed'     => $failed,
					'force'      => $force ? 1 : 0,
				),
			)
		);
	}
}
