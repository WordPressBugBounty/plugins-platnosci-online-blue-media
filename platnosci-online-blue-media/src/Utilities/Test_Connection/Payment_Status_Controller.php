<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;


class Payment_Status_Controller {

	public function execute_request() {
		$order_id                = WC()->session->get( 'bm_wc_order_id' );
		$transaction_start_error = WC()->session->get( 'bm_continue_transaction_start_error' );


		if ( empty( $order_id ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[Payment_Status_Controller] [order_id is empty]'
				) );

			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				__( "Order get failed",
					"bm-woocommerce" )
			);
		}


		if ( '' !== $transaction_start_error ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[Payment_Status_Controller] [transaction_start_error: %s]  [order_id: %s]',
					print_r( $transaction_start_error, true ),
					print_r( $order_id, true )
				) );

			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				$transaction_start_error
			);
		}

		$order      = wc_get_order( $order_id );
		$itn_status = $order->get_meta( 'bm_order_itn_status' );

		$continue_transaction_redirect_url = WC()->session->get( 'bm_continue_transaction_redirect_url' );

		if ( ! wc_is_valid_url( $continue_transaction_redirect_url ) ) {
			$continue_transaction_redirect_url = null;
		}

		return $itn_status;

	}

	public static function get_generic_err_msg(): string {
		return __( 'Payment failed', 'bm-woocommerce' );
	}
}
