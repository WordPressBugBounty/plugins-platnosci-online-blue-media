<?php

namespace Ilabs\BM_Woocommerce\Utilities\File_System;

use Exception;
use Ilabs\BM_Woocommerce\Utilities\Test_Connection\Auditor;
use ZipArchive;

class Log_Downloader {


	public function download_logs(
		string $zip_file_name = ''
	) {
		$zip_file_name = sanitize_file_name( $zip_file_name );
		if ( $zip_file_name === '' ) {
			$zip_file_name = $this->get_random_sanitized_filename();
		}

		$logs_content = $this->get_logs_content();
		$tmp_file     = tmpfile();
		$tmp_location = stream_get_meta_data( $tmp_file )['uri'];

		$zip = new ZipArchive;
		$res = $zip->open( $tmp_location, ZipArchive::CREATE );
		if ( $res === true ) {
			foreach ( $logs_content as $file_name => $content ) {
				$zip->addFromString( $file_name, $content );
			}


			$zip->close();
		} else {
			throw new Exception( 'Error: Zip couldn\'t be created.' );
		}

		header( 'Content-type: application/zip' );
		header( sprintf( 'Content-Disposition: attachment; filename="%s"',
			$zip_file_name ) );
		echo( file_get_contents( $tmp_location ) );
		exit;
	}


	private function get_logs_content(): array {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wc-logs';
		$result     = [];
		if ( ! is_dir( $log_dir ) ) {
			return [];
		}

		$pattern = trailingslashit( $log_dir ) . '*bm_woocommerce*.log';
		$files   = glob( $pattern );

		if ( false === $files ) {
			return [];
		}

		foreach ( $files as $k => $log ) {
			$result[ 'log_' . $k . '.txt' ] = file_get_contents( $log );
		}


		return $result;
	}

	public function handle(): void {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			@blue_media()->require_wp_core_file( 'wp-includes/pluggable.php' );
			if ( ! function_exists( 'wp_get_current_user' ) ) {
				return;
			}
		}

		$current_user = wp_get_current_user();
		if ( isset( $_GET['autopay_download_log'] ) && user_can( $current_user,
				'administrator' ) ) {
			try {
				$log_id = sanitize_key( $_GET['autopay_download_log'] );
				Auditor::load( $log_id );
				Auditor::delete( $log_id );
				$this->download_logs();

				exit();
			} catch ( Exception $exception ) {
				Auditor::delete( $log_id );
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Log_Downloader] [handle] [log_id: %s] [error: %s]',
						print_r( $exception->getMessage(),
							true ),
						print_r( $log_id,
							true ),
					) );
			} finally {
				return;
			}
		}
	}

	private function get_random_sanitized_filename(): string {
		return sanitize_file_name( \substr( \md5( \uniqid( \rand(), \true ) ),
			0,
			10 ) );
	}
}
