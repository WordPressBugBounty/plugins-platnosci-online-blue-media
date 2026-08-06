<?php

use Ilabs\BM_Woocommerce\Features;

defined( 'ABSPATH' ) || exit;

/**
 * @var string $vas_content
 * @var string $title
 * @var string $subtitle
 */



?>

<?php if ( ! empty( $title ) ): ?>
	<?php
	blue_media()->locate_template( 'settings_section_header.php',
		[
			'title'    => $title,
			'subtitle' => $subtitle,
		] ); ?>
<?php endif; ?>


<div class="autopay-vas">
	<?php if ( ! empty( $vas_content ) ): ?>
		<?php echo $vas_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML VAS content from authenticated Blue Media server-to-server API response; stripped of scripts/links in Vas::parse_body_string(). ?>
	<?php else: ?>
		<h4><?php esc_html_e( "Oops, something went wrong!",
				"platnosci-online-blue-media" ) ?></h4>
		<p><?php esc_html_e( "The list of services for Merchant couldn't be loaded. This may be a temporary problem. Try again in a while. If it still doesn't work,",
				"platnosci-online-blue-media" ) ?> <a target="_blank"
										 href="https://developers.autopay.pl/kontakt?mtm_campaign=woocommerce_developers_formularz&mtm_source=woocommerce_backoffice&mtm_medium=hiperlink_load_error"><?php esc_html_e( "let us know.",
					"platnosci-online-blue-media" ) ?></a></p>
	<?php endif ?>
</div>
