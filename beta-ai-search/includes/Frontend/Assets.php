<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets {
	public function __construct( private Settings $settings ) {}
	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin' ] );
		add_action( 'elementor/frontend/after_register_scripts', [ $this, 'register' ] );
		add_action( 'elementor/preview/enqueue_scripts', [ $this, 'preview' ] );
	}
	public function register(): void {
		wp_register_style( 'bas-frontend', BAS_URL . 'assets/css/frontend.css', [], BAS_VERSION );
		wp_register_script( 'bas-frontend', BAS_URL . 'assets/js/frontend.js', [], BAS_VERSION, true );
		$config = [
			'apiUrl' => esc_url_raw( rest_url( 'beta-ai-search/v1/' ) ),
			'language' => $this->settings->get( 'language' ),
			'texts' => $this->settings->get( 'texts' ),
			'maxQueryLength' => 600,
			'enabled' => (bool) $this->settings->get( 'enabled' ),
		];
		wp_add_inline_script( 'bas-frontend', 'window.BetaAiSearchConfig=' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}
	public function preview(): void {
		$this->register();
		wp_enqueue_style( 'bas-frontend' );
		wp_enqueue_script( 'bas-frontend' );
	}
	public function admin( string $hook ): void {
		if ( ! str_contains( $hook, 'beta-ai-search' ) ) {
			return;
		}
		wp_enqueue_style( 'bas-admin', BAS_URL . 'assets/css/admin.css', [], BAS_VERSION );
		wp_enqueue_script( 'bas-admin', BAS_URL . 'assets/js/admin.js', [], BAS_VERSION, true );
		wp_add_inline_script( 'bas-admin', 'window.BetaAiSearchAdmin=' . wp_json_encode( [ 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'bas_admin' ) ] ) . ';', 'before' );
	}
}
