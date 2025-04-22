<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

class Test_Case {

	private string $id;
	private string $name;
	private string $stage;

	private string $result; //critical, warning, info


	/**
	 * @var Log_Entry[]
	 */
	protected array $log = [];

	public function execute() {

	}


	public function put_log( Log_Entry $log_entry ) {
		$this->log[] = $log_entry;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_stage(): string {
		return $this->stage;
	}

	public function get_log(): array {
		return $this->log;
	}

	public function get_result(): string {
		return $this->result;
	}

	public function set_id( string $id ): void {
		$this->id = $id;
	}

	public function set_name( string $name ): void {
		$this->name = $name;
	}

	public function set_stage( string $stage ): void {
		$this->stage = $stage;
	}

	public function set_log( array $log ): void {
		$this->log = $log;
	}
}
