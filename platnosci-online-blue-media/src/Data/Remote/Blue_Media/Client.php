<?php

namespace Ilabs\BM_Woocommerce\Data\Remote\Blue_Media;

use Exception;
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
						'BmHeader' => 'pay-bm-continue-transaction-url',
					],
					'form_params' => $data,
					'verify' => true,
				] );

			//$statusCode   = $response->getStatusCode();
			return $response->getBody()->getContents();
		} catch ( Exception $e ) {
			return "Error: " . $e->getMessage();
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
			return "Error: " . $e->getMessage();
		}
	}
}
