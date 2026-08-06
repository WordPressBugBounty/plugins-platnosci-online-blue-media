<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

defined( 'ABSPATH' ) || exit;

class Strings {

	public static function get_strings(): array {
		return [
			'auditInProgress'              => __( 'Audit in progress',
				'platnosci-online-blue-media' ),
			'auditCompleted'               => __( 'Audit Completed',
				'platnosci-online-blue-media' ),
			'auditAbortedDueCriticalError' => __( 'Audit Completed',
				'platnosci-online-blue-media' ),
			'serverTest'                   => __( 'Server configuration testing',
				'platnosci-online-blue-media' ),
			'pleaseWait'                   => __( 'Please wait...',
				'platnosci-online-blue-media' ),
			'criticalProblemsTotal'        => __( 'Critical problems total',
				'platnosci-online-blue-media' ),
			'warningsTotal'                => __( 'Warnings total',
				'platnosci-online-blue-media' ),
			'start'                        => __( 'Start',
				'platnosci-online-blue-media' ),
			'startAgain'                   => __( 'Start again',
				'platnosci-online-blue-media' ),
			'criticalAjaxMessage'          => __( 'The test couldn\'t continue due to server error.',
				'platnosci-online-blue-media' ),
			'criticalErrorOccurredMessage' => __( 'A critical error occurred',
				'platnosci-online-blue-media' ),
			'critical'                     => __( 'Critical',
				'platnosci-online-blue-media' ),
			'criticalGenericMessage'       => __( 'The testing procedure was stopped by a critical error. Copy the log contents to the clipboard and download the logs to disk. If you don\'t see the error message or can\'t download the logs to disk, ask the administrator for the server error log file.',
				'platnosci-online-blue-media' ),
		];
	}
}
