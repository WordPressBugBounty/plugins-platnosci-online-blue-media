<?php

namespace Ilabs\BM_Woocommerce\Utilities\Test_Connection;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\PLN;

class Auditor {

	private string $id;
	private array $status;
	private array $log = [];
	private array $data = [];
	private ?string $stage_name = null;
	private bool $finished = false;
	private bool $failed = false;
	private bool $warning = false;
	private int $itn_expiry_time = 0;
	private bool $zip_not_found = false;
	private array $result = [];


	/**
	 * @param string $id
	 */
	private function __construct( string $id ) {
		$this->id = $id;
	}

	public function run(): array {
		$callables = self::get_config();
		foreach ( $this->status as $stage => $config ) {
			foreach ( $config as $k => $v ) {

				if ( 0 === $v || 2 === $v ) {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[Auditor] [run] [%s: %s]',
							0 === $v ? 'Begin new test: ' : 'Repeat test: ',
							(string) $this->stage_name
						) );

					$this->stage_name             = $this->get_stage_name( $stage );
					$this->status[ $stage ][ $k ] = 1;
					$this->save();

					if ( is_callable( $callables[ $stage ][ $k ] ) ) {
						$callableResult = $callables[ $stage ][ $k ]( $this );

						if ( $callableResult instanceof Log_Entry ) {
							blue_media()->get_woocommerce_logger()->log_debug(
								sprintf( '[Auditor] [run] [stage: %s] [Result: %s: %s]',
									$this->stage_name,
									$callableResult->get_level(),
									$callableResult->get_message()
								) );

							$this->result[] = $callables[ $stage ][ $k ]( $this );

							if ( $callableResult->get_level() === Log_Entry::LEVEL_CRITICAL ) {
								$this->failed = true;
								$this->save();
							}

							if ( $callableResult->get_level() === Log_Entry::LEVEL_WARNING ) {
								$this->warning = true;
								$this->save();
							}
						}

						if ( $this->failed ) {
							$this->finished = true;

							return $this->result;
						}

						if ( is_string( $callableResult ) && $callableResult === 'locked' ) {
							blue_media()->get_woocommerce_logger()->log_debug(
								sprintf( '[Auditor] [run] [stage: %s] [Result: locked]',
									$stage,
								) );

							$this->status[ $stage ][ $k ] = 2;
							$this->save();
						}
					}
				}
			}

			if ( $this->stage_name ) {
				return $this->result;
			}
		}

		$this->finished   = true;
		$this->stage_name = $stage;

		return $this->result;
	}

	public static function create_new(): Auditor {
		$unique      = md5( microtime() . rand() );
		$id          = substr( $unique, 0, 10 );
		$obj         = new self( $id );
		$obj->status = $obj->build_status();
		$obj->save();

		return $obj;
	}

	private function save() {
		set_transient( 'autopay_audit_' . $this->id, $this->to_array(), 600 );
	}

	public static function load( string $id ) {
		$status = get_transient( 'autopay_audit_' . $id );
		if ( false === $status ) {
			throw new Exception( 'Transient not exists' );
		}
		$obj                  = new self( $id );
		$obj->status          = $status['status'];
		$obj->log             = $status['log'];
		$obj->data            = $status['data'];
		$obj->failed          = $status['failed'];
		$obj->warning         = $status['warning'];
		$obj->itn_expiry_time = $status['itn_expiry_time'];

		return $obj;
	}

	public function get_id(): string {
		return $this->id;
	}

	private function build_status(): array {
		$status = [];
		foreach ( self::get_config() as $stage => $config ) {
			$status[ $stage ] = [];
			foreach ( $config as $k => $v ) {
				$status[ $stage ][ $k ] = 0;
			}
		}

		return $status;
	}

	private function to_array(): array {
		return [
			'id'              => $this->id,
			'status'          => $this->status,
			'log'             => $this->log,
			'data'            => $this->data,
			'failed'          => $this->failed,
			'warning'         => $this->warning,
			'itn_expiry_time' => $this->itn_expiry_time,
		];
	}

	public function get_stageName(): string {
		return $this->stage_name;
	}

	private static function get_config(): array {
		return [
			'server'                => [
				'php_version'     => function ( Auditor $auditor ) {
					return $auditor->test_php_version();
				},
				'php_extensions'  => function ( Auditor $auditor ) {
					return $auditor->test_php_extensions();
				},
				'https'           => function ( Auditor $auditor ) {
					return $auditor->test_https();
				},
				'internet'        => function ( Auditor $auditor ) {
					return $auditor->test_internet();
				},
				'wc_log_writable' => function ( Auditor $auditor ) {
					return $auditor->test_wc_log_writable();
				},
			],
			'wordpress'             => [
				'wp_version'      => function ( Auditor $auditor ) {
					return $auditor->test_wp_version();
				},
				'wc_version'      => function ( Auditor $auditor ) {
					return $auditor->test_wc_version();
				},
				'autopay_version' => function ( Auditor $auditor ) {
					return $auditor->test_autopay_version();
				},
				'autopay_old'     => function ( Auditor $auditor ) {
					return $auditor->test_autopay_old();
				},
				'third_party'     => function ( Auditor $auditor ) {
					return $auditor->test_third_party();
				},
			],
			'autopay_configuration' => [
				'credentials' => function ( Auditor $auditor ) {
					return $auditor->test_credentials();
				},
				'ping'        => function ( Auditor $auditor ) {
					return $auditor->test_ping();
				},
			],
			'transaction'           => [
				'blik_validation'   => function ( Auditor $auditor
				) {
					return $auditor->test_blik_validation();
				},
				'create_test_order' => function ( Auditor $auditor
				) {
					return $auditor->test_create_test_order();
				},
			],
		];
	}

	private function get_stage_name( string $stage_id ): string {
		$strings = [
			'transaction'           => __( "Transaction testing",
				"bm-woocommerce" ),
			'autopay_configuration' => __( "Autopay configuration testing",
				"bm-woocommerce" ),
			'server'                => __( "Server configuration testing",
				"bm-woocommerce" ),
			'wordpress'             => __( "Wordpress configuration testing",
				"bm-woocommerce" ),
		];

		if ( isset( $strings[ $stage_id ] ) ) {
			return $strings[ $stage_id ];
		}

		return __( "Server configuration testing",
			"bm-woocommerce" );
	}


	private function test_php_version() {
		$php_version = phpversion();
		if ( version_compare( $php_version, '7.4', '<' ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				sprintf( __( "PHP version %s is below the minimum required version 7.4. Please update your PHP installation.",
					"bm-woocommerce" ),
					$php_version )
			);
		} elseif ( version_compare( $php_version, '8.2', '>' ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				sprintf( __( "PHP version %s is higher than 8.2. Potential compatibility issues detected.",
					"bm-woocommerce" ),
					$php_version )
			);
		} else {
			return null;
		}
	}

	private function test_php_extensions() {
		$missing = [];
		if ( ! extension_loaded( 'xml' ) ) {
			$missing[] = 'XML';
		}
		if ( ! extension_loaded( 'curl' ) ) {
			$missing[] = 'CURL';
		}
		if ( ! extension_loaded( 'zip' ) ) {
			$this->zip_not_found = true;
			$missing[]           = 'PHP-ZIP';
		}
		if ( ! empty( $missing ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				sprintf( __( "Missing required PHP extensions: %s. Please install them.",
					"bm-woocommerce" ),
					implode( ', ',
						$missing ) ) );

		} else {
			return null;
		}
	}

	private function test_https() {
		if ( ! is_ssl() ) {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				__( "HTTPS is not enabled. Please secure your site with an SSL certificate.",
					"bm-woocommerce" )
			);
		} else {
			return null;
		}
	}

	private function test_wc_log_writable() {
		$log_dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads/wc-logs' : '' );
		if ( empty( $log_dir ) || ! is_writable( $log_dir ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				__( "WooCommerce log directory is not writable. Please check file permissions.",
					"bm-woocommerce" )
			);
		} else {
			return null;
		}
	}

	private function test_internet() {
		$connected = $this->ping_url( 'www.google.com', 5 );
		if ( $connected ) {
			return null;
		} else {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				__( "No internet connection detected. Please contact your hosting provider.",
					"bm-woocommerce" )
			);
		}
	}

	private function test_wc_version() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return null;
		}
		$version  = WC_VERSION;
		$warnings = [];
		if ( version_compare( $version, '8.1', '<' ) ) {
			$warnings[] = sprintf( __( "Detected unsupported WooCommerce version (%s). We recommend updating to version 8.1 or higher.",
				"bm-woocommerce" ),
				$version );
		}
		if ( stripos( $version, 'beta' ) !== false ) {
			$warnings[] = sprintf( __( "Beta version of WooCommerce (%s) detected. It might not be fully supported.",
				"bm-woocommerce" ),
				$version );
		}
		if ( ! empty( $warnings ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				implode( " ", $warnings )
			);
		} else {
			return null;
		}
	}

	private function test_wp_version() {
		$wp_version = get_bloginfo( 'version' );

		$warnings = [];
		if ( version_compare( $wp_version, '6.0', '<' ) ) {
			$warnings[] = sprintf( __( "Detected unsupported WordPress version (%s). We recommend updating to version 6.0 or higher.",
				"bm-woocommerce" ),
				$wp_version );
		}
		if ( stripos( $wp_version, 'beta' ) !== false ) {
			$warnings[] = sprintf( __( "Beta version of WordPress (%s) detected. It might not be fully supported.",
				"bm-woocommerce" ),
				$wp_version );
		}
		if ( ! empty( $warnings ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				implode( " ", $warnings )
			);
		} else {
			return null;
		}
	}

	private function test_autopay_version() {
		$current_version = blue_media()->get_plugin_version();
		$remote_url      = 'https://api.wordpress.org/plugins/info/1.0/platnosci-online-blue-media.json';
		$response        = wp_remote_get( $remote_url, [ 'timeout' => 5 ] );
		if ( is_wp_error( $response ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_INFO,
				Log_Entry::get_header_info(),
				__( "Unable to fetch the latest Autopay plugin version from the WordPress repository.",
					"bm-woocommerce" )
			);
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		if ( empty( $data ) || empty( $data->version ) ) {
			return null;
		}
		$latest_version = $data->version;
		if ( version_compare( $current_version, $latest_version, '<' ) ) {
			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				sprintf( __( "An update is available for the Autopay plugin. Current version: %s, latest version: %s.",
					"bm-woocommerce" ),
					$current_version,
					$latest_version )
			);
		} else {
			return null;
		}
	}

	private function test_autopay_old() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, 'bluepayment' ) !== false ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return new Log_Entry(
						Log_Entry::LEVEL_CRITICAL,
						Log_Entry::get_header_warning(),
						__( "Old BlueMedia plugin is active. Please remove it or deactivate it.",
							"bm-woocommerce" )
					);
				} else {
					return null;
				}
			}
		}

		return null;
	}

	private function test_third_party() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, 'pay-wp' ) !== false ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return new Log_Entry(
						Log_Entry::LEVEL_CRITICAL,
						Log_Entry::get_header_critical(),
						__( "Conflicting third-party plugin 'pay-wp' is active. Please deactivate it to avoid conflicts with Autopay.",
							"bm-woocommerce" )
					);
				}
			}
		}

		return null;
	}

	private function test_credentials() {
		$return     = [];
		$bm_gateway = blue_media()->get_blue_media_gateway();

		$currency_manager  = blue_media()->get_currency_manager();
		$active_currencies = $currency_manager->get_selected_currencies();

		if ( empty( $active_currencies ) ) {
			return new Log_Entry( Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				__( "No configured currencies found",
					"bm-woocommerce" ) );
		}

		foreach ( $active_currencies as $currency ) {
			$currency_manager->reconfigure( $currency->get_code() );
			$bm_gateway->setup_variables( $currency );
			$service_id = $bm_gateway->get_service_id();
			$hash       = $bm_gateway->get_private_key();


			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Auditor] [test_credentials] [currency: %s] [shop currency: %s] [service_id: %s]',
					$currency->get_code(),
					blue_media()->resolve_blue_media_currency_symbol(),
					$service_id
				) );

			if ( empty( $service_id ) ) {
				$currency_manager->reconfigure( $currency->get_code() );
				$bm_gateway->setup_variables();

				return new Log_Entry( Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					sprintf( __( "ServiceID was not provided for currency: %s",
						"bm-woocommerce" ),
						$currency->get_code() ) );
			}

			if ( ! is_numeric( $service_id ) ) {
				$currency_manager->reconfigure( $currency->get_code() );
				$bm_gateway->setup_variables();

				return new Log_Entry( Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					sprintf( __( "An invalid ServiceID was provided for currency: %s",
						"bm-woocommerce" ),
						$currency->get_code() ) );
			}

			if ( empty( $hash ) ) {
				$currency_manager->reconfigure( $currency->get_code() );
				$bm_gateway->setup_variables();

				return new Log_Entry( Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					sprintf( __( "API secret key (Hash) was not provided for currency: %s",
						"bm-woocommerce" ),
						$currency->get_code() ) );
			}
		}

		$currency_manager->reconfigure( $currency->get_code() );
		$bm_gateway->setup_variables();

		return null;
	}

	private function test_ping() {
		$url       = 'www.autopay.pl';
		$connected = $this->ping_url( $url, 5 );
		if ( $connected ) {
			return null;
		} else {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				$url . ' ' . __( "Autopay URL cannot be reached. Make sure you have a valid internet connection",
					"bm-woocommerce" )
			);
		}
	}

	private function test_blik_validation() {
		$bm_gateway        = blue_media()->get_blue_media_gateway();
		$currency_manager  = blue_media()->get_currency_manager();
		$active_currencies = $currency_manager->get_selected_currencies();
		$pln_channels      = [];

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Auditor] [test_blik_validation] [found active currencies: %s]',
				print_r( array_keys( $active_currencies ), true ),

			) );

		foreach ( $active_currencies as $currency ) {
			$currency_manager->reconfigure( $currency->get_code() );
			$bm_gateway->setup_variables( $currency );

			try {
				$channels = $bm_gateway->gateway_list( true,
					$currency->get_code() );
			} catch ( Exception $e ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Auditor] [test_blik_validation] [get gateway_list error: %s]',
						print_r( $e->getMessage(), true ),

					) );
				$channels = [];
			}
			$currency_manager->reconfigure();

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Auditor] [test_blik_validation] [found channels: %s] [for currency: %s]',
					print_r( $channels, true ),
					print_r( $currency->get_code(), true ),

				) );


			if ( empty( $channels ) ) {
				return new Log_Entry(
					Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					sprintf( __( "Failed to download payment channel list for currency: %s. Check the validity of the provided key (hash) and serviceID",
						"bm-woocommerce" ),
						$currency->get_code(),
						true )
				);
			}

			if ( $currency->get_code() === 'PLN' ) {
				$pln_channels = $channels;
			} else {
				$this->result[] = new Log_Entry(
					Log_Entry::LEVEL_WARNING,
					Log_Entry::get_header_warning(),
					sprintf( __( "Transaction test is not available for currency: %s",
						"bm-woocommerce" ),
						$currency->get_code() ),

				);
				$this->warning  = true;
			}
		}

		$currency_manager->reconfigure();

		if ( ! key_exists( 'PLN', $active_currencies ) ) {
			$this->finished = true;

			return new Log_Entry(
				Log_Entry::LEVEL_WARNING,
				Log_Entry::get_header_warning(),
				__( "Transaction test is not available for your currency configuration",
					"bm-woocommerce" )
			);
		}


		$this->data['blik0found'] = false;
		foreach ( $pln_channels as $channel ) {
			if ( is_object( $channel ) && ! empty( $channel->gatewayID ) ) {
				if ( (int) $channel->gatewayID === 509 ) {
					$this->data['blik0found'] = true;
					break;
				}
			}
		}

		$this->save();

		if ( $this->data['blik0found'] === true ) {
			return null;
		} else {
			return new Log_Entry(
				Log_Entry::LEVEL_CRITICAL,
				Log_Entry::get_header_critical(),
				__( "Blik-0 payment channel not found, transaction test will not be performed",
					"bm-woocommerce" )
			);
		}
	}


	private function test_create_test_order() {
		$currency_manager = blue_media()->get_currency_manager();
		$bm_gateway       = blue_media()->get_blue_media_gateway();
		$pln              = ( new PLN() );
		$currency_manager->reconfigure( $pln->get_code() );
		$bm_gateway->setup_variables( $pln );


		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Auditor] [test_create_test_order] [ServiceID: %s]',
				print_r( $bm_gateway->get_service_id(), true ),
			) );

		$transaction_testing_controller = new Transaction_Testing_Controller();
		if ( ! isset( $this->data['test_order_id'] ) ) {
			$order_id = $transaction_testing_controller->execute_request_initialize();

			$current_time          = time();
			$this->itn_expiry_time = $current_time + 30;

			if ( $order_id instanceof Log_Entry ) {
				return $order_id;
			}

			if ( (int) $order_id > 0 ) {
				$this->data['test_order_id'] = $order_id;
				$this->save();

				return 'locked';
			}

			$this->save();
		} else {
			$order_id = $this->data['test_order_id'];

			if ( time() > $this->itn_expiry_time ) {

				if ( (int) $order_id > 0 ) {
					$order_creator = new Order_Creator();
					$order_creator->remove( $order_id );

					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[Auditor] [test_create_test_order] [test order removed: %s]',
							print_r( $order_id, true ),
						) );
				}

				return new Log_Entry(
					Log_Entry::LEVEL_CRITICAL,
					Log_Entry::get_header_critical(),
					__( "During the test transaction, the ITN (Instant Transaction Notification) message was not received within 30 seconds. Please verify the correctness of the URL where the ITN is sent. You can find it in the Autopay merchant panel.",
						"bm-woocommerce" )
				);
			}


			$test_itn_result               = $transaction_testing_controller->execute_request_verify_itn( $order_id );
			$this->data['test_itn_result'] = $test_itn_result ? 1 : 0;
			$this->save();
		}

		if ( $test_itn_result ) {
			return null;
		}

		return 'locked';

	}

	public function get_status(): array {
		return $this->status;
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function is_failed(): bool {
		return $this->failed;
	}

	public function is_warning(): bool {
		return $this->warning;
	}

	function ping_url( string $url, int $timeout_seconds = 25 ): bool {

		$connected = @fsockopen( $url, 80 ); //website, port  (try 80 or 443)
		if ( $connected ) {
			fclose( $connected );

			return true;
		}

		return false;
	}

	public function is_zip_not_found(): bool {
		return $this->zip_not_found;
	}
}
