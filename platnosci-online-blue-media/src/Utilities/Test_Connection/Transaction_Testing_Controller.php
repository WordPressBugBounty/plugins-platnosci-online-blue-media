<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use WC_Order;

class Transaction_Testing_Controller {

	/**
	 * @throws Exception
	 */
	public function execute_request_initialize() {
		try {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Transaction_Testing_Controller] [execute_request_initialize] [START]' )
			);

			$order_creator = new Order_Creator();
			$order         = $order_creator->create();

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Transaction_Testing_Controller] [execute_request_initialize] [Order created] [order_id: %s] [status: %s] [total: %s]',
					$order instanceof WC_Order ? (string) $order->get_id() : 'N/A',
					$order instanceof WC_Order ? (string) $order->get_status() : 'N/A',
					$order instanceof WC_Order ? (string) $order->get_total() : 'N/A'
				)
			);

			$transaction_test_service = new Transaction_Test();
			$transaction_test_service->initialize( $order );

			if ( $order instanceof WC_Order ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Transaction_Testing_Controller] [execute_request_initialize] [After initialize] [order_id: %s] [bm_transaction_init_params meta exists: %s]',
						(string) $order->get_id(),
						! empty( $order->get_meta( 'bm_transaction_init_params' ) ) ? 'yes' : 'no'
					)
				);
			}


			if ( $order instanceof WC_Order ) {
				$order_id = $order->get_id();
				blue_media()->get_order_remote_status_manager()
				            ->install_db_schema();

				blue_media()->get_order_remote_status_manager()
				            ->add_order_remote_status( $order_id,
					            Order_Remote_Status_Manager::STATUS_TEST_CONNECTION
				            );
			} else {

				return new Log_Entry(
					Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					__( "Order create failed",
						"bm-woocommerce" )
				);
			}

			return $order_id;

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
				( new Strings() )::get_strings()['criticalGenericMessage'] . sprintf( ' [Error message: %s]',
					$exception->getMessage() )
			);
		}

	}

	public function execute_request_verify_itn( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$transaction_test_service = new Transaction_Test();
				$result                   = $transaction_test_service->verify_itn( $order );

				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Transaction_Testing_Controller] [execute_request_verify_itn] [order_id: %s] [verify_itn result: %s]',
						(string) $order_id,
						$result ? '1' : '0'
					)
				);

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
