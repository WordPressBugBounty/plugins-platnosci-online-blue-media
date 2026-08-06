<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class EUR implements Currency_Interface{

	public function get_code(): string {
		return Currency_Interface::CODE_EUR;
	}

	public function get_name(): string {
		return __( 'Euro', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Intentional reuse of WooCommerce currency translation for 'Euro'.
	}

	public function get_symbol(): string {
		return '&euro;';
	}

	public function get_element_id(): string {
		return 'eur';
	}
}
