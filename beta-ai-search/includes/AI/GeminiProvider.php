<?php
namespace BetaAI\Search\AI;

defined( 'ABSPATH' ) || exit;

final class GeminiProvider implements ProviderInterface {
	private HttpClient $http;
	public function __construct( private string $base_url, private array $definition ) { $this->http = new HttpClient( 'gemini', $base_url ); }
	public function id(): string { return 'gemini'; }
	public function catalog(): array { return $this->definition['models']; }

	private function model_name( string $model ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_.-]+$/', $model ) ) {
			throw new AIException( 'invalid_model' );
		}
		return 'models/' . $model;
	}

	public function embed( array $texts, string $model, int $dimensions, bool $is_query ): ?array {
		$known = array_values( array_filter( $this->catalog(), static fn( $entry ) => $entry['id'] === $model ) );
		if ( ! $known || empty( $known[0]['embedding'] ) ) {
			throw new AIException( 'embedding_unsupported' );
		}
		if ( ! $texts || count( $texts ) > 64 ) {
			throw new AIException( 'invalid_embedding_batch' );
		}
		$requests = [];
		foreach ( $texts as $text ) {
			$request = [ 'model' => $this->model_name( $model ), 'content' => [ 'parts' => [ [ 'text' => $text ] ] ], 'taskType' => $is_query ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT' ];
			if ( $dimensions > 0 ) {
				$request['outputDimensionality'] = $dimensions;
			}
			$requests[] = $request;
		}
		$data = $this->http->request( $this->model_name( $model ) . ':batchEmbedContents', [ 'requests' => $requests ] );
		$rows = [];
		foreach ( ( $data['embeddings'] ?? [] ) as $row ) {
			$rows[] = $row['values'] ?? null;
		}
		$vectors = HttpClient::vectors( $rows, count( $texts ) );
		if ( $dimensions > 0 && count( $vectors[0] ) !== $dimensions ) {
			throw new AIException( 'embedding_dimension_mismatch' );
		}
		$tokens = $data['usageMetadata']['promptTokenCount'] ?? ( $data['metadata']['billableTokenCount'] ?? null );
		return [ 'vectors' => $vectors, 'model' => $model, 'usage' => [ 'input' => is_numeric( $tokens ) ? (int) $tokens : null, 'output' => null, 'total' => is_numeric( $tokens ) ? (int) $tokens : null ] ];
	}

	public function understand( string $text, array $context, string $model ): ?array {
		$data = $this->http->request( $this->model_name( $model ) . ':generateContent', [
			'systemInstruction' => [ 'parts' => [ [ 'text' => QuerySchema::system_prompt( $context ) ] ] ],
			'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => wp_json_encode( [ 'request' => $text ], JSON_UNESCAPED_UNICODE ) ] ] ] ],
			'generationConfig' => [ 'temperature' => 0, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json', 'responseSchema' => self::response_schema( QuerySchema::schema( false ) ), 'thinkingConfig' => [ 'thinkingBudget' => 0 ] ],
		] );
		$parts = $data['candidates'][0]['content']['parts'] ?? [];
		$content = implode( '', array_map( static fn( $part ) => $part['text'] ?? '', $parts ) );
		if ( 'MAX_TOKENS' === ( $data['candidates'][0]['finishReason'] ?? '' ) ) {
			throw new AIException( 'truncated_ai_response' );
		}
		return [ 'query' => QuerySchema::validate( $content ), 'model' => $model, 'usage' => HttpClient::usage( $data['usageMetadata'] ?? [], 'promptTokenCount', 'candidatesTokenCount', 'totalTokenCount' ) ];
	}

	private static function response_schema( array $schema ): array {
		if ( isset( $schema['type'] ) && is_array( $schema['type'] ) ) {
			$nullable = in_array( 'null', $schema['type'], true );
			$types = array_values( array_diff( $schema['type'], [ 'null' ] ) );
			$schema['type'] = strtoupper( $types[0] ?? 'string' );
			if ( $nullable ) { $schema['nullable'] = true; }
		} elseif ( isset( $schema['type'] ) && is_string( $schema['type'] ) ) {
			$schema['type'] = strtoupper( $schema['type'] );
		}
		foreach ( [ 'properties', 'items' ] as $key ) {
			if ( isset( $schema[ $key ] ) && is_array( $schema[ $key ] ) ) {
				$schema[ $key ] = 'items' === $key ? self::response_schema( $schema[ $key ] ) : array_map( [ self::class, 'response_schema' ], $schema[ $key ] );
			}
		}
		return $schema;
	}
	public function list_models(): array {
		$data = $this->http->request( 'models', null, [], 20 );
		$ids = [];
		foreach ( ( $data['models'] ?? [] ) as $model ) {
			if ( isset( $model['name'] ) && is_string( $model['name'] ) ) {
				$ids[] = preg_replace( '/^models\//', '', $model['name'] );
			}
		}
		return array_slice( $ids, 0, 300 );
	}
}
