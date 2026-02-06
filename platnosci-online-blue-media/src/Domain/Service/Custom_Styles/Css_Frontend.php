<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles;

class Css_Frontend {

	public function include( ?string $id = null ) {
		$editor = new Css_Editor( $id );

		if ( $editor->is_enabled() ) {
			$this->print_to_wp_head( $editor->get_editor_content() );
		}
	}

	private function print_to_wp_head( string $css ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo ( '<style>' . $css . '</style>' );
		} );
	}
}
