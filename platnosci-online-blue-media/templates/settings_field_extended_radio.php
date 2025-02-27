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
 * @var array $data
 * @var bool $visible
 * @var string $bottom_description
 *
 *
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
	'options'           => [],
	'status'            => false,
	'status_class'      => '',
	'status_type'       => '',
	'help_tip'          => false,
];

$data               = wp_parse_args( $data, $defaults );
$value              = esc_attr( $wc_settings_api->get_option( $key ) );
$status             = empty( $status ) ? false : $status;
$status_class       = empty( $status_class ) ? '' : $status_class;
$status_type        = empty( $status_type ) ? 'success' : $status_type;
$bottom_description = empty( $bottom_description ) ? false : $bottom_description;
$help_tip           = empty( [ 'help_tip' ] ) ? false : $data['help_tip'];
$class              = empty( [ 'help_tip' ] ) ? '' : $data['class'];


?>
<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr <?php echo $class ? esc_attr( $class ) . '-tr' : ''; ?> autopay-comp-radio">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?>
			<?php if ( $help_tip ): blue_media()->locate_template( 'settings_help-tip.php',
				[
					'helptip' => $help_tip,
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
				<span><?php echo wp_kses_post( $data['title'] ); ?></span>
			</legend>
			<?php foreach ( (array) $data['options'] as $option_key => $option_value ) : ?>
				<?php if ( is_array( $option_value ) ) : ?>

					<optgroup
						label="<?php echo esc_attr( $option_key ); ?>">
						<?php foreach ( $option_value as $option_key_inner => $option_value_inner ) : ?>
							<label
								for="<?php echo esc_attr( $field_key ); ?>">
								<input
									id="<?php echo esc_attr( $field_key ); ?>"
									type="radio"
									name="<?php echo esc_attr( $field_key ); ?>"
									value="<?php echo esc_attr( $option_key_inner ); ?>" <?php checked( (string) $option_key_inner,
									esc_attr( $value ) ); ?> />
								<?php echo esc_html( $option_value_inner ); ?>
							</label>
						<?php endforeach; ?>
					</optgroup>
				<?php else : ?>
					<label
						for="<?php echo esc_attr( $field_key ); ?>">
						<input id="<?php echo esc_attr( $field_key ); ?>"
							   type="radio"
							   name="<?php echo esc_attr( $field_key ); ?>"
							   value="<?php echo esc_attr( $option_key ); ?>" <?php checked( (string) $option_key,
							esc_attr( $value ) ); ?> />
						<?php echo esc_html( $option_value ); ?>
					</label>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php echo $wc_settings_api->get_description_html( $data ); // WPCS: XSS ok. ?>
		</fieldset>
	</th>
	<?php if ( ! empty( $status ) ): ?>
		<td class="formbadge">
			<?php
			blue_media()->locate_template( 'settings_status_badge.php',
				[
					'status' => $status,
					'class'  => $status_class,
					'type'   => $status_type,
				] ); ?>
		</td>
	<?php endif; ?>

</tr>

<?php if ( $bottom_description ) : ?>
	<tr class="<?php echo esc_attr( $field_key ); ?>-desc-tr autopay-comp-radio-desc-tr">
		<span class='p-info'>
					<td class="formdesc">
						<?php echo $wc_settings_api->get_description_html( [
							'desc_tip'    => false,
							'description' => $bottom_description,
						] ); // WPCS: XSS ok. ?>
					</td>
		</span>
	</tr>
<?php endif; ?>
