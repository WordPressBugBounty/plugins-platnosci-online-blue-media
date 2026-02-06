<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3;

class Gateway_List_Response_Factory {

	public function create(array $data): Gateway_List_Response {
		$response = new Gateway_List_Response();

		$response->setResult($data['result'] ?? '');
		$response->setErrorStatus($data['errorStatus'] ?? null);
		$response->setDescription($data['description'] ?? null);
		$response->setServiceID($data['serviceID'] ?? '');
		$response->setMessageID($data['messageID'] ?? '');

		if (!empty($data['gatewayGroups'])) {
			$response->setGatewayGroups($this->createGatewayGroups($data['gatewayGroups']));
		}

		if (!empty($data['gatewayList'])) {
			$response->setGatewayList($this->createGatewayList($data['gatewayList']));
		}

		return $response;
	}

	private function createGatewayGroups(array $groupsData): array {
		$groups = [];
		foreach ($groupsData as $groupData) {
			$group = new Group();
			$group->setType($groupData['type'] ?? '');
			$group->setTitle($groupData['title'] ?? '');
			$group->setShortDescription($groupData['shortDescription'] ?? null);
			$group->setDescription($groupData['description'] ?? null);
			$group->setOrder($groupData['order'] ?? 0);
			$group->setIconUrl($groupData['iconUrl'] ?? null);
			$groups[] = $group;
		}
		return $groups;
	}

	private function createGatewayList(array $gatewaysData): array {
		$gateways = [];
		foreach ($gatewaysData as $gatewayData) {
			$gateway = new Gateway();
			$gateway->setGatewayID($gatewayData['gatewayID'] ?? 0);
			$gateway->setName($gatewayData['name'] ?? '');
			$gateway->setGroupType($gatewayData['groupType'] ?? null);
			$gateway->setBankName($gatewayData['bankName'] ?? null);
			$gateway->setIconUrl($gatewayData['iconUrl'] ?? $gatewayData['iconURL'] ?? null);
			$gateway->setState($gatewayData['state'] ?? '');
			$gateway->setStateDate($gatewayData['stateDate'] ?? null);
			$gateway->setShortDescription($gatewayData['shortDescription'] ?? null);
			$gateway->setDescription($gatewayData['description'] ?? null);
			$gateway->setDescriptionUrl($gatewayData['descriptionUrl'] ?? null);
			$gateway->setAvailableFor($gatewayData['availableFor'] ?? '');
			$gateway->setRequiredParams($gatewayData['requiredParams'] ?? null);
            $gateway->setInBalanceAllowed($gatewayData['inBalanceAllowed'] ?? null);
            $gateway->setMinValidityTime($gatewayData['minValidityTime'] ?? null);
            $gateway->setOrder($gatewayData['order'] ?? 0);
            $gateway->setButtonTitle($gatewayData['buttonTitle'] ?? '');

			if (!empty($gatewayData['mcc'])) {
				$gateway->setMcc($this->createMcc($gatewayData['mcc']));
			}

			if (!empty($gatewayData['currencies'])) {
				$gateway->setCurrencies($this->createCurrencies($gatewayData['currencies']));
			}

			$gateways[] = $gateway;
		}
		return $gateways;
	}

	private function createMcc(array $mccData): Mcc {
		$mcc = new Mcc();
		$mcc->setAllowed($mccData['allowed'] ?? null);
		$mcc->setDisallowed($mccData['disallowed'] ?? null);
		return $mcc;
	}

	private function createCurrencies(array $currenciesData): array {
		$currencies = [];
		foreach ($currenciesData as $currencyData) {
			$currency = new Currency();
			$currency->setCurrency($currencyData['currency'] ?? '');
			$currency->setMinAmount($currencyData['minAmount'] ?? null);
			$currency->setMaxAmount($currencyData['maxAmount'] ?? null);
			$currencies[] = $currency;
		}
		return $currencies;
	}
}
