<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var WC_Settings_API $wc_settings_api
 * @var array $autopay_data
 * @var bool $visible
 * @var array $custom_tr_attributes
 * @var array $autopay_tr_classes
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

$autopay_data       = wp_parse_args( $autopay_data, $autopay_defaults );
$autopay_tr_classes = empty( $autopay_tr_classes ) ? [] : $autopay_tr_classes;
?>

<tr valign="top"
	class="autopay-comp-title section-<?php echo esc_attr( $field_key ); ?> <?php echo esc_attr( implode( ' ', $autopay_tr_classes ) ); ?>">

	<td class="autopay-comp-title__wrapper">
		<h3 class="wc-settings-sub-title <?php echo esc_attr( $autopay_data['class'] ); ?> <?php echo esc_attr( $field_key ); ?>-title"
			id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $autopay_data['title'] ); ?></h3>
		<?php if ( ! empty( $tip_url ) ): ?>
			<?php
			blue_media()->locate_template( 'settings_url_tooltip.php',
				[
					'url'   => $tip_url,
					'label' => $tip_url_label,
				] ); ?>
		<?php endif; ?>
		<?php if ( ! empty( $autopay_data['description'] ) ) : ?>
			<p><?php echo wp_kses_post( $autopay_data['description'] ); ?></p>
		<?php endif; ?>
	</td>
</tr>
