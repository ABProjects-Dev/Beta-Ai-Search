<?php
namespace BetaAI\Search\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION = 'bas_settings';
	public const FACETS = [ 'price', 'color', 'brand', 'size', 'material', 'technical', 'other' ];

	public static function defaults(): array {
		return [
			'enabled' => 1,
			'language' => 'fa',
			'global_flex' => 35,
			'attribute_flex' => [ 'price' => 20, 'color' => 35, 'brand' => 10, 'size' => 10, 'material' => 25, 'technical' => 25, 'other' => 25 ],
			'max_results' => 100,
			'cache_enabled' => 1,
			'cache_ttl' => 3600,
			'history_enabled' => 1,
			'store_query_text' => 0,
			'retention_days' => 30,
			'batch_size' => 5,
			'public_meta_keys' => '',
			'toman_rate' => '',
			'eur_rate' => '',
			'usd_rate' => '',
			'embedding' => [ 'mode' => 'auto', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 0 ],
			'query' => [ 'mode' => 'auto', 'provider' => 'openai', 'model' => 'gpt-4.1-mini' ],
			'providers' => [],
			'texts' => [ 'fa' => [], 'en' => [] ],
			'debug' => 0,
			'delete_on_uninstall' => 0,
		];
	}

	public static function texts( string $language = 'fa' ): array {
		$fa = [
			'placeholder' => 'چه محصولی مدنظر شماست؟ مثلاً کفش مشکی راحت برای پیاده‌روی…',
			'search' => 'جستجوی هوشمند',
			'loading' => 'در حال بررسی محصولات…',
			'empty' => 'محصولی مطابق درخواست شما پیدا نشد.',
			'clear_filter' => 'حذف فیلتر',
			'error' => 'جستجو انجام نشد. لطفاً دوباره تلاش کنید.',
			'retry' => 'تلاش مجدد',
			'clear_text' => 'پاک کردن متن',
			'label' => 'جستجوی محصولات با زبان طبیعی',
			'results' => '{count} محصول مرتبط',
			'previous' => 'قبلی',
			'next' => 'بعدی',
		];
		$en = [
			'placeholder' => 'What are you looking for? e.g. comfortable black walking shoes…',
			'search' => 'AI search', 'loading' => 'Finding your matches…',
			'empty' => 'No products match your request.', 'clear_filter' => 'Clear filter',
			'error' => 'Search could not be completed. Please try again.', 'retry' => 'Try again',
			'clear_text' => 'Clear text', 'label' => 'Search products using natural language',
			'results' => '{count} matching products', 'previous' => 'Previous', 'next' => 'Next',
		];
		return 'en' === $language ? $en : $fa;
	}

	public function all(): array {
		$saved = get_option( self::OPTION, [] );
		$all   = array_replace_recursive( self::defaults(), is_array( $saved ) ? $saved : [] );
		$custom = is_array( $all['texts'] ) ? $all['texts'] : [];
		if ( isset( $custom['placeholder'] ) ) {
			$custom = [ $all['language'] => $custom ];
		}
		$all['texts'] = array_merge( self::texts( $all['language'] ), is_array( $custom[ $all['language'] ] ?? null ) ? $custom[ $all['language'] ] : [] );
		return $all;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->all()[ $key ] ?? $default;
	}

	public function text( string $key ): string {
		$texts = $this->get( 'texts' );
		return (string) ( $texts[ $key ] ?? self::texts( $this->get( 'language' ) )[ $key ] ?? '' );
	}

	public function update( array $changes ): void {
		$saved = get_option( self::OPTION, [] );
		update_option( self::OPTION, $this->sanitize( array_replace_recursive( is_array( $saved ) ? $saved : [], $changes ) ), false );
		Reports::bump_revision();
		Reports::clear_cache();
	}

	public function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return $this->all();
		}
		$saved = get_option( self::OPTION, [] );
		$input = array_replace_recursive( is_array( $saved ) ? $saved : [], $input );
		$d = self::defaults();
		$out = [];
		foreach ( [ 'enabled', 'cache_enabled', 'history_enabled', 'store_query_text', 'debug', 'delete_on_uninstall' ] as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$out['language'] = in_array( $input['language'] ?? 'fa', [ 'fa', 'en' ], true ) ? $input['language'] : 'fa';
		$out['global_flex'] = $this->number( $input['global_flex'] ?? 35, 0, 100, 35 );
		foreach ( self::FACETS as $facet ) {
			$out['attribute_flex'][ $facet ] = $this->number( $input['attribute_flex'][ $facet ] ?? null, 0, 100, $d['attribute_flex'][ $facet ] );
		}
		foreach ( [ 'max_results' => [ 1, 500, 100 ], 'cache_ttl' => [ 60, 604800, 3600 ], 'retention_days' => [ 1, 365, 30 ], 'batch_size' => [ 1, 20, 5 ] ] as $key => $range ) {
			$out[ $key ] = $this->number( $input[ $key ] ?? null, ...$range );
		}
		$keys = preg_split( '/[\r\n,]+/', (string) ( $input['public_meta_keys'] ?? '' ) ) ?: [];
		$out['public_meta_keys'] = implode( "\n", array_slice( array_unique( array_filter( array_map( static function ( $key ) {
			$key = sanitize_key( trim( $key ) );
			return str_starts_with( $key, '_' ) ? '' : $key;
		}, $keys ) ) ), 0, 100 ) );
		foreach ( [ 'toman_rate', 'eur_rate', 'usd_rate' ] as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			$out[ $key ] = '' === $value ? '' : ( is_numeric( $value ) && $value > 0 ? (float) $value : $d[ $key ] );
		}
		foreach ( [ 'embedding', 'query' ] as $task ) {
			$raw = is_array( $input[ $task ] ?? null ) ? $input[ $task ] : [];
			$out[ $task ] = [
				'mode' => in_array( $raw['mode'] ?? '', [ 'auto', 'manual' ], true ) ? $raw['mode'] : 'auto',
				'provider' => sanitize_key( $raw['provider'] ?? $d[ $task ]['provider'] ),
				'model' => substr( sanitize_text_field( $raw['model'] ?? $d[ $task ]['model'] ), 0, 160 ),
			];
			if ( 'embedding' === $task ) {
				$out[ $task ]['dimensions'] = $this->number( $raw['dimensions'] ?? 0, 0, 8192, 0 );
			}
		}
		$registry = apply_filters( 'bas_ai_provider_definitions', \BetaAI\Search\AI\ProviderRegistry::definitions() );
		foreach ( $registry as $id => $definition ) {
			$raw = is_array( $input['providers'][ $id ] ?? null ) ? $input['providers'][ $id ] : [];
			$base = $raw['base_url'] ?? $definition['base_url'];
			$out['providers'][ $id ] = [
				'base_url' => untrailingslashit( esc_url_raw( $base, [ 'https' ] ) ),
				'embedding_capable' => empty( $raw['embedding_capable'] ) ? 0 : 1,
				'chat_capable' => empty( $raw['chat_capable'] ) ? 0 : 1,
				'rates' => [],
			];
			$rates = is_array( $raw['rates'] ?? null ) ? $raw['rates'] : [];
			if ( isset( $raw['rates_json'] ) && is_string( $raw['rates_json'] ) ) {
				$decoded = json_decode( wp_unslash( $raw['rates_json'] ), true );
				$rates = is_array( $decoded ) ? $decoded : [];
			}
			foreach ( array_slice( $rates, 0, 100, true ) as $model => $rate ) {
				$model = substr( sanitize_text_field( (string) $model ), 0, 160 );
				if ( '' === $model || ! is_array( $rate ) ) {
					continue;
				}
				$currency = strtoupper( sanitize_text_field( $rate['currency'] ?? 'USD' ) );
				$valid = in_array( $currency, [ 'USD', 'EUR', 'IRR', 'IRT' ], true ) && isset( $rate['input'], $rate['output'] ) && is_numeric( $rate['input'] ) && is_numeric( $rate['output'] ) && $rate['input'] >= 0 && $rate['output'] >= 0;
				$out['providers'][ $id ]['rates'][ $model ] = [
					'input' => $valid ? (float) $rate['input'] : null,
					'output' => $valid ? (float) $rate['output'] : null,
					'currency' => $currency,
					'basis' => 'per_million',
				];
			}
		}
		$saved = get_option( self::OPTION, [] );
		$existing_texts = is_array( $saved['texts'] ?? null ) ? $saved['texts'] : [];
		if ( isset( $existing_texts['placeholder'] ) ) {
			$existing_texts = [ $saved['language'] ?? 'fa' => $existing_texts ];
		}
		$out['texts'] = [ 'fa' => [], 'en' => [] ];
		foreach ( [ 'fa', 'en' ] as $language ) {
			$values = is_array( $input['texts'][ $language ] ?? null ) ? $input['texts'][ $language ] : [];
			if ( $language === ( $saved['language'] ?? 'fa' ) && isset( $input['texts']['placeholder'] ) ) {
				$values = $input['texts'];
			}
			$previous = is_array( $existing_texts[ $language ] ?? null ) ? $existing_texts[ $language ] : [];
			foreach ( array_keys( self::texts( $language ) ) as $key ) {
				if ( isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) ) {
					$out['texts'][ $language ][ $key ] = substr( sanitize_textarea_field( $values[ $key ] ), 0, 500 );
				} elseif ( isset( $previous[ $key ] ) ) {
					$out['texts'][ $language ][ $key ] = $previous[ $key ];
				}
			}
		}
		return $out;
	}

	private function number( mixed $value, int $min, int $max, int $default ): int {
		return is_numeric( $value ) ? max( $min, min( $max, (int) $value ) ) : $default;
	}

	public function fingerprint(): string {
		$all = $this->all();
		unset( $all['texts'], $all['debug'], $all['history_enabled'], $all['store_query_text'], $all['retention_days'], $all['batch_size'], $all['providers'], $all['delete_on_uninstall'] );
		// The public-field allowlist changes the indexed document and must invalidate cached IDs.
		return hash( 'sha256', wp_json_encode( $all ) );
	}
}
