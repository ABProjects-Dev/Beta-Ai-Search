<?php
namespace BetaAI\Search\AI;

use BetaAI\Search\Core\Settings;
use BetaAI\Search\Core\Secrets;
use BetaAI\Search\Core\Reports;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	public function __construct( private Settings $settings ) {}

	public static function definitions(): array {
		$chat = static fn( string $id ) => [ 'id' => $id, 'chat' => true, 'embedding' => false ];
		$embed = static fn( string $id, int $dims ) => [ 'id' => $id, 'chat' => false, 'embedding' => true, 'dimensions' => $dims ];
		return [
			'openai' => [ 'label' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'json_schema' => true, 'dimensions_parameter' => true, 'chat_prefixes' => [ 'gpt-4.1', 'gpt-4o', 'gpt-5', 'o3', 'o4' ], 'models' => [ $chat( 'gpt-4.1-mini' ), $chat( 'gpt-4.1' ), $chat( 'gpt-4o-mini' ), $embed( 'text-embedding-3-small', 1536 ), $embed( 'text-embedding-3-large', 3072 ) ] ],
			'gemini' => [ 'label' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'dimensions_parameter' => true, 'models' => [ $chat( 'gemini-2.5-flash' ), $chat( 'gemini-2.5-flash-lite' ), $chat( 'gemini-2.5-pro' ), $embed( 'gemini-embedding-001', 3072 ) ] ],
			'anthropic' => [ 'label' => 'Anthropic Claude', 'base_url' => 'https://api.anthropic.com/v1', 'models' => [ $chat( 'claude-sonnet-4-5' ), $chat( 'claude-haiku-4-5' ), $chat( 'claude-opus-4-5' ) ] ],
			'deepseek' => [ 'label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'chat_prefixes' => [ 'deepseek-chat', 'deepseek-reasoner' ], 'models' => [ $chat( 'deepseek-chat' ), $chat( 'deepseek-reasoner' ) ] ],
			'groq' => [ 'label' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1', 'chat_prefixes' => [ 'llama-', 'openai/gpt-oss', 'qwen/', 'meta-llama/' ], 'models' => [ $chat( 'llama-3.3-70b-versatile' ), $chat( 'openai/gpt-oss-20b' ) ] ],
			'mistral' => [ 'label' => 'Mistral', 'base_url' => 'https://api.mistral.ai/v1', 'chat_prefixes' => [ 'mistral-', 'ministral-', 'magistral-', 'open-mistral' ], 'models' => [ $chat( 'mistral-small-latest' ), $chat( 'mistral-medium-latest' ), $embed( 'mistral-embed', 1024 ) ] ],
			'openrouter' => [ 'label' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1', 'models' => [ $chat( 'openai/gpt-4.1-mini' ), $chat( 'google/gemini-2.5-flash' ), $chat( 'anthropic/claude-sonnet-4.5' ), $embed( 'openai/text-embedding-3-small', 1536 ), $embed( 'mistralai/mistral-embed', 1024 ) ] ],
			'custom' => [ 'label' => 'Custom OpenAI-compatible', 'base_url' => '', 'models' => [] ],
		];
	}

	public function get( string $id ): ProviderInterface {
		$definitions = apply_filters( 'bas_ai_provider_definitions', self::definitions() );
		if ( ! isset( $definitions[ $id ] ) ) {
			throw new AIException( 'unknown_provider' );
		}
		$definition = $definitions[ $id ];
		$config = $this->settings->get( 'providers' )[ $id ] ?? [];
		$base = $config['base_url'] ?? $definition['base_url'];
		if ( 'custom' === $id ) {
			$definition['embedding_capable'] = ! empty( $config['embedding_capable'] );
			$definition['chat_capable'] = ! empty( $config['chat_capable'] );
		}
		if ( 'openrouter' === $id ) {
			$definition['chat_capable'] = true;
		}
		$provider = match ( $id ) {
			'gemini' => new GeminiProvider( $base, $definition ),
			'anthropic' => new AnthropicProvider( $base, $definition ),
			default => new OpenAIProvider( $id, $base, $definition ),
		};
		return apply_filters( 'bas_ai_provider_instance', $provider, $id, $definition, $config );
	}

	public function resolve( string $task, bool $active = false ): array {
		if ( ! in_array( $task, [ 'embedding', 'query' ], true ) ) {
			throw new AIException( 'unknown_task' );
		}
		if ( $active && 'embedding' === $task ) {
			$config = get_option( 'bas_active_config', [] );
			if ( is_array( $config ) && ! empty( $config['model_key'] ) ) {
				return $config;
			}
		}
		$selection = $this->settings->get( $task );
		$definitions = apply_filters( 'bas_ai_provider_definitions', self::definitions() );
		$configured = $this->settings->get( 'providers' );
		$candidates = [];
		if ( 'manual' === $selection['mode'] ) {
			$id = $selection['provider'];
			if ( ! isset( $definitions[ $id ] ) || ! Secrets::configured( $id ) ) {
				throw new AIException( 'provider_not_configured' );
			}
			$model = $selection['model'];
			$known = array_values( array_filter( $definitions[ $id ]['models'], static fn( $entry ) => $entry['id'] === $model ) );
			if ( $known && empty( $known[0][ $task === 'embedding' ? 'embedding' : 'chat' ] ) ) {
				throw new AIException( $task . '_unsupported' );
			}
			if ( ! $known && 'custom' === $id && empty( $configured[ $id ][ ( 'embedding' === $task ? 'embedding' : 'chat' ) . '_capable' ] ) ) {
				throw new AIException( $task . '_unsupported' );
			}
			$candidates[] = [ 'provider' => $id, 'model' => $model, 'dimensions' => 'embedding' === $task ? ( (int) $selection['dimensions'] ?: (int) ( $known[0]['dimensions'] ?? 0 ) ) : 0 ];
		} else {
			foreach ( $definitions as $id => $definition ) {
				if ( ! Secrets::configured( $id ) ) {
					continue;
				}
				foreach ( $definition['models'] as $entry ) {
					if ( ! empty( $entry[ 'embedding' === $task ? 'embedding' : 'chat' ] ) ) {
						$candidates[] = [ 'provider' => $id, 'model' => $entry['id'], 'dimensions' => 'embedding' === $task ? ( (int) $selection['dimensions'] ?: (int) $entry['dimensions'] ) : 0 ];
						break;
					}
				}
			}
			// A custom endpoint participates in auto only with an explicitly pinned, declared-capable model.
			$custom_capability = 'embedding' === $task ? 'embedding_capable' : 'chat_capable';
			if ( ! empty( $configured['custom'][ $custom_capability ] ) && Secrets::configured( 'custom' ) && '' !== $selection['model'] && ( 'query' === $task || $selection['dimensions'] > 0 ) ) {
				$candidates[] = [ 'provider' => 'custom', 'model' => $selection['model'], 'dimensions' => 'embedding' === $task ? (int) $selection['dimensions'] : 0 ];
			}
		}
		if ( ! $candidates ) {
			throw new AIException( 'no_capable_model' );
		}
		$resolved = $candidates[0];
		if ( 'embedding' === $task && 0 === $resolved['dimensions'] ) {
			throw new AIException( 'configure_dimensions' );
		}
		if ( 'embedding' === $task && 'custom' !== $resolved['provider'] ) {
			$entry = array_values( array_filter( $definitions[ $resolved['provider'] ]['models'], static fn( $model ) => $model['id'] === $resolved['model'] ) )[0] ?? null;
			if ( $entry && ( $resolved['dimensions'] > (int) $entry['dimensions'] || ( empty( $definitions[ $resolved['provider'] ]['dimensions_parameter'] ) && $resolved['dimensions'] !== (int) $entry['dimensions'] ) ) ) {
				throw new AIException( 'embedding_dimensions_unsupported' );
			}
		}
		$base = $configured[ $resolved['provider'] ]['base_url'] ?? $definitions[ $resolved['provider'] ]['base_url'];
		$resolved['model_key'] = self::model_key( $resolved['provider'], $resolved['model'], $resolved['dimensions'], $base );
		$resolved['task'] = $task;
		return $resolved;
	}

	public static function model_key( string $provider, string $model, int $dimensions, string $base = '' ): string {
		return hash( 'sha256', wp_json_encode( [ $provider, $model, $dimensions, $base, 'retrieval-v1' ] ) );
	}

	public function embed( array $texts, array $resolved, bool $is_query = false ): array {
		try {
			$result = $this->get( $resolved['provider'] )->embed( $texts, $resolved['model'], $resolved['dimensions'], $is_query );
			if ( ! $result ) {
				throw new AIException( 'invalid_embeddings' );
			}
			$vectors = HttpClient::vectors( $result['vectors'], count( $texts ) );
			if ( count( $vectors[0] ) !== $resolved['dimensions'] ) {
				throw new AIException( 'embedding_dimension_mismatch' );
			}
			Reports::usage( $resolved['provider'], $result['model'], 'embedding', $result['usage'] );
			return $vectors;
		} catch ( AIException $e ) {
			Reports::error( 'embedding', $e, $resolved['provider'], $resolved['model'] );
			throw $e;
		}
	}

	public function understand( string $text, array $context ): array {
		$resolved = $this->resolve( 'query' );
		try {
			$result = $this->get( $resolved['provider'] )->understand( $text, $context, $resolved['model'] );
			if ( ! $result ) {
				throw new AIException( 'invalid_ai_schema' );
			}
			$query = QuerySchema::validate( $result['query'] );
			Reports::usage( $resolved['provider'], $result['model'], 'query', $result['usage'] );
			return [ 'query' => $query, 'resolved' => $resolved, 'model' => $result['model'] ];
		} catch ( AIException $e ) {
			Reports::error( 'query', $e, $resolved['provider'], $resolved['model'] );
			throw $e;
		}
	}
}
