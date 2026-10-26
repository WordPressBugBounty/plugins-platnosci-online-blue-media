<?php
/**
 * Handles Autopay RPAN (recurring activation notification) webhooks.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Logger;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Subscription_Store;
use SimpleXMLElement;
use WC_Order;

/**
 * Parses, validates, and confirms a single RPAN `<recurringActivation>` notification.
 *
 * Response schema confirmed against the official specification (Autopay "Specyfikacja
 * integracji Serwisu Partnera z Systemem Płatności", sekcja "Komunikat RPAN", oraz
 * dodatek "Obsługa transakcji" rozdz. 1.5.1.2): `confirmationList > serviceID,
 * recurringConfirmations > recurringConfirmed > clientHash + confirmation, hash` —
 * note there is **no `<reason>` element** in this schema (unlike the `deactivate_recurring`
 * response, which does have one — the two are documented separately, not stated to
 * share a format, and were verified to differ on this exact point).
 *
 * CONFIRMED/NOTCONFIRMED rule, quoted from the same section: "Wartość elementu
 * confirmation wyznaczana jest przez sprawdzenie poprawności wartości parametru
 * serviceID, porównanie wartości pól orderID i amount w komunikacie powiadomienia oraz
 * w komunikacie rozpoczynającym transakcję, a także weryfikację zgodności wyliczonego
 * skrótu (...) z wartością przekazaną w polu hash" — i.e. confirmation is not solely a
 * hash check: `orderID`/`amount` in this RPAN must also match what we ourselves sent
 * when we initiated the activation (`bm_transaction_init_params`, written by
 * Transaction_Request_Builder via the redirect flow), not merely "an order with
 * this ID exists".
 *
 * Request hash field order confirmed via the documented numbered field list ("Opis
 * zwracanych parametrów dla uruchomienia płatności automatycznej") — it matches
 * document order for the fields present. Fields marked "NIE" (optional) may be absent
 * from a given payload (BLIK vs card carry different optional groups); the documented
 * hashing rule ("W przypadku braku opcjonalnego parametru (...) lub pustej wartości,
 * nie należy używać separatora") means an absent or empty-string field must be skipped
 * entirely, not included as an empty value — mirrored here exactly as the existing
 * ITN handler already does (`ITN_Webhook_Handler.php`, `if ( ! empty( $field ) )`).
 */
class RPAN_Webhook_Handler {

	private const ROOT_TAG = 'recurringActivation';

	private const CONFIRMED     = 'CONFIRMED';
	private const NOT_CONFIRMED = 'NOTCONFIRMED';

	/**
	 * The main Autopay gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Storage for card-recurring data on subscriptions.
	 *
	 * @var Recurring_Subscription_Store
	 */
	private Recurring_Subscription_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway Gateway instance (currency/ServiceID context, hashing).
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
		$this->store   = new Recurring_Subscription_Store();
	}

	/**
	 * Handle a parsed `<recurringActivation>` document. Echoes the XML response;
	 * does not exit (the caller — Recurring_Notification_Dispatcher — does that).
	 *
	 * @param SimpleXMLElement $xml Root `<recurringActivation>` element.
	 */
	public function handle( SimpleXMLElement $xml ): void {
		$currency    = (string) ( $xml->transaction->currency ?? '' );
		$client_hash = (string) ( $xml->recurringData->clientHash ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPAN API; cannot be changed.
		$order_id    = (int) (string) ( $xml->transaction->orderID ?? '0' );
		$channel     = (int) ( $xml->transaction->gatewayID ?? 0 );
		$amount      = (string) ( $xml->transaction->amount ?? '' );

		if ( '' === $currency || '' === $client_hash || $order_id <= 0 || $channel <= 0 || '' === $amount ) {
			// Structurally incomplete payload — distinct from a bad signature/content
			// mismatch: we can tell it is malformed before attempting either check.
			blue_media()->get_woocommerce_logger()->log_error(
				'[RPAN_Webhook_Handler] [rejected: missing required field(s) in payload]'
			);
			$this->respond( $client_hash, self::NOT_CONFIRMED );

			return;
		}

		blue_media()->get_currency_manager()->reconfigure( $currency );
		$this->gateway->setup_variables();

		if ( ! $this->is_signature_valid( $xml ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[RPAN_Webhook_Handler] [invalid signature] [client_hash: %s]',
					$this->mask_client_hash( $client_hash )
				)
			);
			$this->respond( $client_hash, self::NOT_CONFIRMED );

			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			// No local record at all to compare orderID/amount against — same
			// convention as ITN's "order not found" handling: confirm so Autopay stops
			// retrying a message we can never resolve, do nothing.
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[RPAN_Webhook_Handler] [order not found] [order_id: %d]', $order_id )
			);
			$this->respond( $client_hash, self::CONFIRMED );

			return;
		}

		if ( ! $this->matches_initiating_transaction( $order, $order_id, $amount ) ) {
			// Documented CONFIRMED/NOTCONFIRMED rule: orderID and amount in the RPAN
			// must match the message that started the transaction (`bm_transaction_init_params`).
			// A real, known order whose content does not add up is exactly what
			// NOTCONFIRMED exists for — distinct from "order not found" above.
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[RPAN_Webhook_Handler] [orderID/amount mismatch vs initiating transaction] [order_id: %d]',
					$order_id
				)
			);
			( new Recurring_Logger() )->warning( 'rpan_rejected', [
				'order_id'    => $order_id,
				'channel'     => $channel,
				'reason'      => 'orderid_amount_mismatch',
				'client_hash' => $client_hash,
			] );
			$this->respond( $client_hash, self::NOT_CONFIRMED );

			return;
		}

		if ( Blue_Media_Gateway::CARD_RECURRING_CHANNEL !== $channel ) {
			// Everything above is protocol-level confirmation (hash + content match) —
			// this and the checks below are OUR OWN business rules layered on top, so
			// they still respond CONFIRMED (the message itself is authentic) while
			// simply not acting on it locally. Etap 1 only handles card recurring (1503).
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[RPAN_Webhook_Handler] [unsupported channel, ignored] [gateway_id: %d]', $channel )
			);
			$this->respond( $client_hash, self::CONFIRMED );

			return;
		}

		$subscription = $this->store->find_subscription_for_order( $order );

		if ( null === $subscription ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[RPAN_Webhook_Handler] [no matching local subscription] [order_id: %d]', $order_id )
			);
			$this->respond( $client_hash, self::CONFIRMED );

			return;
		}

		if ( $subscription->has_status( 'cancelled' ) ) {
			// Do not activate a recurring link for a subscription the merchant/customer
			// already cancelled, regardless of arrival order between cancellation and a
			// (possibly delayed) RPAN for the original activation.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[RPAN_Webhook_Handler] [subscription already cancelled, activation ignored] [subscription_id: %d]',
					$subscription->get_id()
				)
			);
			$this->respond( $client_hash, self::CONFIRMED );

			return;
		}

		$service_id  = (string) $this->gateway->get_service_id();
		$environment = $this->gateway->resolve_is_test_mode() ? 'test' : 'production';

		if ( $this->store->is_client_hash_tombstoned( $client_hash, $service_id, $environment ) ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[RPAN_Webhook_Handler] [ignored: RPDN already deactivated this ClientHash] [subscription_id: %d]',
					$subscription->get_id()
				)
			);
			$this->respond( $client_hash, self::CONFIRMED );

			return;
		}

		$this->store->save_activation( $subscription, $client_hash, $channel, $currency, $service_id, $environment );

		( new Recurring_Logger() )->debug( 'rpan_activation_confirmed', [
			'order_id'        => $order_id,
			'subscription_id' => $subscription->get_id(),
			'channel'         => $channel,
			'currency'        => $currency,
			'service_id'      => $service_id,
			'environment'     => $environment,
			'client_hash'     => $client_hash,
		] );

		$this->respond( $client_hash, self::CONFIRMED );
	}

	/**
	 * Documented CONFIRMED/NOTCONFIRMED rule: `orderID` and `amount` in the RPAN must
	 * match the message that originally started the transaction. `bm_transaction_init_params`
	 * is exactly that message's parameters, written by our own activation request
	 * (Transaction_Request_Builder) — an existing, established key
	 * also relied on by ITN_Webhook_Handler.
	 *
	 * @param WC_Order $order          Order the RPAN claims to reference.
	 * @param int      $order_id_in_rpan Numeric OrderID parsed from the RPAN.
	 * @param string   $amount_in_rpan   Raw amount string from the RPAN.
	 */
	private function matches_initiating_transaction( WC_Order $order, int $order_id_in_rpan, string $amount_in_rpan ): bool {
		$init_params = $order->get_meta( 'bm_transaction_init_params' );

		if ( ! is_array( $init_params ) ) {
			return false;
		}

		$init_order_id = (string) ( $init_params['OrderID'] ?? '' );

		if ( '' === $init_order_id || $init_order_id !== (string) $order_id_in_rpan ) {
			return false;
		}

		$init_amount = (float) ( $init_params['Amount'] ?? -1 );

		if ( abs( $init_amount - (float) $amount_in_rpan ) > 0.01 ) {
			return false;
		}

		$init_currency = (string) ( $init_params['Currency'] ?? '' );

		return '' === $init_currency || $init_currency === $order->get_currency();
	}

	/**
	 * Validate both the hash (all leaf field values in document order, excluding
	 * `<hash>` itself) and that the ServiceID in the payload matches our currently
	 * configured one for the currency resolved above.
	 *
	 * @param SimpleXMLElement $xml Root `<recurringActivation>` element.
	 */
	private function is_signature_valid( SimpleXMLElement $xml ): bool {
		$service_id_in_payload = (string) ( $xml->serviceID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPAN API; cannot be changed.

		if ( '' === $service_id_in_payload || $service_id_in_payload !== (string) $this->gateway->get_service_id() ) {
			return false;
		}

		$hash_from_payload = (string) ( $xml->hash ?? '' );

		if ( '' === $hash_from_payload ) {
			return false;
		}

		$fields        = $this->flatten_leaf_values( $xml );
		$computed_hash = $this->gateway->hash_transaction_parameters( $fields );

		return hash_equals( $computed_hash, $hash_from_payload );
	}

	/**
	 * Flatten every leaf field value under `$element`, in document order, excluding
	 * `<hash>`. RPAN payloads vary by channel (card vs BLIK carry different optional
	 * groups — `cardData` vs `blikData`), so a hand-maintained fixed field list would
	 * either omit fields present for one channel or include fields absent for another.
	 * Walking the document itself avoids hardcoding a list that has to match exactly.
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
				// Documented rule: an absent optional field or an empty value must be
				// skipped entirely (no separator inserted for it), not included as ''.
				// Mirrors the existing convention in ITN_Webhook_Handler::webhook().
				$values[] = (string) $child;
			}
		}

		return $values;
	}

	/**
	 * Build and echo the `confirmationList` XML response. Schema and hash field order
	 * confirmed against the documented RPAN response — see the class docblock. There is
	 * no `<reason>` element in this schema (confirmed absent, not merely unconfirmed).
	 *
	 * @param string $client_hash ClientHash being confirmed (masked in logs, not in
	 *                            the response itself — Autopay needs the real value).
	 * @param string $confirmation self::CONFIRMED or self::NOT_CONFIRMED.
	 */
	private function respond( string $client_hash, string $confirmation ): void {
		$service_id = (string) $this->gateway->get_service_id();

		$response_hash = $this->gateway->hash_transaction_parameters( [ $service_id, $client_hash, $confirmation ] );

		$xw = xmlwriter_open_memory();
		xmlwriter_set_indent( $xw, true );
		// Confirmed by local testing: passing '' for the 4th ($standalone) argument
		// produces a standalone="" attribute, which libxml rejects outright
		// ("standalone accepts only 'yes' or 'no'") -- simplexml_load_string() on our
		// OWN response then returns false. Omitting the argument matches the
		// documented examples exactly (no standalone attribute at all in the XML
		// declaration). NOTE: ITN_Webhook_Handler.php has the identical 4-argument
		// call with an empty string -- not changed here (pre-existing production code)
		// but flagged for the user's attention; it
		// likely produces the same malformed declaration in every ITN response.
		xmlwriter_start_document( $xw, '1.0', 'UTF-8' );

		xmlwriter_start_element( $xw, 'confirmationList' );
		xmlwriter_write_element( $xw, 'serviceID', $service_id );

		xmlwriter_start_element( $xw, 'recurringConfirmations' );
		xmlwriter_start_element( $xw, 'recurringConfirmed' );
		xmlwriter_write_element( $xw, 'clientHash', $client_hash );
		xmlwriter_write_element( $xw, 'confirmation', $confirmation );
		xmlwriter_end_element( $xw ); // recurringConfirmed.
		xmlwriter_end_element( $xw ); // recurringConfirmations.

		xmlwriter_write_element( $xw, 'hash', $response_hash );
		xmlwriter_end_element( $xw ); // confirmationList.
		xmlwriter_end_document( $xw );

		header( 'Content-Type: text/xml; charset=UTF-8' );
		echo xmlwriter_output_memory( $xw ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built exclusively via xmlwriter (auto-escaping), not string concatenation.
	}

	/**
	 * Never write a full ClientHash to logs.
	 *
	 * @param string $client_hash Value to mask.
	 */
	private function mask_client_hash( string $client_hash ): string {
		if ( strlen( $client_hash ) <= 4 ) {
			return '****';
		}

		return '****' . substr( $client_hash, -4 );
	}
}
