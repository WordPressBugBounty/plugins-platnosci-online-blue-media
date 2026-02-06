<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Currency {
	/**
	 * @var string
	 */
	private $currency;

	/**
	 * @var float|null
	 */
	private $minAmount;

	/**
	 * @var float|null
	 */
	private $maxAmount;

	public function getCurrency(): string {
		return $this->currency;
	}

	public function setCurrency(string $currency): void {
		$this->currency = $currency;
	}

	public function getMinAmount(): ?float {
		return $this->minAmount;
	}

	public function setMinAmount(?float $minAmount): void {
		$this->minAmount = $minAmount;
	}

	public function getMaxAmount(): ?float {
		return $this->maxAmount;
	}

	public function setMaxAmount(?float $maxAmount): void {
		$this->maxAmount = $maxAmount;
	}
}
