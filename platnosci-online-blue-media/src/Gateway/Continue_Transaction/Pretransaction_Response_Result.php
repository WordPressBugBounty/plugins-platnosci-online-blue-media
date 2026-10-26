<?php
/**
 * Pretransaction_Response_Result — typed outcome of validating a
 * Przedtransakcja (continue-transaction) response.
 *
 * @package Ilabs\BM_Woocommerce\Gateway\Continue_Transaction
 */

namespace Ilabs\BM_Woocommerce\Gateway\Continue_Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of {@see Pretransaction_Response_Validator::validate()}.
 */
final class Pretransaction_Response_Result {

	/**
	 * The document is exactly the documented "link do kontynuacji
	 * transakcji" shape (`status`, `redirecturl`, `orderID`, `remoteID`,
	 * `hash` -- no more, no less, no duplicates), `status=PENDING`,
	 * signature verified, OrderID matches, and `redirecturl` is non-empty.
	 */
	public const VALID_REDIRECT = 'valid_redirect';

	/**
	 * The detected shape's structure was intact enough to compute an
	 * expected hash, but it does not match the response's own `<hash>`.
	 */
	public const INVALID_SIGNATURE = 'invalid_signature';

	/**
	 * Signature verified, but the response's own `orderID` does not match
	 * (or is absent, when one was expected) the OrderID this request sent.
	 */
	public const ORDER_ID_MISMATCH = 'order_id_mismatch';

	/**
	 * Response body is not parseable XML at all.
	 */
	public const MALFORMED_XML = 'malformed_xml';

	/**
	 * Document parsed, but no `<hash>` element (or an empty one) was found.
	 */
	public const MISSING_HASH = 'missing_hash';

	/**
	 * Signature verified, OrderID matches, `confirmation=NOTCONFIRMED` in the
	 * "Wynik Przedtransakcji" shape -- Autopay rejected the request.
	 */
	public const REJECTED = 'rejected';

	/**
	 * Signature verified, OrderID matches, `confirmation=CONFIRMED` in the
	 * "Wynik Przedtransakcji" shape, but that shape never carries a
	 * `redirecturl`. Documented as valid when no further customer action is
	 * needed (e.g. a cyclic/recurring charge) -- NOT a rejection, and never
	 * to be redirected on since there is nothing to redirect to.
	 */
	public const CONFIRMED_WITHOUT_REDIRECT = 'confirmed_without_redirect';

	/**
	 * Root is not `<transaction>`, the two documented shapes' fields are
	 * both present (contradictory), a required field for the detected shape
	 * is missing/duplicated/empty, or `confirmation` holds neither
	 * `CONFIRMED` nor `NOTCONFIRMED`.
	 */
	public const UNEXPECTED_SHAPE = 'unexpected_shape';

	/**
	 * One of the outcome constants above.
	 *
	 * @var string
	 */
	private $outcome;

	/**
	 * Trusted redirect URL, present only when `$outcome` is `VALID_REDIRECT`.
	 *
	 * @var string|null
	 */
	private $redirect_url;

	/**
	 * Response `status`, when present.
	 *
	 * @var string|null
	 */
	private $status;

	/**
	 * Response `confirmation`, when present.
	 *
	 * @var string|null
	 */
	private $confirmation;

	/**
	 * Response `reason`, when present.
	 *
	 * @var string|null
	 */
	private $reason;

	/**
	 * Response `orderID`, when present.
	 *
	 * @var string|null
	 */
	private $response_order_id;

	/**
	 * Build the immutable result.
	 *
	 * @param string      $outcome           One of the class constants above.
	 * @param string|null $redirect_url      Present only for VALID_REDIRECT.
	 * @param string|null $status            Response `status`, if present.
	 * @param string|null $confirmation      Response `confirmation`, if present.
	 * @param string|null $reason            Response `reason`, if present.
	 * @param string|null $response_order_id Response `orderID`, if present.
	 */
	public function __construct(
		string $outcome,
		?string $redirect_url = null,
		?string $status = null,
		?string $confirmation = null,
		?string $reason = null,
		?string $response_order_id = null
	) {
		$this->outcome           = $outcome;
		$this->redirect_url      = $redirect_url;
		$this->status            = $status;
		$this->confirmation      = $confirmation;
		$this->reason            = $reason;
		$this->response_order_id = $response_order_id;
	}

	/**
	 * One of the outcome constants declared on this class.
	 *
	 * @return string
	 */
	public function get_outcome(): string {
		return $this->outcome;
	}

	/**
	 * Whether validation produced a trustworthy redirect URL.
	 *
	 * @return bool
	 */
	public function is_valid_redirect(): bool {
		return self::VALID_REDIRECT === $this->outcome;
	}

	/**
	 * Trusted redirect URL, or null unless `is_valid_redirect()` is true.
	 *
	 * @return string|null
	 */
	public function get_redirect_url(): ?string {
		return $this->redirect_url;
	}

	/**
	 * Response `status` field, or null when absent.
	 *
	 * @return string|null
	 */
	public function get_status(): ?string {
		return $this->status;
	}

	/**
	 * Response `confirmation` field, or null when absent.
	 *
	 * @return string|null
	 */
	public function get_confirmation(): ?string {
		return $this->confirmation;
	}

	/**
	 * Response `reason` field, or null when absent.
	 *
	 * @return string|null
	 */
	public function get_reason(): ?string {
		return $this->reason;
	}

	/**
	 * Response `orderID` field, or null when absent.
	 *
	 * @return string|null
	 */
	public function get_response_order_id(): ?string {
		return $this->response_order_id;
	}
}
