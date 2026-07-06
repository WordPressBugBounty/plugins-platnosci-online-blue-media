<?php
/**
 * Outcome of a card-widget pretransaction call to Autopay /payment.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Card_Widget;

/**
 * Value object describing success or a classified failure (no exceptions for control flow).
 */
final class Card_Widget_Start_Result {

	public const SUCCESS          = 'success';
	public const REJECTED         = 'rejected';
	public const BAD_RESPONSE     = 'bad_response';
	public const TRANSPORT_ERROR  = 'transport_error';

	/**
	 * One of the TYPE_* constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Request parameters including Hash (for order meta).
	 *
	 * @var array<string, mixed>
	 */
	private array $params_with_hash;

	/**
	 * Redirect URL when type is SUCCESS.
	 *
	 * @var string|null
	 */
	private ?string $redirect_url;

	/**
	 * Gateway reason text when type is REJECTED (e.g. INVALID_HASH).
	 *
	 * @var string
	 */
	private string $gateway_reason;

	/**
	 * Guzzle/cURL error message when type is TRANSPORT_ERROR.
	 *
	 * @var string
	 */
	private string $transport_detail;

	/**
	 * Raw HTTP body for diagnostics when type is BAD_RESPONSE.
	 *
	 * @var string
	 */
	private string $raw_response_body;

	/**
	 * @param string               $type              self::SUCCESS|REJECTED|BAD_RESPONSE|TRANSPORT_ERROR.
	 * @param array<string, mixed> $params_with_hash  Full request payload sent to Autopay.
	 * @param string|null          $redirect_url      Non-empty only on success.
	 * @param string               $gateway_reason    Rejection reason from XML.
	 * @param string               $transport_detail  Transport-layer error string.
	 * @param string               $raw_response_body Last response body snapshot.
	 */
	public function __construct(
		string $type,
		array $params_with_hash = [],
		?string $redirect_url = null,
		string $gateway_reason = '',
		string $transport_detail = '',
		string $raw_response_body = ''
	) {
		$this->type              = $type;
		$this->params_with_hash  = $params_with_hash;
		$this->redirect_url      = $redirect_url;
		$this->gateway_reason    = $gateway_reason;
		$this->transport_detail  = $transport_detail;
		$this->raw_response_body = $raw_response_body;
	}

	/**
	 * Outcome discriminator.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Full signed parameter set (persist on order when success or for debugging).
	 *
	 * @return array<string, mixed>
	 */
	public function get_params_with_hash(): array {
		return $this->params_with_hash;
	}

	/**
	 * Customer redirect target when transaction start succeeded.
	 *
	 * @return string|null
	 */
	public function get_redirect_url(): ?string {
		return $this->redirect_url;
	}

	/**
	 * Reason from Autopay when transaction was not confirmed.
	 *
	 * @return string
	 */
	public function get_gateway_reason(): string {
		return $this->gateway_reason;
	}

	/**
	 * Low-level HTTP client error description.
	 *
	 * @return string
	 */
	public function get_transport_detail(): string {
		return $this->transport_detail;
	}

	/**
	 * Raw XML/text body returned by Autopay (truncation may be applied by caller when logging).
	 *
	 * @return string
	 */
	public function get_raw_response_body(): string {
		return $this->raw_response_body;
	}
}
