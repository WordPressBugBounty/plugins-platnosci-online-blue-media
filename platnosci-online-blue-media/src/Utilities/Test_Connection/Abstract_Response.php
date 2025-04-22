<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

abstract class Abstract_Response implements Response_Interface {

	/**
	 * @var Log_Entry[]
	 */
	protected array $log = [];


	public function put_log( Log_Entry $log_entry ) {
		$this->log[] = $log_entry;
	}

	public function get_log() {
		return $this->log;
	}

	public function set_log( array $log ): void {
		$this->log = $log;
	}
}
