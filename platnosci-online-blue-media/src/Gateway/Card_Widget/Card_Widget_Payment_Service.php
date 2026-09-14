<?php
/**
 * Card iframe widget (Autopay GatewayID 1500): builds pretransaction, signs Hash, POSTs to /payment.
 *
 * Mirrors the Google Pay token pipeline (base64 decode then encode) and optional CustomerIP omission
 * when empty, per Autopay hash rules. WalletType WIDGET marks PAN as collected in the partner widget.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Card_Widget;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Continue_Transaction\Continue_Transaction_Response_Xml_Parser;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Woocommerce_Logger;
use WC_Order;

/**
 * Encapsulates the Autopay “continue transaction” HTTP call for the card widget channel.
 */
final class Card_Widget_Payment_Service {

	/**
	 * @var Transaction_Hash_Generator_Interface
	 */
	private Transaction_Hash_Generator_Interface $hash_generator;

	/**
	 * @var Client
	 */
	private Client $http_client;

	/**
	 * @var Woocommerce_Logger
	 */
	private Woocommerce_Logger $logger;

	/**
	 * @param Transaction_Hash_Generator_Interface $hash_generator Gateway-bound hasher.
	 * @param Client                               $http_client    Guzzle wrapper for /payment.
	 * @param Woocommerce_Logger                   $logger         Plugin debug/error logger.
	 */
	public function __construct(
		Transaction_Hash_Generator_Interface $hash_generator,
		Client $http_client,
		Woocommerce_Logger $logger
	) {
		$this->hash_generator = $hash_generator;
		$this->http_client    = $http_client;
		$this->logger         = $logger;
	}

	/**
	 * Run pretransaction: normalize token, sign, POST, parse XML.
	 *
	 * @param WC_Order $order               WooCommerce order.
	 * @param string   $payment_token_input Raw token from POST (atp_card_payment_token).
	 * @param string   $amount_string       Formatted order total for the Autopay Amount field.
	 * @param string   $service_id          Autopay ServiceID.
	 * @param string   $gateway_base_url    Trailing-slash base (e.g. https://testpay.autopay.eu/).
	 *
	 * @return Card_Widget_Start_Result Outcome with params for meta persistence on success.
	 */
	public function start_pretransaction(
		WC_Order $order,
		string $payment_token_input,
		string $amount_string,
		string $service_id,
		string $gateway_base_url
	): Card_Widget_Start_Result {
		$order_id = $order->get_id();

		$payment_token_param = $this->normalize_payment_token_for_param( $payment_token_input );

		$params = $this->build_transaction_params(
			$order,
			$amount_string,
			$service_id,
			$payment_token_param
		);

		$params_with_hash = array_merge(
			$params,
			[
				Autopay_Payment_Protocol::FIELD_HASH => $this->hash_generator->generate( $params ),
			]
		);

		$this->log_masked_request( $order_id, $gateway_base_url, $params_with_hash );

		$raw = $this->http_client->continue_transaction_request(
			$params_with_hash,
			$gateway_base_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH
		);

		if ( is_string( $raw )
			&& 0 === strpos( $raw, Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER ) ) {
			$this->logger->log_error(
				sprintf(
					'%s [%s] order_id=%d detail=%s',
					Autopay_Payment_Protocol::LOG_SOURCE_PREFIX_CARD_WIDGET,
					Autopay_Payment_Protocol::LOG_EVENT_HTTP_TRANSPORT_ERROR,
					$order_id,
					$raw
				)
			);

			return new Card_Widget_Start_Result(
				Card_Widget_Start_Result::TRANSPORT_ERROR,
				$params_with_hash,
				null,
				'',
				$raw,
				''
			);
		}

		$raw_string = (string) $raw;
		$this->logger->log_debug(
			sprintf(
				'%s [%s] order_id=%d body_len=%d preview=%s',
				Autopay_Payment_Protocol::LOG_SOURCE_PREFIX_CARD_WIDGET,
				Autopay_Payment_Protocol::LOG_EVENT_RAW_RESPONSE,
				$order_id,
				strlen( $raw_string ),
				substr( $raw_string, 0, Autopay_Payment_Protocol::LOG_RAW_RESPONSE_PREVIEW_MAX_BYTES )
			)
		);

		$parsed = Continue_Transaction_Response_Xml_Parser::parse( $raw_string );
		$this->log_parsed_response( $order_id, $parsed );

		if ( isset( $parsed[ Autopay_Payment_Protocol::XML_LOCAL_CONFIRMATION ] )
			&& strtoupper( (string) $parsed[ Autopay_Payment_Protocol::XML_LOCAL_CONFIRMATION ] )
			=== Autopay_Payment_Protocol::CONFIRMATION_NOT_CONFIRMED ) {
			return new Card_Widget_Start_Result(
				Card_Widget_Start_Result::REJECTED,
				$params_with_hash,
				null,
				(string) ( $parsed[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] ?? '' ),
				'',
				$raw_string
			);
		}

		if ( empty( $parsed[ Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL ] ) ) {
			$this->logger->log_error(
				sprintf(
					'%s [%s] order_id=%d raw_truncated=%s',
					Autopay_Payment_Protocol::LOG_SOURCE_PREFIX_CARD_WIDGET,
					Autopay_Payment_Protocol::LOG_EVENT_EMPTY_REDIRECTURL,
					$order_id,
					substr( $raw_string, 0, Autopay_Payment_Protocol::LOG_RAW_RESPONSE_ERROR_DUMP_MAX_BYTES )
				)
			);

			return new Card_Widget_Start_Result(
				Card_Widget_Start_Result::BAD_RESPONSE,
				$params_with_hash,
				null,
				'',
				'',
				$raw_string
			);
		}

		return new Card_Widget_Start_Result(
			Card_Widget_Start_Result::SUCCESS,
			$params_with_hash,
			(string) $parsed[ Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL ],
			'',
			'',
			$raw_string
		);
	}

	/**
	 * Align widget token with Google Pay: strip whitespace, base64-decode (non-strict), re-encode.
	 *
	 * @param string $input Raw POST value.
	 *
	 * @return string Value for PaymentToken request field.
	 */
	private function normalize_payment_token_for_param( string $input ): string {
		$clean = preg_replace( Autopay_Payment_Protocol::PAYMENT_TOKEN_STRIP_WHITESPACE_PATTERN, '', $input );
		$bin   = base64_decode( $clean );

		return base64_encode( $bin );
	}

	/**
	 * Build ordered fields for Hash (CustomerIP omitted when empty).
	 *
	 * @param WC_Order $order               Order.
	 * @param string   $amount_string       API amount.
	 * @param string   $service_id          Service ID.
	 * @param string   $payment_token_param Normalized token.
	 *
	 * @return array<string, mixed>
	 */
	private function build_transaction_params(
		WC_Order $order,
		string $amount_string,
		string $service_id,
		string $payment_token_param
	): array {
		$customer_ip = trim( (string) blue_media()->get_core_helpers()->get_visitor_ip() );

		$params = [
			Autopay_Payment_Protocol::FIELD_SERVICE_ID     => $service_id,
			Autopay_Payment_Protocol::FIELD_ORDER_ID       => $order->get_id(),
			Autopay_Payment_Protocol::FIELD_AMOUNT         => $amount_string,
			Autopay_Payment_Protocol::FIELD_DESCRIPTION    => (string) $order->get_id(),
			Autopay_Payment_Protocol::FIELD_GATEWAY_ID     => Blue_Media_Gateway::CARD_CHANNEL,
			Autopay_Payment_Protocol::FIELD_CURRENCY       => $order->get_currency(),
			Autopay_Payment_Protocol::FIELD_CUSTOMER_EMAIL => $order->get_billing_email(),
		];

		if ( '' !== $customer_ip ) {
			$params[ Autopay_Payment_Protocol::FIELD_CUSTOMER_IP ] = $customer_ip;
		}

		$params[ Autopay_Payment_Protocol::FIELD_TITLE ]         = (string) $order->get_id();
		$params[ Autopay_Payment_Protocol::FIELD_PAYMENT_TOKEN ] = $payment_token_param;
		$params[ Autopay_Payment_Protocol::FIELD_WALLET_TYPE ]   = Autopay_Payment_Protocol::WALLET_TYPE_WIDGET_VALUE;

		return $params;
	}

	/**
	 * Log request with secrets redacted.
	 *
	 * @param int                  $order_id          Order ID.
	 * @param string               $gateway_base_url  Base URL.
	 * @param array<string, mixed> $params_with_hash  Full payload.
	 *
	 * @return void
	 */
	private function log_masked_request( int $order_id, string $gateway_base_url, array $params_with_hash ): void {
		$this->logger->log_debug(
			sprintf(
				'%s [%s] order_id=%d payment_endpoint=%s gateway_id=%s token_%s=%d',
				Autopay_Payment_Protocol::LOG_SOURCE_PREFIX_CARD_WIDGET,
				Autopay_Payment_Protocol::LOG_EVENT_REQUEST,
				$order_id,
				$gateway_base_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH,
				$params_with_hash[ Autopay_Payment_Protocol::FIELD_GATEWAY_ID ] ?? '',
				Autopay_Payment_Protocol::LOG_PAYMENT_TOKEN_LENGTH_LABEL,
				strlen( (string) ( $params_with_hash[ Autopay_Payment_Protocol::FIELD_PAYMENT_TOKEN ] ?? '' ) )
			)
		);
	}

	/**
	 * Log parsed XML summary.
	 *
	 * @param int                   $order_id Order ID.
	 * @param array<string, string> $parsed Parsed fields.
	 *
	 * @return void
	 */
	private function log_parsed_response( int $order_id, array $parsed ): void {
		$redirect_key = Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL;
		$status_key   = Autopay_Payment_Protocol::XML_LOCAL_STATUS;
		$confirm_key  = Autopay_Payment_Protocol::XML_LOCAL_CONFIRMATION;
		$reason_key   = Autopay_Payment_Protocol::XML_LOCAL_REASON;

		$this->logger->log_debug(
			sprintf(
				'%s [%s] order_id=%d redirecturl_len=%d status=%s confirmation=%s reason_snip=%s',
				Autopay_Payment_Protocol::LOG_SOURCE_PREFIX_CARD_WIDGET,
				Autopay_Payment_Protocol::LOG_EVENT_PARSED,
				$order_id,
				isset( $parsed[ $redirect_key ] ) ? strlen( (string) $parsed[ $redirect_key ] ) : 0,
				$parsed[ $status_key ] ?? '',
				$parsed[ $confirm_key ] ?? '',
				isset( $parsed[ $reason_key ] )
					? substr(
						(string) $parsed[ $reason_key ],
						0,
						Autopay_Payment_Protocol::LOG_PARSED_REASON_SNIPPET_MAX_BYTES
					)
					: ''
			)
		);
	}
}
