<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class EUR implements Currency_Interface{

	public function get_code(): string {
		return Currency_Interface::CODE_EUR;
	}

	public function get_name(): string {
		return __( 'Euro', 'woocommerce' );
	}

	public function get_symbol(): string {
		return '&euro;';
	}

	public function get_element_id(): string {
		return 'eur';
	}
}
