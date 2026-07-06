<?php
/**
 * Autopay URLs helper.
 *
 * @package Ilabs\BM_Woocommerce
 */

namespace Ilabs\BM_Woocommerce\Helpers;

/**
 * Provide Autopay environment URLs.
 */
class Autopay_Urls {

	/**
	 * Production Cards domain.
	 *
	 * @var string
	 */
	private const CARDS_PRODUCTION = 'https://cards.autopay.eu';

	/**
	 * Sandbox Cards domain.
	 *
	 * @var string
	 */
	private const CARDS_SANDBOX = 'https://testcards.autopay.eu';

	/**
	 * Get Cards domain for current environment.
	 *
	 * @param bool $is_test Whether test mode is enabled.
	 * @return string
	 */
	public static function get_cards_domain( bool $is_test ): string {
		return $is_test
			? self::CARDS_SANDBOX
			: self::CARDS_PRODUCTION;
	}
}
