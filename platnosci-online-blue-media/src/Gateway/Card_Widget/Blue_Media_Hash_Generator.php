<?php
/**
 * Adapter delegating hash generation to {@see Blue_Media_Gateway}.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Card_Widget;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;

/**
 * Bridges the card-widget service to the gateway hash implementation.
 */
final class Blue_Media_Hash_Generator implements Transaction_Hash_Generator_Interface {

	/**
	 * Gateway instance (same object that owns private key configuration).
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * @param Blue_Media_Gateway $gateway Configured payment gateway.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate( array $params ): string {
		return $this->gateway->hash_transaction_parameters( $params );
	}
}
