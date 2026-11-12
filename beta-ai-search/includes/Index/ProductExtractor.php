<?php
namespace BetaAI\Search\Index;

use BetaAI\Search\Core\Plugin;
use BetaAI\Search\Search\Text;

defined( 'ABSPATH' ) || exit;

/** Public-data boundary: no arbitrary protected metadata, credentials, orders or customer fields. */
final class ProductExtractor {
	public function extract( int $id ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $product || 'publish' !== $product->get_status() || 'trash' === get_post_status( $id ) ) {
			return [];
		}
		$entities = [ $this->entity( $product, false ) ];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status() && apply_filters( 'bas_variation_indexable', $variation->is_visible() || 'outofstock' === $variation->get_stock_status(), $variation, $product ) ) {
					$entities[] = $this->entity( $variation, true );
				}
			}
		}
		return apply_filters( 'bas_index_entities', $entities, $product );
	}

	private function entity( \WC_Product $product, bool $variation ): array {
		$parent = $variation ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent ) {
			throw new \RuntimeException( 'missing_parent' );
		}
		$id = $product->get_id();
		$parent_id = $parent->get_id();
		$name = $variation ? $parent->get_name() . ' — ' . implode( ' / ', array_map( static fn( $values ) => implode( ', ', $values ), self::variation_values( $product ) ) ) : $product->get_name();
		$description = $product->get_description();
		if ( $variation && '' === trim( wp_strip_all_tags( $description ) ) ) {
			$description = $parent->get_description();
		}
		$attributes = $this->attributes( $product, $variation );
		$terms = [];
		$types = [];
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) || in_array( $taxonomy->name, [ 'product_visibility', 'product_shipping_class' ], true ) ) {
				continue;
			}
			$names = wc_get_object_terms( $parent_id, $taxonomy->name, 'name' );
			if ( ! is_array( $names ) || ! $names ) {
				continue;
			}
			$names = array_map( 'strval', $names );
			$terms[ $taxonomy->name ] = $names;
			$facet = str_contains( $taxonomy->name, 'brand' ) ? 'brand' : self::classify( $taxonomy->labels->singular_name . ' ' . $taxonomy->name );
			if ( 'product_cat' === $taxonomy->name ) {
				$types = array_merge( $types, $names );
			}
			$attributes[ $facet ] = array_merge( $attributes[ $facet ] ?? [], $names );
		}
		$brand = apply_filters( 'bas_product_brand', $attributes['brand'] ?? [], $parent, $product );
		if ( is_array( $brand ) && $brand ) {
			$attributes['brand'] = array_map( 'strval', $brand );
		}
		$custom = $this->custom_fields( $product );
		if ( $variation ) {
			$custom = array_replace( $this->custom_fields( $parent ), $custom );
		}
		foreach ( $custom as $key => $values ) {
			$facet = self::classify( $key );
			$attributes[ $facet ] = array_merge( $attributes[ $facet ] ?? [], $values );
		}
		foreach ( $attributes as &$values ) {
			$values = array_values( array_unique( array_map( static fn( $value ) => mb_substr( wp_strip_all_tags( (string) $value ), 0, 300 ), $values ) ) );
		}
		unset( $values );
		$price = '' !== $product->get_price() ? (float) $product->get_price() : null;
		$facts = [
			'product_id' => $parent_id, 'entity_id' => $id, 'entity_type' => $variation ? 'variation' : 'parent',
			'name' => $name, 'sku' => $product->get_sku(), 'product_type' => array_values( array_unique( $types ) ),
			'attributes' => $attributes, 'custom_fields' => $custom, 'terms' => $terms,
			'price' => $price, 'regular_price' => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : null,
			'sale_price' => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
			'currency' => get_woocommerce_currency(), 'stock_status' => $product->get_stock_status(),
			'catalog_visibility' => $parent->get_catalog_visibility(),
		];
		$facts = apply_filters( 'bas_product_facts', $facts, $product, $parent );
		$sections = [
			'Product: ' . $name,
			'Category / type: ' . implode( ' | ', $facts['product_type'] ),
			'SKU: ' . $facts['sku'],
			'Short description: ' . wp_strip_all_tags( $variation ? $parent->get_short_description() : $product->get_short_description() ),
			'Attributes: ' . self::flatten( $attributes ),
			'Taxonomies: ' . self::flatten( $terms ),
			'Public custom fields: ' . self::flatten( $custom ),
			'Description: ' . wp_strip_all_tags( strip_shortcodes( $description ) ),
		];
		$document = apply_filters( 'bas_index_document', implode( "\n", $sections ), $product, $facts );
		$document = html_entity_decode( wp_strip_all_tags( (string) $document ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$document = preg_replace( '/\s+/u', ' ', $document );
		$document = mb_substr( trim( $document ), 0, (int) apply_filters( 'bas_index_document_max_chars', 60000, $product ) );
		if ( '' === $document ) {
			throw new \RuntimeException( 'empty_document' );
		}
		return [ 'product_id' => $parent_id, 'entity_id' => $id, 'entity_type' => $variation ? 'variation' : 'parent', 'document' => $document, 'facts' => $facts, 'content_hash' => hash( 'sha256', $document . "\n" . wp_json_encode( $facts ) ) ];
	}

	private function attributes( \WC_Product $product, bool $variation ): array {
		$out = [];
		$parent = $variation ? wc_get_product( $product->get_parent_id() ) : $product;
		$variation_axes = [];
		if ( $variation ) {
			foreach ( $product->get_variation_attributes() as $key => $value ) {
				$variation_axes[] = Text::normalize( preg_replace( '/^attribute_(?:pa_)?/', '', $key ) );
				if ( '' !== $value ) {
					$facet = self::classify( $key );
					$term = str_starts_with( $key, 'attribute_pa_' ) ? get_term_by( 'slug', $value, substr( $key, strlen( 'attribute_' ) ) ) : null;
					$out[ $facet ][] = $term && ! is_wp_error( $term ) ? $term->name : $value;
				}
			}
		}
		$source = $variation ? $parent : $product;
		foreach ( $source->get_attributes() as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$label = $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name(), $source ) : $attribute->get_name();
			$facet = self::classify( $label . ' ' . $key );
			if ( $source->is_type( 'variable' ) && $attribute->get_variation() ) {
				// Concrete variation values were added above. Neither a parent nor a
				// child may claim every sibling color/size; non-varying facts are inherited.
				continue;
			}
			$values = $attribute->is_taxonomy() ? wc_get_product_terms( $source->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] ) : $attribute->get_options();
			if ( is_array( $values ) ) {
				$out[ $facet ] = array_merge( $out[ $facet ] ?? [], $values );
			}
		}
		return $out;
	}

	private static function variation_values( \WC_Product_Variation $product ): array {
		$out = [];
		foreach ( $product->get_variation_attributes() as $key => $value ) {
			if ( '' !== $value ) {
				$out[ $key ] = [ $value ];
			}
		}
		return $out;
	}

	private function custom_fields( \WC_Product $product ): array {
		$allow = array_filter( array_map( 'sanitize_key', preg_split( '/[\r\n,]+/', Plugin::instance()->settings->get( 'public_meta_keys' ) ) ?: [] ) );
		$allow = apply_filters( 'bas_product_public_meta_keys', $allow, $product );
		$values = [];
		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key = (string) ( $data['key'] ?? '' );
			if ( str_starts_with( $key, '_' ) || self::sensitive_key( $key ) || ! in_array( sanitize_key( $key ), $allow, true ) ) {
				continue;
			}
			$flat = self::flatten_value( $data['value'] ?? '' );
			if ( $flat ) {
				$values[ $key ] = $flat;
			}
		}
		if ( function_exists( 'get_fields' ) ) {
			$acf = get_fields( $product->get_id() );
			foreach ( is_array( $acf ) ? $acf : [] as $key => $value ) {
				if ( ! is_string( $key ) || str_starts_with( $key, '_' ) || self::sensitive_key( $key ) || ! in_array( sanitize_key( $key ), $allow, true ) ) {
					continue;
				}
				$flat = self::flatten_value( $value );
				if ( $flat ) {
					$values[ $key ] = $flat;
				}
			}
		}
		return apply_filters( 'bas_product_public_custom_fields', $values, $product );
	}

	private static function sensitive_key( string $key ): bool {
		return (bool) preg_match( '/(password|secret|token|api[_-]?key|credential|session|customer|email|phone|address|nonce|transient|edit_lock)/i', $key );
	}
	private static function flatten_value( mixed $value, int $depth = 0 ): array {
		if ( $depth > 4 ) {
			return [];
		}
		if ( is_scalar( $value ) || null === $value ) {
			$text = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
			$text = mb_substr( trim( wp_strip_all_tags( $text ) ), 0, 1000 );
			return '' === $text ? [] : [ $text ];
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( array_slice( $value, 0, 50 ) as $item ) {
				$out = array_merge( $out, self::flatten_value( $item, $depth + 1 ) );
				if ( count( $out ) >= 50 ) {
					break;
				}
			}
			return array_slice( $out, 0, 50 );
		}
		return [];
	}
	private static function flatten( array $values ): string {
		$parts = [];
		foreach ( $values as $key => $items ) {
			$parts[] = $key . ': ' . implode( ', ', is_array( $items ) ? $items : [ (string) $items ] );
		}
		return implode( ' | ', $parts );
	}
	public static function classify( string $key ): string {
		$key = Text::normalize( preg_replace( '/^(?:attribute_)?(?:pa_)?/', '', $key ) );
		$maps = [
			'color' => [ 'color', 'colour', 'رنگ' ],
			'brand' => [ 'brand', 'برند', 'مارک', 'سازنده' ],
			'size' => [ 'size', 'سایز', 'اندازه', 'ابعاد' ],
			'material' => [ 'material', 'fabric', 'جنس', 'متریال', 'پارچه', 'مواد' ],
			'technical' => [ 'technical', 'feature', 'spec', 'waterproof', 'wireless', 'فنی', 'ویژگی', 'مشخصات', 'ضد اب', 'بی سیم' ],
		];
		foreach ( $maps as $facet => $needles ) {
			foreach ( $needles as $needle ) {
				if ( str_contains( $key, $needle ) ) {
					return $facet;
				}
			}
		}
		return 'other';
	}
}
