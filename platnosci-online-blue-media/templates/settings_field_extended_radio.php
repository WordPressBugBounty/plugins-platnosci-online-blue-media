<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var string $status_class
 * @var string $status_type
 * @var string $status
 * @var WC_Settings_API $wc_settings_api
 * @var array $autopay_data
 * @var array $autopay_tr_classes
 * @var bool $visible
 * @var string $bottom_description
 *
 *
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
	'options'           => [],
	'status'            => false,
	'status_class'      => '',
	'status_type'       => '',
	'help_tip'          => false,
];

$autopay_data               = wp_parse_args( $autopay_data, $autopay_defaults );
$autopay_value              = esc_attr( $wc_settings_api->get_option( $key ) );
$autopay_status             = empty( $status ) ? false : $status;
$autopay_status_class       = empty( $status_class ) ? '' : $status_class;
$autopay_status_type        = empty( $status_type ) ? 'success' : $status_type;
$autopay_bottom_description = empty( $bottom_description ) ? false : $bottom_description;
$autopay_help_tip           = empty( [ 'help_tip' ] ) ? false : $autopay_data['help_tip'];
$autopay_class              = empty( [ 'help_tip' ] ) ? '' : $autopay_data['class'];
$autopay_tr_classes         = empty( $autopay_tr_classes ) ? [] : $autopay_tr_classes;


?>
<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr <?php echo $autopay_class ? esc_attr( $autopay_class ) . '-tr' : ''; ?> autopay-comp-radio <?php echo esc_attr( implode( ' ', $autopay_tr_classes ) ); ?>">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $autopay_data['title'] ); ?>
			<?php if ( $autopay_help_tip ): blue_media()->locate_template( 'settings_help-tip.php',
				[
					'helptip' => $autopay_help_tip,
				] ); endif; ?></label>

		<?php if ( ! empty( $tip_url ) ): ?>
			<?php
			blue_media()->locate_template( 'settings_url_tooltip.php',
				[
					'url'   => $tip_url,
					'label' => $tip_url_label,
				] ); ?>
		<?php endif; ?>
		<fieldset class="autopay-fieldset">
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $autopay_data['title'] ); ?></span>
			</legend>
			<?php foreach ( (array) $autopay_data['options'] as $autopay_option_key => $autopay_option_value ) : ?>
				<?php if ( is_array( $autopay_option_value ) ) : ?>

					<optgroup
						label="<?php echo esc_attr( $autopay_option_key ); ?>">
						<?php foreach ( $autopay_option_value as $autopay_option_key_inner => $autopay_option_value_inner ) : ?>
							<label
								for="<?php echo esc_attr( $field_key ); ?>">
								<input
									id="<?php echo esc_attr( $field_key ); ?>"
									type="radio"
									name="<?php echo esc_attr( $field_key ); ?>"
									value="<?php echo esc_attr( $autopay_option_key_inner ); ?>" <?php checked( (string) $autopay_option_key_inner,
									esc_attr( $autopay_value ) ); ?> />
								<?php echo esc_html( $autopay_option_value_inner ); ?>
							</label>
						<?php endforeach; ?>
					</optgroup>
				<?php else : ?>
					<label
						for="<?php echo esc_attr( $field_key ); ?>">
						<input id="<?php echo esc_attr( $field_key ); ?>"
							   type="radio"
							   name="<?php echo esc_attr( $field_key ); ?>"
							   value="<?php echo esc_attr( $autopay_option_key ); ?>" <?php checked( (string) $autopay_option_key,
							esc_attr( $autopay_value ) ); ?> />
						<?php echo esc_html( $autopay_option_value ); ?>
					</label>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php echo $wc_settings_api->get_description_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?>
		</fieldset>
	</th>
	<?php if ( ! empty( $autopay_status ) ): ?>
		<td class="formbadge">
			<?php
			blue_media()->locate_template( 'settings_status_badge.php',
				[
					'status' => $autopay_status,
					'class'  => $autopay_status_class,
					'type'   => $autopay_status_type,
				] ); ?>
		</td>
	<?php endif; ?>

</tr>

<?php if ( $autopay_bottom_description ) : ?>
	<tr class="<?php echo esc_attr( $field_key ); ?>-desc-tr autopay-comp-radio-desc-tr">
		<span class='p-info'>
					<td class="formdesc">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce get_description_html() returns already-sanitized HTML; $autopay_bottom_description passed through wp_kses_post below.
						echo $wc_settings_api->get_description_html( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce API produces safe markup
							'desc_tip'    => false,
							'description' => wp_kses_post( $autopay_bottom_description ),
						] ); ?>
					</td>
		</span>
	</tr>
<?php endif; ?>
