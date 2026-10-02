<?php
/**
 * Admin handlers for partner shops and bulk price changes.
 *
 * Kept separate from WWPro_Admin so the sync features do not crowd the screens
 * that existed before them.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Partner and price admin (static).
 */
class WWPro_Admin_Sync {

	/**
	 * Transient that carries a freshly issued key to the next page load.
	 */
	const KEY_TRANSIENT = 'wwpro_new_key_';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_wwpro_save_partner', array( __CLASS__, 'handle_save_partner' ) );
		add_action( 'admin_post_wwpro_delete_partner', array( __CLASS__, 'handle_delete_partner' ) );
		add_action( 'admin_post_wwpro_partner_key', array( __CLASS__, 'handle_partner_key' ) );
		add_action( 'admin_post_wwpro_test_shopify', array( __CLASS__, 'handle_test_shopify' ) );

		add_action( 'wp_ajax_wwpro_bulk_price_step', array( __CLASS__, 'ajax_bulk_price_step' ) );
		add_action( 'wp_ajax_wwpro_shopify_step', array( __CLASS__, 'ajax_shopify_step' ) );
	}

	/**
	 * Capability and nonce guard.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( WWPro_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wholesale' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Handler: create or update a partner shop.
	 */
	public static function handle_save_partner() {
		self::guard( 'wwpro_save_partner' );

		$raw    = isset( $_POST['partner'] ) && is_array( $_POST['partner'] ) ? wp_unslash( $_POST['partner'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPro_Partners::sanitize().
		$is_new = ! empty( $_POST['is_new'] );

		$result = WWPro_Partners::save( $raw, $is_new );

		if ( is_wp_error( $result ) ) {
			WWPro_Admin::add_notice( $result->get_error_message(), 'error' );
			$args = $is_new ? array( 'action' => 'new' ) : array( 'edit' => isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '' );
			wp_safe_redirect( WWPro_Admin::url( 'partners', $args ) );
			exit;
		}

		// A new WooCommerce partner is useless without a key, so issue one right away.
		if ( $is_new && 'woocommerce' === $result['type'] ) {
			$key = WWPro_Partners::issue_key( $result['id'] );
			if ( ! is_wp_error( $key ) ) {
				self::remember_key( $result['id'], $key );
			}
		}

		WWPro_Admin::add_notice( $is_new ? __( 'Partner shop created.', 'woo-wholesale' ) : __( 'Partner shop saved.', 'woo-wholesale' ) );
		wp_safe_redirect( WWPro_Admin::url( 'partners' ) );
		exit;
	}

	/**
	 * Handler: delete a partner shop.
	 */
	public static function handle_delete_partner() {
		self::guard( 'wwpro_delete_partner' );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

		if ( $id && WWPro_Partners::delete( $id ) ) {
			WWPro_Admin::add_notice( __( 'Partner shop deleted. Products in that shop are not changed.', 'woo-wholesale' ) );
		} else {
			WWPro_Admin::add_notice( __( 'The partner shop could not be deleted.', 'woo-wholesale' ), 'error' );
		}

		wp_safe_redirect( WWPro_Admin::url( 'partners' ) );
		exit;
	}

	/**
	 * Handler: issue or revoke the API key of a partner.
	 */
	public static function handle_partner_key() {
		self::guard( 'wwpro_partner_key' );

		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$mode = isset( $_POST['mode'] ) && 'revoke' === $_POST['mode'] ? 'revoke' : 'issue';

		if ( 'revoke' === $mode ) {
			if ( WWPro_Partners::revoke_key( $id ) ) {
				WWPro_Admin::add_notice( __( 'The key was revoked. The partner shop can no longer connect.', 'woo-wholesale' ) );
			} else {
				WWPro_Admin::add_notice( __( 'The key could not be revoked.', 'woo-wholesale' ), 'error' );
			}
		} else {
			$key = WWPro_Partners::issue_key( $id );
			if ( is_wp_error( $key ) ) {
				WWPro_Admin::add_notice( $key->get_error_message(), 'error' );
			} else {
				self::remember_key( $id, $key );
				WWPro_Admin::add_notice( __( 'A new key was created. Any key issued before is now invalid.', 'woo-wholesale' ) );
			}
		}

		wp_safe_redirect( WWPro_Admin::url( 'partners' ) );
		exit;
	}

	/**
	 * Handler: test the Shopify credentials of a partner.
	 */
	public static function handle_test_shopify() {
		self::guard( 'wwpro_test_shopify' );

		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$partner = WWPro_Partners::get( $id );

		if ( ! $partner || 'shopify' !== $partner['type'] ) {
			WWPro_Admin::add_notice( __( 'This partner shop is not a Shopify shop.', 'woo-wholesale' ), 'error' );
			wp_safe_redirect( WWPro_Admin::url( 'partners' ) );
			exit;
		}

		$test = WWPro_Shopify::test_connection( $partner );

		if ( is_wp_error( $test ) ) {
			WWPro_Partners::touch( $id, array( 'last_error' => $test->get_error_message() ) );
			WWPro_Admin::add_notice( $test->get_error_message(), 'error' );
		} else {
			WWPro_Partners::touch(
				$id,
				array(
					'last_error' => '',
					'last_seen'  => time(),
				)
			);
			WWPro_Admin::add_notice(
				sprintf(
					/* translators: 1: Shopify shop name, 2: currency code */
					__( 'Connected to the Shopify shop "%1$s" (%2$s).', 'woo-wholesale' ),
					$test['name'],
					$test['currency']
				)
			);
		}

		wp_safe_redirect( WWPro_Admin::url( 'partners' ) );
		exit;
	}

	/**
	 * Keep a freshly issued key for exactly one page load.
	 *
	 * @param string $id  Partner id.
	 * @param string $key Plain key.
	 */
	private static function remember_key( $id, $key ) {
		set_transient( self::KEY_TRANSIENT . get_current_user_id(), array( $id => $key ), 120 );
	}

	/**
	 * Read and clear the key issued on the previous request.
	 *
	 * @return array partner id => plain key
	 */
	public static function take_new_keys() {
		$keys = get_transient( self::KEY_TRANSIENT . get_current_user_id() );
		if ( ! is_array( $keys ) ) {
			return array();
		}
		delete_transient( self::KEY_TRANSIENT . get_current_user_id() );
		return $keys;
	}

	/**
	 * Guard for the AJAX endpoints.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function ajax_guard( $nonce_action ) {
		check_ajax_referer( $nonce_action, 'nonce' );

		if ( ! current_user_can( WWPro_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'woo-wholesale' ) ), 403 );
		}
	}

	/**
	 * AJAX: one batch of the bulk price change.
	 */
	public static function ajax_bulk_price_step() {
		self::ajax_guard( 'wwpro_bulk_price' );

		$raw = isset( $_POST['job'] ) ? wp_unslash( $_POST['job'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized below.
		$job = WWPro_Bulk_Prices::sanitize_job( json_decode( (string) $raw, true ) );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$changed = isset( $_POST['changed'] ) ? absint( $_POST['changed'] ) : 0;
		$skipped = isset( $_POST['skipped'] ) ? absint( $_POST['skipped'] ) : 0;

		$total = WWPro_Bulk_Prices::count( $job );
		$log   = array();

		if ( 0 === $offset ) {
			$log[] = WWPro_Bulk_Prices::describe( $job );
		}

		$batch = WWPro_Bulk_Prices::run_batch( $job, $offset );

		$changed += $batch['changed'];
		$skipped += $batch['skipped'];

		$log[] = sprintf(
			/* translators: 1: processed products, 2: total products */
			__( '%1$d of %2$d products processed.', 'woo-wholesale' ),
			min( $batch['offset'], $total ),
			$total
		);

		if ( $batch['done'] ) {
			$summary = array(
				'changed' => $changed,
				'skipped' => $skipped,
				'total'   => $total,
			);

			WWPro_Bulk_Prices::finish( $job, $summary );

			$log[] = sprintf(
				/* translators: 1: changed products, 2: skipped products */
				__( 'Done: %1$d products changed, %2$d left untouched.', 'woo-wholesale' ),
				$changed,
				$skipped
			);

			wp_send_json_success(
				array(
					'done'    => true,
					'log'     => $log,
					'percent' => 100,
					'summary' => $summary,
				)
			);
		}

		wp_send_json_success(
			array(
				'done'    => false,
				'log'     => $log,
				'percent' => $total > 0 ? min( 99, (int) round( $batch['offset'] / $total * 100 ) ) : 0,
				'next'    => array(
					'offset'  => $batch['offset'],
					'changed' => $changed,
					'skipped' => $skipped,
				),
			)
		);
	}

	/**
	 * AJAX: one batch of a Shopify push.
	 */
	public static function ajax_shopify_step() {
		self::ajax_guard( 'wwpro_shopify_sync' );

		$id      = isset( $_POST['partner'] ) ? sanitize_key( wp_unslash( $_POST['partner'] ) ) : '';
		$partner = WWPro_Partners::get( $id );

		if ( ! $partner || 'shopify' !== $partner['type'] ) {
			wp_send_json_error( array( 'message' => __( 'This partner shop is not a Shopify shop.', 'woo-wholesale' ) ) );
		}

		if ( ! WWPro_Partners::is_active( $partner ) ) {
			wp_send_json_error( array( 'message' => __( 'This partner shop is paused or its wholesale role no longer exists.', 'woo-wholesale' ) ) );
		}

		$page    = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$created = isset( $_POST['created'] ) ? absint( $_POST['created'] ) : 0;
		$updated = isset( $_POST['updated'] ) ? absint( $_POST['updated'] ) : 0;
		$failed  = isset( $_POST['failed'] ) ? absint( $_POST['failed'] ) : 0;

		$batch = WWPro_Shopify::push_batch( $partner, $page );

		$created += $batch['created'];
		$updated += $batch['updated'];
		$failed  += $batch['failed'];

		$log = $batch['log'];

		$done_products = ( $page - 1 ) * WWPro_Shopify::BATCH_SIZE + $batch['processed'];

		$log[] = sprintf(
			/* translators: 1: processed products, 2: total products */
			__( '%1$d of %2$d products pushed to Shopify.', 'woo-wholesale' ),
			min( $done_products, $batch['total'] ),
			$batch['total']
		);

		if ( $batch['done'] ) {
			WWPro_Partners::touch(
				$partner['id'],
				array(
					'last_sync'  => time(),
					'last_seen'  => time(),
					'last_error' => $failed > 0 ? __( 'Some products could not be pushed. See the log of the last run.', 'woo-wholesale' ) : '',
					'stats'      => array(
						'products' => $created + $updated,
						'failed'   => $failed,
						'reported' => time(),
					),
				)
			);

			$log[] = sprintf(
				/* translators: 1: created, 2: updated, 3: failed */
				__( 'Done: %1$d created, %2$d updated, %3$d failed.', 'woo-wholesale' ),
				$created,
				$updated,
				$failed
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
				'percent' => $batch['pages'] > 0 ? min( 99, (int) round( $page / $batch['pages'] * 100 ) ) : 0,
				'next'    => array(
					'page'    => $page + 1,
					'created' => $created,
					'updated' => $updated,
					'failed'  => $failed,
				),
			)
		);
	}
}
