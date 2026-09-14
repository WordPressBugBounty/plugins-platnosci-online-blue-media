<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Controller\Controller_Interface;

class Async_Request implements Controller_Interface {

	const AJAX_ACTION_NAME = 'autopay_connection_test';

	const REQUEST_ACTION_NAME_NEW = 'new';

	const REQUEST_ACTION_NAME_CONTINUE = 'continue';


	/**
	 * @throws Exception
	 */
	public function execute_request() {
		try {
			error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Isolated async request handler for test connection; error reporting adjusted to capture test output without debug notices.
			set_transient( 'autopay_debug_enabled', 'on', 3000 );

			add_filter( 'autopay_log_id', function ( $id ) {
				return 'bm_woocommerce_audit';
			} );

			if ( ! isset( $_POST['nonce'] ) ) {
				throw new Exception( __( 'Nonce field not exists',
					'platnosci-online-blue-media' ) );
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, self::get_nonce_action() ) ) {
				throw new Exception( __( 'Verification nonce failed',
					'platnosci-online-blue-media' ) );
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				throw new Exception( __( 'Insufficient permissions', 'platnosci-online-blue-media' ) );
			}

			if ( ! isset( $_POST['autopay_action'] ) ) {
				throw new Exception( __( 'Action field not exists',
					'platnosci-online-blue-media' ) );
			}

			$action = sanitize_text_field( wp_unslash( $_POST['autopay_action'] ?? '' ) );
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Async_Request] [execute_request] [action: %s] [test_id: %s] [nonce_valid: yes]',
					$action,
					isset( $_POST['test_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_id'] ?? '' ) ) : ''
				)
			);

			switch ( $action ) {
				case 'new':
					$auditor = Auditor::create_new();
					$test_id = $auditor->get_id();

					$response = new Response_Continue( $test_id,
						Strings::get_strings()['serverTest'],
						'' );
					break;

				case 'continue':
					if ( ! isset( $_POST['test_id'] ) ) {
						throw new Exception( __( 'test_id field not exists',
							'platnosci-online-blue-media' ) );
					}
					$test_id    = sanitize_text_field( wp_unslash( $_POST['test_id'] ?? '' ) );
					$auditor    = Auditor::load( $test_id );
					$log        = $auditor->run();
					$stage_name = $auditor->get_stageName();
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[Async_Request] [continue] [test_id: %s] [stage_name: %s] [finished: %s] [failed: %s] [warning: %s]',
							$test_id,
							$stage_name,
							$auditor->is_finished() ? '1' : '0',
							$auditor->is_failed() ? '1' : '0',
							$auditor->is_warning() ? '1' : '0'
						)
					);

					if ( $auditor->is_finished() ) {
						$response = new Response_Finished( $test_id,
							$stage_name );

						if ( $auditor->is_failed() ) {
							$message = __( 'Your store is not ready to accept payments. Please check the logs for more details.',
								'platnosci-online-blue-media' );
							$summary = new Summary( $message );
							$response->set_summary_error( $summary );
						} elseif ( $auditor->is_warning() ) {
							$message = __( 'Minor inconsistencies in your shop were detected, though they may not block transactions entirely. Review your integration steps to optimize performance. If you encounter issues, you can share the log file by downloading or copying it.',
								'platnosci-online-blue-media' );
							$summary = new Summary( $message );
							$response->set_summary_success( $summary );
						} else {
							$message = __( 'All gateway prerequisites were met, and transactions should proceed smoothly. Keep this success summary so you can reference it if updates are needed.',
								'platnosci-online-blue-media' );
							$summary = new Summary( $message );
							$response->set_summary_success( $summary );
						}

						if ( $auditor->is_zip_not_found() ) {
							$logs_url = '';
						} else {
							$logs_url = $this->get_logs_url( $auditor->get_id() );
						}

						$response->set_wc_log_url( $logs_url );

					} else {
						if ( $auditor->is_zip_not_found() ) {
							$logs_url = '';
						} else {
							$logs_url = $this->get_logs_url( $auditor->get_id() );
						}

						$response = new Response_Continue( $test_id,
							$stage_name,
							$logs_url );
					}
					$response->set_log( $log );

					break;

				default:
					throw new Exception( __( 'Invalid action',
						'platnosci-online-blue-media' ) );
			}

			blue_media()
				->get_woocommerce_logger()
				->log_debug(
					sprintf( '[Connection_Testing_Controller] [execute_request] [Auditor: %s]',
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Auditor is a complex object that cannot be safely JSON-encoded; used only in debug logger call.
						print_r( $auditor, true )
					) );

			$response_arr = $response->to_array();
			blue_media()
				->get_woocommerce_logger()
				->log_debug(
					sprintf( '[Connection_Testing_Controller] [execute_request] [Response: %s]',
						wp_json_encode( $response_arr )
					) );

			wp_send_json( $response_arr );
			die;

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Connection_Testing_Controller] [execute_request] [Message: %s] [POST: %s] ',
					$exception->getMessage(),
					wp_json_encode( $_POST ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST data is logged only for debugging; nonce is verified above before any processing.
				) );

			delete_transient( 'autopay_debug_enabled' );

			echo wp_json_encode( [
				'status'  => 'error',
				'message' => sanitize_text_field( $exception->getMessage() ),
			] );
			exit;
		}
	}

	public function get_logs_url( string $test_id ) {
		/*
		blue_media()
			->get_woocommerce_logger( 'bm_woocommerce_testing' )
			->log_debug(
				sprintf( '[Connection_Testing_Controller] [logs %s] ',
					print_r( $logs, true )
				) );*/

		$site_url = get_site_url();
		// $download_url = $site_url . '?' + i_plugin_download=' . $random_md5;
		$download_url = add_query_arg( [ 'autopay_download_log' => $test_id ],
			$site_url );

		return wp_nonce_url( $download_url, 'autopay_download_log' );
	}

	private function output_response(
		Response_Interface $response
	) {
		echo wp_json_encode( $response->to_array() );
		exit;
	}

	public function handle() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION_NAME,
			function () {
				$this->execute_request();
			} );

		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION_NAME,
			function () {
				$this->execute_request();
			} );
	}

	public static function generate_nonce(): string {
		return wp_create_nonce( self::get_nonce_action() );
	}

	public static function get_nonce_action(): string {
		return 'autopay_audit_';
	}
}
