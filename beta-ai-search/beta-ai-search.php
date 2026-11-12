<?php
/**
 * Plugin Name: Beta AI Search
 * Description: AI-powered natural-language product search for WooCommerce, with semantic indexing and in-place Elementor grid adapters.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Beta AI Search
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beta-ai-search
 * Domain Path: /languages
 * WC requires at least: 8.2
 */

defined( 'ABSPATH' ) || exit;

define( 'BAS_VERSION', '1.0.0' );
define( 'BAS_FILE', __FILE__ );
define( 'BAS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BAS_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Beta AI Search requires PHP 8.1 or newer.', 'beta-ai-search' ) . '</p></div>';
	} );
	return;
}

if ( ! extension_loaded( 'mbstring' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Beta AI Search requires the PHP mbstring extension for Persian/English text processing.', 'beta-ai-search' ) . '</p></div>';
	} );
	return;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'BetaAI\\Search\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = BAS_PATH . 'includes/' . $relative . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ \BetaAI\Search\Core\Schema::class, 'install' ] );
register_activation_hook( __FILE__, [ \BetaAI\Search\Core\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \BetaAI\Search\Core\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
	\BetaAI\Search\Core\Plugin::instance()->boot();
}, 20 );
