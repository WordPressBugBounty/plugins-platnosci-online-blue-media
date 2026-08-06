<?php

defined( 'ABSPATH' ) || exit;

function blue_media_system_check(): bool {

	if ( ! defined( 'BLUE_MEDIA_PRODUCTION_DIR_NAME' ) ) {
		define( 'BLUE_MEDIA_PRODUCTION_DIR_NAME', 'platnosci-online-blue-media' );
	}

	$basename = basename( __DIR__ );

	if ( $basename !== BLUE_MEDIA_PRODUCTION_DIR_NAME ) {
		add_action( 'admin_notices', function () {
			echo "<div class='notice notice-error error'><p><strong style='color: red;'>";
			echo wp_kses(
				sprintf(
				    // translators: %s is a URL link to the plugin's WordPress.org page.
					__( "It looks like the developer version of the Autopay plug-in is installed instead of the production release. Remove this plugin and install it from this URL: %s", 'platnosci-online-blue-media' ),
					'<a target="_blank" href="https://wordpress.org/plugins/platnosci-online-blue-media/">https://wordpress.org/plugins/platnosci-online-blue-media/</a>'
				),
				[ 'a' => [ 'href' => [], 'target' => [] ] ]
			);
			echo "</strong></p></div>";
		} );

		return false;
	}

	if ( PHP_VERSION_ID < 70200 ) {
		add_action( 'admin_notices', function () {
			echo "<div class='notice notice-error error'><p><strong style='color: red;'>Autopay: ";
			esc_html_e( "PHP version is older than 7.2 so this plugin will not work. Please contact your host and ask them to upgrade.",
				"platnosci-online-blue-media" );
			echo "</strong></p></div>";
		} );

		return false;
	}

	return true;
}
