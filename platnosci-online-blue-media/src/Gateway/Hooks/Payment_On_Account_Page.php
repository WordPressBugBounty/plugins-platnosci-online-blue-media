<?php

namespace Ilabs\BM_Woocommerce\Gateway\Hooks;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Session_Bridge;

class Payment_On_Account_Page {

	public function init() {

		add_action( 'wp', function () {
			if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
				return;
			}

			$this->payment_on_account_page_stage_1();

			if ( ! isset( $_POST['autopay_checkout_on_account_page'] ) ) {
				return;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce-pay-nonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'woocommerce-pay' ) ) {
				return;
			}

			$this->payment_on_account_page_stage_2();
		}, 10 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag; stage 3 is protected by HMAC signature verification via verify_signature().
		if ( isset( $_GET['autopay_payment_on_account_page'] )
		     // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag; stage 3 is protected by HMAC signature verification via verify_signature().
			&& '1' === sanitize_key( wp_unslash( $_GET['autopay_payment_on_account_page'] ) ) ) {
			$this->payment_on_account_page_stage_3();
		}
	}

	private function payment_on_account_page() {
	}

	private function payment_on_account_page_stage_2() {
		blue_media()
			->get_woocommerce_logger()
			->log_debug( '[payment_on_account_page_stage_2]' );

		add_filter( 'autopay_payment_on_account_page',
			function ( bool $return ) {
				return true;
			} );
	}

	private function payment_on_account_page_stage_3() {
		blue_media()
			->get_woocommerce_logger()
			->log_debug( '[payment_on_account_page_stage_3]' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC signature in $_GET['sig'] is verified via verify_signature() before any state change occurs.
		if ( empty( $_GET['sig'] ) || empty( $_GET['order_id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC signature verified via verify_signature() on the line below before any state changes occur.
		$signature = sanitize_key( wp_unslash( $_GET['sig'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC signature verified via verify_signature() on the line below before any state changes occur.
		$order_id = absint( wp_unslash( $_GET['order_id'] ?? '0' ) );

		if ( 64 !== strlen( $signature ) || ! ctype_xdigit( $signature ) ) {
			return;
		}

		if ( ! self::verify_signature( $signature, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_params_recovered = $order->get_meta( 'bm_order_payment_params' );

		if ( empty( $order_params_recovered ) ) {
			return;
		}

		blue_media()
			->get_woocommerce_logger()
			->log_debug( sprintf( '[payment_on_account_page_stage_3] [OrderId: %s] [order_params_recovered: found]',
					$order_id,
				)
			);

		if ( ! is_object( WC()->session ) ) {
			WC()->initialize_session();
		}

		WC()->session->set( 'bm_order_payment_params',
			$order_params_recovered );
		Session_Bridge::save();

		$order->delete_meta_data( 'bm_order_payment_params' );
		$order->save_meta_data();

		add_filter( 'autopay_filter_can_redirect_to_payment_gateway',
			static function ( bool $return ): bool {
				return true;
			} );
	}

	private function payment_on_account_page_stage_1() {
		blue_media()
			->get_woocommerce_logger()
			->log_debug( '[payment_on_account_page_stage_1]' );

		add_action( 'autopay_after_payment_field', function () {
			echo "<input type='hidden' name='autopay_checkout_on_account_page'  value='1' />";
		} );
	}

	private static function verify_signature(
		string $test_signature,
		int $order_id
	): bool {
		$secret    = NONCE_KEY . AUTH_KEY;
		$signature = hash_hmac( 'sha256', (string) $order_id, $secret );

		return hash_equals( $signature, $test_signature );
	}

	public static function generate_signature( int $order_id ): string {
		$secret = NONCE_KEY . AUTH_KEY;

		return hash_hmac( 'sha256', (string) $order_id, $secret );
	}
}
