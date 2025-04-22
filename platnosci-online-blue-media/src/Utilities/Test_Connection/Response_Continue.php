<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

class Response_Continue extends Abstract_Response {

	protected string $stage_name = '';

	protected string $wc_log_url = '';
	protected string $test_id = '';

	/**
	 * @var Log_Entry[]
	 */
	protected array $log = [];

	public function __construct(
		string $test_id,
		string $stage_name,
		string $wc_log_url = ''
	) {
		$this->test_id    = $test_id;
		$this->stage_name = $stage_name;
		$this->wc_log_url = $wc_log_url;
	}

	public function get_status(): string {
		return Response_Interface::STATUS_CONTINUE;
	}

	public function get_stage_name(): string {
		return $this->stage_name;
	}

	public function get_test_id(): string {
		return $this->test_id;
	}

	public function to_array(): array {
		$log = [];
		foreach ( $this->log as $k => $v ) {
			if ( $v instanceof Log_Entry ) {
				$log[] = $v->to_array();
			}
		}

		return [
			'testId'    => $this->get_test_id(),
			'status'    => $this->get_status(),
			'stageName' => $this->get_stage_name(),
			'log'       => $log,
			'wcLogUrl'  => $this->wc_log_url,
		];
	}
}
