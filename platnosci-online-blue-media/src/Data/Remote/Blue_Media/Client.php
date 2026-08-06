<?php

namespace Ilabs\BM_Woocommerce\Data\Remote\Blue_Media;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use \Isolated\Blue_Media\Isolated_Guzzlehttp\GuzzleHttp\Client as GuzzleHttpClient;

class Client {


	public function continue_transaction_request(
		array $data,
		string $gateway_url
	) {

		$client = new GuzzleHttpClient();

		try {
			$response = $client->post( $gateway_url,
				[
					'headers' => [
						'BmHeader' => Autopay_Payment_Protocol::HTTP_HEADER_BM_CONTINUE_TRANSACTION,
					],
					'form_params' => $data,
					'verify' => true,
				] );

			//$statusCode   = $response->getStatusCode();
			return $response->getBody()->getContents();
		} catch ( Exception $e ) {
			return Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER . $e->getMessage();
		}
	}

	public function google_pay_merchant_info(
		array $data,
		string $gateway_url
	) {


		$client = new GuzzleHttpClient( [ 'base_uri' => $gateway_url ] );

		try {
			$response = $client->post( 'webapi/googlePayMerchantInfo',
				[
					'headers' => [
						'Content-Type' => 'application/json',
						'BmHeader'     => 'pay-bm',
					],
					'json'    => $data,
					'verify'  => false,
				] );


			$responseData = $response->getBody()->getContents();

			return $responseData;

		} catch ( Exception $e ) {
			return Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER . $e->getMessage();
		}
	}
}
