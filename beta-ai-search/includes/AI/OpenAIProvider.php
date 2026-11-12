<?php
namespace BetaAI\Search\AI;

defined( 'ABSPATH' ) || exit;

class OpenAIProvider implements ProviderInterface {
	protected HttpClient $http;
	public function __construct( protected string $id, protected string $base_url, protected array $definition ) {
		$this->http = new HttpClient( $id, $base_url );
	}
	public function id(): string { return $this->id; }
	public function catalog(): array { return $this->definition['models']; }

	public function embed( array $texts, string $model, int $dimensions, bool $is_query ): ?array {
		if ( ! $this->supports( $model, 'embedding' ) ) {
			throw new AIException( 'embedding_unsupported' );
		}
		if ( ! $texts || count( $texts ) > 64 ) {
			throw new AIException( 'invalid_embedding_batch' );
		}
		$body = [ 'model' => $model, 'input' => array_values( $texts ), 'encoding_format' => 'float' ];
		if ( $dimensions > 0 && ( 'openai' === $this->id || ! empty( $this->definition['dimensions_parameter'] ) ) ) {
			$body['dimensions'] = $dimensions;
		}
		$data = $this->http->request( 'embeddings', $body );
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) || count( $data['data'] ) !== count( $texts ) ) {
			throw new AIException( 'invalid_embeddings' );
		}
		$rows = [];
		foreach ( $data['data'] as $row ) {
			$index = $row['index'] ?? null;
			if ( ! is_int( $index ) || $index < 0 || $index >= count( $texts ) || isset( $rows[ $index ] ) || ! isset( $row['embedding'] ) ) {
				throw new AIException( 'invalid_embeddings' );
			}
			$rows[ $index ] = $row['embedding'];
		}
		ksort( $rows );
		$vectors = HttpClient::vectors( array_values( $rows ), count( $texts ) );
		if ( $dimensions > 0 && count( $vectors[0] ) !== $dimensions ) {
			throw new AIException( 'embedding_dimension_mismatch' );
		}
		return [ 'vectors' => $vectors, 'model' => (string) ( $data['model'] ?? $model ), 'usage' => HttpClient::usage( $data['usage'] ?? [] ) ];
	}

	public function understand( string $text, array $context, string $model ): ?array {
		if ( ! $this->supports( $model, 'chat' ) ) {
			throw new AIException( 'chat_unsupported' );
		}
		$strict = ! empty( $this->definition['json_schema'] );
		$format = $strict ? [ 'type' => 'json_schema', 'json_schema' => [ 'name' => 'product_search_intent', 'strict' => true, 'schema' => QuerySchema::schema() ] ] : [ 'type' => 'json_object' ];
		$body = [
			'model' => $model,
			'messages' => [ [ 'role' => 'system', 'content' => QuerySchema::system_prompt( $context ) ], [ 'role' => 'user', 'content' => wp_json_encode( [ 'request' => $text ], JSON_UNESCAPED_UNICODE ) ] ],
			'response_format' => $format,
		];
		if ( ! in_array( $this->id, [ 'deepseek', 'mistral' ], true ) ) {
			$body['temperature'] = 0;
		}
		if ( 'openai' === $this->id && str_starts_with( $model, 'gpt-5' ) ) {
			unset( $body['temperature'] );
			$body['max_completion_tokens'] = 2000;
		} else {
			$body['max_tokens'] = 2000;
		}
		$data = $this->http->request( 'chat/completions', $body );
		$content = $data['choices'][0]['message']['content'] ?? null;
		$finish = $data['choices'][0]['finish_reason'] ?? '';
		if ( 'length' === $finish ) {
			throw new AIException( 'truncated_ai_response' );
		}
		return [ 'query' => QuerySchema::validate( $content ), 'model' => (string) ( $data['model'] ?? $model ), 'usage' => HttpClient::usage( $data['usage'] ?? [] ) ];
	}

	public function list_models(): array {
		$data = $this->http->request( 'models', null, [], 20 );
		$ids = [];
		foreach ( ( $data['data'] ?? [] ) as $model ) {
			if ( isset( $model['id'] ) && is_string( $model['id'] ) ) {
				$ids[] = substr( $model['id'], 0, 160 );
			}
		}
		return array_slice( array_unique( $ids ), 0, 300 );
	}

	protected function supports( string $model, string $capability ): bool {
		if ( 'custom' === $this->id ) {
			return ! empty( $this->definition[ $capability . '_capable' ] );
		}
		foreach ( $this->catalog() as $entry ) {
			if ( $entry['id'] === $model ) {
				return ! empty( $entry[ $capability ] );
			}
		}
		// OpenRouter exposes a standard chat endpoint for manually selected models.
		// Automatic selection still uses only the declared catalog, never a guessed model.
		if ( 'openrouter' === $this->id && 'chat' === $capability ) {
			return true;
		}
		if ( in_array( $this->id, [ 'openrouter', 'custom' ], true ) ) {
			return false;
		}
		$prefixes = $this->definition[ $capability . '_prefixes'] ?? [];
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
