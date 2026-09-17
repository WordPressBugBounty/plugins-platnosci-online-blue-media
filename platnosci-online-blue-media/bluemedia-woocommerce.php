<?php
declare( strict_types=1 );

/**
 * Plugin Name: Autopay
 * Plugin URI: https://wordpress.org/plugins/platnosci-online-blue-media
 * Description: Autopay for Woocommerce
 * Tags: woocommerce, bluemedia, Autopay
 * Version: 5.0.3
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 7.9
 * WC tested up to: 11.0
 * Author: Autopay S.A.
 * Author URI: https://autopay.pl
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: platnosci-online-blue-media
 * Domain Path: /languages/
 *
 * Copyright 2026 Autopay S.A.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists('blue_media') ) {
	return;
}

require_once __DIR__ . '/compatibility.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

if ( blue_media_system_check() ) {
	require_once __DIR__ . '/vendor/autoload.php';
	require_once 'dependencies.php';

	/*
	 * WordPress 6.7+ JIT translation loading always checks WP_LANG_DIR/plugins first.
	 * If the system language pack there is outdated (missing strings added after its
	 * release), those strings fall back to English even though the plugin bundles a
	 * complete .mo. Redirect mofile loading to the bundled file so it takes priority.
	 */
	add_filter(
		'load_textdomain_mofile',
		static function ( string $mofile, string $domain ): string {
			if ( 'platnosci-online-blue-media' !== $domain ) {
				return $mofile;
			}
			$bundled_file = __DIR__ . '/languages/' . basename( $mofile );
			return file_exists( $bundled_file ) ? $bundled_file : $mofile;
		},
		10,
		2
	);

	function blue_media(): Ilabs\BM_Woocommerce\Plugin {
		return new Ilabs\BM_Woocommerce\Plugin();
	}

	$autopay_config = [
		'__FILE__'    => __FILE__,
		'slug'        => 'bm_woocommerce',
		'lang_dir'    => 'languages',
		'text_domain' => 'platnosci-online-blue-media',
	];

	blue_media()->execute( $autopay_config );
}
