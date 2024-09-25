<?php

namespace Ilabs\BM_Woocommerce\Controller\Wp_Admin;

use Exception;
use Ilabs\BM_Woocommerce\Controller\Abstract_Controller;
use Ilabs\BM_Woocommerce\Controller\Controller_Interface;
use Ilabs\BM_Woocommerce\Controller\Model\Ajax_Response_Value_Object;
use Ilabs\BM_Woocommerce\Controller\Model\Payment_Status_Response_Value_Object;
use Ilabs\BM_Woocommerce\Domain\Service\Testing\Order_Creator;
use Ilabs\BM_Woocommerce\Domain\Service\Testing\Transaction_Test;
use WC_Order;

class Transaction_Testing_Controller extends Abstract_Controller implements Controller_Interface {

	const INITIALIZE_ACTION_NAME = 'transaction_test';

	const VERIFY_ITN_ACTION_NAME = 'verify_itn';

	const NONCE_ACTION = 'bluemedia_payment';

	/**
	 * @throws Exception
	 */
	public function execute_request_initialize() {
		try {

			if ( ! isset( $_POST['nonce'] ) ) {
				throw new Exception( __( 'Nonce field not exists',
					'bm-woocommerce' ) );
			}

			$nonce = $_POST['nonce'];

			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				throw new Exception( __( 'Verification nonce failed',
					'bm-woocommerce' ) );
			}

			$order_creator = new Order_Creator();
			$order         = $order_creator->create();

			$transaction_test_service = new Transaction_Test();
			$transaction_test_service->initialize( $order );


			if ( $order instanceof WC_Order ) {
				$status  = Ajax_Response_Value_Object::STATUS_SUCCESS;
				$message = $order->get_id();

			} else {
				$status  = Ajax_Response_Value_Object::STATUS_ERROR;
				$message = __( 'Order create failed', 'bm-woocommerce' );
			}

			if ( isset( $order ) && $order instanceof WC_Order && isset( $order_creator ) ) {
				//$order_creator->remove( $order->get_id() );
			}


			$this->send_response(
				$status,
				$message,
				'',
				null
			);
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Connection_Testing_Controller] [execute_request] [Message: %s] [POST: %s] ',
					$exception->getMessage(),
					print_r( $_POST, true )
				) );

			if ( isset( $order ) && $order instanceof WC_Order && isset( $order_creator ) ) {
				$order_creator->remove( $order->get_id() );
			}

			$this->send_response(
				Ajax_Response_Value_Object::STATUS_ERROR,
				$exception->getMessage(),
				'',
				null
			);


		}

	}

	public function execute_request_verify_itn() {
		try {

			if ( ! isset( $_POST['nonce'] ) ) {
				throw new Exception( __( 'Nonce field not exists',
					'bm-woocommerce' ) );
			}

			if ( ! isset( $_POST['order_id'] ) ) {
				throw new Exception( __( 'order_id field not exists',
					'bm-woocommerce' ) );
			}

			$nonce    = $_POST['nonce'];
			$order_id = (int) $_POST['order_id'];

			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				throw new Exception( __( 'Verification nonce failed',
					'bm-woocommerce' ) );
			}

			$order = wc_get_order( $order_id );


			if ( $order instanceof WC_Order ) {
				$transaction_test_service = new Transaction_Test();
				$result                   = $transaction_test_service->verify_itn( $order );

				$status  = Ajax_Response_Value_Object::STATUS_SUCCESS;
				$message = $result ? '1' : '0';
				if ( $result ) {
					( new Order_Creator() )->remove( $order_id );
				}
			} else {
				$status  = Payment_Status_Response_Value_Object::STATUS_ERROR;
				$message = __( 'Order create failed', 'bm-woocommerce' );
			}


			$this->send_response(
				$status,
				$message,
				'',
				null
			);
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Connection_Testing_Controller] [execute_request] [Message: %s] [POST: %s] ',
					$exception->getMessage(),
					print_r( $_POST, true )
				) );

			if ( isset( $order ) && $order instanceof WC_Order && isset( $order_creator ) ) {
				$order_creator->remove( $order->get_id() );
			}

			$this->send_response(
				Ajax_Response_Value_Object::STATUS_ERROR,
				$exception->getMessage(),
				'',
				null
			);


		}
	}

	public function execute_request_cleaning() {

	}


	public function handle() {
		add_action( $this->get_ajax_action_name( self::INITIALIZE_ACTION_NAME ),
			function () {
				$this->execute_request_initialize();
			} );

		add_action( $this->get_ajax_action_name_nopriv( self::INITIALIZE_ACTION_NAME ),
			function () {
				$this->execute_request_initialize();
			} );

		add_action( $this->get_ajax_action_name( self::VERIFY_ITN_ACTION_NAME ),
			function () {
				$this->execute_request_verify_itn();
			} );

		add_action( $this->get_ajax_action_name_nopriv( self::VERIFY_ITN_ACTION_NAME ),
			function () {
				$this->execute_request_verify_itn();
			} );
	}
}
