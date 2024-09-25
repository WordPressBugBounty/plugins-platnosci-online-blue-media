<?php

namespace Ilabs\BM_Woocommerce\Controller\Wp_Admin;

use Exception;
use Ilabs\BM_Woocommerce\Controller\Abstract_Controller;
use Ilabs\BM_Woocommerce\Controller\Controller_Interface;
use Ilabs\BM_Woocommerce\Controller\Model\Ajax_Response_Value_Object;

class Connection_Testing_Controller extends Abstract_Controller implements Controller_Interface {

	const ACTION_NAME = 'connection_test';

	const NONCE_ACTION = 'bluemedia_payment';

	/**
	 * @throws Exception
	 */
	public function execute_request() {
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

			$channels = blue_media()
				->get_blue_media_gateway()
				->gateway_list( true );

			if ( ! empty( $channels ) ) {
				$status  = Ajax_Response_Value_Object::STATUS_SUCCESS;
				$message = __( 'Connection Test Successful',
					'bm-woocommerce' );
			} else {
				$status  = Ajax_Response_Value_Object::STATUS_ERROR;
				$message = __( 'Connection Test Failed' );
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

			$this->send_response(
				Ajax_Response_Value_Object::STATUS_ERROR,
				$exception->getMessage(),
				'',
				null
			);
		}

	}

	public function handle() {
		add_action( $this->get_ajax_action_name( self::ACTION_NAME ),
			function () {
				$this->execute_request();
			} );

		add_action( $this->get_ajax_action_name_nopriv( self::ACTION_NAME ),
			function () {
				$this->execute_request();
			} );
	}
}
