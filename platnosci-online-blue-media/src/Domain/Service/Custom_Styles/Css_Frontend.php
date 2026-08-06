<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles;

defined( 'ABSPATH' ) || exit;

class Css_Frontend {

	public function include( ?string $id = null ) {
		$editor = new Css_Editor( $id );

		if ( $editor->is_enabled() ) {
			$this->print_to_wp_head( $editor->get_editor_content() );
		}
	}

	private function print_to_wp_head( string $css ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style>' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_strip_all_tags prevents HTML injection from CSS context
		} );
	}
}
