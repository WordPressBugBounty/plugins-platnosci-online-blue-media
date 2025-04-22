<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

class Response_Finished extends Abstract_Response {

	protected string $test_id = '';
	protected string $stage_name = '';
	protected string $wc_log_url = '';

	/**
	 * @var Summary | null
	 */
	protected ?Summary $summary_success = null;

	/**
	 * @var Summary | null
	 */
	protected ?Summary $summary_warning = null;

	/**
	 * @var Summary | null
	 */
	protected ?Summary $summary_error = null;


	/**
	 * @var Log_Entry[]
	 */
	protected array $log = [];


	/**
	 * @param string $stage_name
	 */
	public function __construct(
		string $test_id,
		string $stage_name,
		string $wc_log_url = ''
	) {
		$this->stage_name = $stage_name;
		$this->test_id    = $test_id;
		$this->wc_log_url = $wc_log_url;
	}

	public function get_status(): string {
		return Response_Interface::STATUS_FINISHED;
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
			$log[] = $v->to_array();
		}

		return [
			'testId'         => $this->get_test_id(),
			'status'         => $this->get_status(),
			'stageName'      => $this->get_stage_name(),
			'log'            => $log,
			'summarySuccess' => $this->summary_success ? $this->summary_success->to_array() : null,
			'summaryWarning' => $this->summary_warning ? $this->summary_warning->to_array() : null,
			'summaryError'   => $this->summary_error ? $this->summary_error->to_array() : null,
			'wcLogUrl'       => $this->wc_log_url,
		];
	}

	public function set_summary_success( ?Summary $summary_success ): void {
		$this->summary_success = $summary_success;
	}

	public function set_summary_warning( ?Summary $summary_warning ): void {
		$this->summary_warning = $summary_warning;
	}

	public function set_summary_error( ?Summary $summary_error ): void {
		$this->summary_error = $summary_error;
	}

	public function set_wc_log_url( string $wc_log_url ): void {
		$this->wc_log_url = $wc_log_url;
	}
}
