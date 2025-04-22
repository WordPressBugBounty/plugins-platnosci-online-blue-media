<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

class Strings {

	public static function get_strings(): array {
		return [
			'auditInProgress'              => __( 'Audit in progress',
				'bm-woocommerce' ),
			'auditCompleted'               => __( 'Audit Completed',
				'bm-woocommerce' ),
			'auditAbortedDueCriticalError' => __( 'Audit Completed',
				'bm-woocommerce' ),
			'serverTest'                   => __( 'Server configuration testing',
				'bm-woocommerce' ),
			'pleaseWait'                   => __( 'Please wait...',
				'bm-woocommerce' ),
			'criticalProblemsTotal'        => __( 'Critical problems total',
				'bm-woocommerce' ),
			'warningsTotal'                => __( 'Warnings total',
				'bm-woocommerce' ),
			'start'                        => __( 'Start',
				'bm-woocommerce' ),
			'startAgain'                   => __( 'Start again',
				'bm-woocommerce' ),
			'criticalAjaxMessage'          => __( 'The test couldn\'t continue due to server error.',
				'bm-woocommerce' ),
			'criticalErrorOccurredMessage' => __( 'A critical error occurred',
				'bm-woocommerce' ),
			'critical'                     => __( 'Critical',
				'bm-woocommerce' ),
			'criticalGenericMessage'       => __( 'The testing procedure was stopped by a critical error. Copy the log contents to the clipboard and download the logs to disk. If you don\'t see the error message or can\'t download the logs to disk, ask the administrator for the server error log file.',
				'bm-woocommerce' ),
		];
	}
}
