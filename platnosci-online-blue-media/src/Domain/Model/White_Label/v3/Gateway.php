<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Gateway {
	/**
	 * @var int
	 */
	private $gatewayID;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var string|null
	 */
	private $groupType;

	/**
	 * @var string|null
	 */
	private $bankName;

	/**
	 * @var string|null
	 */
	private $iconUrl;

	/**
	 * @var string
	 */
	private $state;

	/**
	 * @var string|null
	 */
	private $stateDate;

	/**
	 * @var string|null
	 */
	private $shortDescription;

	/**
	 * @var string|null
	 */
	private $description;

	/**
	 * @var string|null
	 */
	private $descriptionUrl;

	/**
	 * @var string
	 */
	private $availableFor;

	/**
	 * @var string[]|null
	 */
	private $requiredParams;

	/**
	 * @var Mcc|null
	 */
	private $mcc;

    /**
     * @var bool|null
     */
    private $inBalanceAllowed;

    /**
     * @var int|null
     */
    private $minValidityTime;

    /**
     * @var int
     */
    private $order;

    /**
     * @var Currency[]
     */
    private $currencies;

    /**
     * @var string
     */
    private $buttonTitle;

	public function getGatewayID(): int {
		return $this->gatewayID;
	}

	public function setGatewayID(int $gatewayID): void {
		$this->gatewayID = $gatewayID;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function getGroupType(): ?string {
		return $this->groupType;
	}

	public function setGroupType(?string $groupType): void {
		$this->groupType = $groupType;
	}

	public function getBankName(): ?string {
		return $this->bankName;
	}

	public function setBankName(?string $bankName): void {
		$this->bankName = $bankName;
	}

	public function getIconUrl(): ?string {
		return $this->iconUrl;
	}

	public function setIconUrl(?string $iconUrl): void {
		$this->iconUrl = $iconUrl;
	}

	public function getState(): string {
		return $this->state;
	}

	public function setState(string $state): void {
		$this->state = $state;
	}

	public function getStateDate(): ?string {
		return $this->stateDate;
	}

	public function setStateDate(?string $stateDate): void {
		$this->stateDate = $stateDate;
	}

	public function getShortDescription(): ?string {
		return $this->shortDescription;
	}

	public function setShortDescription(?string $shortDescription): void {
		$this->shortDescription = $shortDescription;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function setDescription(?string $description): void {
		$this->description = $description;
	}

	public function getDescriptionUrl(): ?string {
		return $this->descriptionUrl;
	}

	public function setDescriptionUrl(?string $descriptionUrl): void {
		$this->descriptionUrl = $descriptionUrl;
	}

	public function getAvailableFor(): string {
		return $this->availableFor;
	}

	public function setAvailableFor(string $availableFor): void {
		$this->availableFor = $availableFor;
	}

	public function getRequiredParams(): ?array {
		return $this->requiredParams;
	}

	public function setRequiredParams(?array $requiredParams): void {
		$this->requiredParams = $requiredParams;
	}

	public function getMcc(): ?Mcc {
		return $this->mcc;
	}

	public function setMcc(?Mcc $mcc): void {
		$this->mcc = $mcc;
	}

    public function getInBalanceAllowed(): ?bool {
        return $this->inBalanceAllowed;
    }

    public function setInBalanceAllowed(?bool $inBalanceAllowed): void {
        $this->inBalanceAllowed = $inBalanceAllowed;
    }

    public function getMinValidityTime(): ?int {
        return $this->minValidityTime;
    }

    public function setMinValidityTime(?int $minValidityTime): void {
        $this->minValidityTime = $minValidityTime;
    }

    public function getOrder(): int {
        return $this->order;
    }

    public function setOrder(int $order): void {
        $this->order = $order;
    }

    /**
     * @return Currency[]
     */
    public function getCurrencies(): array {
        return $this->currencies;
    }

    /**
     * @param Currency[] $currencies
     */
    public function setCurrencies(array $currencies): void {
        $this->currencies = $currencies;
    }

    public function getButtonTitle(): string {
        return $this->buttonTitle;
    }

    public function setButtonTitle(string $buttonTitle): void {
        $this->buttonTitle = $buttonTitle;
    }
}
