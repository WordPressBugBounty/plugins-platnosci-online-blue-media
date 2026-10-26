<?php

namespace Ilabs\BM_Woocommerce\Data\Remote\Blue_Media;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Isolated\Blue_Media\Isolated_Guzzlehttp\GuzzleHttp\Client as GuzzleHttpClient;

class Client {


	public function continue_transaction_request(
		array $data,
		string $gateway_url
	) {

		$client = new GuzzleHttpClient();

		try {
			$response = $client->post( $gateway_url,
				[
					'headers'     => [
						'BmHeader' => Autopay_Payment_Protocol::HTTP_HEADER_BM_CONTINUE_TRANSACTION,
					],
					'form_params' => $data,
					'verify'      => true,
				] );

			// $statusCode   = $response->getStatusCode();
			return $response->getBody()->getContents();
		} catch ( Exception $e ) {
			return Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER . $e->getMessage();
		}
	}

	/**
	 * POST to a form-based Autopay endpoint (e.g. `webapi/transactionStatus`,
	 * `deactivate_recurring`) and return the raw response body.
	 *
	 * Confirmed in Autopay "Specyfikacja integracji Serwisu Partnera z Systemem
	 * Płatności", Dodatek "Obsługa transakcji" (rozdz. 1.5.3.1, 4.10):
	 * `Content-Type: application/x-www-form-urlencoded`, NOT JSON -- do not
	 * assume the request format used by {@see google_pay_merchant_info()} (a
	 * different, wallet-specific endpoint with its own JSON contract) applies
	 * here just because both happen to sit under a `webapi/` path.
	 *
	 * @param array       $data      Request fields, already including Hash.
	 * @param string      $url       Full endpoint URL.
	 * @param string|null $bm_header BmHeader value to send, or null when the
	 *                                endpoint does not document one (confirmed
	 *                                absent for `deactivate_recurring`).
	 *
	 * @return string Raw response body, or an {@see Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER}-prefixed
	 *                 string on transport failure.
	 */
	public function form_post(
		array $data,
		string $url,
		?string $bm_header = null
	) {
		$headers = [];

		if ( null !== $bm_header ) {
			$headers['BmHeader'] = $bm_header;
		}

		$client = new GuzzleHttpClient();

		try {
			$response = $client->post( $url,
				[
					'headers'     => $headers,
					'form_params' => $data,
					'verify'      => true,
				] );

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
