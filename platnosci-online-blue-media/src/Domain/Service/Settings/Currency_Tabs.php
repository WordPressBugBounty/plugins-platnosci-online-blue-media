<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Currency;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class Currency_Tabs {

	private Currency $currency_manager;


	private static string $active_tab_id;
	private static Currency_Interface $active_tab_currency;

	public function __construct() {
		$this->currency_manager = blue_media()->get_currency_manager();
	}


	public function get_active_tab_id(): string {
		if ( empty( self::$active_tab_id ) ) {

			$active_tab_id = isset( $_GET['cur'] ) ? sanitize_text_field( $_GET['cur'] ) : $this->get_default_tab_id();

			$sel = $this->currency_manager->get_selected_currencies();

			foreach ( $sel as $currency ) {
				if ( $currency->get_element_id() === $active_tab_id ) {
					self::$active_tab_id = $active_tab_id;

					return self::$active_tab_id;
				}
			}

			self::$active_tab_id = $sel[ array_key_first( $sel ) ]->get_element_id();
		}

		return self::$active_tab_id;
	}

	public function get_active_tab_position(): int {
		$active_tab_code     = $this->get_active_tab_currency()->get_code();
		$selected_currencies = $this->get_currency_manager()
		                            ->get_selected_currencies();

		$i = 0;
		foreach ( $selected_currencies as $k => $v ) {
			if ( $k === $active_tab_code ) {
				return $i;
			}
			$i ++;
		}

		return 0;
	}

	public function get_active_tab_currency(): Currency_Interface {
		if ( empty( self::$active_tab_currency ) ) {

			self::$active_tab_currency = $this->currency_manager->get_currency_by_el_id(
				$this->get_active_tab_id(),
				$this->get_default_tab_currency()->get_code() );
		}


		return self::$active_tab_currency;
	}

	/**
	 * @return Currency_Interface[]
	 */
	public function get_tabs(): array {

		return $this->currency_manager->get_selected_currencies();
	}

	/**
	 * @return null | Currency_Interface[]
	 */
	public function get_available_tabs(): ?array {

		return $this->currency_manager->get_non_selected_currencies();
	}

	private function get_default_tab_currency(): Currency_Interface {
		return $this->currency_manager->get_shop_currency();
	}

	private function get_default_tab_id(): string {

		return $this->get_default_tab_currency()->get_element_id();
	}


	public function get_currency_manager(): Currency {
		return $this->currency_manager;
	}
}
