<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Mcc {
	/**
	 * @var int[]|null
	 */
	private $allowed;

	/**
	 * @var int[]|null
	 */
	private $disallowed;

	/**
	 * @return int[]|null
	 */
	public function getAllowed(): ?array {
		return $this->allowed;
	}

	/**
	 * @param int[]|null $allowed
	 */
	public function setAllowed( ?array $allowed ): void {
		$this->allowed = $allowed;
	}

	/**
	 * @return int[]|null
	 */
	public function getDisallowed(): ?array {
		return $this->disallowed;
	}

	/**
	 * @param int[]|null $disallowed
	 */
	public function setDisallowed( ?array $disallowed ): void {
		$this->disallowed = $disallowed;
	}
}
