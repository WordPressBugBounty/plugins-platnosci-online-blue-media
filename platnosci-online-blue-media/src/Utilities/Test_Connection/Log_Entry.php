<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;

class Log_Entry {

	const LEVEL_INFO = 'info';

	const LEVEL_WARNING = 'warning';

	const LEVEL_CRITICAL = 'critical';

	private string $level;
	private string $header;
	private string $message;

	/**
	 * @param string $level
	 * @param string $message
	 */
	public function __construct(
		string $level,
		string $header,
		string $message
	) {

		$level  = sanitize_text_field( $level );
		$header = sanitize_text_field( $header );


		if ( ! in_array( $level, [
			self::LEVEL_CRITICAL,
			self::LEVEL_INFO,
			self::LEVEL_WARNING,
		] ) ) {
			throw new Exception( 'Invalid log level: ' . $level );
		}

		$this->level   = $level;
		$this->message = $message;
		$this->header  = $header;
	}

	public function get_level(): string {
		return $this->level;
	}

	public function get_header(): string {
		return $this->header;
	}

	public function get_message(): string {
		return $this->message;
	}

	public function to_array(): array {
		return [
			'level'   => $this->level,
			'header'  => $this->header,
			'message' => $this->message,
		];
	}

	public static function get_header_critical(): string {
		return __( 'Critical',
			'bm-woocommerce' );
	}

	public static function get_header_warning(): string {
		return __( 'Warning',
			'bm-woocommerce' );
	}

	public static function get_header_info(): string {
		return __( 'Info',
			'bm-woocommerce' );
	}
}
