<?php

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Legacy_Manager;

class Order_Remote_Status_Manager {

	const AUTOPAY_TABLE_PREFIX = 'autopay_';

	const TABLE_NAME = 'order_remote_status';

	const STATUS_SUCCESS = 'SUCCESS';

	const STATUS_PENDING = 'PENDING';

	const STATUS_PROCESS_PAYMENT = 'PROCESS_PAYMENT';

	const STATUS_FAILURE = 'FAILURE';

	const STATUS_TEST_CONNECTION = 'TEST_CONNECTION';

	const RESULT_CONFIRMED = 'CONFIRMED';

	const RESULT_NOTCONFIRMED = 'NOTCONFIRMED';

	const PATH_VERSION = '1';

	private $db;

	private $debug_id = 'bm_woocommerce_itn';

	private int $migration_version = 2;

	private bool $status_processing_allowed_in_store = false;

	public function __construct() {
		global $wpdb;
		$this->db                  = $wpdb;
		$this->db->show_errors     = false;
		$this->db->suppress_errors = false;
		$this->db->query( 'SET innodb_lock_wait_timeout = 1' );
	}


	private function update_db_schema() {
		$shop_path_version = (int) get_option( 'autopay_order_remote_status_path' );
		$need_update       = $shop_path_version < self::PATH_VERSION;

		blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [update_db_schema] [%s]',
				wp_json_encode( [
					'shop_path_version'   => $shop_path_version,
					'plugin_path_version' => self::PATH_VERSION,
					'need_update'         => $need_update ? 'TRUE' : 'FALSE',
				] ),
			) );

		if ( ! $need_update ) {
			return;
		}

		try {
			$table_name = esc_sql( $this->get_table_name_prefixed() );
			$this->db->query( "ALTER TABLE {$table_name} MODIFY id BIGINT NOT NULL AUTO_INCREMENT" );
			$this->db->query( "ALTER TABLE {$table_name} MODIFY order_id BIGINT NOT NULL" );
			$result = $this->db->last_result;
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [update_db_schema] [error] [%s]',
					wp_json_encode( [
						'message' => $exception->getMessage(),
					] ),
				) );

			return;
		}

		blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [update_db_schema] [%s]',
				wp_json_encode( [
					'result' => $result,
				] ),
			) );

		update_option( 'autopay_order_remote_status_path', self::PATH_VERSION );
	}

	public function install_db_schema() {

		try {
			$table_name      = esc_sql( $this->get_table_name_prefixed() );
			$charset_collate = $this->db->get_charset_collate();
			$sql             = "CREATE TABLE IF NOT EXISTS $table_name (
                id BIGINT NOT NULL AUTO_INCREMENT,
                order_id BIGINT NOT NULL,
                status varchar(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY order_id (order_id)
            ) $charset_collate;";

			blue_media()->require_wp_core_file( 'wp-admin/includes/upgrade.php' );

			$result = dbDelta( $sql );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [create_table] [%s]',
					wp_json_encode( [
						'table_name' => $table_name,
						'result'     => $result,
					] ),
				) );

			blue_media()->update_autopay_option( 'order_remote_schema_installed',
				'1' );

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [create_table] [error] [%s]',
					wp_json_encode( [
						'message' => $exception->getMessage(),
					] ),
				) );

		}

		$this->update_db_schema();
	}

	public function add_order_remote_status(
		int $order_id,
		string $status_from_remote
	) {
		blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [add_order_remote_status] [%s]',
				wp_json_encode( [
					'order_id'           => $order_id,
					'status_from_remote' => $status_from_remote,
				] ),
			) );

		try {
			$table_name = esc_sql( $this->get_table_name_prefixed() );
			$result     = $this->db->query(
				$this->db->prepare(
					"INSERT INTO `{$table_name}` (order_id, status) VALUES (%d, %s) ON DUPLICATE KEY UPDATE status = %s",
					$order_id,
					$status_from_remote,
					$status_from_remote
				)
			);

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [add_order_remote_status] [%s]',
					wp_json_encode( [
						'result' => $result,
					] ),
				) );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [add_order_remote_status] [error] [%s]',
					wp_json_encode( [
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
						'error message'      => $exception->getMessage(),
					] ),
				) );
		}
	}


	public function update_order_status(
		int $order_id,
		string $status_from_remote
	): string {
		$table_name = $this->get_table_name_prefixed();

		try {
			$this->db->query( 'START TRANSACTION' );

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [START TRANSACTION] [%s]',
					wp_json_encode( [
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
					] ),
				) );

			$current_status = $this->db->get_var(
				$this->db->prepare( "SELECT status FROM $table_name WHERE order_id = %d FOR UPDATE",
					$order_id ) );

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [%s]',
					wp_json_encode( [
						'order_id'       => $order_id,
						'current_status' => $current_status,
					] ),
				) );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}

			if ( empty( $current_status ) ) {
				$legacy_status = Order_Remote_Status_Legacy_Manager::find_itn_status( $order_id );

				if ( $legacy_status ) {
					blue_media()
						->get_woocommerce_logger( $this->debug_id )
						->log_debug(
							sprintf( '[Order_Remote_Status] [Legacy status found] [order_id: %s]',
								$order_id ) );
					$this->add_order_remote_status( $order_id, $legacy_status );
					$current_status = $legacy_status;
				} else {
					$this->set_status_processing_allowed_in_store( false );

					blue_media()
						->get_woocommerce_logger( $this->debug_id )
						->log_debug(
							sprintf( '[Order_Remote_Status] [Order not found][order_id: %s] [set_status_processing_allowed_in_store: false] [return code: RESULT_CONFIRMED]',
								$order_id ) );
					$this->db->query( 'ROLLBACK' );

					return self::RESULT_CONFIRMED;
				}
			}

			if ( $current_status === $status_from_remote ) {
				$this->set_status_processing_allowed_in_store( false );
				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [path: ALREADY_PROCESSED - current_status and status_from_remote are identical. Doing rollback.] [order_id: %s] [return code: RESULT_CONFIRMED]',
							$order_id ) );
				$this->db->query( 'ROLLBACK' );

				return self::RESULT_CONFIRMED;
			}

			if ( $current_status === self::STATUS_SUCCESS ) {
				$this->set_status_processing_allowed_in_store( false );
				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [path: ALREADY_PROCESSED - current_status SUCCESS is unchangeable. Doing rollback] [order_id: %s] [return code: RESULT_CONFIRMED]',
							$order_id
						) );
				$this->db->query( 'ROLLBACK' );

				return self::RESULT_CONFIRMED;
			}

			$this->update_to_db(
				$order_id,
				$status_from_remote
			);

			$this->db->query( 'COMMIT' );
			$this->set_status_processing_allowed_in_store( true );

			blue_media()
				->get_woocommerce_logger( $this->debug_id )
				->log_debug(
					sprintf( '[Order_Remote_Status] [path: UPDATED - status changed successfully] [order_id: %s] [from: %s] [to: %s] [return code: RESULT_CONFIRMED]',
						$order_id,
						(string) $current_status,
						$status_from_remote
					) );

		} catch ( Exception $exception ) {
			$this->set_status_processing_allowed_in_store( false );
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [update_order_status] [can\'t update ] [%s]',
					wp_json_encode( [
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
						'status_processing_allowed_in_store' => $this->status_processing_allowed_in_store ? 'TRUE' : 'FALSE',
						'error message'      => $exception->getMessage(),
					] ),
				) );

			$this->db->query( 'ROLLBACK' );

			return self::RESULT_NOTCONFIRMED;

		}

		return self::RESULT_CONFIRMED;
	}


	private function update_to_db(
		int $order_id,
		string $status_from_remote
	) {

		$result = $this->db->update(
			$this->get_table_name_prefixed(),
			[ 'status' => $status_from_remote ],
			[ 'order_id' => $order_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $this->db->last_error !== '' ) {

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [update_to_db] [%s]',
					wp_json_encode( [
						'result'             => $result,
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
					] ),
				) );

			throw new Exception( $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
		}
	}

	public function get_order_remote_status( $order_id ): ?string {
		$table_name = $this->get_table_name_prefixed();

		try {

			$result = $this->db->get_var(
				$this->db->prepare( "SELECT status FROM $table_name WHERE order_id = %d FOR UPDATE",
					$order_id )
			);

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [get_order_remote_status] [%s]',
					wp_json_encode( [
						'result'   => $result,
						'order_id' => $order_id,
					] ),
				) );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}

			if ( ! is_string( $result ) ) {
				return null;
			}
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [get_order_remote_status error] [%s]',
					wp_json_encode( [
						'message' => $exception->getMessage(),
					] ),
				) );

			return null;

		}

		return $result;
	}

	public function get_table_name_prefixed(): string {
		return $this->get_table_prefix() . self::TABLE_NAME;
	}

	public function get_table_prefix(): string {
		global $wpdb;

		return $wpdb->prefix . self::AUTOPAY_TABLE_PREFIX;
	}

	public function is_status_processing_allowed_in_store(): bool {
		return $this->status_processing_allowed_in_store;
	}

	private function set_status_processing_allowed_in_store(
		bool $status_processing_allowed_in_store
	): void {

		/*
		blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [status_processing_allowed_in_store flag is now %s for order_id: %s]',
				$status_processing_allowed_in_store ? 'TRUE' : 'FALSE',
				$order_id ),
		);*/

		$this->status_processing_allowed_in_store = $status_processing_allowed_in_store;
	}
}
