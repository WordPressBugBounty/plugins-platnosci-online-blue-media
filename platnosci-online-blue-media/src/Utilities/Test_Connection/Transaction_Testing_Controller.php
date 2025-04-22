<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;
use WC_Order;

class Transaction_Testing_Controller {

	/**
	 * @throws Exception
	 */
	public function execute_request_initialize() {
		try {
			$order_creator = new Order_Creator();
			$order         = $order_creator->create();

			$transaction_test_service = new Transaction_Test();
			$transaction_test_service->initialize( $order );


			if ( $order instanceof WC_Order ) {
				$orderId = $order->get_id();

			} else {

				return new Log_Entry(
					Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					__( "Order create failed",
						"bm-woocommerce" )
				);
			}

			return $orderId;

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[Connection_Testing_Controller] [execute_request] [Error message: %s] [POST: %s] ',
					$exception->getMessage(),
					print_r( $_POST, true )
				) );

			if ( isset( $order ) && $order instanceof WC_Order && isset( $order_creator ) ) {
				$order_creator->remove( $order->get_id() );
			}


			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				( new Strings() )::get_strings()['criticalGenericMessage']
			);
		}

	}

	public function execute_request_verify_itn( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$transaction_test_service = new Transaction_Test();
				$result                   = $transaction_test_service->verify_itn( $order );

				if ( $result ) {
					( new Order_Creator() )->remove( $order_id );
				}
			} else {

				return new Log_Entry(
					Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					__( "Order create failed",
						"bm-woocommerce" )
				);

			}


			return $result;

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Connection_Testing_Controller] [execute_request_verify_itn] [Message: %s] [POST: %s] ',
					$exception->getMessage(),
					print_r( $_POST, true )
				) );

			if ( isset( $order ) && $order instanceof WC_Order && isset( $order_creator ) ) {
				$order_creator->remove( $order->get_id() );
			}

			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				$exception->getMessage()
			);


		}
	}
}
