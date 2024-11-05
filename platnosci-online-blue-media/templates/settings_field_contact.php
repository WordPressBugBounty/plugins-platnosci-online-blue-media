<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var WC_Settings_API $wc_settings_api
 * @var array $data
 */

$defaults = [
	'title'             => '',
	'disabled'          => false,
	'class'             => '',
	'css'               => '',
	'placeholder'       => '',
	'type'              => 'text',
	'desc_tip'          => false,
	'description'       => '',
	'custom_attributes' => [],
];

$data = wp_parse_args( $data, $defaults );

?>

</table>
<section class="autopay-comp-contact">
	<div class="autopay-comp-contact__header">
		<h3><?php _e( 'Meet Autopay.', 'bm-woocommerce' ); ?></h3>
		<div>
			<iframe width="560" height="315"
					src="https://www.youtube-nocookie.com/embed/ij9KwlojKQg?si=MqQ55VoEXuYU7cwm"
					title="YouTube video player" frameborder="0"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
					referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
		</div>
	</div>
	<div class="autopay-comp-contact__content">
		<h4 class="wc-settings-sub-title"><?php _e( 'Read more about this plugin:',
				'bm-woocommerce' ); ?></h4>
		<ul>
			<li>
				<a target="_blank"
				   href="https://developers.autopay.pl/online/wtyczki/woocommerce?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#skonfiguruj-wtyczkę">
					<?php _e( 'Plugin configuration', 'bm-woocommerce' ); ?>
				</a>&nbsp;- <?php _e( 'step by step guide', 'bm-woocommerce' ); ?>
			</li>
			<li>
				<a target="_blank"
				   href="https://developers.autopay.pl/online/wtyczki/woocommerce?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#najczęściej-zadawane-pytania">
					<?php _e( 'Frequently Asked Questions',
						'bm-woocommerce' ); ?>
				</a>
			</li>
		</ul>
	</div>
	<div class="autopay-comp-contact__footer">
		<a class="autopay-button" target="_blank"
		   href="https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link">
			<?php _e( 'Ask question about this plugin',
				'bm-woocommerce' ); ?>
		</a>
	</div>
</section>
<table class="form-table">
