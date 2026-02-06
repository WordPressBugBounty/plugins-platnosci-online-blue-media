<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Gateway_List_Response {
	/**
	 * @var string
	 */
	private $result;

	/**
	 * @var string|null
	 */
	private $errorStatus;

	/**
	 * @var string|null
	 */
	private $description;

	/**
	 * @var Group[]
	 */
	private $gatewayGroups;

	/**
	 * @var string
	 */
	private $serviceID;

	/**
	 * @var string
	 */
	private $messageID;

	/**
	 * @var Gateway[]|null
	 */
	private $gatewayList;

	public function getResult(): string {
		return $this->result;
	}

	public function setResult(string $result): void {
		$this->result = $result;
	}

	public function getErrorStatus(): ?string {
		return $this->errorStatus;
	}

	public function setErrorStatus(?string $errorStatus): void {
		$this->errorStatus = $errorStatus;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function setDescription(?string $description): void {
		$this->description = $description;
	}

	/**
	 * @return \Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Group[]
	 */
	public function getGatewayGroups(): array {
		return $this->gatewayGroups;
	}

	/**
	 * @param \Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Group[] $gatewayGroups
	 */
	public function setGatewayGroups(array $gatewayGroups): void {
		$this->gatewayGroups = $gatewayGroups;
	}

	public function getServiceID(): string {
		return $this->serviceID;
	}

	public function setServiceID(string $serviceID): void {
		$this->serviceID = $serviceID;
	}

	public function getMessageID(): string {
		return $this->messageID;
	}

	public function setMessageID(string $messageID): void {
		$this->messageID = $messageID;
	}

	/**
	 * @return \Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway[]|null
	 */
	public function getGatewayList(): ?array {
		return $this->gatewayList;
	}

	/**
	 * @param \Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway[]|null $gatewayList
	 */
	public function setGatewayList(?array $gatewayList): void {
		$this->gatewayList = $gatewayList;
	}
}
