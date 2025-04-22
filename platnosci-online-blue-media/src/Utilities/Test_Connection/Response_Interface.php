<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

interface Response_Interface {

	const STATUS_CONTINUE = 'continue';

	const STATUS_FINISHED = 'finished';

	public function get_status(): string;

	public function get_stage_name(): string;

	public function to_array(): array;
}
