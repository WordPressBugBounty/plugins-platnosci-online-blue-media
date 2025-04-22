<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;

class Summary {

	private string $header;
	private string $message;

	/**
	 * @param string $header
	 * @param string $message
	 */
	public function __construct( string $header, string $message = '' ) {

		$header = sanitize_text_field( $header );

		$this->header  = $header;
		$this->message = $message;
	}

	public function get_header(): string {
		return $this->header;
	}

	public function get_message(): string {
		return $this->message;
	}

	public function to_array(): array {
		return [
			'header'  => $this->header,
			'message' => $this->message,
		];
	}
}
