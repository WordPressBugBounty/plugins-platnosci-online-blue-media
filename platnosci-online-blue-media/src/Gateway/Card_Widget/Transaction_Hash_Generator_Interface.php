<?php
/**
 * Abstraction for Autopay transaction parameter hashing (SHA-256 over pipe-separated values).
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Card_Widget;

/**
 * Generates the Hash field for Autopay payment API requests.
 */
interface Transaction_Hash_Generator_Interface {

	/**
	 * Compute hash for the given parameter set (Hash key itself must not be included).
	 *
	 * @param array<string, scalar> $params Ordered transaction fields without Hash.
	 *
	 * @return string Hex-encoded SHA-256 digest.
	 */
	public function generate( array $params ): string;
}
