<?php

namespace Ilabs\BM_Woocommerce;

use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Ga4_Hooks;

class Hooks {

	public function init() {
		$this->ga4_hooks();
	}


	private function ga4_hooks() {
		$ga4 = new Ga4_Hooks();
		$ga4->init();
	}
}
