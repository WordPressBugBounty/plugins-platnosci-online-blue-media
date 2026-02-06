<?php defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response_Factory;

/**
 * @var callable $channels
 */

$channels_data = $channels();

?>

<?php if ( $channels_data instanceof Exception ) : ?>
    <tr valign="top" class="autopay-comp-channels">
        <th scope="row"
            class="titledesc"></th>
        <td class="forminp">
            <fieldset>
				<?php _e( 'The problem occurred while retrieving a channel list',
					'bm-woocommerce' ) ?>

                <div class="get-channels-error">
                    <a class="bm_ga_help_modal" href="#"
                       data-modal="get_channels_error_modal_target">
						<span><?php echo __( 'Show details',
								'bm-woocommerce' ); ?></span>
                    </a>
                </div>

                <div class="bm-modal-content get_channels_error_modal_target">
                    <span class="bm-close">&times;</span>
                    <pre><?php echo $channels_data->getMessage() ?></pre>
                </div>
            </fieldset>
        </td>
    </tr>

<?php endif; ?>



<?php if ( is_array( $channels_data ) && ! empty( $channels_data ) ) : ?>
    <tr valign="top" class="autopay-comp-channels">
        <th scope="row"
            class="titledesc"><?php _e( 'Allowed payment method list',
				'bm-woocommerce' ) ?></th>
        <td class="forminp">
            <fieldset>
				<?php
				$gateway_list_response = ( new Gateway_List_Response_Factory() )->create( $channels_data );

				// Title previously injected via CSS ::before. Now rendered by PHP for clarity/i18n.
				echo '<p class="bm-payment-order-title">' . __( 'Set the order at checkout', 'bm-woocommerce' ) . '</p>';
				blue_media()
					->get_blue_media_gateway()
					->render_channels_for_admin_panel( $gateway_list_response );

                // Hidden field keeps the order defined via drag & drop
                $stored_order = get_option( 'bm_payment_methods_order', '' );
                printf( '<input type="hidden" id="bm_payment_methods_order_field" name="bm_payment_methods_order" value="%s" />', esc_attr( $stored_order ) );

				?>
            </fieldset>
        </td>
    </tr>

<?php endif; ?>
