<?php

namespace Ilabs\BM_Woocommerce\Controller;


use Ilabs\BM_Woocommerce\Controller\Model\Payment_Status_Response_Value_Object;

abstract class Abstract_Controller {


	protected function send_response(
		string $status,
		string $message,
		?string $order_received_url,
		?string $continue_transaction_redirect_url

	) {
		$response = new Payment_Status_Response_Value_Object(
			$status,
			$message,
			$order_received_url,
			$continue_transaction_redirect_url
		);


		blue_media()->get_woocommerce_logger()->log_error(
			sprintf( '[Abstract_Controller] [send_response]  [response: %s]',
				print_r( $response, true )
			) );

		$this->output_response( $response );
	}

	private function output_response(
		Payment_Status_Response_Value_Object $response
	) {
		echo wp_json_encode( $response->to_array() );
		exit;
	}

	protected function build_full_action_name( string $action_name ): string {
		return 'wp_ajax_bm_' . $action_name . '_action';
	}

	protected function build_full_action_name_nopriv( string $action_name
	): string {
		return 'wp_ajax_nopriv_bm_' . $action_name . '_action';
	}

	public function get_ajax_action_name( string $action ): string {
		return $this->build_full_action_name( $action );
	}

	public function get_ajax_action_name_nopriv( string $action ): string {
		return $this->build_full_action_name_nopriv( $action );
	}
}
