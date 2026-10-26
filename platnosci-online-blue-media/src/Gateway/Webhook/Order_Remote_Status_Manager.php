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

	/**
	 * Transitional state: SUCCESS accepted, WooCommerce side effects in progress. Stored
	 * as "SUCCESS_APPLYING:<unix timestamp>"; switched to plain SUCCESS by
	 * finalize_success() only after the effects succeeded.
	 */
	const STATUS_SUCCESS_APPLYING = 'SUCCESS_APPLYING';

	/**
	 * Age after which a SUCCESS_APPLYING claim is considered abandoned (crashed run) and
	 * may be taken over by a retry. Consistent with
	 * Attempt_State_Store::SIDE_EFFECTS_CLAIM_TIMEOUT_SECONDS in the recurring flow.
	 */
	const SUCCESS_APPLYING_TAKEOVER_SECONDS = 120;

	const RESULT_CONFIRMED = 'CONFIRMED';

	const RESULT_NOTCONFIRMED = 'NOTCONFIRMED';

	const PATH_VERSION = '1';

	private $db;

	private $debug_id = 'bm_woocommerce_itn';

	private bool $status_processing_allowed_in_store = false;

	public function __construct() {
		global $wpdb;
		$this->db                  = $wpdb;
		$this->db->show_errors     = false;
		$this->db->suppress_errors = false;
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
		// Defence in depth: reject an empty/unknown status before the table is touched,
		// so a garbage value can never overwrite the mutex row. Strict in_array() on
		// purpose -- empty() would also be true for '0'.
		if ( ! in_array(
			$status_from_remote,
			[
				self::STATUS_SUCCESS,
				self::STATUS_PENDING,
				self::STATUS_FAILURE,
				self::STATUS_PROCESS_PAYMENT,
				self::STATUS_TEST_CONNECTION,
				self::STATUS_SUCCESS_APPLYING,
			],
			true
		) ) {
			$this->set_status_processing_allowed_in_store( false );

			blue_media()->get_woocommerce_logger( $this->debug_id )->log_warning(
				sprintf(
					'[Order_Remote_Status] [update_order_status] [rejected: unsupported status] [%s]',
					wp_json_encode(
						[
							'order_id'           => $order_id,
							'status_from_remote' => $status_from_remote,
						]
					)
				)
			);

			return self::RESULT_NOTCONFIRMED;
		}

		$table_name = $this->get_table_name_prefixed();

		// Short lock wait ONLY for the mutex-table work below; the previous session value
		// is restored in `finally`, so the rest of the request (e.g. WooCommerce writes in
		// payment_complete()) keeps the server default.
		$previous_timeout = $this->db->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );

		if ( null === $previous_timeout ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_warning(
				sprintf(
					'[Order_Remote_Status] [update_order_status] [could not read innodb_lock_wait_timeout, it will not be restored] [order_id: %d]',
					$order_id
				)
			);
		}

		$this->db->query( 'SET SESSION innodb_lock_wait_timeout = 1' );

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

			// Parse once: "SUCCESS_APPLYING:<ts>" -> clean state + timestamp (0 when there is
			// no ":"). Every gate below compares the CLEAN state, never the raw column value.
			list( $current_state, $current_state_since ) = $this->parse_status( (string) $current_status );

			if ( $current_state === $status_from_remote ) {
				$this->set_status_processing_allowed_in_store( false );
				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [path: ALREADY_PROCESSED - current_status and status_from_remote are identical. Doing rollback.] [order_id: %s] [return code: RESULT_CONFIRMED]',
							$order_id ) );
				$this->db->query( 'ROLLBACK' );

				return self::RESULT_CONFIRMED;
			}

			if ( self::STATUS_SUCCESS === $status_from_remote ) {
				$now = time();

				if ( self::STATUS_SUCCESS_APPLYING === $current_state
					&& ( $now - $current_state_since ) < self::SUCCESS_APPLYING_TAKEOVER_SECONDS
				) {
					// Another run is applying the SUCCESS side effects right now -- never
					// enter them concurrently.
					$this->set_status_processing_allowed_in_store( false );
					blue_media()
						->get_woocommerce_logger( $this->debug_id )
						->log_debug(
							sprintf( '[Order_Remote_Status] [path: SUCCESS_APPLYING in progress - not entering side effects concurrently. Doing rollback] [order_id: %s] [return code: RESULT_CONFIRMED]',
								$order_id
							) );
					$this->db->query( 'ROLLBACK' );

					return self::RESULT_CONFIRMED;
				}

				// First SUCCESS processing, or takeover of an abandoned SUCCESS_APPLYING
				// claim. Plain SUCCESS is written only by finalize_success(), after the
				// side effects succeeded.
				$this->update_to_db(
					$order_id,
					self::STATUS_SUCCESS_APPLYING . ':' . $now
				);

				$this->db->query( 'COMMIT' );
				$this->set_status_processing_allowed_in_store( true );

				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [path: SUCCESS_APPLYING claimed] [order_id: %s] [from: %s] [takeover: %s] [return code: RESULT_CONFIRMED]',
							$order_id,
							(string) $current_status,
							self::STATUS_SUCCESS_APPLYING === $current_state ? 'yes' : 'no'
						) );

				return self::RESULT_CONFIRMED;
			}

			// A completed or in-progress SUCCESS is never reverted by a late PENDING/FAILURE.
			if ( in_array( $current_state, [ self::STATUS_SUCCESS, self::STATUS_SUCCESS_APPLYING ], true ) ) {
				$this->set_status_processing_allowed_in_store( false );
				blue_media()
					->get_woocommerce_logger( $this->debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status] [path: ALREADY_PROCESSED - current_status %s is unchangeable. Doing rollback] [order_id: %s] [return code: RESULT_CONFIRMED]',
							$current_state,
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

		} finally {
			// Runs on every exit of the try/catch above: COMMIT, each ROLLBACK + return,
			// the catch, and the normal end before the final return below.
			if ( null !== $previous_timeout ) {
				$this->db->query(
					$this->db->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', (int) $previous_timeout )
				);
			}
		}

		return self::RESULT_CONFIRMED;
	}

	/**
	 * Switches the row from "SUCCESS_APPLYING:<ts>" to plain SUCCESS once the SUCCESS
	 * side effects have been applied. A row that is already SUCCESS counts as success
	 * (another run finished first); any other state is left untouched and logged.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return bool True when the row is SUCCESS after this call.
	 * @throws Exception Internally on a DB error; always caught here (returns false).
	 */
	public function finalize_success( int $order_id ): bool {
		$table_name = $this->get_table_name_prefixed();

		// Same scoped short lock wait as update_order_status().
		$previous_timeout = $this->db->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );

		if ( null === $previous_timeout ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_warning(
				sprintf(
					'[Order_Remote_Status] [finalize_success] [could not read innodb_lock_wait_timeout, it will not be restored] [order_id: %d]',
					$order_id
				)
			);
		}

		$this->db->query( 'SET SESSION innodb_lock_wait_timeout = 1' );

		try {
			$this->db->query( 'START TRANSACTION' );

			$current_status = $this->db->get_var(
				$this->db->prepare( "SELECT status FROM $table_name WHERE order_id = %d FOR UPDATE",
					$order_id ) );

			if ( $this->db->last_error !== '' ) {
				throw new Exception( $this->db->last_error );
			}

			list( $current_state ) = $this->parse_status( (string) $current_status );

			if ( self::STATUS_SUCCESS === $current_state ) {
				$this->db->query( 'ROLLBACK' );

				return true;
			}

			if ( self::STATUS_SUCCESS_APPLYING !== $current_state ) {
				$this->db->query( 'ROLLBACK' );

				blue_media()->get_woocommerce_logger( $this->debug_id )->log_warning(
					sprintf( '[Order_Remote_Status] [finalize_success] [unexpected current status, not overwritten] [%s]',
						wp_json_encode( [
							'order_id'       => $order_id,
							'current_status' => $current_status,
						] ),
					) );

				return false;
			}

			$this->update_to_db(
				$order_id,
				self::STATUS_SUCCESS
			);

			$this->db->query( 'COMMIT' );

			blue_media()
				->get_woocommerce_logger( $this->debug_id )
				->log_debug(
					sprintf( '[Order_Remote_Status] [finalize_success] [path: FINALIZED] [order_id: %s] [from: %s] [to: %s]',
						$order_id,
						(string) $current_status,
						self::STATUS_SUCCESS
					) );

			return true;
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_error(
				sprintf( '[Order_Remote_Status] [finalize_success] [can\'t finalize] [%s]',
					wp_json_encode( [
						'order_id'      => $order_id,
						'error message' => $exception->getMessage(),
					] ),
				) );

			$this->db->query( 'ROLLBACK' );

			return false;
		} finally {
			if ( null !== $previous_timeout ) {
				$this->db->query(
					$this->db->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', (int) $previous_timeout )
				);
			}
		}
	}

	/**
	 * Splits a raw status column value into its clean state and timestamp:
	 * "SUCCESS_APPLYING:1760700000" -> [ 'SUCCESS_APPLYING', 1760700000 ],
	 * "PENDING" -> [ 'PENDING', 0 ].
	 *
	 * @param string $raw_status Raw `status` column value.
	 *
	 * @return array{0: string, 1: int}
	 */
	private function parse_status( string $raw_status ): array {
		$parts = explode( ':', $raw_status, 2 );

		return [ $parts[0], isset( $parts[1] ) ? (int) $parts[1] : 0 ];
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

	/**
	 * Read-only check whether the order's Autopay payment is paid (SUCCESS) or being
	 * finalized (SUCCESS_APPLYING). Used by the unpaid-order cancel cron, so it opens no
	 * transaction, takes no row lock and does not touch innodb_lock_wait_timeout.
	 *
	 * Fail-safe: a DB read error returns true (protect the order from cancellation).
	 * A missing row returns false (cancellation allowed, as before).
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return bool
	 * @throws Exception On database read error.
	 */
	public function is_success_or_applying( int $order_id ): bool {
		$table_name = $this->get_table_name_prefixed();

		try {
			$result = $this->db->get_var(
				$this->db->prepare( "SELECT status FROM $table_name WHERE order_id = %d",
					$order_id )
			);

			if ( '' !== $this->db->last_error ) {
				throw new Exception( $this->db->last_error );
			}
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger( $this->debug_id )->log_warning(
				sprintf( '[Order_Remote_Status] [is_success_or_applying] [could not read remote status, protecting order from cancellation] [%s]',
					wp_json_encode( [
						'order_id' => $order_id,
						'message'  => $exception->getMessage(),
					] ),
				) );

			return true;
		}

		if ( ! is_string( $result ) ) {
			return false;
		}

		list( $current_state ) = $this->parse_status( $result );

		return in_array( $current_state, [ self::STATUS_SUCCESS, self::STATUS_SUCCESS_APPLYING ], true );
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
