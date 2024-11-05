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


<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $data['title'] ); ?></span>
			</legend>

			<table>
				<tr>
					<th><?php echo __( 'Event name', 'bm-woocommerce' ); ?></th>
					<th><?php echo __( 'Event ID', 'bm-woocommerce' ); ?></th>
					<th><?php echo __( 'Description',
							'bm-woocommerce' ); ?></th>
				</tr>
				<tr>
					<td><?php echo __( 'View product on list',
							'bm-woocommerce' ); ?></td>
					<td><code>view_item_list</code></td>
					<td><?php echo __( 'Triggered for each product that is listed and visible to the customer when browsing the site.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'View product details',
							'bm-woocommerce' ); ?></td>
					<td><code>view_item</code></td>
					<td><?php echo __( 'Triggered when a user visits a specific product page. Triggered when the page is displayed/loaded.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Click on a product',
							'bm-woocommerce' ); ?></td>
					<td><code>add_to_cart</code></td>
					<td><?php echo __( 'Triggered when a user adds a product to the cart.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Remove a product from the cart',
							'bm-woocommerce' ); ?></td>
					<td><code>remove_from_cart</code></td>
					<td><?php echo __( 'Triggered when a user removes a product from the cart.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Start the checkout process',
							'bm-woocommerce' ); ?></td>
					<td><code>begin_checkout</code></td>
					<td><?php echo __( 'Triggered when a user proceeds to checkout.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Completed order details',
							'bm-woocommerce' ); ?></td>
					<td><code>set_checkout_option</code></td>
					<td><?php echo __( 'Triggered when the user has completed the checkout details.',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Select payment method',
							'bm-woocommerce' ); ?></td>
					<td><code>checkout_progress</code></td>
					<td><?php echo __( 'Triggered when the user has proceeded to the second step of the checkout (selection of payment method).',
							'bm-woocommerce' ); ?></td>
				</tr>
				<tr>
					<td><?php echo __( 'Complete transaction',
							'bm-woocommerce' ); ?></td>
					<td><code>purchase</code></td>
					<td><?php echo __( 'Triggered when the transaction is successfully completed. It is sent on the server side so that the transaction is marked, even if the Customer has not returned to the thank you page.',
							'bm-woocommerce' ); ?></td>
				</tr>
			</table>


		</fieldset>
	</td>
</tr>


