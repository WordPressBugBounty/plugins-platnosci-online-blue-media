<?php defined( 'ABSPATH' ) || exit; ?>

<div class="bm-modal-content ga4_tracking_id_target">
	<span class="bm-close">&times;</span>
	<p><?php echo __( 'Measurement identifier', 'bm-woocommerce' ); ?></p>
	<ul>
		<li><?php echo __( 'Measurement ID, which you will find in Google Analytics:',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '1. Log into your Google Analytics dashboard and click on ‘Administrator’ in the bottom left corner.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '2. In the ‘Collecting and modifying data’ section, click ‘Data streams’.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '3. Click the name of the data stream.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '4. Your Measurement ID is in the top right corner (e.g. G-QCX4K9GSPC).',
				'bm-woocommerce' ); ?></li>
	</ul>
</div>

<div class="bm-modal-content ga4_client_id_target">
	<span class="bm-close">&times;</span>
	<p><?php echo __( 'Stream ID', 'bm-woocommerce' ); ?></p>
	<ul>
		<li><?php echo __( 'The Data Stream Identifier you will find in Google Anlytics:',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '1. Log into your Google Analytics dashboard and click ‘Administration’.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '2. In the ‘Services’ section, click ‘Data streams’.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '3. Click the name of the data stream.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '4. Copy the identifier of the data stream from the ‘Stream details’ field.',
				'bm-woocommerce' ); ?></li>
	</ul>

</div>

<div class="bm-modal-content ga4_api_secret_target">
	<span class="bm-close">&times;</span>

	<p><?php echo __( 'Google Analytics API secret',
			'bm-woocommerce' ); ?></p>
	<ul>
		<li><?php echo __( 'Text API key, which you will find in Google Analytics:',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '1. Go to ‘Administrator’ in the bottom left corner.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '2. In the ‘Services’ section, click ‘Data streams’.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '3. Click the name of the data stream.',
				'bm-woocommerce' ); ?></li>
		<li><?php echo __( '4. then click ‘Create’ in the ‘Measurement Protocol’ section.',
				'bm-woocommerce' ); ?></li>
	</ul>

</div>
<div class="bm-modal-overlay"></div>


