<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class GBP implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_GBP;
	}

	public function get_name(): string {
		return __( 'Pound sterling', 'platnosci-online-blue-media' );
	}

	public function get_symbol(): string {
		return '&pound;';
	}

	public function get_element_id(): string {
		return 'gbp';
	}
}
