<?php
namespace BetaAI\Search\Core;

use BetaAI\Search\Admin\Admin;
use BetaAI\Search\AI\ProviderRegistry;
use BetaAI\Search\Index\Indexer;
use BetaAI\Search\Index\ProductExtractor;
use BetaAI\Search\Index\Queue;
use BetaAI\Search\Search\SearchService;
use BetaAI\Search\Frontend\Assets;
use BetaAI\Search\Frontend\GridRenderer;
use BetaAI\Search\Frontend\RestApi;
use BetaAI\Search\Frontend\WidgetManager;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?Plugin $instance = null;
	public Settings $settings;
	public ProviderRegistry $providers;
	public Queue $queue;
	public Indexer $indexer;
	public SearchService $search;
	public GridRenderer $renderer;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->providers = new ProviderRegistry( $this->settings );
		$this->queue     = new Queue();
		$this->indexer   = new Indexer( $this->settings, $this->providers, $this->queue );
		$this->search    = new SearchService( $this->settings, $this->providers );
		$this->renderer  = new GridRenderer();
	}

	public function boot(): void {
		Schema::maybe_upgrade();
		$this->queue->hooks();
		$this->indexer->hooks();
		$this->renderer->hooks();
		( new Assets( $this->settings ) )->hooks();
		( new WidgetManager( $this->settings ) )->hooks();
		( new RestApi( $this->search, $this->renderer, $this->indexer, $this->providers, $this->settings ) )->hooks();
		if ( is_admin() ) {
			( new Admin( $this->settings, $this->indexer, $this->providers ) )->hooks();
		}
		if ( ! function_exists( 'WC' ) ) {
			add_action( 'admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Beta AI Search needs an active WooCommerce installation. The store grid has not been changed.', 'beta-ai-search' ) . '</p></div>';
			} );
		}
		add_action( 'init', static function (): void {
			load_plugin_textdomain( 'beta-ai-search', false, dirname( plugin_basename( BAS_FILE ) ) . '/languages' );
		} );
		add_action( 'bas_privacy_cleanup', [ Reports::class, 'cleanup' ] );
		if ( ! wp_next_scheduled( 'bas_privacy_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bas_privacy_cleanup' );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public static function activate(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$instance = self::instance();
		try {
			if ( ! $instance->indexer->active_job() ) {
				$instance->indexer->start( false );
			}
		} catch ( \BetaAI\Search\AI\AIException ) {
			// No provider is configured yet. Setup remains available; indexing starts after configuration.
		}
		Indexer::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'bas_index_worker' );
		wp_clear_scheduled_hook( 'bas_index_cleanup' );
		wp_clear_scheduled_hook( 'bas_privacy_cleanup' );
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}bas_jobs SET lock_token='',lock_until=0 WHERE status='running'" );
	}
}
