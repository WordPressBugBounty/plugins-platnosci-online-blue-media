<?php

namespace Ilabs\BM_Woocommerce\Gateway;


class Session_Bridge {

	public static function restore_session_data(): void {
		if ( ! empty( WC()->session->get( 'bm_order_payment_params' ) ) ) {
			return;
		}

		$request_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

		if ( empty( $request_key ) ) {
			return;
		}


		$detected_order_id = wc_get_order_id_by_order_key( $request_key );

		if ( ! $detected_order_id ) {
			blue_media()->get_woocommerce_logger( 'session_debug' )->log_error(
				sprintf(
					'[restore_session_data] Invalid Order Key provided: %s',
					print_r(
						[
							'request_key' => $request_key,
							'ip'          => blue_media()
								->get_core_helpers()
								->get_visitor_ip(),
						]
						, true )

				) );

			return;
		}

		$order = wc_get_order( $detected_order_id );

		if ( ! $order ) {

			blue_media()->get_woocommerce_logger( 'session_debug' )->log_error(
				sprintf(
					'[restore_session_data] Cant find Order with ID: %s',
					print_r(
						[
							'order_id' => $detected_order_id,
						]
						, true )

				) );

			return;
		}

		$meta_params = (array) $order->get_meta( 'bm_order_payment_params' );


		if ( ! isset( $meta_params['params'] ) ) {
			blue_media()->get_woocommerce_logger( 'session_debug' )->log_error(
				sprintf(
					'[restore_session_data] meta bm_order_payment_params is empty: %s',
					print_r(
						[
							'order_id' => $detected_order_id,
						]
						, true )

				) );

			return;
		}

		$meta_params['restored_from_order_meta'] = 1;


		WC()->session->set( 'bm_order_payment_params', $meta_params );
		WC()->session->save_data();

		blue_media()->get_woocommerce_logger( 'session_debug' )->log_debug(
			sprintf(
				'[restore_session_data] Restore: Success! Payment params restored from Order Meta to Session: %s',
				print_r(
					[
						'order_id' => $detected_order_id,
						'data'     => $meta_params,
					]
					, true )

			) );

	}
}
