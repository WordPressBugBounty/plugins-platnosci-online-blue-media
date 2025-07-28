<?php

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

use Exception;

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

	private $db;

	private $debug_id = 'bm_woocommerce_itn';

	private bool $status_processing_allowed_in_store = false;

	public function __construct() {
		global $wpdb;
		$this->db                  = $wpdb;
		$this->db->show_errors     = false;
		$this->db->suppress_errors = false;
		$this->db->query( 'SET innodb_lock_wait_timeout = 1' );
	}


	public function install_db_schema() {

		try {
			$table_name      = esc_sql( $this->get_table_name_prefixed() );
			$charset_collate = $this->db->get_charset_collate();
			$sql             = "CREATE TABLE IF NOT EXISTS $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                order_id mediumint(9) NOT NULL,
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
					print_r( [
						'table_name' => $table_name,
						'result'     => $result,
					], true ),
				) );

			blue_media()->update_autopay_option( 'order_remote_schema_installed',
				'1' );

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [create_table] [error] [%s]',
					print_r( [
						'message' => $exception->getMessage(),
					], true ),
				) );

		}

	}

	public function add_order_remote_status(
		int $order_id,
		string $status_from_remote
	) {
		blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [add_order_remote_status] [%s]',
				print_r( [
					'order_id'           => $order_id,
					'status_from_remote' => $status_from_remote,
				], true ),
			) );

		try {
			$result = $this->db->insert(
				$this->get_table_name_prefixed(),
				[ 'order_id' => $order_id, 'status' => $status_from_remote ],
				[ '%d', '%s' ]
			);

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [add_order_remote_status] [%s]',
					print_r( [
						'result' => $result,
					], true ),
				) );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [add_order_remote_status] [error] [%s]',
					print_r( [
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
						'error message'      => $exception->getMessage(),
					], true ),
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
					print_r( [
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
					], true ),
				) );

			$current_status = $this->db->get_var(
				$this->db->prepare( "SELECT status FROM $table_name WHERE order_id = %d FOR UPDATE",
					$order_id ) );


			blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
				sprintf( '[Order_Remote_Status] [%s]',
					print_r( [
						'order_id'       => $order_id,
						'current_status' => $current_status,
					], true ),
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
						sprintf( '[Order_Remote_Status] [current_status and status_from_remote are identical. Doing rollback.] [order_id: %s] [return code: RESULT_CONFIRMED]',
							$order_id ) );
				$this->db->query( 'ROLLBACK' );

				return self::RESULT_CONFIRMED;
			}

			if ( $current_status === self::STATUS_SUCCESS ) {
				$this->set_status_processing_allowed_in_store( false );
				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [current_status SUCCESS is unchangeable. Doing rollback] [order_id: %s] [return code: RESULT_CONFIRMED]',
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

		} catch ( Exception $exception ) {
			$this->set_status_processing_allowed_in_store( false );
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [update_order_status] [can\'t update ] [%s]',
					print_r( [
						'order_id'                           => $order_id,
						'status_from_remote'                 => $status_from_remote,
						'status_processing_allowed_in_store' => $this->status_processing_allowed_in_store ? 'TRUE' : 'FALSE',
						'error message'                      => $exception->getMessage(),
					], true ),
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
					print_r( [
						'result'             => $result,
						'order_id'           => $order_id,
						'status_from_remote' => $status_from_remote,
					], true ),
				) );


			throw new Exception( $this->db->last_error );
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
					print_r( [
						'result'   => $result,
						'order_id' => $order_id,
					], true ),
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
					print_r( [
						'message' => $exception->getMessage(),
					], true ),
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

		/*blue_media()->get_woocommerce_logger( $this->debug_id )->log_debug(
			sprintf( '[Order_Remote_Status] [status_processing_allowed_in_store flag is now %s for order_id: %s]',
				$status_processing_allowed_in_store ? 'TRUE' : 'FALSE',
				$order_id ),
		);*/

		$this->status_processing_allowed_in_store = $status_processing_allowed_in_store;
	}


}
