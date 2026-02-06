<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\CZK;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\EUR;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\GBP;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\HUF;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\PLN;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\RON;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object\USD;

class Currency {

	const SELECTED_CURRENCIES_OPT_KEY = 'selected_currencies';

	private static ?Currency_Interface $shop_currency = null;

	private static array $supported_currencies = [];
	private static array $selected_currencies = [];


	public function init() {
		$this->hooks();
		$this->handle();
		$this->migrate();

	}

	public function get_currency_by_el_id(
		string $id,
		?string $default_currency_code = null
	): ?Currency_Interface {
		/**
		 * @var Currency_Interface $currency
		 */
		foreach ( $this->get_supported_currencies() as $code => $currency ) {
			if ( $id === $currency->get_element_id() ) {
				return $currency;
			}
		}

		return $this->get_currency( (string) $default_currency_code );
	}

	public function get_currency( string $code ): ?Currency_Interface {
		if ( key_exists( $code, $this->get_supported_currencies() ) ) {
			return $this->get_supported_currencies()[ $code ];
		}

		return null;
	}

	public function add_currency_prefix(
		string $text,
		?string $currency_code = null
	): string {

		$prefix = $this->get_currency_element_id( $currency_code );

		if ( ! $prefix ) {
			return $text;
		}

		return $prefix . '_' . $text;
	}

	public function add_currency_postfix(
		string $text,
		?string $currency_code = null
	): string {

		$prefix = $this->get_currency_element_id( $currency_code );

		if ( ! $prefix ) {
			return $text;
		}

		return $text . '_' . $prefix;
	}

	public function get_currency_element_id(
		?string $currency_code = null
	): ?string {

		if ( ! $currency_code ) {
			$currency = $this->get_shop_currency();
		} else {
			$currency = $this->get_currency( $currency_code );
		}

		if ( ! $currency ) {
			return null;
		}

		return $currency->get_element_id();
	}

	/**
	 * @return Currency_Interface[]
	 */
	public function get_supported_currencies(): array {

		if ( empty( self::$supported_currencies ) ) {
			self::$supported_currencies = [
				Currency_Interface::CODE_PLN => new PLN(),
				Currency_Interface::CODE_EUR => new EUR(),
				Currency_Interface::CODE_HUF => new HUF(),
				Currency_Interface::CODE_CZK => new CZK(),
				Currency_Interface::CODE_RON => new RON(),
				Currency_Interface::CODE_USD => new USD(),
				Currency_Interface::CODE_GBP => new GBP(),
			];
		}

		return self::$supported_currencies;
	}

	private function add_currency_to_db( string $code ) {
		$currency = $this->get_currency_by_el_id( $code );

		if ( $currency ) {
			$selected_currencies = $this->get_selected_currencies();
			if ( key_exists( $code, $selected_currencies ) ) {
				return;
			}

			$selected_currencies[ $currency->get_code() ] = $currency;

			$result = [];

			foreach ( $selected_currencies as $k => $v ) {
				$result[] = $k;
			}

			blue_media()->update_autopay_option( self::SELECTED_CURRENCIES_OPT_KEY,
				$result );


			self::$selected_currencies = $selected_currencies;
		}
	}

	private function remove_currency_from_db( string $code ) {
		$currency = $this->get_currency( $code );

		if ( $currency ) {
			$selected_currencies = $this->get_selected_currencies();
			if ( key_exists( $code,
					$selected_currencies ) && count( $selected_currencies ) > 1 ) {
				unset( $selected_currencies[ $code ] );
			}

			foreach ( $selected_currencies as $k => $v ) {
				$result[] = $k;
			}

			blue_media()
				->update_autopay_option( self::SELECTED_CURRENCIES_OPT_KEY,
					$result );

			self::$selected_currencies = $selected_currencies;
		}
	}

	/**
	 * @return Currency_Interface[]
	 */
	public function get_selected_currencies(): array {

		if ( empty( self::$selected_currencies ) ) {
			$selected_currencies = blue_media()
				->get_autopay_option( self::SELECTED_CURRENCIES_OPT_KEY,
					"" );

			if ( empty( $selected_currencies ) || ! is_array( $selected_currencies ) ) {
				if ( null === $this->get_shop_currency() ) {
					return [];
				}

				self::$selected_currencies[ $this->get_shop_currency()
				                                 ->get_code() ] = $this->get_shop_currency();

				return self::$selected_currencies;
			}


			foreach ( $selected_currencies as $key ) {
				$currency = $this->get_currency( $key );
				if ( ! $currency ) {
					continue;
				}

				self::$selected_currencies[ $currency->get_code() ] = $currency;
			}
		}

		// Ensure there is at least one selected currency and the current shop currency (if supported) is present.
		// This prevents the gateway from disappearing after changing the WooCommerce currency to a supported one (e.g., USD/GBP).
		$shop_currency = $this->get_shop_currency();
		$changed       = false;

		// If empty, seed with shop currency (or PLN fallback if ever null).
		if ( empty( self::$selected_currencies ) && $shop_currency ) {
			self::$selected_currencies[ $shop_currency->get_code() ] = $shop_currency;
			$changed = true;
		}

		// If shop currency is supported but missing, add it to keep gateway active for current store currency.
		if ( $shop_currency && ! isset( self::$selected_currencies[ $shop_currency->get_code() ] ) ) {
			self::$selected_currencies[ $shop_currency->get_code() ] = $shop_currency;
			$changed = true;
		}

		if ( $changed ) {
			$codes = array_keys( self::$selected_currencies );
			blue_media()->update_autopay_option( self::SELECTED_CURRENCIES_OPT_KEY, $codes );
		}

		return self::$selected_currencies;
	}

	public function is_currency_selected( string $code ): bool {
		foreach ( $this->get_selected_currencies() as $key => $v ) {

			if ( $code !== $key ) {
				continue;
			}

			return true;
		}

		return false;
	}

	public function get_non_selected_currencies(): ?array {
		$selected_currencies = $this->get_selected_currencies();
		$all                 = $this->get_supported_currencies();
		$result              = null;

		foreach ( $all as $k => $v ) {
			if ( key_exists( $k, $selected_currencies ) ) {
				continue;
			}

			$result[ $k ] = $v;
		}

		return $result;
	}

	public function reconfigure( ?string $currency_code = null
	): ?Currency_Interface {
		$old = self::$shop_currency ? self::$shop_currency->get_code() : '';

		if ( $currency_code ) {
			$found = $this->get_currency( $currency_code );
			if ( $found ) {

				self::$shop_currency = $found;
				blue_media()->get_woocommerce_logger()->log_debug
				(
					sprintf( '[Currency] [reconfigured forced] [From: %s] [To: %s]',
						$old,
						$found->get_code()
					) );

				return $found;
			}
		}
		self::$shop_currency = null;

		return $this->get_shop_currency();
	}

	public function get_shop_currency(): ?Currency_Interface {
		if ( empty( self::$shop_currency ) ) {
			if ( ! function_exists( 'get_woocommerce_currency' ) ) {
				$woo_currency_code = Currency_Interface::CODE_PLN;
			} else {
				$woo_currency_code = get_woocommerce_currency();
			}

			if ( empty( $woo_currency_code ) || ! is_string( $woo_currency_code ) ) {
				$woo_currency_code = Currency_Interface::CODE_PLN;
			}

			$found = $this->get_currency( $woo_currency_code );

			if ( $found ) {
				self::$shop_currency = $found;
			}
		}

		return self::$shop_currency;
	}

	private function hooks() {

		if ( isset( $_GET['section'] ) && $_GET['section'] === 'bluemedia' ) {
			$request_id = $this->generate_unique_request_id();
			$nonce      = $this->generate_nonce( $request_id );

			add_filter( 'woocommerce_before_settings_checkout',
				function () use ( $request_id, $nonce ) {
					printf( '<form method="post" id="autopay_form_currency" action="%s" enctype="multipart/form-data">',
						esc_attr( admin_url( "admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=authentication",
						) ) );

					printf( '<input
			type="hidden"
			name="autopay_currency_edit[nonce]"
			value="%s"
		/>',
						$nonce );

					printf( '<input
			type="hidden"
			name="autopay_currency_edit[request_id]"
			value="%s"
		/>',
						$request_id );

					echo '<input
			type="hidden"
			name="autopay_currency_edit[currency_code]"
		/>';
					echo '<input
			type="hidden"
			name="autopay_currency_edit[action]"
		/>';
					echo '</form>';
				} );
		}

	}

	private function generate_nonce( string $unique_id ): string {
		$nonce = wp_create_nonce( 'autopay_currency_edit_' . $unique_id );
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[generate nonce] [%s] [%s]',
				'autopay_currency_edit_' . $unique_id,
				$nonce
			) );

		return $nonce;
	}

	private function generate_unique_request_id(): string {
		$unique_id = wp_generate_uuid4();
		set_transient( 'autopay_request_' . $unique_id, true, 3600 );

		return $unique_id;
	}


	private function migrate() {
		if ( ! empty( blue_media()->get_autopay_option( 'migrate_4_5' ) ) ) {
			return;
		}

		if ( $this->get_shop_currency() ) {
			$shop_currency = $this->get_shop_currency();

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Currency] [migrate] [$shop_currency: %s]',
					print_r( $shop_currency, true ),
				) );

			if ( 'PLN' !== $shop_currency->get_code() ) {

				$whitelabel       = blue_media()->get_autopay_option( 'whitelabel',
					'no' );
				$service_id       = blue_media()->get_autopay_option( 'service_id' );
				$test_service_id  = blue_media()->get_autopay_option( 'test_service_id' );
				$private_key      = blue_media()->get_autopay_option( 'private_key' );
				$test_private_key = blue_media()->get_autopay_option( 'test_private_key' );

				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Currency] [migrate] [migrate_4_5 matched options: %s]',
						print_r( [
							'whitelabel'       => $whitelabel,
							'service_id'       => $service_id,
							'test_service_id'  => $test_service_id,
							'private_key'      => $private_key,
							'test_private_key' => $test_private_key,

						], true ),
					) );

				blue_media()->update_autopay_option( $this->add_currency_postfix( 'whitelabel',
					$shop_currency->get_code() ),
					$whitelabel );

				blue_media()->update_autopay_option( $this->add_currency_postfix( 'service_id',
					$shop_currency->get_code() ),
					$service_id );

				blue_media()->update_autopay_option( $this->add_currency_postfix( 'test_service_id',
					$shop_currency->get_code() ),
					$test_service_id );

				blue_media()->update_autopay_option( $this->add_currency_postfix( 'private_key',
					$shop_currency->get_code() ),
					$private_key );

				blue_media()->update_autopay_option( $this->add_currency_postfix( 'test_private_key',
					$shop_currency->get_code() ),
					$test_private_key );


			}
			blue_media()->update_autopay_option( 'migrate_4_5', '1' );
		}


	}


	private function handle() {

		if ( isset( $_POST['autopay_currency_edit'] ) && is_array( $_POST['autopay_currency_edit'] ) ) {

			$params = $_POST['autopay_currency_edit'];

			$nonce         = $this->get_from_params( 'nonce', $params );
			$request_id    = $this->get_from_params( 'request_id', $params );
			$currency_code = $this->get_from_params( 'currency_code', $params );
			$action        = $this->get_from_params( 'action', $params );

			if ( ! $request_id ) {
				throw new Exception( 'Request id can not be empty' );
			}

			if ( ! get_transient( 'autopay_request_' . $request_id ) ) {
				throw new Exception( 'Nonce already used or expired' );
			}

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[verify nonce] [%s] [%s]',
					"autopay_currency_edit_{$nonce}_$request_id",
					$nonce
				) );

			if ( ! wp_verify_nonce( $nonce,
				"autopay_currency_edit_$request_id" ) ) {
				throw new Exception( 'Invalid nonce' );
			}

			if ( empty( $currency_code ) ) {
				throw new Exception( 'Invalid currency_code' );
			}

			if ( $action !== 'add' && $action !== 'remove' ) {
				throw new Exception( 'Invalid action' );
			}

			delete_transient( 'autopay_request_' . $request_id );

			if ( 'add' === $action ) {
				$this->handle_add( $currency_code );
			}

			if ( 'remove' === $action ) {
				$this->handle_remove( $currency_code );
			}
		}

	}

	private function get_from_params(
		string $key,
		array $array
	): ?string {
		return isset( $array[ $key ] ) ? sanitize_text_field( ( $array[ $key ] ) ) : null;
	}

	private function handle_add( string $currency_code ) {
		$this->add_currency_to_db( $currency_code );
	}

	private function handle_remove( string $currency_code ) {
		$this->remove_currency_from_db( $currency_code );


	}

}
