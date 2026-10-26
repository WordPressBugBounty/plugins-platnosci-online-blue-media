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
use Ilabs\BM_Woocommerce\Gateway\Recurring\Attempt_State_Store;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Result_Applier;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use SimpleXMLElement;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;

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
	 * Validated ITN for an ordinary order that goes through the remote status table.
	 */
	private const ITN_KIND_ORDINARY = 'ordinary';

	/**
	 * Validated ITN for a card-recurring attempt ("{id}-{seq}" orderID).
	 */
	private const ITN_KIND_RECURRING = 'recurring';

	/**
	 * Validated ITN that is only confirmed back to Autopay, without any state change
	 * (order not found, init params missing, renewal order not found).
	 */
	private const ITN_KIND_CONFIRM_ONLY = 'confirm_only';

	/**
	 * Register the woocommerce_api_wc_gateway_bluemedia action and process ITN POSTs.
	 *
	 * Processing is split into two phases: validate_itn_request() (no persistent
	 * writes, ends with the hash check) and apply_itn() (all state changes and the
	 * XML response). Nothing is written before validate_itn_request() accepts the ITN.
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
						$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

						$validation = $this->validate_itn_request( $posted );

						if ( true !== $validation['accepted'] ) {
							$this->respond_itn_rejected( $validation );
						}

						$this->apply_itn( $validation );

						exit; // Exit with 200.
					}
				} catch ( \Throwable $e ) {
					// \Throwable, not Exception: a crash inside apply_itn() (e.g. a PHP Error
					// raised from a third-party hook on woocommerce_order_status_changed, as
					// opposed to one of our own Exceptions) must still hit this log + the 500
					// response below. Order_Remote_Status_Manager::update_order_status() may
					// already have claimed SUCCESS_APPLYING for this order by this point; an
					// uncaught Error here would otherwise skip straight past this handler with
					// no logging, leaving that row stuck until a later Autopay retry happens to
					// take over the claim.
					// Never log the raw $_POST: it carries the payer's data (base64). Log the
					// decoded ITN XML through the same mask as validate_itn_request() instead.
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ITN webhook authenticated by HMAC hash, not a nonce (the hash may not be verified yet here); the base64 payload is only decoded and masked for the log, never trusted.
					$raw_transactions = isset( $_POST['transactions'] ) ? wp_unslash( $_POST['transactions'] ) : null;

					if ( ! is_string( $raw_transactions ) || '' === $raw_transactions ) {
						$itn_log_data = '[no transactions field]';
					} else {
						$itn_xml      = base64_decode( $raw_transactions, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 is the transport encoding mandated by the Autopay ITN protocol.
						$itn_log_data = false === $itn_xml ? '[invalid base64]' : $this->mask_itn_xml( $itn_xml );
					}

					blue_media()
					->get_woocommerce_logger( 'bm_woocommerce_itn' )
					->log_error(
						sprintf(
							'[Webhook exception debug] [message: %s] [Transactions from ITN: %s]',
							wp_json_encode( $e->getMessage() ),
							$itn_log_data
						)
					);

					// The ITN was not processed: answer 500 so Autopay retries it. The response
					// never carries the exception message or code (unauthenticated sender).
					if ( ! headers_sent() ) {
						header( 'HTTP/1.0 500 Internal Server Error' );
					}

					die( esc_html__( 'Internal error', 'platnosci-online-blue-media' ) );
				}
			}
		);
	}

	/**
	 * Validation phase: parses the ITN and checks every invariant, then the hash.
	 *
	 * Performs no persistent write (no DB, order status, order meta or option). The
	 * only side effects are logging and the in-memory currency/gateway configuration
	 * (Currency::reconfigure() + Blue_Media_Gateway::setup_variables()), which
	 * apply_itn() re-establishes explicitly from the returned currency.
	 *
	 * @param array $posted Unslashed $_POST.
	 *
	 * @return array Either [ 'accepted' => false, 'reason' => string, 'context' => array ]
	 *               or [ 'accepted' => true, 'kind', 'currency', 'payment_status',
	 *               'remote_id', 'wc_order_id', 'response_order_id', 'recurring_entry' ].
	 * @throws Exception When hash computation fails.
	 */
	private function validate_itn_request( array $posted ): array {
		$raw_transactions = $posted['transactions'] ?? null;

		if ( ! is_string( $raw_transactions ) || '' === $raw_transactions ) {
			return $this->itn_rejected( 'transactions_field_missing' );
		}

		$itn_xml = base64_decode( $raw_transactions, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 is the transport encoding mandated by the Autopay ITN protocol.

		if ( false === $itn_xml ) {
			return $this->itn_rejected( 'invalid_base64' );
		}

		blue_media()
		->get_woocommerce_logger( 'bm_woocommerce_itn' )
		->log_debug( 'Transactions from ITN: ' . $this->mask_itn_xml( $itn_xml ) );

		if ( false !== stripos( $itn_xml, '<!DOCTYPE' ) ) {
			return $this->itn_rejected( 'doctype_not_allowed' );
		}

		$posted_xml = $this->safe_parse_itn_xml( $itn_xml );

		if ( ! $posted_xml instanceof SimpleXMLElement ) {
			return $this->itn_rejected( 'unparsable_xml' );
		}

		// Autopay ITN always concerns exactly one transaction.
		$transactions = $posted_xml->xpath( '/transactionList/transactions/transaction' );

		if ( ! is_array( $transactions ) || 1 !== count( $transactions ) ) {
			return $this->itn_rejected(
				'transaction_count_not_one',
				array( 'count' => is_array( $transactions ) ? count( $transactions ) : 0 )
			);
		}

		$transaction    = $transactions[0];
		$all_fields_itn = array();

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

		$raw_order_id = (string) $transaction->orderID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
		$attempt_ref  = Attempt_State_Store::parse_order_id( $raw_order_id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
		$bm_order_status = (string) $transaction->paymentStatus;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
		$bm_remote_id       = (string) $transaction->remoteID;
		$bm_currency_symbol = (string) $transaction->currency;
		$bm_gateway_id      = (int) ( $transaction->gatewayID ?? 0 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.
		$bm_amount          = (string) ( $transaction->amount ?? '' );
		$bm_start_amount    = (string) ( $transaction->startAmount ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay ITN API; cannot be changed.

		// Status whitelist. '' is checked explicitly (not empty(), which is also true for '0').
		if ( '' === $bm_order_status
			|| ! in_array(
				$bm_order_status,
				array(
					Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID,
					Blue_Media_Gateway::ITN_PENDING_STATUS_ID,
					Blue_Media_Gateway::ITN_FAILURE_STATUS_ID,
				),
				true
			)
		) {
			return $this->itn_rejected( 'unsupported_payment_status', array( 'payment_status' => $bm_order_status ) );
		}

		// Resolve the currency once, for this one transaction, and check the RESULT:
		// Currency::reconfigure() silently falls back to the shop currency for an
		// unknown code, so the resolved code must equal the requested one.
		$resolved_currency = blue_media()->get_currency_manager()->reconfigure( $bm_currency_symbol );
		$this->gateway->setup_variables();

		if ( '' === $bm_currency_symbol
			|| null === $resolved_currency
			|| $resolved_currency->get_code() !== $bm_currency_symbol
			|| blue_media()->resolve_blue_media_currency_symbol() !== $bm_currency_symbol
		) {
			return $this->itn_rejected( 'currency_not_supported', array( 'currency' => $bm_currency_symbol ) );
		}

		$configured_service_id = trim( (string) $this->gateway->get_service_id() );

		if ( '' === $configured_service_id || '' === trim( (string) $this->gateway->get_private_key() ) ) {
			return $this->itn_rejected( 'currency_not_configured', array( 'currency' => $bm_currency_symbol ) );
		}

		$service_id_nodes = $posted_xml->xpath( '/transactionList/serviceID' );

		if ( ! is_array( $service_id_nodes ) || 1 !== count( $service_id_nodes ) ) {
			return $this->itn_rejected( 'service_id_missing' );
		}

		$itn_service_id = trim( (string) $service_id_nodes[0] );

		if ( '' === $itn_service_id || $itn_service_id !== $configured_service_id ) {
			return $this->itn_rejected( 'service_id_mismatch', array( 'currency' => $bm_currency_symbol ) );
		}

		$hash_nodes = $posted_xml->xpath( '/transactionList/hash' );

		if ( ! is_array( $hash_nodes ) || 1 !== count( $hash_nodes ) ) {
			return $this->itn_rejected( 'hash_missing' );
		}

		$hash_from_itn = (string) $hash_nodes[0];

		if ( '' === $hash_from_itn ) {
			return $this->itn_rejected( 'hash_missing' );
		}

		$wc_order_id       = null;
		$response_order_id = $raw_order_id;
		$recurring_entry   = null;
		$known_order_id    = 0;

		if ( null !== $attempt_ref ) {
			// A card-recurring attempt: the "{id}-{seq}" shape
			// alone proves nothing -- do NOT cast to int and do NOT run the
			// ordinary Order_Remote_Status_Manager dedup path (that is keyed by
			// plain WC order id, the wrong model for per-attempt correlation).
			// The actual resolution happens in apply_itn(), after the hash is
			// validated (resolve_recurring_transactions()).
			$known_order_id = (int) $attempt_ref['renewal_order_id'];
			$renewal_order  = wc_get_order( $attempt_ref['renewal_order_id'] );

			if ( $renewal_order instanceof WC_Order ) {
				$kind            = self::ITN_KIND_RECURRING;
				$recurring_entry = array(
					'renewal_order_id' => $attempt_ref['renewal_order_id'],
					'seq'              => $attempt_ref['seq'],
					'payment_status'   => $bm_order_status,
					'remote_id'        => $bm_remote_id,
					'amount'           => $bm_amount,
					'currency'         => $bm_currency_symbol,
					'gateway_id'       => $bm_gateway_id,
				);
			} else {
				$kind = self::ITN_KIND_CONFIRM_ONLY;
				blue_media()
				->get_woocommerce_logger( 'bm_woocommerce_itn' )
				->log_debug(
					sprintf( '[webhook] [recurring attempt: renewal order not found] [renewal_order_id: %d]', $attempt_ref['renewal_order_id'] )
				);
			}
		} else {
			$wc_order_id       = (int) $raw_order_id;
			$response_order_id = (string) $wc_order_id;
			$known_order_id    = $wc_order_id;
			$order             = wc_get_order( $wc_order_id );

			if ( ! $order instanceof WC_Order ) {
				$kind = self::ITN_KIND_CONFIRM_ONLY;
				blue_media()
				->get_woocommerce_logger( 'bm_woocommerce_itn' )
				->log_debug( '[webhook] [order not found]' );
			} else {
				$init_params = $order->get_meta( 'bm_transaction_init_params' );

				if ( ! is_array( $init_params ) ) {
					$kind = self::ITN_KIND_CONFIRM_ONLY;
					blue_media()
					->get_woocommerce_logger( 'bm_woocommerce_itn' )
					->log_debug( '[webhook] [init params not found in order meta]' );
				} else {
					if ( $bm_currency_symbol !== (string) $order->get_currency() ) {
						return $this->itn_rejected( 'order_currency_mismatch', array( 'order_id' => $wc_order_id ) );
					}

					$amount_rejection = $this->validate_itn_amount_against_init_params( $init_params, $bm_start_amount, $bm_amount, $wc_order_id );

					if ( null !== $amount_rejection ) {
						return $amount_rejection;
					}

					$kind = self::ITN_KIND_ORDINARY;
				}
			}
		}

		// Exactly one hash check, with the key of the ACTIVE mode only (production key in
		// production, test key when the global test mode is on). There is deliberately no
		// fallback to the other mode's key.
		$is_hash_valid = $this->validate_itn_hash( $all_fields_itn, $hash_from_itn );

		if ( ! $is_hash_valid ) {
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

			return $this->itn_rejected( 'invalid_hash' );
		}

		return array(
			'accepted'          => true,
			'kind'              => $kind,
			'currency'          => $bm_currency_symbol,
			'payment_status'    => $bm_order_status,
			'remote_id'         => $bm_remote_id,
			'wc_order_id'       => $wc_order_id,
			'response_order_id' => $response_order_id,
			'recurring_entry'   => $recurring_entry,
		);
	}

	/**
	 * Application phase: performs every state change for an ITN that
	 * validate_itn_request() accepted, then echoes the signed XML response.
	 *
	 * The currency context is re-established explicitly from the validated result,
	 * never inherited from whatever the validation phase left configured.
	 *
	 * @param array $validation Accepted result of validate_itn_request().
	 *
	 * @return void
	 * @throws Exception When hash computation fails.
	 */
	private function apply_itn( array $validation ): void {
		$this->establish_currency_context( $validation['currency'] );

		$bm_order_status                    = $validation['payment_status'];
		$bm_remote_id                       = $validation['remote_id'];
		$wc_order_id                        = $validation['wc_order_id'];
		$confirmation_result                = Order_Remote_Status_Manager::RESULT_CONFIRMED;
		$status_processing_allowed_in_store = false;
		$order_success_to_update            = array();
		$order_failure_to_update            = array();
		$order_pending_to_update            = array();
		$recurring_transactions_to_resolve  = array();

		if ( self::ITN_KIND_RECURRING === $validation['kind'] ) {
			$recurring_transactions_to_resolve[] = $validation['recurring_entry'];
		} elseif ( self::ITN_KIND_ORDINARY === $validation['kind'] ) {
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

		blue_media()
		->get_woocommerce_logger( 'bm_woocommerce_itn' )
		->log_debug(
			sprintf(
				'[webhook] [%s]',
				wp_json_encode(
					array(
						'order_id'            => $wc_order_id,
						'ITN status'          => $bm_order_status,
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

		// Card-recurring attempts are resolved here, strictly AFTER the hash has been
		// validated in validate_itn_request().
		$this->resolve_recurring_transactions( $recurring_transactions_to_resolve );
		$this->establish_currency_context( $validation['currency'] );

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

			// Plain SUCCESS is written only after the side effects actually left the order
			// paid. Otherwise the row stays SUCCESS_APPLYING and a retry can finish the job.
			$fresh_order = wc_get_order( $wc_order->get_id() );

			if ( $fresh_order instanceof WC_Order && $fresh_order->is_paid() ) {
				blue_media()->get_order_remote_status_manager()->finalize_success( (int) $fresh_order->get_id() );
			}
		}

		foreach ( $order_pending_to_update as $k => $wc_order ) {
			if ( $this->should_skip_reversal( $wc_order, Blue_Media_Gateway::ITN_PENDING_STATUS_ID ) ) {
				continue;
			}

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
			if ( $this->should_skip_reversal( $wc_order, Blue_Media_Gateway::ITN_FAILURE_STATUS_ID ) ) {
				continue;
			}

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

		$all_fields_reponse = array( $validation['response_order_id'], $confirmation_result );

		$xw = xmlwriter_open_memory();
		xmlwriter_set_indent( $xw, true );
		xmlwriter_set_indent_string( $xw, ' ' );

		// 4th ($standalone) argument intentionally omitted: passing '' produces
		// a standalone="" attribute, which
		// libxml rejects ("standalone accepts only 'yes' or 'no'") -- our own
		// response would then fail to parse. Same fix already applied in
		// RPAN_Webhook_Handler::respond() for the identical pattern.
		xmlwriter_start_document( $xw, '1.0', 'UTF-8' );
		xmlwriter_start_element( $xw, 'confirmationList' );
		xmlwriter_start_element( $xw, 'serviceID' );
		xmlwriter_text( $xw, $this->gateway->get_service_id() );
		xmlwriter_end_element( $xw ); // serviceID.
		xmlwriter_start_element( $xw, 'transactionsConfirmations' );
		xmlwriter_start_element( $xw, 'transactionConfirmed' );
		xmlwriter_start_element( $xw, 'orderID' );
		xmlwriter_text( $xw, $validation['response_order_id'] );
		xmlwriter_end_element( $xw ); // orderID.
		xmlwriter_start_element( $xw, 'confirmation' );
		xmlwriter_text( $xw, $confirmation_result );
		xmlwriter_end_element( $xw ); // confirmation.
		xmlwriter_end_element( $xw ); // transactionConfirmed.
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
	}

	/**
	 * Decides whether a PENDING/FAILURE ITN must NOT be applied to the order: the order
	 * belongs to another payment method (except Test Connection orders, which have no
	 * payment method) or is already paid. Logs a warning when the reversal is skipped.
	 * The ITN is still confirmed to Autopay; only the local status change is skipped.
	 *
	 * @param WC_Order $wc_order   Order targeted by the ITN.
	 * @param string   $itn_status Incoming ITN status (PENDING or FAILURE).
	 *
	 * @return bool True when the status change must be skipped.
	 */
	private function should_skip_reversal( WC_Order $wc_order, string $itn_status ): bool {
		$payment_method = (string) $wc_order->get_payment_method();
		$is_test_order  = '1' === (string) $wc_order->get_meta( 'autopay_test_order' );
		$is_paid        = $wc_order->is_paid();
		$reasons        = array();

		if ( 'bluemedia' !== $payment_method && ! $is_test_order ) {
			$reasons[] = 'foreign_payment_method';
		}

		if ( $is_paid ) {
			$reasons[] = 'is_paid';
		}

		if ( empty( $reasons ) ) {
			return false;
		}

		blue_media()
		->get_woocommerce_logger( 'bm_woocommerce_itn' )
		->log_warning(
			sprintf(
				'[webhook] [ITN %s not applied - order status left unchanged] [%s]',
				$itn_status,
				wp_json_encode(
					array(
						'order_id'       => $wc_order->get_id(),
						'itn_status'     => $itn_status,
						'wc_status'      => $wc_order->get_status(),
						'payment_method' => $payment_method,
						'is_paid'        => $is_paid ? 'yes' : 'no',
						'reason'         => implode( ',', $reasons ),
					)
				)
			)
		);

		return true;
	}

	/**
	 * Logs a rejected ITN and ends the request with the same 401 response for every
	 * rejection reason, so the response never reveals which check failed.
	 *
	 * @param array $validation Rejected result of validate_itn_request().
	 *
	 * @return void
	 */
	private function respond_itn_rejected( array $validation ): void {
		blue_media()
		->get_woocommerce_logger( 'bm_woocommerce_itn' )
		->log_error(
			sprintf(
				'[webhook] [ITN rejected] [reason: %s] [%s]',
				$validation['reason'],
				wp_json_encode( $validation['context'] )
			)
		);

		ob_start();
		header( 'HTTP/1.0 401 Unauthorized' );
		echo esc_html__( 'validate_itn_hash - not valid', 'platnosci-online-blue-media' );
		exit;
	}

	/**
	 * Builds a rejected validation result.
	 *
	 * @param string $reason  Machine-readable rejection reason (logged only).
	 * @param array  $context Non-secret, PII-free diagnostic context (logged only).
	 *
	 * @return array
	 */
	private function itn_rejected( string $reason, array $context = array() ): array {
		return array(
			'accepted' => false,
			'reason'   => $reason,
			'context'  => $context,
		);
	}

	/**
	 * Parses untrusted ITN XML without PHP warnings and without network access.
	 * The caller rejects any DOCTYPE before this is reached.
	 *
	 * @param string $itn_xml Base64-decoded ITN payload.
	 *
	 * @return SimpleXMLElement|null Null when the payload is not well-formed XML.
	 */
	private function safe_parse_itn_xml( string $itn_xml ): ?SimpleXMLElement {
		$previous_setting = libxml_use_internal_errors( true );
		$xml              = simplexml_load_string( $itn_xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		return $xml instanceof SimpleXMLElement ? $xml : null;
	}

	/**
	 * Re-establishes the gateway configuration for an explicitly given, already
	 * validated currency.
	 *
	 * @param string $currency Validated ISO-4217 currency code.
	 *
	 * @return void
	 */
	private function establish_currency_context( string $currency ): void {
		blue_media()->get_currency_manager()->reconfigure( $currency );
		$this->gateway->setup_variables();
	}

	/**
	 * Binds the ITN amount to the amount this plugin sent when starting the
	 * transaction (`bm_transaction_init_params['Amount']`). `startAmount` is compared
	 * when the ITN carries it, `amount` otherwise; both sides are compared as integer
	 * cents after rounding to 2 decimal places.
	 *
	 * The check is skipped (not failed) when no trustworthy reference amount exists,
	 * e.g. the 3DS path stores [ '3ds' ] instead of the request parameters.
	 *
	 * @param array  $init_params     `bm_transaction_init_params` of the order.
	 * @param string $bm_start_amount Raw `startAmount` from the ITN ('' when absent).
	 * @param string $bm_amount       Raw `amount` from the ITN ('' when absent).
	 * @param int    $wc_order_id     WooCommerce order ID (for logging).
	 *
	 * @return array|null A rejected result on mismatch, null when the amount is accepted
	 *                    or cannot be compared.
	 */
	private function validate_itn_amount_against_init_params( array $init_params, string $bm_start_amount, string $bm_amount, int $wc_order_id ): ?array {
		$expected_cents = isset( $init_params['Amount'] ) && is_scalar( $init_params['Amount'] )
			? $this->amount_to_rounded_cents( (string) $init_params['Amount'] )
			: null;

		if ( null === $expected_cents ) {
			blue_media()
			->get_woocommerce_logger( 'bm_woocommerce_itn' )
			->log_debug(
				sprintf( '[webhook] [amount check skipped: no reference amount in init params] [order_id: %d]', $wc_order_id )
			);

			return null;
		}

		$itn_amount   = '' !== trim( $bm_start_amount ) ? $bm_start_amount : $bm_amount;
		$actual_cents = $this->amount_to_rounded_cents( $itn_amount );

		if ( null === $actual_cents || $expected_cents !== $actual_cents ) {
			return $this->itn_rejected(
				'order_amount_mismatch',
				array(
					'order_id'          => $wc_order_id,
					'start_amount_used' => '' !== trim( $bm_start_amount ) ? 'yes' : 'no',
				)
			);
		}

		return null;
	}

	/**
	 * Normalises a non-negative decimal amount string ("100", "100.00", "100,5",
	 * "100.005") to integer cents, rounding to 2 decimal places first.
	 *
	 * @param string $raw Decimal amount string.
	 *
	 * @return int|null Null for anything that is not a plain non-negative decimal number.
	 */
	private function amount_to_rounded_cents( string $raw ): ?int {
		$normalized = str_replace( ',', '.', trim( $raw ) );

		if ( 1 !== preg_match( '/^\d+(?:\.\d+)?\z/', $normalized ) ) {
			return null;
		}

		return (int) round( round( (float) $normalized, 2 ) * 100 );
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
	 * The whole customerData node (payer name, address, NRB, ...) is replaced by
	 * <customerData>[REDACTED]</customerData> regardless of which child tags it holds;
	 * an empty or self-closing node is replaced the same way.
	 *
	 * @param string $itn_xml Raw ITN XML.
	 *
	 * @return string XML with PII values replaced by [REDACTED].
	 */
	private function mask_itn_xml( string $itn_xml ): string {
		// Self-closing alternative first, so "<customerData/>" never opens a lazy match
		// that would run on to the next node's closing tag.
		$itn_xml = preg_replace(
			'/<customerData\b[^>]*\/>|<customerData\b(?:[^>]*[^\/>])?>.*?<\/customerData\s*>/is',
			'<customerData>[REDACTED]</customerData>',
			$itn_xml
		);

		if ( null === $itn_xml ) {
			// Fail closed: never log the unmasked payload when the regex engine fails.
			return '[ITN XML masking failed]';
		}

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

		$is_valid = hash_equals( (string) $itn_values_based_hash, (string) $hash_from_itn );

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

	/**
	 * Resolves every card-recurring attempt collected during the per-transaction loop
	 * above, now that the packet-wide ITN hash has already been validated. Every check
	 * here is defense-in-depth against a superficially well-formed but wrong-content
	 * transaction — a failed check never mutates anything, it only skips resolving that
	 * one entry, leaving it for recovery (Renewal_Status_Check_Job/Renewal_Attempt_Sweep)
	 * to eventually determine.
	 *
	 * @param array $entries Queued recurring transactions (renewal_order_id, seq,
	 *                       payment_status, remote_id, amount, currency, gateway_id).
	 */
	private function resolve_recurring_transactions( array $entries ): void {
		$store = new Attempt_State_Store();

		foreach ( $entries as $entry ) {
			$renewal_order_id = (int) $entry['renewal_order_id'];
			$seq              = (int) $entry['seq'];

			// Re-establish the gateway context for THIS entry's own currency before
			// touching anything — the main loop above may have left the gateway
			// configured for a different transaction's currency if the packet mixes
			// currencies (a currency-of-last-entry bug).
			blue_media()->get_currency_manager()->reconfigure( $entry['currency'] );
			$this->gateway->setup_variables();

			$renewal_order = wc_get_order( $renewal_order_id );

			if ( ! $renewal_order instanceof WC_Order
				|| ( class_exists( Renewal::class ) && ! Renewal::is_renewal_order( $renewal_order ) )
			) {
				// The "{id}-{seq}" shape alone proves nothing — confirm this
				// is actually one of our renewal orders before trusting anything else.
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_error(
					sprintf( '[resolve_recurring_transactions] [not a renewal order] [renewal_order_id: %d]', $renewal_order_id )
				);
				continue;
			}

			$attempt = $store->get_current_attempt( $renewal_order_id );

			if ( null === $attempt || (int) ( $attempt['seq'] ?? -1 ) !== $seq ) {
				// No open attempt, or this is a late/duplicate notification for an
				// attempt that is no longer current — resolve() would refuse it anyway,
				// but the amount/context checks below are meaningless against a
				// stale/absent record, so skip them explicitly.
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_debug(
					sprintf(
						'[resolve_recurring_transactions] [no matching current attempt, ignored] [renewal_order_id: %d] [seq: %d]',
						$renewal_order_id,
						$seq
					)
				);
				continue;
			}

			$context = (array) ( $attempt['context'] ?? [] );

			// An attempt started with GatewayID=0 (customer picks
			// the channel on Autopay's hosted page -- no whitelabel widget) cannot know
			// the real, eventual GatewayID in advance -- 'gateway_choice' === 'hosted'
			// (set only by that specific case) skips this check. Every other attempt
			// (AUTO/MANUAL/an explicit-channel ordinary repayment) requires an exact
			// match against what THIS attempt's own context recorded -- never the
			// hardcoded card-recurring channel constant, so an ordinary channel's ITN
			// resolves through this same path, not just channel 1503.
			$is_hosted_choice = 'hosted' === (string) ( $context['gateway_choice'] ?? 'explicit' );

			if ( ! $is_hosted_choice
				&& (int) ( $context['gateway_id'] ?? -1 ) !== (int) $entry['gateway_id']
			) {
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_error(
					sprintf(
						'[resolve_recurring_transactions] [unexpected gateway_id for this attempt] [renewal_order_id: %d] [seq: %d] [expected: %s] [got: %d]',
						$renewal_order_id,
						$seq,
						wp_json_encode( $context['gateway_id'] ?? null ),
						$entry['gateway_id']
					)
				);
				continue;
			}

			if ( (string) ( $context['currency'] ?? '' ) !== $entry['currency']
				|| (string) ( $context['service_id'] ?? '' ) !== $this->gateway->get_service_id()
				|| (string) ( $context['environment'] ?? '' ) !== ( $this->gateway->resolve_is_test_mode() ? 'test' : 'production' )
			) {
				// Content does not match the context this attempt was started under —
				// never resolve on mismatched data.
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_error(
					sprintf(
						'[resolve_recurring_transactions] [context mismatch, not resolved] [renewal_order_id: %d] [seq: %d]',
						$renewal_order_id,
						$seq
					)
				);
				continue;
			}

			$expected_cents = isset( $context['amount_cents'] ) ? (int) $context['amount_cents'] : null;
			$actual_cents   = $this->normalize_amount_to_cents( $entry['amount'] );

			if ( null === $expected_cents || null === $actual_cents || $expected_cents !== $actual_cents ) {
				// Exact integer-cents comparison against what THIS attempt actually sent
				// (Renewal_Payment_Handler records it in context at start_new_attempt()
				// time) — never a float comparison with a tolerance.
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_error(
					sprintf(
						'[resolve_recurring_transactions] [amount mismatch, not resolved] [renewal_order_id: %d] [seq: %d]',
						$renewal_order_id,
						$seq
					)
				);
				continue;
			}

			if ( '' !== $entry['remote_id'] ) {
				$store->record_remote_id( $renewal_order_id, $seq, $entry['remote_id'] );
			}

			if ( Blue_Media_Gateway::ITN_PENDING_STATUS_ID === $entry['payment_status'] ) {
				// Informational only: a WC status update here would need its
				// own "is this still the open, unresolved attempt" gate to avoid a
				// spoofed/late PENDING overwriting a newer or already-SUCCESS attempt.
				// No business need for it has been identified, so this stays a no-op —
				// the checks above already confirmed the notification belongs to the
				// current attempt. The frontend polling meta is
				// still updated -- it is only ever read for 'ordinary' attempts, and is
				// already gated on the same current-attempt/seq match above.
				Recurring_Result_Applier::update_polling_status_meta( $renewal_order, $context, Blue_Media_Gateway::ITN_PENDING_STATUS_ID );
				continue;
			}

			$new_state = $this->map_itn_status_to_attempt_state( $entry['payment_status'] );

			if ( null === $new_state ) {
				blue_media()->get_woocommerce_logger( 'bm_woocommerce_itn' )->log_warning(
					sprintf(
						'[resolve_recurring_transactions] [unrecognised paymentStatus, not resolved] [renewal_order_id: %d] [seq: %d] [payment_status: %s]',
						$renewal_order_id,
						$seq,
						$entry['payment_status']
					)
				);
				continue;
			}

			( new Recurring_Result_Applier() )->apply( $renewal_order_id, $seq, $new_state, 'itn' );
		}
	}

	/**
	 * Maps an ITN `paymentStatus` value to an Attempt_State_Store terminal state.
	 * PENDING is handled separately by the caller (not terminal) and never reaches here.
	 *
	 * @param string $payment_status Raw `paymentStatus` value from the ITN transaction.
	 *
	 * @return string|null One of Attempt_State_Store::STATE_SUCCESS/STATE_FAILURE, or
	 *                      null for anything unrecognised.
	 */
	private function map_itn_status_to_attempt_state( string $payment_status ): ?string {
		if ( Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID === $payment_status ) {
			return Attempt_State_Store::STATE_SUCCESS;
		}

		if ( Blue_Media_Gateway::ITN_FAILURE_STATUS_ID === $payment_status ) {
			return Attempt_State_Store::STATE_FAILURE;
		}

		return null;
	}

	/**
	 * Normalises a decimal amount string to an exact integer number of minor units
	 * (cents), so amounts can be compared with `===` instead of a float tolerance
	 * Returns null for anything not matching a plain
	 * decimal number with at most two fractional digits — callers must treat that as
	 * a mismatch, never as "close enough".
	 *
	 * @param string $raw Decimal amount string (e.g. "19.99", "19,99", "19").
	 */
	private function normalize_amount_to_cents( string $raw ): ?int {
		$raw = str_replace( ',', '.', trim( $raw ) );

		if ( 1 !== preg_match( '/^-?\d+(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}

		$parts       = array_pad( explode( '.', $raw, 2 ), 2, '' );
		$fraction    = str_pad( substr( $parts[1], 0, 2 ), 2, '0' );
		$is_negative = 0 === strpos( $parts[0], '-' );
		$int_part    = ltrim( $is_negative ? substr( $parts[0], 1 ) : $parts[0], '0' );
		$int_part    = '' === $int_part ? '0' : $int_part;
		$cents       = (int) ( $int_part . $fraction );

		return $is_negative ? -$cents : $cents;
	}
}
