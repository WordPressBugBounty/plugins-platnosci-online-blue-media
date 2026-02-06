<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response;

class View_Model_Group_Factory {

	public function create(
		Gateway_List_Response $gateway_list_response,
		bool $filter_for_cart = false
	): array {
		/** @var View_Model_Group[] $groups */
		$groups = [];

		if ( $gateway_list_response->getGatewayGroups() ) {
			foreach ( $gateway_list_response->getGatewayGroups() as $groupData ) {
				$viewModelGroup = new View_Model_Group();
				$viewModelGroup->setType( $groupData->getType() );
				$viewModelGroup->setTitle( $groupData->getTitle() );
				$viewModelGroup->setShortDescription( $groupData->getShortDescription() );
				$viewModelGroup->setDescription( $groupData->getDescription() );
				$viewModelGroup->setOrder( $groupData->getOrder() );
				$viewModelGroup->setIconUrl( $groupData->getIconUrl() );

				if ( $groupData->getType() === 'PBL' ) {
					$viewModelGroup->setToggled( true );

					$viewModelGroup->setIconUrl( blue_media()->get_plugin_images_url() . '/logo-group.svg' );
				}

				$groups[ $groupData->getType() ] = $viewModelGroup;
			}
		}


		if ( $gateway_list_response->getGatewayList() ) {
			foreach ( $gateway_list_response->getGatewayList() as $gateway ) {
				if ( $filter_for_cart ) {
					if ( ! self::check_amount_range( $gateway ) ) {
						continue;
					}
				}
				$groupType = $gateway->getGroupType();
				if ( isset( $groups[ $groupType ] ) ) {
					$groups[ $groupType ]->addGateway( $gateway );
				}
			}
		}

		// Business require is to join FR and PBL items
		if ( isset( $groups['FR'] ) && isset( $groups['PBL'] ) ) {
			foreach ( $groups['FR']->getGateways() as $gateway ) {
				$gateway->setGroupType( 'PBL' );
				$groups['PBL']->addGateway( $gateway );
			}
			unset( $groups['FR'] );
		}

		// Remove groups with no gateways
		$groups = array_filter( $groups, function ( View_Model_Group $group ) {
			return count( $group->getGateways() ) > 0;
		} );

		// Sort gateways within each group by order
		foreach ( $groups as $group ) {
			$gateways = $group->getGateways();
			usort( $gateways, function ( Gateway $a, Gateway $b ) {
				return $a->getOrder() <=> $b->getOrder();
			} );
			$group->setGateways( $gateways );
		}


		// Sort groups by order
		usort( $groups, function ( View_Model_Group $a, View_Model_Group $b ) {
			return $a->getOrder() <=> $b->getOrder();
		} );

		return array_values( $groups );
	}


	private function check_amount_range( Gateway $gateway_obj ): bool {
		if ( ! WC()->cart ) {
			return true;
		}

		$woocommerce_currency = get_woocommerce_currency();
		$woocommerce_cart     = WC()->cart;
		$cart_total           = (float) $woocommerce_cart->get_total( false );

		foreach ( $gateway_obj->getCurrencies() as $currency_info ) {

			if ( $currency_info->getCurrency() === $woocommerce_currency ) {

				$min_amount = $currency_info->getMinAmount();
				$max_amount = $currency_info->getMaxAmount();


				if ( $min_amount ) {
					if ( $cart_total < $min_amount ) {
						return false;
					}
				}

				if ( $max_amount ) {
					if ( $cart_total > $max_amount ) {
						return false;
					}
				}

				return true;
			}
		}

		return true;
	}
}
