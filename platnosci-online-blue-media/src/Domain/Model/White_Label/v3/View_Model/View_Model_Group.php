<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Group;

class View_Model_Group extends Group {
	/**
	 * @var Gateway[]
	 */
	private $gateways = [];

	/**
	 * @var bool
	 */
	private $toggled = false;

	/**
	 * @return Gateway[]
	 */
	public function getGateways(): array {
		return $this->gateways;
	}

	/**
	 * @param Gateway[] $gateways
	 */
	public function setGateways( array $gateways ): void {
		$this->gateways = $gateways;
	}

	public function addGateway( Gateway $gateway ): void {
		$this->gateways[] = $gateway;
	}

	public function isToggled(): bool {
		return $this->toggled;
	}

	public function setToggled( bool $toggled ): void {
		$this->toggled = $toggled;
	}
}
