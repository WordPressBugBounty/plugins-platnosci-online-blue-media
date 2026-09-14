<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Gateway_List;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway as View_Model_Gateway;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model\View_Model_Group;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model\View_Model_Group_Factory;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;

class Gateway_List_Mapper_Block_Checkout {

	private const SPLIT_GROUP_SLUGS = [
		'wallet',     // Apple Pay / Google Pay (legacy gatewayList groupType).
		'apple_pay',  // Apple Pay (gatewayList groupType APPLE_PAY).
		'google_pay', // Google Pay (gatewayList groupType GOOGLE_PAY).
		'bnpl',       // Kup teraz, zapłać później / PayPo.
		'fr',         // Volkswagen / SGB / Other banks.
	];

	/**
	 * @var Gateway_List_Response
	 */
	private Gateway_List_Response $gateway_list_response;

	private array $gpay_form_data = [];

	/**
	 * When false, Google Pay (channel 1512) is omitted from the block checkout channel list.
	 */
	private bool $offer_google_pay_on_checkout = true;

	public function __construct(
		Gateway_List_Response $gateway_list_response,
		array $gpay_form_data = [],
		bool $offer_google_pay_on_checkout = true
	) {
		$this->gateway_list_response        = $gateway_list_response;
		$this->gpay_form_data               = $gpay_form_data;
		$this->offer_google_pay_on_checkout = $offer_google_pay_on_checkout;
	}

	private function convert_view_model_group_to_array( View_Model_Group $group ): array {

		$items = [];

		foreach ( $group->getGateways() as $item ) {
			$converted = $this->convert_gateway_item_to_array( $item );
			if ( null !== $converted ) {
				$items[] = $converted;
			}
		}

		if ( $group->isToggled() ) {
			return [
				'label'         => $group->getTitle(),
				'slug'          => sanitize_title( $group->getTitle() ),
				'is_expandable' => true,
				'key'           => 'bm_channnel_group_' . wp_rand( 1, 1000 ),
				'value'         => 'test',
				'name'          => 'bm-payment-channel-group',
				'icon'          => $group->getIconUrl(),
				'items'         => $items,
			];
		}

		return [
			'name'          => $group->getTitle(),
			'slug'          => sanitize_title( $group->getTitle() ),
			'is_expandable' => false,
			'items'         => $items,
		];

		/**
		 * return [
		 * 'label'         => $this->name,
		 * 'key'           => 'bm_channnel_group_' . rand( 1, 1000 ),
		 * 'value'         => 'test',
		 * 'name'          => 'bm-payment-channel-group',
		 * 'icon'          => $this->icon,
		 * 'is_expandable' => true,
		 * 'items'         => $items,
		 * ];
		 */

		/*
		* return [
				'name'          => $this->name,
				'slug'          => $this->slug,
				'is_expandable' => false,
				'items'         => $items,
			];
		*/
	}


	private function convert_gateway_item_to_array( Gateway $gateway ): ?array {
		if ( Blue_Media_Gateway::GPAY_CHANNEL === $gateway->getGatewayID()
			&& ! $this->offer_google_pay_on_checkout ) {
			return null;
		}

		$data = null;
		if ( Blue_Media_Gateway::BLIK_0_CHANNEL === $gateway->getGatewayID() ) {
			$blik0_type = blue_media()
				->get_blue_media_gateway()
				->get_option( 'blik_type', 'with_redirect' );
			if ( 'blik_0_without_redirect' === $blik0_type ) {
				$data['blik0'] = true;
			}
		}

		if ( Blue_Media_Gateway::GPAY_CHANNEL === $gateway->getGatewayID() ) {

			if ( ! empty( $this->gpay_form_data ) ) {
				$data['gpay_form_data'] = $this->gpay_form_data;
			}
		}

		return [
			'label'             => $gateway->getName(),
			'key'               => 'bm_channnel_' . $gateway->getGatewayID(),
			'value'             => $gateway->getGatewayID(),
			'name'              => 'bm-payment-channel',
			'id'                => $gateway->getGatewayID(),
			'icon'              => $gateway->getIconUrl(),
			'class'             => '',
			'description'       => (string) $gateway->getDescription(),
			'block_description' => (string) $gateway->getDescription(),
			'data'              => $data,
		];

		/*
		* return [
		'label'             => $this->name,
		'key'               => 'bm_channnel_' . $this->id,
		'value'             => $this->id,
		'name'              => 'bm-payment-channel',
		'id'                => $this->id,
		'icon'              => $this->icon,
		'class'             => $this->class,
		'description'       => (string) $this->description,
		'block_description' => (string) $this->block_description,
		'data'              => $this->data,
		];
		*/

		/**
		 * 'data'       => [
		 * 'blik0'    => $blik0_type === 'blik_0_without_redirect',
		 * 'test_key' => 'test_value',
		 * ],
		 */
	}


	public function map_for_blocks(): array {
		$group_arr = ( new View_Model_Group_Factory() )->create( $this->gateway_list_response,
			true );
		$group_arr = $this->sort_groups_by_saved_order( $group_arr );

		$result_arr = [];

		foreach ( $group_arr as $group ) {
			if ( $group->isToggled() ) {
				$result_arr[] = $this->convert_view_model_group_to_array( $group );
			} else {
				$result_arr = array_merge( $result_arr,
					$this->convert_view_model_group_to_array( $group )['items'] );
			}
		}

		return $result_arr;
	}

	/**
	 * Apply saved drag-and-drop ordering to view-model groups.
	 *
	 * @param View_Model_Group[] $groups
	 *
	 * @return View_Model_Group[]
	 */
	private function sort_groups_by_saved_order( array $groups ): array {
		$saved_order = $this->get_saved_group_order();

		if ( empty( $saved_order ) || empty( $groups ) ) {
			return $groups;
		}

		$slug_to_group      = [];
		$gateway_pref_order = [];

		foreach ( $groups as $group ) {
			if ( ! $group instanceof View_Model_Group ) {
				continue;
			}

			$group_slug                   = $this->get_group_slug( $group );
			$slug_to_group[ $group_slug ] = $group;

			if ( $this->is_split_group( $group ) ) {
				foreach ( $group->getGateways() as $gateway ) {
					if ( ! $gateway instanceof View_Model_Gateway ) {
						continue;
					}
					$slug_to_group[ $this->get_gateway_slug( $gateway ) ] = $group;
				}
			}
		}

		$sorted = [];
		$added  = [];

		foreach ( $saved_order as $slug ) {
			if ( isset( $slug_to_group[ $slug ] ) ) {
				$group      = $slug_to_group[ $slug ];
				$group_slug = $this->get_group_slug( $group );

				if ( $this->is_split_group( $group ) && 0 === strpos( $slug,
						'gateway-' ) ) {
					$gateway_pref_order[ $group_slug ][] = $slug;
				}

				if ( isset( $added[ $group_slug ] ) ) {
					continue;
				}

				$sorted[]             = $group;
				$added[ $group_slug ] = true;
			}
		}

		foreach ( $groups as $group ) {
			if ( ! $group instanceof View_Model_Group ) {
				continue;
			}
			$group_slug = $this->get_group_slug( $group );
			if ( isset( $added[ $group_slug ] ) ) {
				continue;
			}
			$sorted[]             = $group;
			$added[ $group_slug ] = true;
		}

		// Reorder gateways inside split groups according to saved preferences
		foreach ( $sorted as $group ) {
			if ( ! $group instanceof View_Model_Group ) {
				continue;
			}
			if ( ! $this->is_split_group( $group ) ) {
				continue;
			}

			$gateways = $group->getGateways();
			if ( empty( $gateways ) ) {
				continue;
			}

			$gateway_map = [];
			foreach ( $gateways as $gateway ) {
				if ( ! $gateway instanceof View_Model_Gateway ) {
					continue;
				}
				$gateway_map[ $this->get_gateway_slug( $gateway ) ] = $gateway;
			}

			$ordered    = [];
			$group_slug = $this->get_group_slug( $group );

			if ( isset( $gateway_pref_order[ $group_slug ] ) ) {
				foreach ( $gateway_pref_order[ $group_slug ] as $slug ) {
					if ( isset( $gateway_map[ $slug ] ) ) {
						$ordered[] = $gateway_map[ $slug ];
						unset( $gateway_map[ $slug ] );
					}
				}
			}

			foreach ( $gateways as $gateway ) {
				if ( ! $gateway instanceof View_Model_Gateway ) {
					continue;
				}
				$slug = $this->get_gateway_slug( $gateway );
				if ( isset( $gateway_map[ $slug ] ) ) {
					$ordered[] = $gateway_map[ $slug ];
					unset( $gateway_map[ $slug ] );
				}
			}

			$group->setGateways( $ordered );
		}

		return $sorted;
	}

	/**
	 * Retrieve normalized list of saved slugs from the admin UI.
	 *
	 * @return string[]
	 */
	private function get_saved_group_order(): array {
		$saved = (string) get_option( 'bm_payment_methods_order', '' );

		if ( '' === $saved ) {
			return [];
		}

		$parts      = array_filter( array_map( 'trim',
			explode( ',', $saved ) ) );
		$normalized = [];

		foreach ( $parts as $slug ) {
			$slug = strtolower( $slug );

			if ( 0 === strpos( $slug, 'bm-group-' ) ) {
				$slug = substr( $slug, 9 );
			}

			$slug = sanitize_title( $slug );

			if ( '' !== $slug ) {
				$normalized[] = $slug;
			}
		}

		return array_unique( $normalized );
	}


	/**
	 * Build stable identifier for group ordering / CSS hooks.
	 */
	private function get_group_slug( View_Model_Group $group ): string {
		$source = $group->getType() ?: $group->getTitle();
		$slug   = sanitize_title( $source );

		if ( '' === $slug ) {
			$slug = 'group-' . substr( md5( $group->getTitle() . '|' . $group->getOrder() ),
					0,
					8 );
		}

		return $slug;
	}

	private function is_split_group( View_Model_Group $group ): bool {
		return in_array( $this->get_group_slug( $group ),
			self::SPLIT_GROUP_SLUGS,
			true );
	}

	/**
	 * Build slug for individual gateway.
	 */
	private function get_gateway_slug( View_Model_Gateway $gateway ): string {
		return 'gateway-' . (int) $gateway->getGatewayID();
	}


	private function get_checkout_group_logo_url(): string {
		$gateway = blue_media()->get_blue_media_gateway();
		if ( $gateway instanceof Blue_Media_Gateway ) {
			return $gateway->get_checkout_group_logo_url();
		}

		return blue_media()->get_plugin_images_url() . '/logo-group.svg';
	}
}
