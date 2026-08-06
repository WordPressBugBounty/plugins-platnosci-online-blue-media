<?php

namespace Ilabs\BM_Woocommerce\Data\Remote\Ga4\Dto;

defined( 'ABSPATH' ) || exit;

interface Ga4_Dto_Interface {

	public function to_array(): array;
}
