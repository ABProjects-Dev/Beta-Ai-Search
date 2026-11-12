<?php
/**
 * TEST ONLY. Install as an mu-plugin in a disposable WordPress test site.
 * Intercepts one explicitly configured test endpoint; never intercepts real providers.
 * Controlled responses test the plugin pipeline, NOT live AI quality.
 */
defined( 'ABSPATH' ) || exit;

// The sandbox resolves allowed external hosts to a private proxy. This test-only
// exception is scoped to the intercepted host; the request never reaches the network.
add_filter( 'http_request_host_is_external', static fn( $external, $host ) => 'api.github.com' === $host ? true : $external, 10, 2 );
// Test-only placement above a real category archive, to verify global catalog search.
add_action( 'woocommerce_before_shop_loop', static function () { if ( is_product_category() ) { echo do_shortcode( '[beta_ai_search]' ); } }, 5 );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( ! str_starts_with( $url, 'https://api.github.com/__bas_test__/v1/' ) ) {
		return $pre;
	}
	$count = (int) get_option( 'bas_test_http_count', 0 );
	update_option( 'bas_test_http_count', $count + 1, false );
	$fault = get_option( 'bas_test_fault', '' );
	$response = static function ( $data, $status = 200 ) {
		return [ 'headers' => [], 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), 'response' => [ 'code' => $status, 'message' => 'Test response' ], 'cookies' => [], 'filename' => null ];
	};
	if ( 'timeout' === $fault ) { return new WP_Error( 'http_request_timeout', 'Controlled test timeout' ); }
	if ( 'transport' === $fault ) { return new WP_Error( 'http_request_failed', 'Controlled test transport failure' ); }
	if ( in_array( $fault, [ 'http500', 'badkey', 'quota', 'rate' ], true ) ) {
		return $response( [ 'error' => 'Controlled failure; no secrets' ], [ 'http500' => 500, 'badkey' => 401, 'quota' => 402, 'rate' => 429 ][ $fault ] );
	}
	if ( 'malformed' === $fault ) { return [ 'headers' => [], 'body' => '{not json', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ]; }
	$body = json_decode( $args['body'] ?? '{}', true ) ?: [];
	if ( str_ends_with( $url, '/models' ) ) {
		return $response( [ 'data' => [ [ 'id' => 'test-embed' ], [ 'id' => 'test-query' ] ] ] );
	}
	if ( str_ends_with( $url, '/embeddings' ) ) {
		$vectors = [];
		foreach ( $body['input'] as $index => $text ) {
			$vectors[] = [ 'index' => index_reverse( $index, count( $body['input'] ) ), 'embedding' => bas_test_vector( $text, (int) ( $body['dimensions'] ?? 8 ) ) ];
		}
		return $response( [ 'model' => $body['model'], 'data' => $vectors, 'usage' => [ 'prompt_tokens' => 20 * count( $body['input'] ), 'completion_tokens' => 0, 'total_tokens' => 20 * count( $body['input'] ) ] ] );
	}
	if ( str_ends_with( $url, '/chat/completions' ) ) {
		$user = json_decode( $body['messages'][1]['content'], true );
		$query = bas_test_normalize( $user['request'] ?? '' );
		$intents = get_option( 'bas_test_intents', [] );
		$intent = $intents[ $query ] ?? bas_test_intent( 'unknown request', [], [], [], null, null, 0.2 );
		if ( 'invalid_schema' === $fault ) { $intent = [ 'sql' => 'SELECT 1' ]; }
		return $response( [ 'model' => $body['model'], 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'content' => wp_json_encode( $intent, JSON_UNESCAPED_UNICODE ) ] ] ], 'usage' => [ 'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150 ] ] );
	}
	return new WP_Error( 'http_request_failed', 'Unknown test endpoint' );
}, 10, 3 );

function index_reverse( int $index, int $count ): int { return $count - 1 - $index; }
function bas_test_normalize( string $text ): string {
	return trim( preg_replace( '/\s+/u', ' ', strtr( mb_strtolower( $text ), [ 'ي' => 'ی', 'ك' => 'ک', 'آ' => 'ا', '‌' => ' ', '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9' ] ) ) );
}
function bas_test_intent( string $semantic, array $types = [], array $uses = [], array $attributes = [], ?float $min = null, ?float $max = null, float $confidence = 0.98, string $currency = 'store' ): array {
	return [ 'semantic_text' => $semantic, 'product_type' => $types, 'use_case' => $uses, 'attributes' => $attributes, 'price' => [ 'min' => $min, 'max' => $max, 'currency' => $currency ], 'confidence' => $confidence, 'language' => 'fa' ];
}
function bas_test_attr( string $key, array $values, string $importance = 'required' ): array { return [ 'key' => $key, 'values' => $values, 'importance' => $importance ]; }
function bas_test_vector( string $text, int $dimensions ): array {
	$text = bas_test_normalize( $text );
	$axes = [
		'shoe' => [ 'shoe', 'sneaker', 'کفش', 'کتانی' ],
		'walking' => [ 'walking', 'walk', 'پیاده روی', 'پیادهروی', 'قدم زدن' ],
		'comfort' => [ 'comfortable', 'comfort', 'راحت' ],
		'running' => [ 'running', 'دویدن' ],
		'bag' => [ 'backpack', 'bag', 'کوله', 'کیف' ],
		'laptop' => [ 'laptop', 'لپ تاپ' ],
		'headphones' => [ 'headphones', 'هدفون' ],
		'shirt' => [ 'shirt', 'تیشرت', 'پیراهن' ],
	];
	$vector = array_fill( 0, $dimensions, 0.0 );
	$index = 0;
	foreach ( $axes as $words ) {
		foreach ( $words as $word ) {
			if ( str_contains( $text, bas_test_normalize( $word ) ) ) { $vector[ $index ] = 1.0; break; }
		}
		++$index;
	}
	if ( ! array_sum( $vector ) ) { $vector[ count( $vector ) - 1 ] = 1.0; }
	return $vector;
}
