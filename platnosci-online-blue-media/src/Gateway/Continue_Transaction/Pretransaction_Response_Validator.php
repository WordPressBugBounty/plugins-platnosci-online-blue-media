<?php
/**
 * Pretransaction_Response_Validator — verifies a Przedtransakcja
 * (server-to-server continue-transaction) response against the documented
 * contract for that specific response type.
 *
 * @package Ilabs\BM_Woocommerce\Gateway\Continue_Transaction
 */

namespace Ilabs\BM_Woocommerce\Gateway\Continue_Transaction;

defined( 'ABSPATH' ) || exit;

use SimpleXMLElement;

/**
 * Validates a Przedtransakcja response against Autopay's documented
 * contract (Specyfikacja integracji Serwisu Partnera z Systemem Płatności).
 * Two distinct, separately-numbered shapes share the same `<transaction>`
 * root, and this class treats them as mutually exclusive, closed shapes --
 * not one generic "flatten whatever is in the document" walk:
 *
 * - "Odpowiedź na Przedtransakcję – link do kontynuacji transakcji"
 *   ("Atrybuty obiektu transaction dla Przedtransakcji"): exactly 5 direct
 *   children of `<transaction>` -- `status`, `redirecturl`, `orderID`,
 *   `remoteID`, `hash` -- in that Hash order (1-4, confirmed from the
 *   documentation's own numbered table, not merely inferred from one
 *   example's element order). `status` is documented as the constant
 *   `PENDING`.
 * - "Wynik Przedtransakcji": `orderID`, `remoteID`, `confirmation`
 *   (`CONFIRMED`/`NOTCONFIRMED`), optional `reason`, optional `blikAMList`
 *   (nested `blikAM` entries, each `blikAMKey`+`blikAMLabel`), optional
 *   `paymentStatus`, `hash` -- in that Hash order (1-6, from its own
 *   separately numbered table), skipping any absent optional field entirely
 *   per Autopay's documented "brak opcjonalnego parametru -> nie używać
 *   separatora" rule. `confirmation=CONFIRMED` here does NOT imply a
 *   redirect -- it is documented as valid for channels/actions where no
 *   further customer action is needed (the docs name "płatność cykliczna"
 *   explicitly), so it is reported as its own outcome
 *   ({@see Pretransaction_Response_Result::CONFIRMED_WITHOUT_REDIRECT}),
 *   never folded into REJECTED.
 *
 * A response carrying fields from BOTH shapes (e.g. both `redirecturl` and
 * `confirmation`) is contradictory per the documented contract and is
 * rejected outright as {@see Pretransaction_Response_Result::UNEXPECTED_SHAPE},
 * never resolved by preferring one shape over the other. Any field that
 * feeds the signature or the outcome decision is required to appear at most
 * once as a direct child -- a duplicate is treated the same way (Autopay's
 * own contract never documents more than one occurrence of any of these
 * fields, so accepting a duplicate would mean silently picking one value
 * from an ambiguous document).
 *
 * These fixtures are hand-built directly from Autopay's documentation, not
 * from a captured real response -- passing this validator's own tests
 * confirms internal consistency with what is documented, not that it has
 * been exercised against Autopay's actual, live output.
 *
 * Deliberately free of WordPress/HTTP/Blue_Media_Gateway dependencies so it
 * can be exercised by a standalone script with no WordPress bootstrap and no
 * network access -- see `bin/test-pretransaction-response-validator.php`.
 */
final class Pretransaction_Response_Validator {

	/**
	 * Autopay shared key for the currently configured ServiceID/currency/environment.
	 *
	 * @var string
	 */
	private $private_key;

	/**
	 * Build the validator for one specific credential context.
	 *
	 * @param string $private_key Autopay shared key for the currently
	 *                             configured ServiceID/currency/environment.
	 */
	public function __construct( string $private_key ) {
		$this->private_key = $private_key;
	}

	/**
	 * Validate a raw Przedtransakcja response body.
	 *
	 * @param string $raw_xml           Raw response body.
	 * @param string $expected_order_id The `OrderID` this request sent --
	 *                                  the response's own `orderID` must
	 *                                  match it before anything else in the
	 *                                  response (including a `redirecturl`)
	 *                                  is trusted.
	 *
	 * @return Pretransaction_Response_Result
	 */
	public function validate( string $raw_xml, string $expected_order_id ): Pretransaction_Response_Result {
		$xml = $this->parse_xml( $raw_xml );

		if ( null === $xml ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::MALFORMED_XML );
		}

		// Well-formed XML with the wrong root is not "unparseable" -- it is a
		// document that structurally cannot be either documented shape.
		if ( 'transaction' !== $xml->getName() ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		$has_redirect_field     = $this->direct_child_count( $xml, 'redirecturl' ) > 0;
		$has_confirmation_field = $this->direct_child_count( $xml, 'confirmation' ) > 0;

		// Contradictory per the documented contract -- these two shapes are
		// mutually exclusive, never both present in one authentic response.
		if ( $has_redirect_field && $has_confirmation_field ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		if ( $has_redirect_field ) {
			return $this->validate_redirect_shape( $xml, $expected_order_id );
		}

		if ( $has_confirmation_field ) {
			return $this->validate_result_shape( $xml, $expected_order_id );
		}

		return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
	}

	/**
	 * Validate the "link do kontynuacji transakcji" shape: exactly `status`,
	 * `redirecturl`, `orderID`, `remoteID`, `hash` as direct children of the
	 * root, each exactly once, no other direct children.
	 *
	 * @param SimpleXMLElement $xml                Parsed `<transaction>` root.
	 * @param string           $expected_order_id  OrderID this request sent.
	 *
	 * @return Pretransaction_Response_Result
	 */
	private function validate_redirect_shape( SimpleXMLElement $xml, string $expected_order_id ): Pretransaction_Response_Result {
		$required = [ 'status', 'redirecturl', 'orderID', 'remoteID', 'hash' ];

		foreach ( $required as $field ) {
			if ( 1 !== $this->direct_child_count( $xml, $field ) ) {
				return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
			}
		}

		// "Complete shape" also means closed -- no direct child beyond the 5
		// documented ones, so this cannot be satisfied by, say, 5 required
		// fields plus one unexpected extra one slipped in.
		if ( 5 !== count( $xml->children() ) ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		// Raw text content -- never trimmed. A signed value is hashed exactly
		// as transmitted; trimming it before comparing/recomputing the hash
		// would silently change what is being verified.
		$status             = $this->direct_child_value( $xml, 'status' );
		$redirect_url       = $this->direct_child_value( $xml, 'redirecturl' );
		$response_order_id  = $this->direct_child_value( $xml, 'orderID' );
		$remote_id          = $this->direct_child_value( $xml, 'remoteID' );
		$hash_from_response = $this->direct_child_value( $xml, 'hash' );

		if ( '' === $status || '' === $redirect_url || '' === $response_order_id
			|| '' === $remote_id || '' === $hash_from_response
		) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		// Documented Hash order for THIS shape specifically (1-4): status,
		// redirecturl, orderID, remoteID.
		$computed_hash = hash(
			'sha256',
			implode( '|', [ $status, $redirect_url, $response_order_id, $remote_id ] ) . '|' . $this->private_key
		);

		if ( ! hash_equals( $computed_hash, $hash_from_response ) ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::INVALID_SIGNATURE );
		}

		if ( $response_order_id !== $expected_order_id ) {
			return new Pretransaction_Response_Result(
				Pretransaction_Response_Result::ORDER_ID_MISMATCH,
				null,
				$status,
				null,
				null,
				$response_order_id
			);
		}

		// Documented as a constant -- anything else is an anomaly in
		// Autopay's own output for this shape, not a value this class
		// interprets as a decision point.
		if ( 'PENDING' !== $status ) {
			return new Pretransaction_Response_Result(
				Pretransaction_Response_Result::UNEXPECTED_SHAPE,
				null,
				$status,
				null,
				null,
				$response_order_id
			);
		}

		return new Pretransaction_Response_Result(
			Pretransaction_Response_Result::VALID_REDIRECT,
			$redirect_url,
			$status,
			null,
			null,
			$response_order_id
		);
	}

	/**
	 * Validate the "Wynik Przedtransakcji" shape: `confirmation` required;
	 * `orderID`, `remoteID`, `reason`, `blikAMList`, `paymentStatus`, `hash`
	 * each optional but never duplicated.
	 *
	 * @param SimpleXMLElement $xml                Parsed `<transaction>` root.
	 * @param string           $expected_order_id  OrderID this request sent.
	 *
	 * @return Pretransaction_Response_Result
	 */
	private function validate_result_shape( SimpleXMLElement $xml, string $expected_order_id ): Pretransaction_Response_Result {
		if ( 1 !== $this->direct_child_count( $xml, 'confirmation' ) ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		foreach ( [ 'orderID', 'remoteID', 'reason', 'blikAMList', 'paymentStatus', 'hash' ] as $field ) {
			if ( $this->direct_child_count( $xml, $field ) > 1 ) {
				return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
			}
		}

		$confirmation = $this->direct_child_value( $xml, 'confirmation' );
		if ( '' === $confirmation ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::UNEXPECTED_SHAPE );
		}

		$response_order_id  = $this->direct_child_count( $xml, 'orderID' ) > 0 ? $this->direct_child_value( $xml, 'orderID' ) : null;
		$remote_id          = $this->direct_child_count( $xml, 'remoteID' ) > 0 ? $this->direct_child_value( $xml, 'remoteID' ) : null;
		$reason             = $this->direct_child_count( $xml, 'reason' ) > 0 ? $this->direct_child_value( $xml, 'reason' ) : null;
		$payment_status     = $this->direct_child_count( $xml, 'paymentStatus' ) > 0 ? $this->direct_child_value( $xml, 'paymentStatus' ) : null;
		$hash_from_response = $this->direct_child_value( $xml, 'hash' );

		if ( '' === $hash_from_response ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::MISSING_HASH );
		}

		// Documented Hash order for THIS shape specifically (1-6): orderID,
		// remoteID, confirmation, reason, blikAMList, paymentStatus -- each
		// absent optional field skipped entirely, per Autopay's documented
		// "brak opcjonalnego parametru -> nie używać separatora" rule. This
		// is a SEPARATE, independently-numbered contract from the redirect
		// shape above -- not the same order reused.
		$ordered_values = [];

		if ( null !== $response_order_id && '' !== $response_order_id ) {
			$ordered_values[] = $response_order_id;
		}
		if ( null !== $remote_id && '' !== $remote_id ) {
			$ordered_values[] = $remote_id;
		}
		$ordered_values[] = $confirmation;
		if ( null !== $reason && '' !== $reason ) {
			$ordered_values[] = $reason;
		}
		$blik_am_list = $this->direct_child_element( $xml, 'blikAMList' );
		if ( null !== $blik_am_list ) {
			$ordered_values = array_merge( $ordered_values, $this->flatten_blik_am_list( $blik_am_list ) );
		}
		if ( null !== $payment_status && '' !== $payment_status ) {
			$ordered_values[] = $payment_status;
		}

		$computed_hash = hash( 'sha256', implode( '|', $ordered_values ) . '|' . $this->private_key );

		if ( ! hash_equals( $computed_hash, $hash_from_response ) ) {
			return new Pretransaction_Response_Result( Pretransaction_Response_Result::INVALID_SIGNATURE );
		}

		if ( null !== $response_order_id && $response_order_id !== $expected_order_id ) {
			return new Pretransaction_Response_Result(
				Pretransaction_Response_Result::ORDER_ID_MISMATCH,
				null,
				null,
				$confirmation,
				$reason,
				$response_order_id
			);
		}

		$normalized_confirmation = strtoupper( $confirmation );

		if ( 'NOTCONFIRMED' === $normalized_confirmation ) {
			return new Pretransaction_Response_Result(
				Pretransaction_Response_Result::REJECTED,
				null,
				null,
				$confirmation,
				$reason,
				$response_order_id
			);
		}

		if ( 'CONFIRMED' === $normalized_confirmation ) {
			// NOT a rejection -- documented as valid for e.g. cyclic/recurring
			// charges where no further customer action is needed. Callers must
			// not redirect on this outcome (no redirecturl exists in this
			// shape at all), but must also not report it as "rejected".
			return new Pretransaction_Response_Result(
				Pretransaction_Response_Result::CONFIRMED_WITHOUT_REDIRECT,
				null,
				null,
				$confirmation,
				$reason,
				$response_order_id
			);
		}

		return new Pretransaction_Response_Result(
			Pretransaction_Response_Result::UNEXPECTED_SHAPE,
			null,
			null,
			$confirmation,
			$reason,
			$response_order_id
		);
	}

	/**
	 * Flatten a `<blikAMList>` element's `<blikAM>` entries, in document
	 * order, each contributing `blikAMKey` then `blikAMLabel`. 1503 (card)
	 * never sends this -- it is BLIK-0-OneClick-specific -- but the "Wynik
	 * Przedtransakcji" contract documents it as position 5, so a response
	 * carrying it must still hash correctly rather than silently corrupt the
	 * result for a field this channel happens not to use.
	 *
	 * @param SimpleXMLElement $blik_am_list The `<blikAMList>` element.
	 *
	 * @return array<int, string>
	 */
	private function flatten_blik_am_list( SimpleXMLElement $blik_am_list ): array {
		$values = [];

		foreach ( $blik_am_list->children() as $blik_am ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
			/* @var SimpleXMLElement $blik_am */
			foreach ( [ 'blikAMKey', 'blikAMLabel' ] as $field ) {
				if ( 1 !== $this->direct_child_count( $blik_am, $field ) ) {
					continue;
				}

				$value = $this->direct_child_value( $blik_am, $field );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Parse a raw XML body, returning null on any parse failure. Restores
	 * whatever `libxml_use_internal_errors()` state the caller already had,
	 * rather than unconditionally leaving it disabled -- this class must not
	 * change how the rest of the process handles libxml errors.
	 *
	 * @param string $raw_xml Raw response body.
	 *
	 * @return SimpleXMLElement|null
	 */
	private function parse_xml( string $raw_xml ): ?SimpleXMLElement {
		$previous_setting = libxml_use_internal_errors( true );
		$xml              = simplexml_load_string( trim( $raw_xml ), 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		return false === $xml ? null : $xml;
	}

	/**
	 * Count direct children of `$element` whose tag name is exactly `$name`
	 * (case-sensitive, structural match -- not a document-wide search by
	 * local name).
	 *
	 * @param SimpleXMLElement $element Element whose direct children are checked.
	 * @param string           $name    Exact, case-sensitive tag name.
	 *
	 * @return int
	 */
	private function direct_child_count( SimpleXMLElement $element, string $name ): int {
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $name ) ) {
			return 0;
		}

		$matches = $element->xpath( './' . $name );

		return is_array( $matches ) ? count( $matches ) : 0;
	}

	/**
	 * Raw text content of the first direct child of `$element` whose tag
	 * name is exactly `$name`, or `''` when absent. Never trims -- see the
	 * "never trimmed" note on the signed-value reads above.
	 *
	 * Reads via `xpath()` rather than SimpleXML magic property access
	 * (`$element->{$name}`) on purpose -- Autopay's exact, documented
	 * camelCase tag names (`orderID`, `blikAMList`, ...) are not something
	 * this class renames to satisfy a PHP-property-naming convention.
	 *
	 * @param SimpleXMLElement $element Element whose direct children are read.
	 * @param string           $name    Exact, case-sensitive tag name.
	 *
	 * @return string
	 */
	private function direct_child_value( SimpleXMLElement $element, string $name ): string {
		$child = $this->direct_child_element( $element, $name );

		return null === $child ? '' : (string) $child;
	}

	/**
	 * The first direct child of `$element` whose tag name is exactly
	 * `$name`, or null when absent. See {@see self::direct_child_value()}
	 * for why this reads via `xpath()` rather than magic property access.
	 *
	 * @param SimpleXMLElement $element Element whose direct children are read.
	 * @param string           $name    Exact, case-sensitive tag name.
	 *
	 * @return SimpleXMLElement|null
	 */
	private function direct_child_element( SimpleXMLElement $element, string $name ): ?SimpleXMLElement {
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $name ) ) {
			return null;
		}

		$matches = $element->xpath( './' . $name );

		return is_array( $matches ) && isset( $matches[0] ) ? $matches[0] : null;
	}
}
