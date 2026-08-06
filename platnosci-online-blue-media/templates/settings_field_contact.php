<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var WC_Settings_API $wc_settings_api
 * @var array $autopay_data
 */

$autopay_defaults = [
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

$autopay_data = wp_parse_args( $autopay_data, $autopay_defaults );

?>

</table>
<section class="autopay-comp-contact">
	<div class="autopay-comp-contact__header">
		<h3><?php esc_html_e( 'Meet Autopay.', 'platnosci-online-blue-media' ); ?></h3>
		<div>
			<iframe width="560" height="315"
					src="https://www.youtube-nocookie.com/embed/ij9KwlojKQg?si=MqQ55VoEXuYU7cwm"
					title="YouTube video player" frameborder="0"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
					referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
		</div>
	</div>
	<div class="autopay-comp-contact__content">
		<h4 class="wc-settings-sub-title"><?php esc_html_e( 'Read more about this plugin:',
				'platnosci-online-blue-media' ); ?></h4>
		<ul>
			<li>
				<a target="_blank"
				   href="<?php echo esc_url( __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d957630b07', 'platnosci-online-blue-media' ) ); ?>">
					<?php esc_html_e( 'Plugin configuration', 'platnosci-online-blue-media' ); ?>
				</a>&nbsp;- <?php esc_html_e( 'step by step guide', 'platnosci-online-blue-media' ); ?>
			</li>
			<li>
				<a target="_blank"
				   href="<?php echo esc_url( __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d957630b13', 'platnosci-online-blue-media' ) ); ?>">
					<?php esc_html_e( 'Frequently Asked Questions',
						'platnosci-online-blue-media' ); ?>
				</a>
			</li>
		</ul>
	</div>
	<div class="autopay-comp-contact__footer">
		<a class="autopay-button" target="_blank"
		   href="https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link">
			<?php esc_html_e( 'Ask question about this plugin',
				'platnosci-online-blue-media' ); ?>
		</a>
	</div>
</section>
<table class="form-table">
