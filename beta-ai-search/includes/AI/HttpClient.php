<?php
namespace BetaAI\Search\AI;

use BetaAI\Search\Core\Secrets;

defined( 'ABSPATH' ) || exit;

final class HttpClient {
	public function __construct( protected string $provider, protected string $base_url ) {}

	public function request( string $path, ?array $body = null, array $headers = [], int $timeout = 35 ): array {
		$key = Secrets::get( $this->provider );
		if ( '' === $key ) {
			throw new AIException( 'missing_api_key' );
		}
		$url = untrailingslashit( $this->base_url ) . '/' . ltrim( $path, '/' );
		if ( ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_FRAGMENT ) ) {
			throw new AIException( 'unsafe_endpoint' );
		}
		$headers = array_merge( [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ], $headers );
		if ( 'gemini' === $this->provider ) {
			$headers['x-goog-api-key'] = $key;
		} elseif ( 'anthropic' === $this->provider ) {
			$headers['x-api-key'] = $key;
			$headers['anthropic-version'] = '2023-06-01';
		} else {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$response = wp_safe_remote_request( $url, [
			'method' => null === $body ? 'GET' : 'POST',
			'timeout' => $timeout,
			'redirection' => 2,
			'limit_response_size' => 4 * MB_IN_BYTES,
			'headers' => $headers,
			'body' => null === $body ? null : wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
			'data_format' => 'body',
		] );
		if ( is_wp_error( $response ) ) {
			$code = $response->get_error_code();
			throw new AIException( str_contains( $code, 'timeout' ) ? 'timeout' : 'connection_error' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$code = match ( true ) {
				401 === $status || 403 === $status => 'invalid_api_key',
				429 === $status => 'rate_limited',
				402 === $status => 'quota_exceeded',
				408 === $status || 504 === $status => 'timeout',
				$status >= 500 => 'provider_unavailable',
				default => 'api_error',
			};
			// Never include the raw response body, request headers, or query in an error.
			throw new AIException( $code, $status );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new AIException( 'invalid_provider_response', $status );
		}
		return $data;
	}

	public static function vectors( mixed $rows, int $expected ): array {
		if ( ! is_array( $rows ) || count( $rows ) !== $expected || ! array_is_list( $rows ) ) {
			throw new AIException( 'invalid_embeddings' );
		}
		$vectors = [];
		$dimension = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 2 || count( $row ) > 8192 || ! array_is_list( $row ) ) {
				throw new AIException( 'invalid_embeddings' );
			}
			$norm = 0.0;
			foreach ( $row as $value ) {
				if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || abs( (float) $value ) > 1e6 ) {
					throw new AIException( 'invalid_embeddings' );
				}
				$norm += (float) $value * (float) $value;
			}
			if ( $norm <= 0 || ( $dimension && count( $row ) !== $dimension ) ) {
				throw new AIException( 'invalid_embeddings' );
			}
			$dimension = count( $row );
			$norm = sqrt( $norm );
			$vectors[] = array_map( static fn( $value ) => (float) $value / $norm, $row );
		}
		return $vectors;
	}

	public static function usage( mixed $usage, string $input = 'prompt_tokens', string $output = 'completion_tokens', string $total = 'total_tokens' ): array {
		$usage = is_array( $usage ) ? $usage : [];
		$value = static fn( string $key ) => isset( $usage[ $key ] ) && is_numeric( $usage[ $key ] ) && $usage[ $key ] >= 0 ? (int) $usage[ $key ] : null;
		return [ 'input' => $value( $input ), 'output' => $value( $output ), 'total' => $value( $total ) ];
	}
}
