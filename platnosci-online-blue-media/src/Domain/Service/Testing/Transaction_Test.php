<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Testing;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use WC_Order;

class Transaction_Test {


	/**
	 * @throws Exception
	 */
	public function initialize( WC_Order $order ) {
		$bm_gateway              = blue_media()->get_blue_media_gateway();
		$blik_authorization_code = '888888';
		$client                  = new Client();
		$params                  = [
			'ServiceID'         => $bm_gateway->get_service_id(),
			'OrderID'           => $order->get_id(),
			'Amount'            => '10.00',
			'Description'       => (string) $order->get_id(),
			'GatewayID'         => $bm_gateway::BLIK_0_CHANNEL,
			'Currency'          => 'PLN',
			'CustomerEmail'     => $order->get_billing_email(),
			'CustomerIP'        => '127.0.0.1',
			'Title'             => (string) $order->get_id(),
			'AuthorizationCode' => $blik_authorization_code,
		];

		/*'CustomerIP'        => blue_media()
			->get_core_helpers()
			->get_visitor_ip(),*/
		$params = array_merge( $params, [
			'Hash' => $bm_gateway->hash_transaction_parameters(
				$params ),
		] );

		update_post_meta( $order->get_id(),
			'bm_transaction_init_params',
			$params );

		$result = $bm_gateway->decode_continue_transaction_response( $client->continue_transaction_request(
			$params,
			$bm_gateway->get_gateway_url() . 'payment'
		) );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [params: %s] [result: %s]',
				print_r( $params, true ),
				print_r( $result, true ),
			) );

		if ( isset( $result['reason'] ) ) {
			throw new Exception( $result['reason'] );
		}

		if ( empty( $result ) || ! is_array( $result ) ) {
			throw new Exception( sprintf( 'Continue transaction response invalid format (%s)',
				serialize( $result ) ) );
		}
	}

	public function verify_itn( WC_Order $order ): bool {
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [verify_itn] [order_id: %s] [result: %s]',
				$order->get_id(),
				print_r( $order->get_meta( 'autopay_itn_received' ), true ),
			) );
		if ( ! empty( $order->get_meta( 'autopay_itn_received' ) ) ) {
			return true;
		} else {
			return false;
		}
	}
}
