<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Payment_Channel_Renderer — renders Autopay payment channel selection UI.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Config;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model\View_Model_Group;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\View_Model\View_Model_Group_Factory;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway as View_Model_Gateway;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Helpers\Autopay_Urls;

/**
 * Renders the payment channel selection UI for checkout and admin panel.
 */
class Payment_Channel_Renderer {

	/**
	 * The main Autopay gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Cached HTML snippet for inline BLIK-0 form.
	 *
	 * @var string|null
	 */
	private ?string $blik_inline_template = null;

	/**
	 * Cached Google Pay inline template/data.
	 *
	 * @var string|null
	 */
	private ?string $gpay_inline_template = null;

	/**
	 * Cached Card Widget inline template.
	 *
	 * @var string|null
	 */
	private ?string $card_widget_inline_template = null;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway The main Autopay gateway instance.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Render payment channels for the classic WooCommerce checkout (v3 layout).
	 *
	 * @param Gateway_List_Response $gateway_list_response  The gateway list API response.
	 * @param array                 $temporary_ignore_this_param Unused legacy parameter.
	 *
	 * @return void
	 */
	public function render_channels_v3(
		Gateway_List_Response $gateway_list_response,
		array $temporary_ignore_this_param = array()
	) {
		$group_arr = ( new View_Model_Group_Factory() )->create(
			$gateway_list_response,
			true
		);
		$group_arr = $this->sort_groups_by_saved_order( $group_arr );
		$group_arr = $this->remove_google_pay_channel_when_terms_disabled( $group_arr );
		$group_arr = $this->apply_special_gateway_descriptions( $group_arr );

		blue_media()->get_woocommerce_logger( 'bm_debug_group_arr' )->log_debug(
			sprintf( '$group_arr: %s', wp_json_encode( $group_arr ) )
		);

		$payment_names = array();
		foreach ( $group_arr as $group ) {
			$payment_names[] = $group->getTitle();
		}

		echo '<div class="payment_box payment_method_bacs">';
		// Use configured description. If it contains {methods} token, replace with available methods list.
		$description_text = $this->gateway->description;
		if ( false !== strpos( (string) $description_text, '{methods}' ) ) {
			$description_text = str_replace(
				'{methods}',
				implode( ', ', $payment_names ),
				(string) $description_text
			);
		}
		echo wp_kses_post( wpautop( wptexturize( $description_text ) ) );
		echo '</div>';
		echo '<div class="payment_box payment_method_bacs">';
		echo '<div class="bm-payment-channels-wrapper">';

		$channels_list_class = sprintf(
			'woocommerce-shipping-methods bm-%d',
			wp_rand( 0, 1000 )
		);

		printf(
			'<ul id="shipping_method" class="%s">',
			esc_attr( $channels_list_class )
		);

		// Iterate over groups and render each payment channel.
		foreach ( $group_arr as $group ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
			/* @var View_Model_Group $group */
			$group_slug       = $this->get_group_slug( $group );
			$expandable_group = $group->isToggled();

			if ( empty( $group->getGateways() ) ) {
				continue;
			}

			printf(
				"<div class='bm-group-%s%s' data-slug='%s'><li><ul>",
				esc_attr( $group_slug ),
				$expandable_group ? ' bm-group-expandable' : '',
				esc_attr( $group_slug )
			);

			if ( $expandable_group ) {
				printf(
					'<li class="bm-payment-channel-group-item">
						<label for="bm-gateway-bank-group">
							<input type="radio" name="bm-payment-channel-group" id="bm-gateway-bank-group" >
							<img src="%s" class="bm-payment-channel-group-method-logo">
							<p class="bm-payment-channel-group-method-name">%s</p>
						</label>
						<span class="bm-payment-channel-method-desc">
						<span>
						<span class="payment-method-description">%s</span>
						</span>
					</span>
				</li>',
					esc_url( $group->getIconUrl() ),
					esc_html( $group->getTitle() ),
					esc_html( $group->getShortDescription() ),
				);

				echo '<div class="bm-group-expandable-wrapper">';
			}

			foreach ( $group->getGateways() as $item ) {
				$special_class = '';
				if ( $item->getGatewayID() === Blue_Media_Gateway::APPLE_PAY_CHANNEL ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin-generated inline script element; not user input.
					echo Config::get_applepay_check_script();
					$special_class = 'bm-apple-pay';
				}

				$inline_html = $item->getInlineHtml();

				printf(
					'<li class="bm-payment-channel-item %s %s">
						<label class="bm-payment-channel-label" for="bm-gateway-id-%s">
							<input type="radio" name="bm-payment-channel" onclick="addCurrentClass(this)" data-index="0" id="bm-gateway-id-%s" value="%s" class="%s">
							<img src="%s" class="bm-payment-channel-method-logo">
							<p class="bm-payment-channel-method-name">%s</p>
						</label>
						<span class="bm-payment-channel-method-desc">',
					'',
					esc_attr( $special_class ),
					esc_attr( $item->getGatewayID() ),
					esc_attr( $item->getGatewayID() ),
					esc_attr( $item->getGatewayID() ),
					$expandable_group ? 'bm-payment-channel-group-in-group' : '',
					esc_url( $item->getIconUrl() ),
					esc_html( $item->getName() ),
				);

				if ( null !== $inline_html ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin-generated template HTML set via setInlineHtml(); never contains user input.
					echo $inline_html;
				} else {
					echo esc_html( (string) $item->getDescription() );
				}

				echo '</span></li>';
			}
			if ( $expandable_group ) {
				echo '</div>';
			}
			printf( '</li></ul></div>' );
		}

		echo '<input type="hidden" name="bm_standard_checkout" value="1">';
		echo '</ul></div>';

		echo '</div>';

		?>

		<script>
			<?php
			if ( 'yes' === $this->gateway->get_option(
				'compatibility_with_live_update_checkout',
				'no'
			) ) :
				?>
			BmTimerValue = 1500;
			<?php else : ?>
			BmTimerValue = 0;
			<?php endif; ?>

			jQuery(document).ready(function () {
				clearTimeout(bm_global_timer)

				var isBlueMediaSelected = jQuery('#payment_method_bluemedia').is(':checked');

				if (isBlueMediaSelected) {
					BmDeactivateNewOrderButton()
				}

				blueMediaRadioHide();

				bm_global_timer = setTimeout(function () {
					bm_global_update_checkout_in_progress = 0;
					blueMediaRadioTest();
				}, BmTimerValue);

			});

			jQuery("input[name='payment_method']").on("click touchstart", function () {
				var radioButtons = jQuery("input[name='payment_method']");
				for (var i = 0; i < radioButtons.length; i++) {
					if (!radioButtons[i].checked || radioButtons[i].id === "payment_method_bluemedia") {
						continue;
					}
					BmActivateNewOrderButton()
					BmDeselectGroupedLi()
				}

				jQuery("input[id='payment_method_bluemedia']").on("click", function () {
					jQuery(".payment_box").find("input[type='radio']").prop("checked", false);
					jQuery(".payment_box").find("li").removeClass("selected");
					BmDeactivateNewOrderButton()
				});

				clearTimeout(bm_global_timer);
				bm_global_timer = setTimeout(function () {
					bm_global_update_checkout_in_progress = 0;
					blueMediaRadioTest();
				}, BmTimerValue);

				jQuery('#payment_method_bluemedia').on('click', function () {

					clearTimeout(bm_global_timer);
					bm_global_timer = setTimeout(function () {
						bm_global_update_checkout_in_progress = 0;
						blueMediaRadioShow();
					}, BmTimerValue);

				});

				jQuery('ul.wc_payment_methods > li.wc_payment_method:not(.payment_method_bluemedia)').on('click', function () {
					blueMediaRadioHide();
				});
			});

		</script>
		<?php
	}


	/**
	 * Render payment channels for the WooCommerce admin panel.
	 *
	 * @param Gateway_List_Response $gateway_list_response The gateway list API response.
	 *
	 * @return void
	 */
	public function render_channels_for_admin_panel(
		Gateway_List_Response $gateway_list_response
	) {
		$group_arr = ( new View_Model_Group_Factory() )->create( $gateway_list_response );
		$group_arr = $this->sort_groups_by_saved_order( $group_arr );

		echo '<ul id="shipping_method" class="woocommerce-shipping-methods payment_box payment_box_wpadmin payment_method_bacs bm-payment-channels__wrapper">';

		// Iterate over groups and render each payment channel in the admin panel.
		foreach ( $group_arr as $group ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Type hint for IDE.
			/* @var View_Model_Group $group */
			$expandable_group = $group->isToggled();
			$group_slug       = $this->get_group_slug( $group );

			if ( empty( $group->getGateways() ) ) {
				continue;
			}

			if ( $this->is_split_group( $group ) ) {
				foreach ( $group->getGateways() as $gateway ) {
					if ( ! $gateway instanceof View_Model_Gateway ) {
						continue;
					}

					$gateway_slug = $this->get_gateway_slug( $gateway );

					printf(
						"<li class='bm-payment-channel bm-group-%s' data-slug='%s'><ul class='bm-payment-channel__wrapper'>",
						esc_attr( $gateway_slug ),
						esc_attr( $gateway_slug ),
					);

					printf(
						'<li class="bm-payment-channel__item bm-inside-single-item">
							<img class="bm-payment-channel__logo" src="%s" alt="%s">
							<p class="bm-payment-channel__desc bm-inside-single-item">%s</p>
						</li>',
						esc_url( $gateway->getIconUrl() ),
						esc_attr( $gateway->getName() ),
						esc_html( $gateway->getName() ),
					);

					echo '</ul></li>';
				}

				continue;
			}

			printf(
				"<li class='bm-payment-channel bm-group-%s%s' data-slug='%s'><ul class='bm-payment-channel__wrapper'>",
				esc_attr( $group_slug ),
				$expandable_group ? ' bm-group-expandable' : '',
				esc_attr( $group_slug )
			);

			if ( $expandable_group ) {
				printf(
					"<p class='bm-group-name'>%s</p>",
					esc_html( $group->getTitle() )
				);
			}

			foreach ( $group->getGateways() as $item ) {
				if ( ! $item instanceof View_Model_Gateway ) {
					continue;
				}

				printf(
					'<li class="bm-payment-channel__item %s"><img class="bm-payment-channel__logo" src="%s" alt="%s"><p class="bm-payment-channel__desc %s">%s</p></li>',
					'',
					esc_url( $item->getIconUrl() ),
					esc_attr( $item->getName() ),
					$expandable_group ? 'bm-inside-expandable-group' : 'bm-inside-single-item',
					esc_html( $item->getName() ),
				);
			}

			printf( '</li></ul>' );
		}

		echo '</ul>';
	}

	/**
	 * Apply saved drag-and-drop ordering to view-model groups.
	 *
	 * @param View_Model_Group[] $groups The groups to sort.
	 *
	 * @return View_Model_Group[]
	 */
	private function sort_groups_by_saved_order( array $groups ): array {
		$saved_order = $this->get_saved_group_order();

		if ( empty( $saved_order ) || empty( $groups ) ) {
			return $groups;
		}

		$slug_to_group      = array();
		$gateway_pref_order = array();

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

		$sorted = array();
		$added  = array();

		foreach ( $saved_order as $slug ) {
			if ( isset( $slug_to_group[ $slug ] ) ) {
				$group      = $slug_to_group[ $slug ];
				$group_slug = $this->get_group_slug( $group );

				if ( $this->is_split_group( $group ) && 0 === strpos(
					$slug,
					'gateway-'
				) ) {
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

		// Reorder gateways inside split groups according to saved preferences.
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

			$gateway_map = array();
			foreach ( $gateways as $gateway ) {
				if ( ! $gateway instanceof View_Model_Gateway ) {
					continue;
				}
				$gateway_map[ $this->get_gateway_slug( $gateway ) ] = $gateway;
			}

			$ordered    = array();
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
	 * Remove Google Pay from the classic checkout channel list when the store does not
	 * show the WooCommerce terms checkbox (Google Pay must not be offered without it).
	 *
	 * @param View_Model_Group[] $groups The groups to filter.
	 *
	 * @return View_Model_Group[]
	 */
	private function remove_google_pay_channel_when_terms_disabled( array $groups ): array {
		if ( $this->gateway->should_offer_google_pay_on_checkout() ) {
			return $groups;
		}

		foreach ( $groups as $group ) {
			if ( ! $group instanceof View_Model_Group ) {
				continue;
			}
			$filtered = array_values(
				array_filter(
					$group->getGateways(),
					static function ( $gateway ): bool {
						if ( ! $gateway instanceof View_Model_Gateway ) {
							return true;
						}

						return (int) $gateway->getGatewayID() !== Blue_Media_Gateway::GPAY_CHANNEL;
					},
				)
			);
			$group->setGateways( $filtered );
		}

		return $groups;
	}

	/**
	 * Attach legacy inline HTML snippets (e.g. BLIK-0 form) to selected gateways.
	 *
	 * @param View_Model_Group[] $groups The groups to process.
	 *
	 * @return View_Model_Group[]
	 */
	private function apply_special_gateway_descriptions( array $groups ): array {
		$blik_html = '';
		if ( $this->is_inline_blik_enabled() ) {
			$blik_html = $this->get_blik_inline_template();
		}

		$gpay_html = '';
		if ( $this->gateway->should_offer_google_pay_on_checkout() && $this->is_inline_gpay_enabled() ) {
			$gpay_html = $this->get_gpay_inline_template();
		}
		$card_widget = $this->get_card_widget_inline_template();

		foreach ( $groups as $group ) {
			if ( ! $group instanceof View_Model_Group ) {
				continue;
			}

			foreach ( $group->getGateways() as $gateway ) {
				if ( ! $gateway instanceof View_Model_Gateway ) {
					continue;
				}

				if ( '' !== $blik_html && (int) $gateway->getGatewayID() === Blue_Media_Gateway::BLIK_0_CHANNEL ) {
					$gateway->setInlineHtml( $blik_html );
				}

				if ( '' !== $gpay_html && (int) $gateway->getGatewayID() === Blue_Media_Gateway::GPAY_CHANNEL ) {
					$gateway->setInlineHtml( $gpay_html );
				}

				if ( '' !== $card_widget && (int) $gateway->getGatewayID() === Blue_Media_Gateway::CARD_CHANNEL ) {
					$gateway->setInlineHtml( $card_widget );
				}
			}
		}

		return $groups;
	}

	/**
	 * Check whether inline BLIK-0 mode is enabled.
	 *
	 * @return bool
	 */
	private function is_inline_blik_enabled(): bool {
		return 'blik_0_without_redirect' === $this->gateway->get_option(
			'blik_type',
			'with_redirect'
		);
	}

	/**
	 * Checks whether inline Google Pay mode is enabled.
	 *
	 * @return bool
	 */
	private function is_inline_gpay_enabled(): bool {
		return 'without_redirect' === $this->gateway->get_option(
			Settings_Manager::get_currency_option_key( 'gpay_type', get_woocommerce_currency() ),
			'with_redirect'
		);
	}

	/**
	 * Return the cached inline BLIK-0 HTML template.
	 *
	 * @return string
	 */
	private function get_blik_inline_template(): string {
		if ( null !== $this->blik_inline_template ) {
			return $this->blik_inline_template;
		}

		ob_start();
		blue_media()->locate_template( 'blik_0.php' );
		$this->blik_inline_template = (string) ob_get_clean();

		return $this->blik_inline_template;
	}

	/**
	 * Return the cached inline Google Pay HTML template.
	 *
	 * @return string
	 */
	private function get_gpay_inline_template(): string {
		if ( null !== $this->gpay_inline_template ) {
			return $this->gpay_inline_template;
		}

		if ( ! $this->gateway->should_offer_google_pay_on_checkout() ) {
			$this->gpay_inline_template = '';

			return '';
		}

		$gpay_form_data = $this->gateway->get_gpay_form_data();
		if ( empty( $gpay_form_data ) || ! is_array( $gpay_form_data ) ) {
			return '';
		}

		ob_start();
		blue_media()->locate_template(
			'google_pay.php',
			array(
				'response_data'       => $gpay_form_data,
				'environment'         => $this->gateway->resolve_is_test_mode() ? 'TEST' : 'PRODUCTION',
				'shopBaseCountryCode' => WC()->countries->get_base_country(),
			)
		);
		$this->gpay_inline_template = (string) ob_get_clean();

		return $this->gpay_inline_template;
	}

	/**
	 * Return Card Widget inline template.
	 *
	 * @return string
	 */
	private function get_card_widget_inline_template(): string {
		if ( null === $this->card_widget_inline_template ) {
			ob_start();
			blue_media()->locate_template(
				'card_widget.php',
				array(
					'autopay_card_widget_data' => array(
						'service_id'   => $this->gateway->get_service_id(),
						'is_test'      => $this->gateway->resolve_is_test_mode(),
						'amount'       => WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0,
						'currency'     => get_woocommerce_currency(),
						'language'     => substr( get_locale(), 0, 2 ),
						'cards_domain' => Autopay_Urls::get_cards_domain( $this->gateway->resolve_is_test_mode() ),
					),
				)
			);
			$this->card_widget_inline_template = (string) ob_get_clean();
		}

		return $this->card_widget_inline_template;
	}

	/**
	 * Build stable identifier for group ordering / CSS hooks.
	 *
	 * @param View_Model_Group $group The group to build slug for.
	 *
	 * @return string
	 */
	private function get_group_slug( View_Model_Group $group ): string {
		$source = $group->getType() ? $group->getType() : $group->getTitle();
		$slug   = sanitize_title( $source );

		if ( '' === $slug ) {
			$slug = 'group-' . substr(
				md5( $group->getTitle() . '|' . $group->getOrder() ),
				0,
				8
			);
		}

		return $slug;
	}

	/**
	 * Check whether a group should be split into individual gateway items.
	 *
	 * @param View_Model_Group $group The group to check.
	 *
	 * @return bool
	 */
	private function is_split_group( View_Model_Group $group ): bool {
		return in_array(
			$this->get_group_slug( $group ),
			Blue_Media_Gateway::SPLIT_GROUP_SLUGS,
			true
		);
	}

	/**
	 * Build slug for individual gateway.
	 *
	 * @param View_Model_Gateway $gateway The gateway to build slug for.
	 *
	 * @return string
	 */
	private function get_gateway_slug( View_Model_Gateway $gateway ): string {
		return 'gateway-' . (int) $gateway->getGatewayID();
	}

	/**
	 * Retrieve normalized list of saved slugs from the admin UI.
	 *
	 * @return string[]
	 */
	private function get_saved_group_order(): array {
		$saved = (string) get_option( 'bm_payment_methods_order', '' );

		if ( '' === $saved ) {
			return array();
		}

		$parts      = array_filter(
			array_map(
				'trim',
				explode( ',', $saved )
			)
		);
		$normalized = array();

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
	 * Reposition an array element by its key.
	 *
	 * @param array      $items The array being reordered (passed by reference).
	 * @param string|int $key   The key of the element you want to reposition.
	 * @param int        $order The position in the array you want to move the element to (0 is first).
	 *
	 * @return void
	 * @throws Exception When the key cannot be found in the array.
	 */
	private function reposition_array_element(
		array &$items,
		$key,
		int $order
	): void {
		$a = array_search( $key, array_keys( $items ), true );
		if ( false === $a ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
			throw new Exception( "The {$key} cannot be found in the given array." );
		}
		$p1    = array_splice( $items, $a, 1 );
		$p2    = array_splice( $items, 0, $order );
		$items = array_merge( $p2, $p1, $items );
	}
}
