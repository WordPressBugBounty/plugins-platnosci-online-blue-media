<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\Settings;

class Woocomerce_Vas_Value_Object {

	const LANGUAGE_CODE_EN = 'en-EN';

	const LANGUAGE_CODE_PL = 'pl-PL';

	private $ecommerce;
	private $ecommerce_version;
	private $plugin_name;
	private $plugin_version;
	private $utm_campaign;
	private $utm_source;
	private $utm_medium;
	private $language_code;
	private ?string $service_id;

	private ?string $programming_language_version;

	public function __construct(
		$ecommerce,
		$ecommerce_version,
		$plugin_name,
		$plugin_version,
		$utm_campaign,
		$utm_source,
		$utm_medium,
		$language_code,
		?string $service_id,
		?string $programming_language_version
	) {
		$this->ecommerce                    = $ecommerce;
		$this->ecommerce_version            = $ecommerce_version;
		$this->plugin_name                  = $plugin_name;
		$this->plugin_version               = $plugin_version;
		$this->utm_campaign                 = $utm_campaign;
		$this->utm_source                   = $utm_source;
		$this->utm_medium                   = $utm_medium;
		$this->language_code                = $language_code;
		$this->service_id                   = $service_id;
		$this->programming_language_version = $programming_language_version;
	}

	public function get_ecommerce() {
		return $this->ecommerce;
	}

	public function get_ecommerce_version() {
		return $this->ecommerce_version;
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_plugin_version() {
		return $this->plugin_version;
	}

	public function get_utm_campaign() {
		return $this->utm_campaign;
	}

	public function get_utm_source() {
		return $this->utm_source;
	}

	public function get_utm_medium() {
		return $this->utm_medium;
	}

	public function get_language_code() {
		return $this->language_code;
	}

	public function get_service_id(): ?string {
		return $this->service_id;
	}

	public function get_programming_language_version(): ?string {
		return $this->programming_language_version;
	}
}
