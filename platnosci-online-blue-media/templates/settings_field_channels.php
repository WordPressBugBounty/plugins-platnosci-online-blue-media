<?php defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response_Factory;

/**
 * @var callable $channels
 */

$autopay_channels_data = $channels();

?>

<?php if ( $autopay_channels_data instanceof Exception ) : ?>
    <tr valign="top" class="autopay-comp-channels">
        <th scope="row"
            class="titledesc"></th>
        <td class="forminp">
            <fieldset>
				<?php esc_html_e( 'The problem occurred while retrieving a channel list',
					'platnosci-online-blue-media' ) ?>

                <div class="get-channels-error">
                    <a class="bm_ga_help_modal" href="#"
                       data-modal="get_channels_error_modal_target">
						<span><?php esc_html_e( 'Show details',
								'platnosci-online-blue-media' ); ?></span>
                    </a>
                </div>

                <div class="bm-modal-content get_channels_error_modal_target">
                    <span class="bm-close">&times;</span>
                    <pre><?php echo esc_html( $autopay_channels_data->getMessage() ); ?></pre>
                </div>
            </fieldset>
        </td>
    </tr>

<?php endif; ?>



<?php if ( is_array( $autopay_channels_data ) && ! empty( $autopay_channels_data ) ) : ?>
    <tr valign="top" class="autopay-comp-channels">
        <th scope="row"
            class="titledesc"><?php esc_html_e( 'Allowed payment method list',
				'platnosci-online-blue-media' ) ?></th>
        <td class="forminp">
            <fieldset>
				<?php
				$autopay_gateway_list_response = ( new Gateway_List_Response_Factory() )->create( $autopay_channels_data );

				// Title previously injected via CSS ::before. Now rendered by PHP for clarity/i18n.
				echo '<p class="bm-payment-order-title">' . esc_html( __( 'Set the order at checkout', 'platnosci-online-blue-media' ) ) . '</p>';
				blue_media()
					->get_blue_media_gateway()
					->render_channels_for_admin_panel( $autopay_gateway_list_response );

                // Hidden field keeps the order defined via drag & drop
                $autopay_stored_order = get_option( 'bm_payment_methods_order', '' );
                printf( '<input type="hidden" id="bm_payment_methods_order_field" name="bm_payment_methods_order" value="%s" />', esc_attr( $autopay_stored_order ) );

				?>
            </fieldset>
        </td>
    </tr>

<?php endif; ?>
