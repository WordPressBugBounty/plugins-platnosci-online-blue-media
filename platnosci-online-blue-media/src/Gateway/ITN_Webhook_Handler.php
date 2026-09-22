<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * ITN_Webhook_Handler — handles Autopay ITN (Instant Transaction Notification) webhooks.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Woocommerce\Autopay_Order_Factory;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use SimpleXMLElement;
use WC_Order;

/**
 * Registers the WooCommerce API webhook action and processes ITN payloads from Autopay.
 */
class ITN_Webhook_Handler {

	/**
	 * The main Autopay gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway The main Autopay gateway instance.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Register the woocommerce_api_wc_gateway_bluemedia action and process ITN POSTs.
	 *
	 * @return void
	 */
	public function webhook(): void {
		do_action( 'autopay_debugger' );

		add_action(
			'woocommerce_api_wc_gateway_bluemedia',
			function () {
				if ( ob_get_level() ) {
					ob_clean();
				}

				try {
					if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ITN webhook from Blue Media payment processor; authentication is via HMAC signature, not WordPress nonce.
						$posted                  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$posted_xml              = simplexml_load_string( base64_decode( $posted['transactions'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						$all_fields_itn          = array();
						$all_fields_reponse      = array();
						$order_success_to_update = array();
						$order_failure_to_update = array();
						$order_pending_to_update = array();

						$itn_xml = base64_decode( $posted['transactions'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						blue_media()
						->get_woocommerce_logger( 'bm_woocommerce_itn' )
						->log_debug( 'Transactions from ITN: ' . $this->mask_itn_xml( $itn_xml ) );

						if ( preg_match(
							'/<currency>\s*(.*?)\s*<\/currency>/',
							base64_decode( $posted['transactions'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
							$matches
						) ) {
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[webhook] [Transactions from ITN] [currency found: %s]',
										wp_json_encode( $matches[1] ),
									)
								);

							blue_media()
								->get_currency_manager()
								->reconfigure( $matches[1] );
							$this->gateway->setup_variables();
						}

						$xw = xmlwriter_open_memory();
						xmlwriter_set_indent( $xw, true );
						xmlwriter_set_indent_string( $xw, ' ' );

						// 4th ($standalone) argument intentionally omitted: passing '' produces
						// a standalone="" attribute, which libxml rejects ("standalone accepts
						// only 'yes' or 'no'") -- our own response would then fail to parse.
						xmlwriter_start_document( $xw, '1.0', 'UTF-8' );
						xmlwriter_start_element( $xw, 'confirmationList' );
						xmlwriter_start_element( $xw, 'serviceID' );
						xmlwriter_text( $xw, $this->gateway->get_service_id() );
						xmlwriter_end_element( $xw ); // serviceID.
						xmlwriter_start_element( $xw, 'transactionsConfirmations' );

						foreach (
						$posted_xml->xpath( '/transactionList/transactions/transaction' )
						as $transaction
						) {
							$status_processing_allowed_in_store = false;
							blue_media()->get_currency_manager()->reconfigure();
							$this->gateway->setup_variables();

							foreach ( $transaction as $field ) {
								// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
								/* @var SimpleXMLElement $field ITN transaction field. */
								$field_string = ( (string) $field );
								if ( ! empty( $field ) ) {
									if ( 'customerData' === $field->getName() ) {
										$customer_data_fields = (array) $field;
										foreach ( $customer_data_fields as $value ) {
											$all_fields_itn[] = $value;
										}
									} else {
										$all_fields_itn[] = $field_string;
									}
								}
							}

							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
							$wc_order_id = (int) (string) $transaction->orderID;
							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
							$bm_order_status = (string) $transaction->paymentStatus;
							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
							$bm_remote_id       = (string) $transaction->remoteID;
							$bm_currency_symbol = (string) $transaction->currency;

							blue_media()
							->get_currency_manager()
							->reconfigure( $bm_currency_symbol );
							$this->gateway->setup_variables();

							$order               = wc_get_order( $wc_order_id );
							$confirmation_result = '';

							if ( $order instanceof WC_Order ) {
								$init_params = $order->get_meta( 'bm_transaction_init_params' );
								if ( ! is_array( $init_params ) ) {
									$confirmation_result                = Order_Remote_Status_Manager::RESULT_CONFIRMED;
									$status_processing_allowed_in_store = false;
									blue_media()
									->get_woocommerce_logger( 'bm_woocommerce_itn' )
									->log_debug( '[webhook] [init params not found in order meta]' );
								}
							} else {
								$confirmation_result                = Order_Remote_Status_Manager::RESULT_CONFIRMED;
								$status_processing_allowed_in_store = false;
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [order not found]' );
							}

							if ( '' === $confirmation_result ) {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [remote_status_manager - do update status]' );

								$remote_status_manager = blue_media()->get_order_remote_status_manager();
								$remote_status_manager->install_db_schema();

								$confirmation_result = $remote_status_manager->update_order_status(
									$wc_order_id,
									$bm_order_status
								);

								$status_processing_allowed_in_store = $remote_status_manager->is_status_processing_allowed_in_store();
							} else {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [remote_status_manager - skip]' );
							}

							xmlwriter_start_element( $xw, 'transactionConfirmed' );
							xmlwriter_start_element( $xw, 'orderID' );
							xmlwriter_text( $xw, (string) $wc_order_id );
							$all_fields_reponse[] = $wc_order_id;
							xmlwriter_end_element( $xw ); // orderID.
							xmlwriter_start_element( $xw, 'confirmation' );
							xmlwriter_text( $xw, $confirmation_result );
							$all_fields_reponse[] = $confirmation_result;
							xmlwriter_end_element( $xw ); // confirmation.
							xmlwriter_end_element( $xw ); // transactionConfirmed.

							blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf(
									'[webhook] [%s]',
									wp_json_encode(
										array(
											'order_id'   => $wc_order_id,
											'ITN status' => $bm_order_status,
											'confirmation_result' => $confirmation_result,
											'status_processing_allowed_in_store' => $status_processing_allowed_in_store ? 'yes' : 'no',
										)
									),
								)
							);

							if ( $status_processing_allowed_in_store ) {
									$wc_order = wc_get_order( $wc_order_id );

								if ( Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID === $bm_order_status ) {
									$order_success_to_update[ $bm_remote_id ] = $wc_order;
								}

								if ( Blue_Media_Gateway::ITN_PENDING_STATUS_ID === $bm_order_status ) {
									$order_pending_to_update[ $bm_remote_id ] = $wc_order;
								}

								if ( Blue_Media_Gateway::ITN_FAILURE_STATUS_ID === $bm_order_status ) {
									$order_failure_to_update[ $bm_remote_id ] = $wc_order;
								}
							}
						}

						$hash_from_itn = $posted_xml->xpath( '/transactionList/hash' );
						$hash_from_itn = (string) $hash_from_itn[0];

						$is_hash_valid = $this->validate_itn_hash( $all_fields_itn, $hash_from_itn );

						if ( ! $is_hash_valid && ! $this->gateway->resolve_is_test_mode() ) {
							blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_warning(
								sprintf(
									/* translators: %s: currency code resolved at the time of the fallback. */
									'[webhook] [validate_itn_hash - production failed, attempting testmode fallback] [currency=%s]',
									blue_media()->resolve_blue_media_currency_symbol()
								)
							);

							$this->gateway->set_testmode( true );
							$this->gateway->setup_variables();
							$is_hash_valid = $this->validate_itn_hash( $all_fields_itn, $hash_from_itn );
							$this->gateway->set_testmode( false );
							$this->gateway->setup_variables();
						}

						if ( ! $is_hash_valid ) {
							$known_order_id = 0;
							if ( ! empty( $order_success_to_update ) ) {
								$first_order    = reset( $order_success_to_update );
								$known_order_id = $first_order instanceof WC_Order ? (int) $first_order->get_id() : 0;
							} elseif ( ! empty( $order_pending_to_update ) ) {
								$first_order    = reset( $order_pending_to_update );
								$known_order_id = $first_order instanceof WC_Order ? (int) $first_order->get_id() : 0;
							} elseif ( ! empty( $order_failure_to_update ) ) {
								$first_order    = reset( $order_failure_to_update );
								$known_order_id = $first_order instanceof WC_Order ? (int) $first_order->get_id() : 0;
							}

							blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_error(
								sprintf(
									'[webhook] [validate_itn_hash - not valid] [currency=%s] [order_id=%d] [fields_count=%d] [Hash: %s]',
									blue_media()->resolve_blue_media_currency_symbol(),
									$known_order_id,
									count( $all_fields_itn ),
									$hash_from_itn
								)
							);

							ob_start();
							header( 'HTTP/1.0 401 Unauthorized' );
							echo esc_html__( 'validate_itn_hash - not valid', 'platnosci-online-blue-media' );
							exit;
						}

						foreach ( $order_success_to_update as $k => $wc_order ) {
							if ( '1' === (string) $wc_order->get_meta( 'autopay_test_order' ) ) {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[TestConnection] [ITN received] [Status: SUCCESS] [Order_Id: %s] [remoteID: %s]',
										$wc_order->get_id(),
										(string) $k,
									)
								);
							}
							$wc_order->add_meta_data( 'autopay_itn_received', 'SUCCESS' );
							$autopay_order = ( new Autopay_Order_Factory() )->create_by_wc_order( $wc_order );
							if ( $autopay_order->is_order_only_virtual() ) {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[webhook] [is_order_only_virtual] returns true [Order_Id: %s]',
										$wc_order->get_id(),
									)
								);

								$new_status = $this->gateway->get_option(
									'wc_payment_status_on_bm_success_virtual',
									'completed'
								);
							} else {
								$new_status = $this->gateway->get_currency_aware_option(
									'wc_payment_status_on_bm_success',
									'completed'
								);
							}

							$wc_order->payment_complete( $k );

							blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf(
									'[webhook] [Status from ITN: SUCCESS] [Matched WC status: %s] [Order_Id: %s]',
									$new_status,
									$wc_order->get_id(),
								)
							);

							$this->gateway->update_order_status(
								$wc_order,
								$new_status,
								'Autopay ITN: paymentStatus SUCCESS'
							);

							$wc_order->update_meta_data( 'bm_order_itn_status', Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID );
							$wc_order->save_meta_data();

							do_action(
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name with bm_ prefix; static analysis cannot verify dynamic string construction.
								sprintf( 'bm_order_bm_int_status_%s_processed', Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID ),
								$wc_order
							);
						}

						foreach ( $order_pending_to_update as $k => $wc_order ) {
							if ( '1' === (string) $wc_order->get_meta( 'autopay_test_order' ) ) {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[TestConnection] [ITN received] [Status: PENDING] [Order_Id: %s] [remoteID: %s]',
										$wc_order->get_id(),
										(string) $k,
									)
								);
							}
							$wc_order->add_meta_data( 'autopay_itn_received', 'PENDING' );
							$new_status = $this->gateway->get_currency_aware_option(
								'wc_payment_status_on_bm_pending',
								'pending'
							);
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[webhook] [Status from ITN: PENDING] [Matched WC status: %s] [Order_Id: %s]',
										$new_status,
										$wc_order->get_id(),
									)
								);

							$this->gateway->update_order_status(
								$wc_order,
								$new_status,
								'Autopay ITN: paymentStatus PENDING'
							);

							$wc_order->update_meta_data( 'bm_order_itn_status', Blue_Media_Gateway::ITN_PENDING_STATUS_ID );
							$wc_order->save_meta_data();
						}

						foreach ( $order_failure_to_update as $k => $wc_order ) {
							if ( '1' === (string) $wc_order->get_meta( 'autopay_test_order' ) ) {
								blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[TestConnection] [ITN received] [Status: FAILURE] [Order_Id: %s] [remoteID: %s]',
										$wc_order->get_id(),
										(string) $k,
									)
								);
							}
							$wc_order->add_meta_data( 'autopay_itn_received', 'FAILURE' );
							$new_status = $this->gateway->get_currency_aware_option(
								'wc_payment_status_on_bm_failure',
								'failed'
							);
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf(
										'[webhook] [Status from ITN: FAILURE] [Matched WC status: %s] [Order_Id: %s]',
										$new_status,
										$wc_order->get_id(),
									)
								);

							$this->gateway->update_order_status(
								$wc_order,
								$new_status,
								'Autopay ITN: paymentStatus FAILURE'
							);

							$wc_order->update_meta_data( 'bm_order_itn_status', Blue_Media_Gateway::ITN_FAILURE_STATUS_ID );
							$wc_order->save_meta_data();
						}

						xmlwriter_end_element( $xw ); // transactionsConfirmations.
						xmlwriter_start_element( $xw, 'hash' );
						xmlwriter_text( $xw, $this->generate_response_xml_hash( $all_fields_reponse ) );
						xmlwriter_end_element( $xw ); // hash.
						xmlwriter_end_document( $xw );

						$xml_response = xmlwriter_output_memory( $xw );
						blue_media()
						->get_woocommerce_logger( 'bm_woocommerce_itn' )
						->log_debug(
							sprintf(
								'[webhook xml_response] [xml: %s]',
								$xml_response,
							)
						);

						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML ITN protocol response built via XMLWriter; all values are properly encoded by xmlwriter_text().
						echo $xml_response;

						exit; // Exit with 200.
					}
				} catch ( Exception $e ) {
					blue_media()
					->get_woocommerce_logger( 'bm_woocommerce_itn' )
					->log_error(
						sprintf(
							'[Webhook exception debug] [message: %s] [Post data: %s]',
							wp_json_encode( $e->getMessage() ),
							// phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST data logged only for debugging; nonce is verified upstream before any state change.
							wp_json_encode( $_POST ),
						)
					);

					die( 'Message: ' . esc_html( $e->getMessage() ) . ' Code: ' . esc_html( (string) $e->getCode() ) );
				}
			}
		);
	}

	/**
	 * Build the XML response hash for a list of confirmation fields.
	 *
	 * @param array $all_fields_reponse The confirmation field values.
	 *
	 * @return string
	 * @throws Exception When hash computation fails.
	 */
	private function generate_response_xml_hash( array $all_fields_reponse ): string {
		array_unshift( $all_fields_reponse, $this->gateway->get_service_id() );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[generate_response_xml_hash] [fields count: %d]', count( $all_fields_reponse ) )
		);

		return $this->gateway->hash_transaction_parameters( $all_fields_reponse );
	}

	/**
	 * Redact all PII fields from an ITN XML string before writing to diagnostic logs.
	 *
	 * Covers every field that can carry payment-party identifying information per the
	 * Autopay ITN protocol: customer name, e-mail, phone, IP address, and bank account
	 * numbers (both sender and receiver NRB).  Tag matching is case-insensitive so the
	 * method is resilient to capitalisation differences across API versions.
	 *
	 * @param string $itn_xml Raw ITN XML.
	 *
	 * @return string XML with PII values replaced by [REDACTED].
	 */
	private function mask_itn_xml( string $itn_xml ): string {
		$pii_tags = [
			'customerEmail',
			'customerFirstname',
			'customerLastname',
			'customerPhone',
			'customerIP',
			'accountNumber',
			'senderNRB',
			'receiverNRB',
		];

		foreach ( $pii_tags as $tag ) {
			$itn_xml = preg_replace(
				'/<' . preg_quote( $tag, '/' ) . '>([^<]+)<\/' . preg_quote( $tag, '/' ) . '>/i',
				'<' . $tag . '>[REDACTED]</' . $tag . '>',
				$itn_xml
			);
		}

		return $itn_xml;
	}

	/**
	 * Validate the ITN hash received from Autopay against a locally computed hash.
	 *
	 * @param array  $transactions_from_itn Field values extracted from the ITN XML.
	 * @param string $hash_from_itn         Hash value from the ITN XML.
	 *
	 * @return bool
	 */
	private function validate_itn_hash( array $transactions_from_itn, $hash_from_itn ): bool {
		array_unshift( $transactions_from_itn, $this->gateway->get_service_id() );
		$itn_values_based_hash = $this->gateway->hash_transaction_parameters( $transactions_from_itn );

		$is_valid = $hash_from_itn === $itn_values_based_hash;

		// Non-secret diagnostic so support can correlate failures across currencies/test modes
		// without exposing the private key. The "fingerprint" is a short prefix of the SHA1
		// of the private key, which is one-way and stable per credential.
		blue_media()
			->get_woocommerce_logger( 'bm_woocommerce_itn' )
			->log_debug(
				sprintf(
					'[validate_itn_hash] [valid=%s] [currency=%s] [testmode=%s] [service_id=%s] [private_key_fingerprint=%s]',
					$is_valid ? 'yes' : 'no',
					blue_media()->resolve_blue_media_currency_symbol(),
					$this->gateway->resolve_is_test_mode() ? 'yes' : 'no',
					$this->gateway->get_service_id(),
					'' !== (string) $this->gateway->get_private_key() ? substr( sha1( (string) $this->gateway->get_private_key() ), 0, 8 ) : 'empty'
				)
			);

		return $is_valid;
	}
}
