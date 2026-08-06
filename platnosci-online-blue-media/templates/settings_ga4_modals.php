<?php defined( 'ABSPATH' ) || exit; ?>

<div class="bm-modal-content ga4_tracking_id_target">
	<span class="bm-close">&times;</span>
	<p><?php esc_html_e( 'Measurement identifier', 'platnosci-online-blue-media' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Measurement ID, which you will find in Google Analytics:',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "1. Log into your Google Analytics dashboard and click on 'Administrator' in the bottom left corner.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "2. In the 'Collecting and modifying data' section, click 'Data streams'.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( '3. Click the name of the data stream.',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( '4. Your Measurement ID is in the top right corner (e.g. G-QCX4K9GSPC).',
				'platnosci-online-blue-media' ); ?></li>
	</ul>
</div>

<div class="bm-modal-content ga4_client_id_target">
	<span class="bm-close">&times;</span>
	<p><?php esc_html_e( 'Stream ID', 'platnosci-online-blue-media' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'The Data Stream Identifier you will find in Google Anlytics:',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "1. Log into your Google Analytics dashboard and click 'Administration'.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "2. In the 'Services' section, click 'Data streams'.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( '3. Click the name of the data stream.',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "4. Copy the identifier of the data stream from the 'Stream details' field.",
				'platnosci-online-blue-media' ); ?></li>
	</ul>

</div>

<div class="bm-modal-content ga4_api_secret_target">
	<span class="bm-close">&times;</span>

	<p><?php esc_html_e( 'Google Analytics API secret',
			'platnosci-online-blue-media' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Text API key, which you will find in Google Analytics:',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "1. Go to 'Administrator' in the bottom left corner.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "2. In the 'Services' section, click 'Data streams'.",
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( '3. Click the name of the data stream.',
				'platnosci-online-blue-media' ); ?></li>
		<li><?php esc_html_e( "4. then click 'Create' in the 'Measurement Protocol' section.",
				'platnosci-online-blue-media' ); ?></li>
	</ul>

</div>
<div class="bm-modal-overlay"></div>


