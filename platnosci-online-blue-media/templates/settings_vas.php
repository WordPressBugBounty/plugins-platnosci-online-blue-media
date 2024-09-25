<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $vas_content
 * @var string $src
 */
?>

<tr valign="top">
	<th scope="row"
		class="titledesc"></th>
	<td class="forminp">
		<div class="autopay-vas">
			<?php if ( ! empty( $vas_content ) ): ?>
				<?php echo $vas_content ?>
			<?php else: ?>
				<h4><?php _e( "Oops, something went wrong!",
						"bm-woocommerce" ) ?></h4
				<p><?php _e( "The list of services for Merchant couldn't be loaded. This may be a temporary problem. Try again in a while. If it still doesn't work,",
						"bm-woocommerce" ) ?> <a target="_blank"
												 href="https://developers.autopay.pl/kontakt?mtm_campaign=woocommerce_developers_formularz&mtm_source=woocommerce_backoffice&mtm_medium=hiperlink_load_error"><?php _e( "let us know.",
							"bm-woocommerce" ) ?></a></p>
			<?php endif ?>
		</div>
	</td>
</tr>
