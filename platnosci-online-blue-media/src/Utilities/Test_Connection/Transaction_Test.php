<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use WC_Order;

class Transaction_Test {

	/**
	 * @throws Exception
	 */
	public function initialize( WC_Order $order ) {
		$bm_gateway          = blue_media()->get_blue_media_gateway();
		$client              = new Client();
		$gateway_payment_url = $bm_gateway->get_gateway_url() . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH;

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [Order ID: %s] [Gateway payment url: %s] [CustomerEmail: %s]',
				wp_json_encode( $order->get_id() ),
				$gateway_payment_url,
				wp_json_encode( $order->get_billing_email() )
			)
		);

		$params = [
			'ServiceID'     => $bm_gateway->get_service_id(),
			'OrderID'       => $order->get_id(),
			'Amount'        => '10.00',
			'Description'   => (string) $order->get_id(),
			'GatewayID'     => $bm_gateway::BLIK_0_CHANNEL,
			'Currency'      => 'PLN',
			'CustomerEmail' => $order->get_billing_email(),
			'CustomerIP'    => '127.0.0.1',
			'Title'         => (string) $order->get_id(),
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
				wp_json_encode( $params ),
				wp_json_encode( $result ),
			) );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [initialize] [parsed] [status: %s] [redirecturl: %s] [hash: %s]',
				isset( $result['status'] ) ? (string) $result['status'] : '',
				isset( $result['redirecturl'] ) ? (string) $result['redirecturl'] : '',
				isset( $result['hash'] ) ? (string) $result['hash'] : ''
			)
		);

		if ( isset( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
			throw new Exception( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] );
		}

		if ( empty( $result ) || ! is_array( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
			throw new Exception( sprintf( 'Continue transaction response invalid format (%s)',
				wp_json_encode( $result ) ) );
		}
	}

	public function verify_itn( WC_Order $order ): bool {
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Transaction_Test] [verify_itn] [order_id: %s] [autopay_itn_received: %s] [bm_order_itn_status: %s] [autopay_test_order: %s]',
				$order->get_id(),
				wp_json_encode( $order->get_meta( 'autopay_itn_received' ) ),
				wp_json_encode( $order->get_meta( 'bm_order_itn_status' ) ),
				wp_json_encode( $order->get_meta( 'autopay_test_order' ) ),
			) );
		if ( ! empty( $order->get_meta( 'autopay_itn_received' ) ) ) {
			return true;
		} else {
			return false;
		}
	}
}
