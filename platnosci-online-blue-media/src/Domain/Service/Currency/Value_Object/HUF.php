<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class HUF implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_HUF;
	}

	public function get_name(): string {
		return __( 'Hungarian forint', 'platnosci-online-blue-media' );
	}

	public function get_symbol(): string {
		return '&#70;&#116;';
	}

	public function get_element_id(): string {
		return 'huf';
	}
}
