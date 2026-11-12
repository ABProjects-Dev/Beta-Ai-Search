<?php
namespace BetaAI\Search\AI;

defined( 'ABSPATH' ) || exit;

final class AnthropicProvider implements ProviderInterface {
	private HttpClient $http;
	public function __construct( private string $base_url, private array $definition ) { $this->http = new HttpClient( 'anthropic', $base_url ); }
	public function id(): string { return 'anthropic'; }
	public function catalog(): array { return $this->definition['models']; }
	public function embed( array $texts, string $model, int $dimensions, bool $is_query ): ?array {
		throw new AIException( 'embedding_unsupported', 0, 'Anthropic has no native embedding endpoint. Select a separate embedding provider.' );
	}
	public function understand( string $text, array $context, string $model ): ?array {
		$data = $this->http->request( 'messages', [
			'model' => $model, 'max_tokens' => 2000, 'temperature' => 0,
			'system' => QuerySchema::system_prompt( $context ),
			'messages' => [ [ 'role' => 'user', 'content' => wp_json_encode( [ 'request' => $text ], JSON_UNESCAPED_UNICODE ) ] ],
			'tools' => [ [ 'name' => 'extract_product_query', 'description' => 'Submit the validated product search intent. Do not answer the request itself.', 'input_schema' => QuerySchema::schema( false ) ] ],
			'tool_choice' => [ 'type' => 'tool', 'name' => 'extract_product_query' ],
		] );
		$input = null;
		foreach ( ( $data['content'] ?? [] ) as $block ) {
			if ( 'tool_use' === ( $block['type'] ?? '' ) && 'extract_product_query' === ( $block['name'] ?? '' ) ) {
				$input = $block['input'] ?? null;
			}
		}
		if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
			throw new AIException( 'truncated_ai_response' );
		}
		return [ 'query' => QuerySchema::validate( $input ), 'model' => (string) ( $data['model'] ?? $model ), 'usage' => HttpClient::usage( $data['usage'] ?? [], 'input_tokens', 'output_tokens', 'total_tokens' ) ];
	}
	public function list_models(): array {
		$data = $this->http->request( 'models', null, [], 20 );
		$ids = [];
		foreach ( ( $data['data'] ?? [] ) as $model ) {
			if ( isset( $model['id'] ) && is_string( $model['id'] ) ) {
				$ids[] = $model['id'];
			}
		}
		return array_slice( $ids, 0, 300 );
	}
}
