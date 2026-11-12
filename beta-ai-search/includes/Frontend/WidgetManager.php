<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class WidgetManager {
	public function __construct( private Settings $settings ) {}
	public function hooks(): void {
		add_shortcode( 'beta_ai_search', function ( $atts ) {
			$atts = shortcode_atts( [ 'target' => '', 'template' => 'classic', 'scope' => 'first' ], $atts, 'beta_ai_search' );
			return SearchBox::render( $this->settings, $atts );
		} );
		add_action( 'elementor/elements/categories_registered', function ( $manager ) {
			$manager->add_category( 'beta-ai-search', [ 'title' => __( 'Beta AI Search', 'beta-ai-search' ), 'icon' => 'eicon-search' ] );
		} );
		add_action( 'elementor/widgets/register', function ( $manager ) {
			if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
				return;
			}
			require_once BAS_PATH . 'includes/Frontend/ElementorWidget.php';
			$manager->register( new ElementorWidget() );
		} );
	}
}
