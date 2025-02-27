<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class RON implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_RON;
	}

	public function get_name(): string {
		return __( 'Romanian leu', 'bm-woocommerce' );
	}

	public function get_symbol(): string {
		return 'lei';
	}

	public function get_element_id(): string {
		return 'ron';
	}
}
