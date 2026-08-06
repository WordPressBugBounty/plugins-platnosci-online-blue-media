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


<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $autopay_data['title'] ); ?></label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $autopay_data['title'] ); ?></span>
			</legend>

			<table>
				<tr>
					<th><?php esc_html_e( 'Event name', 'platnosci-online-blue-media' ); ?></th>
					<th><?php esc_html_e( 'Event ID', 'platnosci-online-blue-media' ); ?></th>
					<th><?php esc_html_e( 'Description',
							'platnosci-online-blue-media' ); ?></th>
				</tr>
				<tr>
					<td><?php esc_html_e( 'View product on list',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>view_item_list</code></td>
					<td><?php esc_html_e( 'Triggered for each product that is listed and visible to the customer when browsing the site.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'View product details',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>view_item</code></td>
					<td><?php esc_html_e( 'Triggered when a user visits a specific product page. Triggered when the page is displayed/loaded.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Click on a product',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>add_to_cart</code></td>
					<td><?php esc_html_e( 'Triggered when a user adds a product to the cart.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Remove a product from the cart',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>remove_from_cart</code></td>
					<td><?php esc_html_e( 'Triggered when a user removes a product from the cart.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Start the checkout process',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>begin_checkout</code></td>
					<td><?php esc_html_e( 'Triggered when a user proceeds to checkout.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Completed order details',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>set_checkout_option</code></td>
					<td><?php esc_html_e( 'Triggered when the user has completed the checkout details.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Select payment method',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>checkout_progress</code></td>
					<td><?php esc_html_e( 'Triggered when the user has proceeded to the second step of the checkout (selection of payment method).',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Complete transaction',
							'platnosci-online-blue-media' ); ?></td>
					<td><code>purchase</code></td>
					<td><?php esc_html_e( 'Triggered when the transaction is successfully completed. It is sent on the server side so that the transaction is marked, even if the Customer has not returned to the thank you page.',
							'platnosci-online-blue-media' ); ?></td>
				</tr>
			</table>


		</fieldset>
	</td>
</tr>


