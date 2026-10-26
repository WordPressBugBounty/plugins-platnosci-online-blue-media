<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Recurring_Api_Client — request/response contract for card-recurring charges
 * (AUTO/MANUAL), transaction-status polling, and recurring-instrument
 * deactivation.
 *
 * Every fact used to build request/response handling below is confirmed
 * against the primary source: Autopay "Specyfikacja integracji Serwisu
 * Partnera z Systemem Płatności", Dodatek "Obsługa transakcji" (PDF,
 * `system_platnosci_online_obsluga_transakcji_dodatek_2.43.01.pdf`), sections
 * 1.5.1.1 ("Komunikat startu transakcji" / RecurringAcceptanceState /
 * RecurringAction, field table #35-37), 1.5.2.1 (AUTO/MANUAL charge
 * parameters), 1.5.3.1 (deactivate_recurring request/response), and 4.10
 * (webapi/transactionStatus request/response) -- NOT assumed by analogy with
 * `webapi/googlePayMerchantInfo` (a different, JSON-based, wallet-specific
 * endpoint). pay-wp (`BlueMediaClient.php`) does not implement either
 * transactionStatus or deactivate_recurring at all, so it could not serve as
 * a pattern for this contract; its `sendApiRequest()`/`do_recurring_transaction_init()`
 * for other, similarly form-based endpoints do confirm the general convention
 * (form-urlencoded request, XML response) used throughout this class.
 *
 * This client is explicitly NOT aware of the charge-attempt model
 * (`Attempt_State_Store`): every method takes the exact Autopay `OrderID` to
 * use from its caller and never allocates or guesses one itself.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use SimpleXMLElement;
use WC_Order;

/**
 * Typed client for the recurring charge / status-check / deactivation
 * operations of the Autopay API.
 */
class Recurring_Api_Client {

	// --- Outcomes shared across all three operations -- never a payment result.
	public const OUTCOME_TRANSPORT_ERROR = 'TRANSPORT_ERROR';

	public const OUTCOME_INVALID_RESPONSE = 'INVALID_RESPONSE';

	public const OUTCOME_CREDENTIALS_UNAVAILABLE = 'CREDENTIALS_UNAVAILABLE';

	// --- check_status() outcomes (Autopay Dodatek, rozdz. 4.10, "Obsługa odpowiedzi").
	public const STATUS_MATCHED_SUCCESS = 'MATCHED_SUCCESS';

	public const STATUS_MATCHED_FAILURE = 'MATCHED_FAILURE';

	public const STATUS_MATCHED_PENDING = 'MATCHED_PENDING';

	public const STATUS_EMPTY_CONFIRMED_NO_TRANSACTION = 'EMPTY_CONFIRMED_NO_TRANSACTION';

	public const STATUS_AMBIGUOUS = 'AMBIGUOUS';

	// --- deactivate() outcomes (Autopay Dodatek, rozdz. 1.5.3.1).
	public const DEACTIVATE_CONFIRMED = 'CONFIRMED';

	public const DEACTIVATE_NOT_CONFIRMED = 'NOTCONFIRMED';

	/**
	 * The send_charge() outcomes below describe whether the CHARGE REQUEST was
	 * accepted, never whether money actually moved. Confirmed explicitly in the
	 * Dodatek, rozdz. "Wynik Przedtransakcji": "CONFIRMED – operacja powiodła
	 * się; Uwaga! Nie oznacza to wykonania obciążenia!" (CONFIRMED means the
	 * operation succeeded; it does NOT mean the charge was executed). The
	 * authoritative result always comes later, via ITN or check_status().
	 */
	public const CHARGE_ACCEPTED = 'CHARGE_ACCEPTED';

	public const CHARGE_NOT_CONFIRMED = 'CHARGE_NOT_CONFIRMED';

	public const CHARGE_CONTINUATION_REQUIRED = 'CHARGE_CONTINUATION_REQUIRED';

	/**
	 * The gateway instance -- source of the currently configured
	 * ServiceID/private_key/gateway_url/environment, and the hash implementation.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway Gateway instance, already configured for
	 *                                     the currency/ServiceID/environment the
	 *                                     caller intends to use (see
	 *                                     {@see Recurring_Background_Context}).
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Build a signed parameter array for an AUTO/MANUAL charge against an
	 * existing recurring instrument. Pure -- performs no network I/O and no
	 * context verification (that happens in {@see send_charge()}), so it stays
	 * usable standalone (e.g. for hash acceptance testing).
	 *
	 * @param WC_Order $renewal_order    Renewal order being charged.
	 * @param string   $order_id         Exact Autopay `OrderID` to use for this
	 *                                    attempt -- built by the CALLER (e.g.
	 *                                    `Attempt_State_Store::build_order_id()`).
	 *                                    This client never allocates or guesses one.
	 * @param int      $gateway_id       Autopay channel id to charge (from the
	 *                                    subscription's recurring-link data, e.g.
	 *                                    `Recurring_Subscription_Store::get_recurring_data()['channel']`)
	 *                                    -- never hardcoded to a specific channel here.
	 * @param string   $client_hash      Recurring instrument identifier.
	 * @param string   $recurring_action `Autopay_Payment_Protocol::RECURRING_ACTION_AUTO`
	 *                                    or `RECURRING_ACTION_MANUAL`.
	 *
	 * @return array Params including Hash.
	 */
	public function build_charge_params( WC_Order $renewal_order, string $order_id, int $gateway_id, string $client_hash, string $recurring_action ): array {
		$params = [
			'ServiceID'                                 => $this->gateway->get_service_id(),
			'OrderID'                                   => $order_id,
			'Amount'                                    => $this->format_amount( $renewal_order ),
			'GatewayID'                                 => $gateway_id,
			'Currency'                                  => $renewal_order->get_currency(),
			'CustomerEmail'                             => $renewal_order->get_billing_email(),
			Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE => Autopay_Payment_Protocol::RECURRING_ACCEPTANCE_STATE_NOT_APPLICABLE,
			Autopay_Payment_Protocol::FIELD_RECURRING_ACTION => $recurring_action,
			Autopay_Payment_Protocol::FIELD_CLIENT_HASH => $client_hash,
		];

		$params = array_merge( $params, [ 'Hash' => $this->gateway->hash_transaction_parameters( $params ) ] );

		( new Recurring_Logger() )->debug( 'charge_params_built', [
			'order_id'                 => $order_id,
			'gateway_id'               => $gateway_id,
			'RecurringAcceptanceState' => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE ],
			'RecurringAction'          => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACTION ],
			'client_hash'              => $client_hash,
			'field_names'              => array_keys( $params ),
			'field_count'              => count( $params ),
		] );

		return $params;
	}

	/**
	 * Send an AUTO/MANUAL charge for an existing recurring instrument through
	 * the same `/payment` continue-transaction endpoint used for card-widget/GPay.
	 *
	 * Refuses (returns `OUTCOME_CREDENTIALS_UNAVAILABLE`) instead of sending
	 * anything when `$context` does not match what the gateway is currently
	 * configured for -- see {@see verify_context()}.
	 *
	 * @param WC_Order $renewal_order    Renewal order being charged.
	 * @param string   $order_id         Exact Autopay `OrderID` for this attempt
	 *                                    (caller-supplied, see {@see build_charge_params()}).
	 * @param int      $gateway_id       Autopay channel id to charge.
	 * @param string   $client_hash      Recurring instrument identifier.
	 * @param string   $recurring_action `RECURRING_ACTION_AUTO`/`RECURRING_ACTION_MANUAL`.
	 * @param array    $context          Expected `currency`/`service_id`/`environment`
	 *                                    for this attempt (e.g. from
	 *                                    `Recurring_Subscription_Store::get_recurring_data()`
	 *                                    or an `Attempt_State_Store` record's `context`).
	 *
	 * @return array{outcome: string, reason: string, remote_id: ?string, redirect_url: ?string, early_payment_status: ?string, raw: string}
	 */
	public function send_charge( WC_Order $renewal_order, string $order_id, int $gateway_id, string $client_hash, string $recurring_action, array $context ): array {
		$mismatch = $this->verify_context( $context );

		if ( null !== $mismatch ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Recurring_Api_Client] [send_charge refused: %s] [order_id: %s]', $mismatch, $order_id )
			);

			return $this->charge_result( self::OUTCOME_CREDENTIALS_UNAVAILABLE, $mismatch );
		}

		$params = $this->build_charge_params( $renewal_order, $order_id, $gateway_id, $client_hash, $recurring_action );
		$raw    = ( new Client() )->continue_transaction_request(
			$params,
			$this->gateway->get_gateway_url() . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH
		);

		if ( 0 === strpos( $raw, Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER ) ) {
			return $this->charge_result( self::OUTCOME_TRANSPORT_ERROR, $raw, $raw );
		}

		$xml = $this->parse_xml( $raw );

		if ( null === $xml || 'transaction' !== $xml->getName() ) {
			return $this->charge_result( self::OUTCOME_INVALID_RESPONSE, 'unparseable_or_wrong_root', $raw );
		}

		if ( ! $this->verify_response_hash( $xml ) ) {
			return $this->charge_result( self::OUTCOME_INVALID_RESPONSE, 'invalid_hash', $raw );
		}

		$order_id_in_response = (string) ( $xml->orderID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		if ( '' !== $order_id_in_response && $order_id_in_response !== $order_id ) {
			// Never attribute a validly-signed response to this attempt unless it
			// actually echoes back the exact OrderID we just sent.
			return $this->charge_result( self::OUTCOME_INVALID_RESPONSE, 'order_id_mismatch', $raw );
		}

		$redirect_url = (string) ( $xml->redirecturl ?? '' );
		$remote_id    = '' !== (string) ( $xml->remoteID ?? '' ) ? (string) $xml->remoteID : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		if ( '' !== $redirect_url ) {
			// "Odpowiedź – link do kontynuacji" shape (status=PENDING+redirecturl) --
			// e.g. MANUAL charge requiring 3DS/CVC. The caller decides what to do
			// with the redirect; this client only reports it, verified.
			return $this->charge_result( self::CHARGE_CONTINUATION_REQUIRED, '', $raw, $remote_id, $redirect_url );
		}

		$confirmation = (string) ( $xml->confirmation ?? '' );
		$early_status = '' !== (string) ( $xml->paymentStatus ?? '' ) ? (string) $xml->paymentStatus : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		( new Recurring_Logger() )->debug( 'charge_result', [
			'order_id'             => $order_id,
			'gateway_id'           => $gateway_id,
			'recurring_action'     => $recurring_action,
			'confirmation'         => $confirmation,
			'remote_id'            => $remote_id,
			'early_payment_status' => $early_status,
		] );

		if ( 'CONFIRMED' === $confirmation ) {
			return $this->charge_result( self::CHARGE_ACCEPTED, '', $raw, $remote_id, null, $early_status );
		}

		if ( 'NOTCONFIRMED' === $confirmation ) {
			return $this->charge_result( self::CHARGE_NOT_CONFIRMED, (string) ( $xml->reason ?? '' ), $raw );
		}

		return $this->charge_result( self::OUTCOME_INVALID_RESPONSE, 'unrecognised_confirmation_value', $raw );
	}

	/**
	 * Query the status of the transaction(s) recorded under `$order_id`.
	 *
	 * Correlation is by the exact `OrderID` the caller supplies -- never by
	 * `remoteID`/`paymentDate` heuristics. Since every attempt already uses a
	 * unique per-attempt `OrderID` (caller's responsibility, e.g.
	 * `Attempt_State_Store::build_order_id()`), every transaction Autopay
	 * returns under that exact OrderID unambiguously belongs to this attempt;
	 * no "adopt on first use"/timestamp-based matching is needed or performed.
	 *
	 * Refuses (`CREDENTIALS_UNAVAILABLE`) instead of querying when `$context`
	 * does not match the gateway's currently configured
	 * currency/ServiceID/environment -- this is what protects a stale/expired
	 * attempt from ever being queried with the WRONG (currently active, not the
	 * attempt's original) credentials ("never use the CURRENT ServiceID to check
	 * an OLD attempt").
	 *
	 * @param string $order_id Exact Autopay `OrderID` for the attempt being checked.
	 * @param array  $context  Expected `currency`/`service_id`/`environment` --
	 *                          the SNAPSHOT taken when the attempt started (e.g.
	 *                          an `Attempt_State_Store` record's `context`), not
	 *                          whatever is configured "now".
	 *
	 * @return array{outcome: string, transaction: ?array, reason: string}
	 */
	public function check_status( string $order_id, array $context ): array {
		$mismatch = $this->verify_context( $context );

		if ( null !== $mismatch ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Recurring_Api_Client] [check_status refused: %s] [order_id: %s]', $mismatch, $order_id )
			);

			return [
				'outcome'     => self::OUTCOME_CREDENTIALS_UNAVAILABLE,
				'transaction' => null,
				'reason'      => $mismatch,
			];
		}

		$params = [
			'ServiceID' => $this->gateway->get_service_id(),
			'OrderID'   => $order_id,
		];

		$params['Hash'] = $this->gateway->hash_transaction_parameters( $params );

		$raw = ( new Client() )->form_post(
			$params,
			$this->gateway->get_gateway_url() . Autopay_Payment_Protocol::HTTP_PATH_TRANSACTION_STATUS,
			Autopay_Payment_Protocol::HTTP_HEADER_BM_WEBAPI
		);

		if ( 0 === strpos( $raw, Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER ) ) {
			return [
				'outcome'     => self::OUTCOME_TRANSPORT_ERROR,
				'transaction' => null,
				'reason'      => $raw,
			];
		}

		$xml = $this->parse_xml( $raw );

		if ( null === $xml || 'transactionList' !== $xml->getName() ) {
			return [
				'outcome'     => self::OUTCOME_INVALID_RESPONSE,
				'transaction' => null,
				'reason'      => 'unparseable_or_wrong_root',
			];
		}

		if ( ! $this->verify_response_hash( $xml ) ) {
			return [
				'outcome'     => self::OUTCOME_INVALID_RESPONSE,
				'transaction' => null,
				'reason'      => 'invalid_hash',
			];
		}

		$service_id_in_response = (string) ( $xml->serviceID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		if ( $service_id_in_response !== $this->gateway->get_service_id() ) {
			return [
				'outcome'     => self::OUTCOME_INVALID_RESPONSE,
				'transaction' => null,
				'reason'      => 'service_id_mismatch',
			];
		}

		$transactions = [];

		if ( isset( $xml->transactions->transaction ) ) {
			foreach ( $xml->transactions->transaction as $node ) {
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
				/* @var SimpleXMLElement $node */
				$node_order_id = (string) ( $node->orderID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

				if ( $node_order_id !== $order_id ) {
					// Defensive only: transactionStatus is queried BY this exact
					// OrderID, so every returned transaction should already carry it.
					continue;
				}

				$transactions[] = [
					'order_id'               => $node_order_id,
					'remote_id'              => (string) ( $node->remoteID ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
					'amount'                 => (string) ( $node->amount ?? '' ),
					'currency'               => (string) ( $node->currency ?? '' ),
					'gateway_id'             => (string) ( $node->gatewayID ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
					'payment_date'           => (string) ( $node->paymentDate ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
					'payment_status'         => (string) ( $node->paymentStatus ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
					'payment_status_details' => (string) ( $node->paymentStatusDetails ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
				];
			}
		}

		return $this->classify_transactions( $transactions );
	}

	/**
	 * Deactivate a recurring instrument via the dedicated `deactivate_recurring`
	 * endpoint (Autopay Dodatek, rozdz. 1.5.3.1) -- distinct from, and NOT the
	 * same contract as, RPAN/RPDN or the `/payment` continue-transaction endpoint.
	 *
	 * @param string $client_hash Recurring instrument identifier to deactivate.
	 * @param array  $context     Expected `currency`/`service_id`/`environment`
	 *                             for this instrument (e.g.
	 *                             `Recurring_Subscription_Store::get_recurring_data()`).
	 *
	 * @return array{outcome: string, raw: string, reason: string}
	 */
	public function deactivate( string $client_hash, array $context ): array {
		$mismatch = $this->verify_context( $context );

		if ( null !== $mismatch ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Recurring_Api_Client] [deactivate refused: %s]', $mismatch )
			);

			return [
				'outcome' => self::OUTCOME_CREDENTIALS_UNAVAILABLE,
				'raw'     => '',
				'reason'  => $mismatch,
			];
		}

		$message_id = substr( bin2hex( random_bytes( 32 ) ), 32 );

		$params = [
			'ServiceID'                                 => $this->gateway->get_service_id(),
			Autopay_Payment_Protocol::FIELD_MESSAGE_ID  => $message_id,
			Autopay_Payment_Protocol::FIELD_CLIENT_HASH => $client_hash,
		];

		$params['Hash'] = $this->gateway->hash_transaction_parameters( $params );

		// No BmHeader documented for this endpoint (see
		// Autopay_Payment_Protocol::HTTP_PATH_DEACTIVATE_RECURRING docblock) --
		// sent without one, unlike /payment and webapi/transactionStatus.
		$raw = ( new Client() )->form_post(
			$params,
			$this->gateway->get_gateway_url() . Autopay_Payment_Protocol::HTTP_PATH_DEACTIVATE_RECURRING,
			null
		);

		if ( 0 === strpos( $raw, Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER ) ) {
			return [
				'outcome' => self::OUTCOME_TRANSPORT_ERROR,
				'raw'     => $raw,
				'reason'  => '',
			];
		}

		$xml = $this->parse_xml( $raw );

		if ( null === $xml || 'confirmationList' !== $xml->getName() ) {
			return [
				'outcome' => self::OUTCOME_INVALID_RESPONSE,
				'raw'     => $raw,
				'reason'  => 'unparseable_or_wrong_root',
			];
		}

		if ( ! $this->verify_response_hash( $xml ) ) {
			return [
				'outcome' => self::OUTCOME_INVALID_RESPONSE,
				'raw'     => $raw,
				'reason'  => 'invalid_hash',
			];
		}

		$service_id_in_response  = (string) ( $xml->serviceID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
		$message_id_in_response  = (string) ( $xml->messageID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
		$client_hash_in_response = (string) ( $xml->recurringConfirmations->recurringConfirmed->clientHash ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		if ( $service_id_in_response !== $this->gateway->get_service_id()
			|| $message_id_in_response !== $message_id
			|| $client_hash_in_response !== $client_hash
		) {
			return [
				'outcome' => self::OUTCOME_INVALID_RESPONSE,
				'raw'     => $raw,
				'reason'  => 'identifier_mismatch',
			];
		}

		$confirmation = (string) ( $xml->recurringConfirmations->recurringConfirmed->confirmation ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.

		( new Recurring_Logger() )->debug( 'deactivate_result', [
			'client_hash'  => $client_hash,
			'confirmation' => $confirmation,
		] );

		if ( self::DEACTIVATE_CONFIRMED === $confirmation ) {
			return [
				'outcome' => self::DEACTIVATE_CONFIRMED,
				'raw'     => $raw,
				'reason'  => '',
			];
		}

		if ( self::DEACTIVATE_NOT_CONFIRMED === $confirmation ) {
			return [
				'outcome' => self::DEACTIVATE_NOT_CONFIRMED,
				'raw'     => $raw,
				'reason'  => (string) ( $xml->recurringConfirmations->recurringConfirmed->reason ?? '' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element name from Autopay API; cannot be changed.
			];
		}

		return [
			'outcome' => self::OUTCOME_INVALID_RESPONSE,
			'raw'     => $raw,
			'reason'  => 'unrecognised_confirmation_value',
		];
	}

	/**
	 * Shared context guard for charge/status/deactivate: refuses to proceed
	 * unless the gateway's CURRENTLY configured currency/ServiceID/environment
	 * match what the caller expects for this operation. Protects against both
	 * "caller forgot to call `Recurring_Background_Context::setup_for_currency()`"
	 * and "configuration changed since an attempt/instrument was created" --
	 * this client never simply trusts that the gateway singleton is already set
	 * up correctly.
	 *
	 * @param array $context Expected `currency`/`service_id`/`environment`.
	 *
	 * @return string|null Null when the context matches; a short machine-readable
	 *                      reason string otherwise.
	 */
	private function verify_context( array $context ): ?string {
		$currency    = (string) ( $context['currency'] ?? '' );
		$service_id  = (string) ( $context['service_id'] ?? '' );
		$environment = (string) ( $context['environment'] ?? '' );

		if ( '' === $currency || '' === $service_id || '' === $environment ) {
			return 'incomplete_context';
		}

		if ( blue_media()->resolve_blue_media_currency_symbol() !== $currency ) {
			return 'currency_mismatch';
		}

		if ( $this->gateway->get_service_id() !== $service_id ) {
			return 'service_id_mismatch';
		}

		$actual_environment = $this->gateway->resolve_is_test_mode() ? 'test' : 'production';

		if ( $actual_environment !== $environment ) {
			return 'environment_mismatch';
		}

		return null;
	}

	/**
	 * Classify the transaction(s) returned for one OrderID into a single,
	 * unambiguous outcome, following the interpretation table documented in
	 * Autopay Dodatek, rozdz. 4.10 ("Obsługa odpowiedzi odpytania o status
	 * transakcji").
	 *
	 * @param array $transactions Parsed, signature-verified transaction records.
	 *
	 * @return array{outcome: string, transaction: ?array, reason: string}
	 */
	private function classify_transactions( array $transactions ): array {
		if ( [] === $transactions ) {
			return [
				'outcome'     => self::STATUS_EMPTY_CONFIRMED_NO_TRANSACTION,
				'transaction' => null,
				'reason'      => '',
			];
		}

		$success = [];
		$pending = [];
		$failure = [];

		foreach ( $transactions as $transaction ) {
			switch ( $transaction['payment_status'] ) {
				case 'SUCCESS':
					$success[] = $transaction;
					break;
				case 'PENDING':
					$pending[] = $transaction;
					break;
				case 'FAILURE':
					$failure[] = $transaction;
					break;
			}
		}

		if ( count( $success ) > 1 ) {
			return [
				'outcome'     => self::STATUS_AMBIGUOUS,
				'transaction' => null,
				'reason'      => 'multiple_success_transactions',
			];
		}

		if ( 1 === count( $success ) ) {
			return [
				'outcome'     => self::STATUS_MATCHED_SUCCESS,
				'transaction' => $success[0],
				'reason'      => '',
			];
		}

		if ( [] !== $pending ) {
			return [
				'outcome'     => self::STATUS_MATCHED_PENDING,
				'transaction' => $pending[0],
				'reason'      => '',
			];
		}

		if ( [] !== $failure ) {
			return [
				'outcome'     => self::STATUS_MATCHED_FAILURE,
				'transaction' => $failure[0],
				'reason'      => '',
			];
		}

		// Every transaction carried an unrecognised paymentStatus value (the
		// documented enum is PENDING/SUCCESS/FAILURE only) -- cannot classify
		// confidently, so this is a response problem, not a payment result.
		return [
			'outcome'     => self::OUTCOME_INVALID_RESPONSE,
			'transaction' => null,
			'reason'      => 'unrecognised_payment_status',
		];
	}

	/**
	 * Build a send_charge() result array.
	 *
	 * @param string      $outcome              One of the CHARGE_ or OUTCOME_ constants.
	 * @param string      $reason               Short machine-readable reason (empty when not applicable).
	 * @param string      $raw                  Raw response body (empty when refused before sending).
	 * @param string|null $remote_id            Autopay `remoteID`, when present.
	 * @param string|null $redirect_url         Continuation URL, when present.
	 * @param string|null $early_payment_status Early `paymentStatus`, informational
	 *                                           only -- never authoritative (see class docblock).
	 */
	private function charge_result( string $outcome, string $reason, string $raw = '', ?string $remote_id = null, ?string $redirect_url = null, ?string $early_payment_status = null ): array {
		return [
			'outcome'              => $outcome,
			'reason'               => $reason,
			'remote_id'            => $remote_id,
			'redirect_url'         => $redirect_url,
			'early_payment_status' => $early_payment_status,
			'raw'                  => $raw,
		];
	}

	/**
	 * Parse a raw response body into XML, tolerating a leading UTF-8 BOM.
	 * Mirrors the XXE-safe parsing convention already used for inbound RPAN/RPDN
	 * payloads (`LIBXML_NONET`, no `LIBXML_NOENT`/`LIBXML_DTDLOAD`).
	 *
	 * @param string $raw Raw response body.
	 */
	private function parse_xml( string $raw ): ?SimpleXMLElement {
		$trimmed = trim( $raw );
		$bom     = Autopay_Payment_Protocol::BINARY_UTF8_BOM;

		if ( 0 === strncmp( $trimmed, $bom, strlen( $bom ) ) ) {
			$trimmed = substr( $trimmed, strlen( $bom ) );
		}

		if ( '' === $trimmed ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $trimmed, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		return false === $xml ? null : $xml;
	}

	/**
	 * Verify a response document's `<hash>` element against every other leaf
	 * value in the document, in document order -- the same generic rule already
	 * applied to inbound RPAN payloads
	 * (`RPAN_Webhook_Handler::flatten_leaf_values()`, not reused directly here
	 * to avoid coupling this client to already-verified webhook code, but
	 * identical in behaviour): an absent/empty optional field contributes
	 * nothing (no separator), matching Autopay's documented "brak opcjonalnego
	 * parametru... nie należy używać separatora" hashing rule, so walking the
	 * document as received is equivalent to following each endpoint's own
	 * numbered field list without hardcoding it.
	 *
	 * @param SimpleXMLElement $xml Parsed response document (root element).
	 */
	private function verify_response_hash( SimpleXMLElement $xml ): bool {
		$hash_nodes = $xml->xpath( './/*[local-name()="hash"]' );

		if ( ! is_array( $hash_nodes ) || ! isset( $hash_nodes[0] ) ) {
			return false;
		}

		$hash_from_response = trim( (string) $hash_nodes[0] );

		if ( '' === $hash_from_response ) {
			return false;
		}

		$fields        = $this->flatten_leaf_values( $xml );
		$computed_hash = $this->gateway->hash_transaction_parameters( $fields );

		return hash_equals( $computed_hash, $hash_from_response );
	}

	/**
	 * Flatten every leaf field value under `$element`, in document order,
	 * excluding `<hash>`.
	 *
	 * @param SimpleXMLElement $element Element to flatten (called with the root).
	 *
	 * @return array<int, string>
	 */
	private function flatten_leaf_values( SimpleXMLElement $element ): array {
		$values = [];

		foreach ( $element->children() as $child ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
			/* @var SimpleXMLElement $child */
			if ( 'hash' === $child->getName() ) {
				continue;
			}

			if ( $child->count() > 0 ) {
				$values = array_merge( $values, $this->flatten_leaf_values( $child ) );
			} elseif ( ! empty( $child ) ) {
				$values[] = (string) $child;
			}
		}

		return $values;
	}

	/**
	 * Format a WC order total as the decimal string Autopay expects.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	private function format_amount( WC_Order $order ): string {
		$price = str_replace( ',', '.', (string) $order->get_total( false ) );

		if ( false === strpos( $price, '.' ) ) {
			$price .= '.00';
		}

		return $price;
	}
}
