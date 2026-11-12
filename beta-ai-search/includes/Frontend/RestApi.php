<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\AI\AIException;
use BetaAI\Search\AI\ProviderRegistry;
use BetaAI\Search\Core\RateLimiter;
use BetaAI\Search\Core\Reports;
use BetaAI\Search\Core\Settings;
use BetaAI\Search\Index\Indexer;
use BetaAI\Search\Search\SearchService;

defined( 'ABSPATH' ) || exit;

final class RestApi {
	public function __construct( private SearchService $search, private GridRenderer $renderer, private Indexer $indexer, private ProviderRegistry $providers, private Settings $settings ) {}
	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}
	public function register(): void {
		register_rest_route( 'beta-ai-search/v1', '/search', [
			'methods' => 'POST', 'permission_callback' => '__return_true',
			'args' => [ 'query' => [ 'required' => true, 'type' => 'string', 'maxLength' => 600 ] ],
			'callback' => [ $this, 'search' ],
		] );
		register_rest_route( 'beta-ai-search/v1', '/render', [
			'methods' => 'POST', 'permission_callback' => '__return_true',
			'args' => [
				'ids' => [ 'required' => true, 'type' => 'array', 'maxItems' => 500, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ],
				'page' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 48, 'default' => 12 ],
				'columns' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 4 ],
				'context' => [ 'type' => 'object', 'default' => [] ],
			],
			'callback' => [ $this, 'render' ],
		] );
		register_rest_route( 'beta-ai-search/v1', '/admin/index', [
			'methods' => 'GET', 'permission_callback' => static fn() => \BetaAI\Search\Core\Plugin::can_manage(),
			'callback' => function () { return rest_ensure_response( $this->indexer->status() ); },
		] );
	}
	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! RateLimiter::allow( 'search', (int) apply_filters( 'bas_search_rate_limit', 12 ) ) ) {
			return new \WP_Error( 'bas_rate_limited', __( 'Too many searches. Please wait a moment.', 'beta-ai-search' ), [ 'status' => 429 ] );
		}
		try {
			return rest_ensure_response( $this->search->search( (string) $request['query'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'bas_invalid_query', __( 'Enter a search request between 1 and 600 characters.', 'beta-ai-search' ), [ 'status' => 400 ] );
		} catch ( AIException $e ) {
			return $this->error_response( $e );
		} catch ( \Throwable $e ) {
			Reports::error( 'search', new AIException( 'search_processing_error' ) );
			return new \WP_Error( 'bas_search_failed', $this->settings->text( 'error' ), [ 'status' => 503 ] );
		}
	}
	public function render( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! RateLimiter::allow( 'render', 60 ) ) {
			return new \WP_Error( 'bas_rate_limited', __( 'Too many requests.', 'beta-ai-search' ), [ 'status' => 429 ] );
		}
		try {
			$context = $request['context'];
			return rest_ensure_response( $this->renderer->render( (array) $request['ids'], (int) $request['page'], (int) $request['page_size'], is_array( $context ) ? $context : [], (int) $request['columns'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'bas_invalid_grid', __( 'The existing grid could not be addressed safely.', 'beta-ai-search' ), [ 'status' => 400 ] );
		} catch ( AIException $e ) {
			return $this->error_response( $e );
		} catch ( \Throwable $e ) {
			Reports::error( 'render', new AIException( 'render_failed' ) );
			return new \WP_Error( 'bas_render_failed', $this->settings->text( 'error' ), [ 'status' => 500 ] );
		}
	}
	private function error_response( AIException $e ): \WP_Error {
		$status = match ( $e->safe_code ) {
			'index_not_ready', 'provider_not_configured', 'no_capable_model' => 503,
			'currency_not_configured' => 422,
			'invalid_api_key', 'quota_exceeded', 'rate_limited' => 502,
			default => 503,
		};
		return new \WP_Error( 'bas_' . $e->safe_code, $this->settings->text( 'error' ), [ 'status' => $status, 'retry' => true ] );
	}
}
