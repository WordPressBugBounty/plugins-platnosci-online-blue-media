<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;
use Ilabs\BM_Woocommerce\Features;

class Settings_Manager {

	private static bool $initiated = false;

	public function get_form_fields() {
		if ( isset( $_GET['bmtab'] ) ) {
			return ( new WC_Form_Fields_Integration )->get_fields_by_tab_id( sanitize_text_field( $_GET['bmtab'] ) );
		}

		return ( new WC_Form_Fields_Integration )->get_fields_by_tab_id( null );
	}

	/**
	 * @throws Exception
	 */
	public function init_once() {
		if ( self::$initiated ) {
			return;
		}

		$this->handle_admin_body();

		( new WC_Form_Fields_Integration() )->define_template_field();

		$settings = blue_media()->get_event_chain();

		$settings
			->on_wc_before_settings( 'checkout' )
			->action( function () {
				$tabs = new Settings_Tabs;

				if ( isset( $_GET['section'] ) && $_GET['section'] === 'bluemedia' ) {
					add_filter( 'woocommerce_get_sections_checkout',
						function ( $sections ) {
							return [];
						},
						1000 );
					if ( ! ( isset( $_GET['bmtab'] ) && $_GET['bmtab'] === 'vas' ) ) {
						$content = ( new Banner() )->get_banner_content();
						blue_media()->locate_template( 'settings_banner.php',
							[ 'content' => $content ] );
					}
					blue_media()->locate_template( 'settings_tabs.php',
						[ 'tabs' => $tabs ] );
				}
			} )
			->on_wc_before_settings( 'checkout' )
			->action( function () {
				if ( isset( $_GET['bmtab'] ) && $_GET['bmtab'] === 'help' ) {
					add_action( 'woocommerce_settings_checkout',
						function () {
							$GLOBALS['hide_save_button'] = true;
						} );
					add_action( 'woocommerce_after_settings_checkout',
						function () {
							$GLOBALS['hide_save_button'] = false;
						} );
				}
			} )
			->on_wc_before_settings( 'checkout' )
			->action( function () {
				$tabs          = ( new Settings_Tabs );
				$active_tab_id = $tabs->get_active_tab_id();
				if ( $active_tab_id === $tabs::VAS_TAB_ID ) {
					add_action( 'woocommerce_settings_checkout',
						function () {
							$GLOBALS['hide_save_button'] = true;
						} );
					add_action( 'woocommerce_after_settings_checkout',
						function () {
							$GLOBALS['hide_save_button'] = false;
						} );

					$vas_content = ( new Vas() )->get_vas_content();
					blue_media()->locate_template( 'settings_vas.php',
						[
							'vas_content' => $vas_content,
							'title'       => $tabs->get_active_tab_name(),
							'subtitle'    => __( 'Use the services of official and verified Autopay partners!',
								'bm-woocommerce' ),
						] );

				}
			} )
			->on_wp_init()
			->action( function () {
				$editor = new Css_Editor();
				$editor->handle_save();
			} )
			->on_wp_admin_footer()
			->action( function () {
				$current_screen = get_current_screen();

				if ( is_a( $current_screen,
						'WP_Screen' ) && 'woocommerce_page_wc-settings' === $current_screen->id ) {
					if ( isset( $_GET['tab'] ) && $_GET['tab'] == 'checkout' ) {
						if ( isset( $_GET['section'] ) && $_GET['section'] == 'bluemedia' ) {
							blue_media()->locate_template( 'settings_ga4_modals.php' );
						}
					}
				}
			} )
			->execute();

		self::$initiated = true;
	}

	private function handle_admin_body() {
		if ( isset( $_GET['section'] ) && $_GET['section'] === 'bluemedia' ) {
			$active_tab = ( new Settings_Tabs() )->get_active_tab_id();

			add_filter( 'admin_body_class',
				function ( $classes ) use ( $active_tab ) {
					$classes .= " autopay-active-tab-$active_tab";

					return $classes;
				} );
		}

	}

	public function render_settings( string $settings_html ) {
		$tabs          = ( new Settings_Tabs );
		$active_tab_id = $tabs->get_active_tab_id();


		switch ( $active_tab_id ) {
			case Settings_Tabs::AUTHENTICATION_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( 'Turn on sandbox mode or accept real payments.',
					'bm-woocommerce' );
				break;

			case Settings_Tabs::ADVERTISING_SERVICES_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( "The Ad services is a comprehensive solution that enables merchants to effectively promote their products directly from the shop's administration panel. The service is fully integrated with WooCommerce which allows the automatic creation of advertising campaigns tailored to the shop's product range, customers' purchase history and analysis of their preferences.",
					'bm-woocommerce' );
				break;
			case Settings_Tabs::PAYMENT_SETTINGS_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( 'Select the payment methods you wish to use.',
					'bm-woocommerce' );
				break;
			case Settings_Tabs::VAS_TAB_ID:
				$section_title    = '';
				$section_subtitle = '';
				break;
			case Settings_Tabs::ANALYTICS_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( 'The Autopay plugin allows you to send payment information to Google Analytics. Among other things, this makes it possible to track sales conversions within the Google Analytics platform. Communication with Google Analytics is an optional feature of the plug-in.',
					'bm-woocommerce' );
				break;
			case Settings_Tabs::HELP_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( 'Need support? Discover the content library that makes installing and configuring Autopay solutions a piece of cake.',
					'bm-woocommerce' );
				break;
			case Settings_Tabs::ADVANCED_SETTINGS_TAB_ID:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = __( 'Advanced settings intended for developers. When making changes in this section, please refer to the technical specifications to know how it will affect plugin.',
					'bm-woocommerce' );
				break;
			default:
				$section_title    = $tabs->get_active_tab_name();
				$section_subtitle = '';
		}

		blue_media()->locate_template( 'settings.php',
			[
				'settings_html' => $settings_html,
				'title'         => $section_title,
				'subtitle'      => $section_subtitle,
				'tab_id'        => $active_tab_id,
			] );
	}
}
