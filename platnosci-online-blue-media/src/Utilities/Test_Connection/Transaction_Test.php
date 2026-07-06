<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use WC_Order;

class Transaction_Test {

	/**
	 * @throws Exception
	 */
	public function initialize( WC_Order $order ) {
		$bm_gateway              = blue_media()->get_blue_media_gateway();
		$client                  = new Client();
		$gateway_payment_url      = $bm_gateway->get_gateway_url() . 'payment';

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [Order ID: %s] [Gateway payment url: %s] [CustomerEmail: %s]',
				print_r( $order->get_id(), true ),
				$gateway_payment_url,
				print_r( $order->get_billing_email(), true )
			)
		);

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
		];

		$params = array_merge( $params, [
			'Hash' => $bm_gateway->hash_transaction_parameters(
				$params ),
		] );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [params prepared] [Hash: %s]',
				$params['Hash']
			)
		);


		$order->update_meta_data( 'bm_transaction_init_params', $params );
		$order->save_meta_data();

		$result = $bm_gateway->decode_continue_transaction_response( $client->continue_transaction_request(
			$params,
			$gateway_payment_url
		) );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [params: %s] [result: %s]',
				print_r( $params, true ),
				print_r( $result, true ),
			) );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [parsed] [status: %s] [redirecturl: %s] [hash: %s]',
				isset( $result['status'] ) ? (string) $result['status'] : '',
				isset( $result['redirecturl'] ) ? (string) $result['redirecturl'] : '',
				isset( $result['hash'] ) ? (string) $result['hash'] : ''
			)
		);

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
			sprintf( '[Transaction_Test] [verify_itn] [order_id: %s] [autopay_itn_received: %s] [bm_order_itn_status: %s] [autopay_test_order: %s]',
				$order->get_id(),
				print_r( $order->get_meta( 'autopay_itn_received' ), true ),
				print_r( $order->get_meta( 'bm_order_itn_status' ), true ),
				print_r( $order->get_meta( 'autopay_test_order' ), true ),
			) );
		if ( ! empty( $order->get_meta( 'autopay_itn_received' ) ) ) {
			return true;
		} else {
			return false;
		}
	}
}
