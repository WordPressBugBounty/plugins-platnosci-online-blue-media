<?php defined( 'ABSPATH' ) || exit; ?>

<?php


/**
 * @var callable $channels
 */

$channels = $channels();

?>
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

