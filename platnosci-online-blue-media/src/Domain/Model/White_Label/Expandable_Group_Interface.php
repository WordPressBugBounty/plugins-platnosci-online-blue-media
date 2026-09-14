<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label;

defined( 'ABSPATH' ) || exit;

interface Expandable_Group_Interface {

	public function get_icon(): string;

	public function get_subtitle(): string;
}
