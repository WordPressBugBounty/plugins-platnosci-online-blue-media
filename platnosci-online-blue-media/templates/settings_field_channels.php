<?php defined( 'ABSPATH' ) || exit; ?>

<?php


/**
 * @var callable $channels
 */

$channels = $channels();

?>

<?php if ( $channels instanceof Exception ) : ?>
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
					<pre><?php echo $channels->getMessage() ?></pre>
				</div>
			</fieldset>
		</td>
	</tr>

<?php endif; ?>



<?php if ( is_array( $channels ) && ! empty( $channels ) ) : ?>
	<tr valign="top" class="autopay-comp-channels">
		<th scope="row"
			class="titledesc"><?php _e( 'Allowed payment method list',
				'bm-woocommerce' ) ?></th>
		<td class="forminp">
			<fieldset>
				<?php
				blue_media()
					->get_blue_media_gateway()
					->render_channels_for_admin_panel( $channels );

				?>
			</fieldset>
		</td>
	</tr>

<?php endif; ?>
