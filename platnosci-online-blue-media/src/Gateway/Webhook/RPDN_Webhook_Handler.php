<?php
/**
 * Handles Autopay RPDN (recurring deactivation notification) webhooks.
 *
 * Contract confirmed against the primary source used throughout development —
 * Autopay "Specyfikacja integracji Serwisu Partnera z Systemem Płatności", Dodatek
 * "Obsługa transakcji" (`system_platnosci_online_obsluga_transakcji_dodatek_2.43.01.pdf`),
 * section "1.5.3.2 Powiadomienie o dezaktywacji płatności automatycznej (RPDN)" —
 * a locally-cached, previously-extracted text copy of this exact document (not
 * re-downloaded here) was read directly for this class; nothing about the wire
 * format below is guessed or inferred by analogy.
 *
 * Request: same POST field as RPAN (`recurring`, base64), root element
 * `<recurringDeactivation>`:
 *
 *   <recurringDeactivation>
 *     <serviceID>ServiceID</serviceID>
 *     <recurringData>
 *       <recurringAction>RecurringAction</recurringAction>   (always DEACTIVATE)
 *       <clientHash>ClientHash</clientHash>
 *       <deactivationSource>DeactivationSource</deactivationSource>
 *       <deactivationDate>DeactivationDate</deactivationDate>
 *     </recurringData>
 *     <hash>Hash</hash>
 *   </recurringDeactivation>
 *
 * ALL FIVE fields feeding the hash (serviceID, recurringAction, clientHash,
 * deactivationSource, deactivationDate, in that document order) are documented as
 * required ("Wymagany: TAK") — unlike RPAN, which has genuinely optional groups.
 * `deactivationSource`'s value set is documented as open-ended ("lista jego
 * dozwolonych wartości jest ciągle powiększana i pojawienie się nowych wartości nie
 * może pociągać za sobą brak akceptacji komunikatu RPDN") — an unrecognised value
 * there must NOT cause rejection. `recurringAction`, by contrast, is documented for
 * this message type as always `DEACTIVATE` — an authentically-signed message
 * carrying anything else is a message this handler does not understand and must
 * not act on.
 *
 * Response: `<confirmationList>`, WITHOUT `<reason>` — confirmed identical in shape
 * to RPAN's response (the same document explicitly does NOT repeat `<reason>` here,
 * unlike `deactivate_recurring`'s response, which is a different endpoint/contract
 * entirely and must not be assumed to apply):
 *
 *   <confirmationList>
 *     <serviceID>ServiceID</serviceID>
 *     <recurringConfirmations>
 *       <recurringConfirmed>
 *         <clientHash>ClientHash</clientHash>
 *         <confirmation>Confirmation</confirmation>
 *       </recurringConfirmed>
 *     </recurringConfirmations>
 *     <hash>Hash</hash>
 *   </confirmationList>
 *
 * Confirmation rule, quoted: "Wartość elementu wyznaczana jest przez sprawdzenie
 * poprawności wartości parametrów serviceID oraz clientHash z podanymi w
 * komunikacie RPAN przy rozpoczęciu danej płatności inicjalizacyjnej, a także
 * weryfikację zgodności wyliczonego skrótu z parametrów komunikatu z wartością
 * przekazaną w polu hash." I.e. confirmation depends on hash validity and on
 * serviceID/clientHash matching what we ourselves recorded from the original RPAN
 * — NOT on whether a local subscription is found NOW. For a ClientHash we never
 * saw via any RPAN (never activated locally, or already forgotten), that
 * comparison is simply inapplicable — exactly the same situation RPAN's own
 * "order not found" branch already treats as CONFIRMED (protocol-authentic
 * message, our own business logic has nothing to act on), documented there as
 * "OUR OWN business rules layered on top" of protocol-level confirmation. This
 * class follows the identical, now doc-grounded (not merely analogous) precedent:
 * a correctly-signed RPDN is always CONFIRMED; whether we found anything locally
 * only decides what we DO with it, never the confirmation value.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Fs_Pause_Sync_Retry_Job;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Subscription_Store;
use Ilabs\BM_Woocommerce\Gateway\Transaction_Request_Builder;
use SimpleXMLElement;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Parses, validates, and confirms a single RPDN `<recurringDeactivation>` notification.
 */
class RPDN_Webhook_Handler {

	private const CONFIRMED     = 'CONFIRMED';
	private const NOT_CONFIRMED = 'NOTCONFIRMED';

	/**
	 * The main Autopay gateway instance — used only to reach `get_option()`
	 * (candidate credential lookup, §below) and to build the fallback/NOTCONFIRMED
	 * response when a signing context cannot be resolved at all. Never used for its
	 * OWN currently-configured service_id/private_key (see resolve_signing_context()
	 * for why).
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
	 * @param Blue_Media_Gateway $gateway Gateway instance, passed by
	 *                                     Recurring_Notification_Dispatcher exactly
	 *                                     like RPAN_Webhook_Handler.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
		$this->store   = new Recurring_Subscription_Store();
	}

	/**
	 * Handle a parsed `<recurringDeactivation>` document. Echoes the XML response;
	 * does not exit (the caller — Recurring_Notification_Dispatcher — does that).
	 *
	 * @param SimpleXMLElement $xml Root `<recurringDeactivation>` element.
	 */
	public function handle( SimpleXMLElement $xml ): void {
		$service_id_in_payload = (string) ( $xml->serviceID ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPDN API; cannot be changed.
		$recurring_action      = (string) ( $xml->recurringData->recurringAction ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPDN API; cannot be changed.
		$client_hash           = (string) ( $xml->recurringData->clientHash ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPDN API; cannot be changed.
		$deactivation_source   = (string) ( $xml->recurringData->deactivationSource ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPDN API; cannot be changed.
		$deactivation_date     = (string) ( $xml->recurringData->deactivationDate ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML element names from Autopay RPDN API; cannot be changed.
		$hash_from_payload     = (string) ( $xml->hash ?? '' );

		// Every one of these five fields is documented as required ("TAK") -- unlike
		// RPAN, RPDN has no genuinely optional group. A missing field makes the
		// message unverifiable/malformed; never proceed to signature verification
		// (let alone deactivation) on the strength of a hash alone when the
		// document itself does not carry what the protocol requires.
		if ( '' === $service_id_in_payload || '' === $recurring_action || '' === $client_hash
			|| '' === $deactivation_source || '' === $deactivation_date || '' === $hash_from_payload
		) {
			blue_media()->get_woocommerce_logger()->log_error(
				'[RPDN_Webhook_Handler] [rejected: missing required field(s) in payload]'
			);
			$this->respond_without_context();

			return;
		}

		$fields  = $this->flatten_leaf_values( $xml );
		$context = $this->resolve_signing_context( $service_id_in_payload, $fields, $hash_from_payload );

		if ( null === $context ) {
			// Either no locally-configured currency/environment has a service_id
			// matching this payload, or none of the candidates' keys verify the
			// hash, or more than one distinct, independently-verified credential
			// pair matches (genuinely ambiguous) -- in every case we cannot
			// authenticate this message, so we cannot even sign a confirmationList
			// response with confidence. Never guess a key to sign with.
			blue_media()->get_woocommerce_logger()->log_error(
				'[RPDN_Webhook_Handler] [rejected: no unique verified signing context for this ServiceID]'
			);
			$this->respond_without_context();

			return;
		}

		// From here on the message is authenticated: serviceID is ours, and its
		// signature verifies under a single, unambiguous credential pair.
		if ( Autopay_Payment_Protocol::RECURRING_ACTION_DEACTIVATE !== $recurring_action ) {
			// Authentic, but not a recurringAction this handler understands for this
			// message type. Never deactivate anything just because the signature
			// matches -- respond CONFIRMED (the message itself is authentic; this is
			// our own business-layer decision not to act on it, same convention as
			// RPAN's "unsupported channel" branch), log loudly for investigation.
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[RPDN_Webhook_Handler] [unsupported recurringAction, no local action taken] [value: %s]', esc_html( $recurring_action ) )
			);
			$this->respond( $context, $client_hash, self::CONFIRMED );

			return;
		}

		// Tombstone written BEFORE any subscription is touched (not after the loop
		// below) -- this is what lets try_claim_for_activation()/the CAS takeover in
		// Recurring_Subscription_Store actually intercept a concurrent RPAN for this
		// exact ClientHash as early as possible, and it is itself idempotent/safe to
		// call unconditionally regardless of whether local subscriptions are found.
		$this->store->mark_client_hash_tombstoned( $client_hash, $context['service_id'], $context['environment'] );

		$subscriptions = $this->store->find_subscription_by_client_hash( $client_hash, $context['service_id'], $context['environment'] );

		foreach ( $subscriptions as $subscription ) {
			$this->deactivate_subscription( $subscription, $deactivation_source );
		}

		$this->respond( $context, $client_hash, self::CONFIRMED );
	}

	/**
	 * Apply the full local effect of a valid RPDN to one matched subscription.
	 * Idempotent and log-quiet on a genuine repeat ("unikaj powielania
	 * notatek przy identycznych, już obsłużonych powiadomieniach") — a subscription
	 * that is already inactive with no pending FS sync is left untouched entirely.
	 *
	 * @param Subscription $subscription        Subscription to deactivate.
	 * @param string       $deactivation_source Raw `deactivationSource` from the
	 *                                           payload, stored informationally only.
	 */
	private function deactivate_subscription( Subscription $subscription, string $deactivation_source ): void {
		$already_inactive = null === $this->store->get_recurring_data( $subscription );
		$fs_sync_pending  = $this->store->is_fs_sync_pending( $subscription );

		if ( $already_inactive && ! $fs_sync_pending ) {
			// Fully handled by an earlier delivery of this (or an equivalent)
			// notification -- nothing left to do, and nothing new to note/log.
			return;
		}

		if ( ! $already_inactive ) {
			$this->store->mark_inactive( $subscription, 'rpdn:' . $deactivation_source );
		}

		// A correctly-signed RPDN IS Autopay's own confirmation that this instrument
		// is externally dead -- record that fact regardless of whether our own FS
		// pause() below succeeds; the two are independent (README/spec §7).
		$this->store->mark_external_deactivation_confirmed( $subscription, true );

		if ( $subscription->has_status( 'active' ) ) {
			$paused = blue_media()->get_fs_lifecycle_bridge()->pause( $subscription );

			if ( $paused ) {
				$this->store->clear_fs_sync_pending( $subscription );
			} else {
				$this->store->mark_fs_sync_pending( $subscription );

				if ( ! Fs_Pause_Sync_Retry_Job::schedule_once( $subscription->get_id() ) ) {
					blue_media()->get_woocommerce_logger()->log_error(
						sprintf(
							'[RPDN_Webhook_Handler] [pause sync retry could not be scheduled (slot already used, or Action Scheduler refused it) — manual review required] [subscription_id: %d]',
							$subscription->get_id()
						)
					);
				}
			}
		} elseif ( $fs_sync_pending && ! $subscription->has_status( 'on-hold' ) ) {
			// Status is neither active nor on-hold any more (e.g. cancelled by the
			// customer in the meantime) -- FS has already moved past the state pause()
			// would set, so there is nothing left to sync; clear the stale flag.
			$this->store->clear_fs_sync_pending( $subscription );
		}
	}

	/**
	 * Resolve which of this plugin's configured (currency, environment) credential
	 * pairs the payload was actually signed with, and verify the signature against
	 * it in the same step (signature verification and context resolution
	 * are the same operation here, not two separate steps, because there is no
	 * currency/cart/session to reconfigure the gateway singleton from — RPDN
	 * carries neither).
	 *
	 * Enumerates the 7 fixed supported currencies (`get_supported_currencies()`,
	 * not `get_selected_currencies()` — a merchant may have deselected a currency
	 * from new checkouts without deleting its credentials, and an old subscription
	 * activated under it must remain deactivatable), both environments per currency
	 * (`service_id`/`private_key` and `test_service_id`/`test_private_key` — the
	 * gateway's `testmode` toggle is a single GLOBAL setting, `Blue_Media_Gateway::
	 * resolve_is_test_mode()`, so both pairs can be simultaneously configured and
	 * meaningful regardless of which one is "currently active").
	 *
	 * Configurations that reduce to the SAME (service_id, private_key, environment)
	 * triple under different currency labels are deduplicated first — they are not
	 * competing candidates, just one security context reachable via more than one
	 * currency setting. Each remaining distinct candidate's key is then actually
	 * tried against the hash; only a signature that verifies against EXACTLY ONE
	 * distinct credential pair is accepted. No private key or ClientHash content is
	 * ever logged — only counts.
	 *
	 * @param string $service_id_in_payload `serviceID` from the payload.
	 * @param array  $fields                Flattened, in-document-order leaf values
	 *                                       (excluding `<hash>`), from flatten_leaf_values().
	 * @param string $hash_from_payload     `hash` from the payload.
	 *
	 * @return array{currency: string, environment: string, service_id: string, private_key: string}|null
	 */
	private function resolve_signing_context( string $service_id_in_payload, array $fields, string $hash_from_payload ): ?array {
		$candidates = [];

		foreach ( array_keys( blue_media()->get_currency_manager()->get_supported_currencies() ) as $currency_code ) {
			foreach ( [
				'test'       => true,
				'production' => false,
			] as $environment => $is_test ) {
				$service_id_key  = $is_test ? 'test_service_id' : 'service_id';
				$private_key_key = $is_test ? 'test_private_key' : 'private_key';

				$candidate_service_id  = (string) $this->gateway->get_option( Settings_Manager::get_currency_option_key( $service_id_key, $currency_code ) );
				$candidate_private_key = (string) $this->gateway->get_option( Settings_Manager::get_currency_option_key( $private_key_key, $currency_code ) );

				if ( '' === $candidate_service_id || '' === $candidate_private_key
					|| ! hash_equals( $candidate_service_id, $service_id_in_payload )
				) {
					continue;
				}

				$dedup_key = $environment . '|' . $candidate_service_id . '|' . $candidate_private_key;

				if ( isset( $candidates[ $dedup_key ] ) ) {
					continue; // Same security context reached via another currency label.
				}

				$candidates[ $dedup_key ] = [
					'currency'    => $currency_code,
					'environment' => $environment,
					'service_id'  => $candidate_service_id,
					'private_key' => $candidate_private_key,
				];
			}
		}

		if ( [] === $candidates ) {
			return null;
		}

		$verified = [];

		foreach ( $candidates as $candidate ) {
			$computed = ( new Transaction_Request_Builder( $candidate['service_id'], $candidate['private_key'] ) )->hash( $fields );

			if ( hash_equals( $computed, $hash_from_payload ) ) {
				$verified[] = $candidate;
			}
		}

		if ( 1 !== count( $verified ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[RPDN_Webhook_Handler] [signing context unresolved] [candidates: %d] [verified: %d]',
					count( $candidates ),
					count( $verified )
				)
			);

			return null;
		}

		return $verified[0];
	}

	/**
	 * Flatten every leaf field value under `$element`, in document order, excluding
	 * `<hash>`. Same algorithm as RPAN_Webhook_Handler::flatten_leaf_values() and
	 * Recurring_Api_Client::flatten_leaf_values() — duplicated per this codebase's
	 * established convention (see those classes' docblocks) rather than shared.
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
	 * Build and echo the `confirmationList` response, signed with the resolved,
	 * verified context's own credential pair — never the gateway singleton's
	 * currently-active one, which may be a different (currency, environment) pair
	 * than the one this message was actually signed under.
	 *
	 * @param array{service_id: string, private_key: string} $context      Resolved signing context.
	 * @param string                                         $client_hash  ClientHash being confirmed.
	 * @param string                                         $confirmation self::CONFIRMED or self::NOT_CONFIRMED.
	 */
	private function respond( array $context, string $client_hash, string $confirmation ): void {
		$builder       = new Transaction_Request_Builder( $context['service_id'], $context['private_key'] );
		$response_hash = $builder->hash( [ $context['service_id'], $client_hash, $confirmation ] );

		$this->write_confirmation_list( $context['service_id'], $client_hash, $confirmation, $response_hash );
	}

	/**
	 * Fallback response for the two cases where no verified signing context exists
	 * at all (structurally incomplete payload, or no candidate/ambiguous match) — a
	 * `confirmationList` cannot be signed without a private key, so this mirrors
	 * `Recurring_Notification_Dispatcher::respond_not_confirmed_and_exit()`'s own
	 * safe fallback (plain HTTP 400) rather than fabricating an unsigned response.
	 */
	private function respond_without_context(): void {
		header( 'HTTP/1.0 400 Bad Request' );
	}

	/**
	 * Writes the `confirmationList` XML response body. Same `xmlwriter` construction
	 * (auto-escaping, no string concatenation) and the same `xmlwriter_start_document()`
	 * fix already applied in RPAN_Webhook_Handler::respond() (omitting the 4th
	 * `$standalone` argument — passing `''` produces an invalid `standalone=""`
	 * attribute that libxml rejects on OUR OWN response).
	 *
	 * @param string $service_id    ServiceID to echo.
	 * @param string $client_hash   ClientHash to echo (real value — Autopay needs it).
	 * @param string $confirmation  self::CONFIRMED or self::NOT_CONFIRMED.
	 * @param string $response_hash Hash of the response, computed by the caller.
	 */
	private function write_confirmation_list( string $service_id, string $client_hash, string $confirmation, string $response_hash ): void {
		$xw = xmlwriter_open_memory();
		xmlwriter_set_indent( $xw, true );
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
}
