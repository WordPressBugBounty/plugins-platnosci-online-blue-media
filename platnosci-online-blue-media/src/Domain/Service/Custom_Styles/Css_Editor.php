<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Helpers\Helper;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Presentation\Form\Fields\Checkbox;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Presentation\Woocommerce\Field;
use function GuzzleHttp\Psr7\str;

class Css_Editor {

	const ID = 'css_editor';

	private Checkbox $switcher_checkbox;
	private string $editor_content;
	private bool $enabled;
	private string $id;

	public function __construct( ?string $id = null ) {
		$this->id = $id
			? blue_media()->add_slug_prefix( $id )
			: blue_media()->add_slug_prefix( self::ID );

		$this->configure_controls();
		$this->editor_content = $this->read_content_option();
		$this->enabled        = $this->read_enabled_option();

	}

	public static function enqueue_scripts() {
		$cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
		if ( false !== $cm_settings ) {
			wp_enqueue_script( 'wp-theme-plugin-editor' );
			wp_enqueue_style( 'wp-codemirror' );
		}
	}

	/**
	 * @throws Exception
	 */
	public function display_editor() {
		$this->display_switcher();
		echo $this->get_editor( $this->editor_content ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-generated HTML from ob_get_clean(), esc_html would destroy markup
	}

	private function configure_controls() {
		$checkbox = new Checkbox();
		$checkbox->set_id( $this->get_switcher_checkbox_option_id() );
		$checkbox->set_name( $this->get_switcher_checkbox_option_id() );

		$checkbox->set_value( $this->read_enabled_option() ? 'yes' : 'no' );
		$checkbox->set_default( 'no' );
		$checkbox->set_label( __( 'Feature enabled', 'platnosci-online-blue-media' ) );
		$this->switcher_checkbox = $checkbox;

	}

	/**
	 * @throws Exception
	 */
	public function display_switcher() {
		$field = new Field();
		echo $field->get_html( $this->switcher_checkbox ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC/ilabs Field object returns safe HTML
	}

	private function get_editor( string $content = '' ): string {
		$id = $this->get_editor_content_option_id();
		$content = $content === '' ? $this->get_default_css_code() : $content;
		ob_start();

		printf(
			'<textarea name="%1$s" id="%1$s" style="width:100%%; height:500px;">%2$s</textarea>',
			esc_attr( $id ),
			esc_textarea( $content )
		);
		?>
		<script>
			jQuery(document).ready(function ($) {
				var editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
				editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
					indentUnit: 2,
					tabSize: 2,
					mode: 'css',
				});
				var editor = wp.codeEditor.initialize($('#<?php echo esc_js( $id )?>'), editorSettings);
			});
		</script>
		<?php

		return ob_get_clean();
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' )
		    || ! isset( $_POST['autopay_css_editor_nonce_field'] )
		    || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['autopay_css_editor_nonce_field'] ) ), 'autopay_css_editor_nonce' ) ) {
			return;
		}

		if ( blue_media()
			->get_request()
			->key_exsists( $this->get_editor_content_option_id() ) ) {

			$content = wp_unslash( $_POST[ $this->get_editor_content_option_id() ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS content; sanitize_text_field() would corrupt valid CSS (strips angle brackets, colons, semicolons). Access is nonce-verified and capability-checked above.
			$content = $this->normalize_new_lines( $content );
			Helper::update_gateway_option( $this->get_editor_content_option_id(),
				$content );

			if ( isset( $_POST[ $this->get_switcher_checkbox_option_id() ] ) ) {
				Helper::update_gateway_option( $this->get_switcher_checkbox_option_id(),
					'yes' );
			} else {
				Helper::update_gateway_option( $this->get_switcher_checkbox_option_id(),
					'no' );
			}

			blue_media()
				->alerts()
				->add_success( __( 'Changes have been saved.',
					'platnosci-online-blue-media' ) );
		}
	}

	private function read_content_option(): string {
		return (string) Helper::get_gateway_option( $this->get_editor_content_option_id() );
	}

	private function read_enabled_option(): bool {
		return 'yes' == Helper::get_gateway_option( $this->get_switcher_checkbox_option_id() );
	}

	public function get_editor_content(): string {
		return $this->editor_content;
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	private function get_default_css_code(): string {
		$message = __( "Insert your CSS code here", "platnosci-online-blue-media" );

		return "/*$message*/";
	}

	private function get_editor_content_option_id(): string {
		return $this->id . '_content';
	}

	private function get_switcher_checkbox_option_id(): string {
		return $this->id . '_checkbox';
	}

	private function normalize_new_lines( $text ) {
		return $text;
		$text = str_replace( "\r\n",
			PHP_EOL,
			$text );
		$text = str_replace( "\r",
			PHP_EOL,
			$text );
		$text = str_replace( "\n",
			PHP_EOL,
			$text );

		return $text;
	}
}
