<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Group {
	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var string
	 */
	private $title;

	/**
	 * @var string|null
	 */
	private $shortDescription;

	/**
	 * @var string|null
	 */
	private $description;

	/**
	 * @var int
	 */
	private $order;

	/**
	 * @var string|null
	 */
	private $iconUrl;

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): void {
		$this->type = $type;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): void {
		$this->title = $title;
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

	public function getOrder(): int {
		return $this->order;
	}

	public function setOrder(int $order): void {
		$this->order = $order;
	}

	public function getIconUrl(): ?string {
		return $this->iconUrl;
	}

	public function setIconUrl(?string $iconUrl): void {
		$this->iconUrl = $iconUrl;
	}
}
