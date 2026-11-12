<?php
namespace BetaAI\Search\Search;

use BetaAI\Search\AI\AIException;
use BetaAI\Search\AI\ProviderRegistry;
use BetaAI\Search\Core\Reports;
use BetaAI\Search\Core\Schema;
use BetaAI\Search\Core\Settings;
use BetaAI\Search\Index\Indexer;

defined( 'ABSPATH' ) || exit;

final class SearchService {
	public function __construct( private Settings $settings, private ProviderRegistry $providers ) {}

	public function search( string $raw ): array {
		$start = microtime( true );
		$normalized = '';
		try {
			if ( ! $this->settings->get( 'enabled' ) || ! function_exists( 'wc_get_product' ) ) {
				throw new AIException( 'search_unavailable' );
			}
			$query = Text::clean_query( $raw );
			$normalized = Text::normalize( $query );
			$config = get_option( 'bas_active_config', [] );
			$model_key = get_option( 'bas_active_model', '' );
			if ( ! is_array( $config ) || ! $model_key || ( $config['model_key'] ?? '' ) !== $model_key ) {
				throw new AIException( 'index_not_ready' );
			}
			$query_model = $this->providers->resolve( 'query' );
			$currency = get_woocommerce_currency();
			$query_endpoint = $this->settings->get( 'providers' )[ $query_model['provider'] ]['base_url'] ?? '';
			$cache_key = hash( 'sha256', wp_json_encode( [ $normalized, $this->settings->fingerprint(), $model_key, $query_model['provider'], $query_model['model'], $query_endpoint, Reports::revision(), $currency, 'v2' ] ) );
			$cached = $this->cache_get( $cache_key );
			if ( null !== $cached ) {
				Reports::search( $normalized, $cached['total'] ? 'success' : 'no_results', $cached['total'], (int) ( ( microtime( true ) - $start ) * 1000 ), true );
				return array_replace( $cached, [ 'cached' => true ] );
			}
			$understanding = $this->providers->understand( $query, $this->context() );
			$intent = $understanding['query'];
			if ( $intent['confidence'] < (float) apply_filters( 'bas_minimum_query_confidence', 0.60 ) ) {
				$result = [ 'ids' => [], 'total' => 0, 'catalog_matches' => 0, 'cached' => false ];
				Reports::search( $normalized, 'no_results', 0, (int) ( ( microtime( true ) - $start ) * 1000 ) );
				return $result;
			}
			$explicit_price = Text::price_bounds( $normalized );
			if ( null !== $explicit_price ) {
				$intent['price'] = $explicit_price;
			}
			$intent['price'] = $this->convert_price( $intent['price'], $currency );
			$embedding_text = implode( ' | ', array_filter( array_merge(
				[ $intent['semantic_text'] ], $intent['product_type'], $intent['use_case'],
				array_map( static fn( $attribute ) => implode( ' ', $attribute['values'] ), $intent['attributes'] )
			) ) );
			$query_vectors = $this->providers->embed( [ mb_substr( $embedding_text, 0, 6000 ) ], $config, true );
			$query_vector = $query_vectors[0];
			$matches = $this->scan( $model_key, $query_vector, $intent );
			usort( $matches, static fn( $a, $b ) => $b['in_stock'] <=> $a['in_stock'] ?: $b['score'] <=> $a['score'] ?: $a['product_id'] <=> $b['product_id'] );
			$ids = array_map( 'intval', array_column( array_slice( $matches, 0, (int) $this->settings->get( 'max_results' ) ), 'product_id' ) );
			$result = [ 'ids' => $ids, 'total' => count( $ids ), 'catalog_matches' => count( $matches ), 'cached' => false ];
			$this->cache_put( $cache_key, $result );
			Reports::search( $normalized, $ids ? 'success' : 'no_results', count( $ids ), (int) ( ( microtime( true ) - $start ) * 1000 ) );
			return $result;
		} catch ( AIException $e ) {
			if ( $normalized ) {
				Reports::search( $normalized, 'error', 0, (int) ( ( microtime( true ) - $start ) * 1000 ) );
			}
			throw $e;
		}
	}

	private function scan( string $model_key, array $query_vector, array $intent ): array {
		global $wpdb;
		$settings = $this->settings->all();
		$index = Schema::table( 'index' );
		$queue = Schema::table( 'queue' );
		$last = 0;
		$parents = [];
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT i.* FROM $index i INNER JOIN {$wpdb->posts} p ON p.ID=i.product_id AND p.post_status='publish' LEFT JOIN $queue q ON q.product_id=i.product_id WHERE i.id > %d AND i.model_key=%s AND i.status='ready' AND q.product_id IS NULL ORDER BY i.id ASC LIMIT 200",
				$last, $model_key
			), ARRAY_A );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				$facts = json_decode( $row['facts'], true );
				if ( ! is_array( $facts ) || in_array( $facts['catalog_visibility'] ?? '', [ 'hidden', 'search' ], true ) ) {
					continue;
				}
				$vectors = Indexer::decode_vectors( $row['vector'], (int) $row['dimensions'] );
				$semantic = 0.0;
				foreach ( $vectors as $vector ) {
					$semantic = max( $semantic, Matcher::cosine( $query_vector, $vector ) );
				}
				$match = Matcher::score( $intent, $facts, $semantic, $settings );
				if ( ! $match['accepted'] ) {
					continue;
				}
				$product = wc_get_product( (int) $row['entity_id'] );
				$parent = wc_get_product( (int) $row['product_id'] );
				if ( ! $product || ! $parent || 'publish' !== $parent->get_status() || ! in_array( $parent->get_catalog_visibility(), [ 'visible', 'search' ], true ) || post_password_required( $parent->get_id() ) ) {
					continue;
				}
				if ( ! $product->is_visible() && 'outofstock' !== $product->get_stock_status() ) {
					continue;
				}
				// Price and stock are checked live even if an external integration changed them without a hook.
				$live_facts = $facts;
				$live_facts['price'] = '' !== $product->get_price() ? (float) $product->get_price() : null;
				$live = Matcher::score( $intent, $live_facts, $semantic, $settings );
				if ( ! $live['accepted'] ) {
					continue;
				}
				$id = (int) $row['product_id'];
				$stock = 'instock' === $product->get_stock_status() ? 1 : 0;
				if ( ! isset( $parents[ $id ] ) || $live['score'] > $parents[ $id ]['score'] || ( $live['score'] === $parents[ $id ]['score'] && $stock > $parents[ $id ]['in_stock'] ) ) {
					$parents[ $id ] = [ 'product_id' => $id, 'entity_id' => (int) $row['entity_id'], 'score' => $live['score'], 'in_stock' => $stock ];
				}
			}
		} while ( count( $rows ) === 200 );
		return array_values( $parents );
	}

	private function context(): array {
		$attributes = [];
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$attributes[] = $taxonomy->attribute_label;
			}
		}
		return [ 'currency' => get_woocommerce_currency(), 'attributes' => $attributes, 'languages' => [ 'fa', 'en' ] ];
	}

	private function convert_price( array $price, string $store_currency ): array {
		if ( null === $price['min'] && null === $price['max'] ) {
			return $price;
		}
		$unit = $price['currency'];
		if ( 'store' === $unit || $unit === $store_currency ) {
			return $price;
		}
		if ( in_array( $unit, [ 'IRT', 'IRR' ], true ) && in_array( $store_currency, [ 'IRT', 'IRR' ], true ) ) {
			$factor = 'IRT' === $unit ? 10 : 0.1;
		} else {
			$settings = $this->settings;
			$rate = match ( $unit ) {
				'IRT' => $settings->get( 'toman_rate' ),
				'USD' => $settings->get( 'usd_rate' ),
				'EUR' => $settings->get( 'eur_rate' ),
				default => '',
			};
			if ( '' === $rate && 'IRT' === $unit ) {
				$rate = 'IRR' === $store_currency ? 10 : ( 'IRT' === $store_currency ? 1 : '' );
			}
			if ( ! is_numeric( $rate ) || $rate <= 0 ) {
				throw new AIException( 'currency_not_configured', 0, 'Configure an explicit conversion rate for the requested currency.' );
			}
			$factor = (float) $rate;
		}
		foreach ( [ 'min', 'max' ] as $key ) {
			$price[ $key ] = null === $price[ $key ] ? null : $price[ $key ] * $factor;
		}
		$price['currency'] = $store_currency;
		return $price;
	}

	private function cache_get( string $key ): ?array {
		if ( ! $this->settings->get( 'cache_enabled' ) ) {
			return null;
		}
		global $wpdb;
		$table = Schema::table( 'cache' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM $table WHERE cache_key=%s AND expires_at > %d AND revision=%d", $key, time(), Reports::revision() ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( $row['payload'], true );
		if ( ! is_array( $data ) || ! isset( $data['ids'] ) || ! is_array( $data['ids'] ) || count( $data['ids'] ) > 500 ) {
			return null;
		}
		$data['ids'] = array_values( array_unique( array_map( 'intval', $data['ids'] ) ) );
		$data['total'] = count( $data['ids'] );
		return $data;
	}
	private function cache_put( string $key, array $result ): void {
		if ( ! $this->settings->get( 'cache_enabled' ) ) {
			return;
		}
		global $wpdb;
		$table = Schema::table( 'cache' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO $table (cache_key,payload,expires_at,revision,created_at) VALUES (%s,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at),revision=VALUES(revision),created_at=VALUES(created_at)", $key, wp_json_encode( $result ), time() + (int) $this->settings->get( 'cache_ttl' ), Reports::revision(), Reports::now() ) );
	}
}
