<?php
/**
 * Gateway_Configuration — resolved runtime configuration for Blue_Media_Gateway.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Resolved runtime configuration for Blue_Media_Gateway.
 *
 * Populated by Blue_Media_Gateway::setup_variables() after reading WC options
 * for the active currency context. Re-created on every currency switch.
 */
class Gateway_Configuration {

	/**
	 * @var string
	 */
	private $service_id;

	/**
	 * @var string
	 */
	private $private_key;

	/**
	 * @var string
	 */
	private $gateway_url;

	/**
	 * @var string
	 */
	private $gateway_url_not_modified_by_user;

	/**
	 * @var string
	 */
	private $express_payment_redirect_url;

	/**
	 * @var bool
	 */
	private $testmode;

	/**
	 * Create a configuration snapshot for the current currency context.
	 *
	 * @param string $service_id                       Autopay service ID.
	 * @param string $private_key                      Autopay private key.
	 * @param string $gateway_url                      Active gateway URL (may be overridden by admin).
	 * @param string $gateway_url_not_modified_by_user Default gateway URL without admin override.
	 * @param string $express_payment_redirect_url     URL for redirect-based express payments.
	 * @param bool   $testmode                         Whether sandbox mode is active.
	 */
	public function __construct(
		string $service_id,
		string $private_key,
		string $gateway_url,
		string $gateway_url_not_modified_by_user,
		string $express_payment_redirect_url,
		bool $testmode
	) {
		$this->service_id                       = $service_id;
		$this->private_key                      = $private_key;
		$this->gateway_url                      = $gateway_url;
		$this->gateway_url_not_modified_by_user = $gateway_url_not_modified_by_user;
		$this->express_payment_redirect_url     = $express_payment_redirect_url;
		$this->testmode                         = $testmode;
	}

	/**
	 * Returns the Autopay service ID.
	 *
	 * @return string
	 */
	public function get_service_id(): string {
		return $this->service_id;
	}

	/**
	 * Returns the Autopay private key.
	 *
	 * @return string
	 */
	public function get_private_key(): string {
		return $this->private_key;
	}

	/**
	 * Returns the active gateway URL (may be overridden by admin).
	 *
	 * @return string
	 */
	public function get_gateway_url(): string {
		return $this->gateway_url;
	}

	/**
	 * Returns the default gateway URL without admin override.
	 *
	 * @return string
	 */
	public function get_gateway_url_not_modified_by_user(): string {
		return $this->gateway_url_not_modified_by_user;
	}

	/**
	 * Returns the URL used for redirect-based express payments.
	 *
	 * @return string
	 */
	public function get_express_payment_redirect_url(): string {
		return $this->express_payment_redirect_url;
	}

	/**
	 * Returns true when sandbox/test mode is active.
	 *
	 * @return bool
	 */
	public function is_testmode(): bool {
		return $this->testmode;
	}
}
