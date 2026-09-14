<?php

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;


class Session_Bridge {

	public static function restore_session_data(): void {
		if ( ! empty( WC()->session->get( 'bm_order_payment_params' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only session restore using WooCommerce order key; no state is changed beyond restoring payment session data from order meta.
		$request_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( empty( $request_key ) ) {
			return;
		}

		$detected_order_id = wc_get_order_id_by_order_key( $request_key );

		if ( ! $detected_order_id ) {
			blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_error(
				'[restore_session_data] Invalid Order Key provided' );

			return;
		}

		$order = wc_get_order( $detected_order_id );

		if ( ! $order ) {

			blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_error(
				sprintf(
					'[restore_session_data] Cant find Order with ID: %s',
					wp_json_encode(
						[
							'order_id' => $detected_order_id,
						]
					)

				) );

			return;
		}

		$meta_params = (array) $order->get_meta( 'bm_order_payment_params' );

		if ( ! isset( $meta_params['params'] ) ) {
			blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_error(
				sprintf(
					'[restore_session_data] meta bm_order_payment_params is empty: %s',
					wp_json_encode(
						[
							'order_id' => $detected_order_id,
						]
					)

				) );

			return;
		}

		$meta_params['restored_from_order_meta'] = 1;

		WC()->session->set( 'bm_order_payment_params', $meta_params );
		self::save();

		blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_debug(
			sprintf(
				'[restore_session_data] Restore: Success! Payment params restored from Order Meta to Session: %s',
				wp_json_encode( [ 'order_id' => $detected_order_id ] )
			) );
	}

	public static function save(): void {
		if ( WC()->session instanceof \WC_Session_Handler ) {
			WC()->session->save_data();
		}
	}
}
