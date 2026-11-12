<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\AI\AIException;

defined( 'ABSPATH' ) || exit;

final class GridRenderer {
	public function hooks(): void {
		add_filter( 'elementor/widget/render_content', [ $this, 'loop_context' ], 999, 2 );
		add_action( 'elementor/query/bas_search', [ $this, 'custom_query' ], 10, 2 );
	}

	public function loop_context( string $content, $widget ): string {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'loop-grid' !== $widget->get_name() ) {
			return $content;
		}
		$settings = $widget->get_settings_for_display();
		$template = (int) ( $settings['template_id'] ?? 0 );
		if ( ! $template || ! get_post( $template ) ) {
			return $content;
		}
		$item_classes = [];
		if ( preg_match( '/class="([^"]*\be-loop-item\b[^"]*)"/', $content, $match ) ) {
			foreach ( preg_split( '/\s+/', $match[1] ) as $class ) {
				if ( ! preg_match( '/^(?:post-\d+|e-loop-item-\d+|page-item-\d+|product_cat-|product_tag-|instock$|outofstock$|onbackorder$)/', $class ) ) {
					$item_classes[] = sanitize_html_class( $class );
				}
			}
		}
		$payload = [
			'type' => 'elementor-loop', 'template' => $template, 'widget' => sanitize_key( $widget->get_id() ),
			'query_id' => sanitize_key( $settings['custom_query_id'] ?? '' ),
			'page_size' => max( 1, min( 48, (int) ( $settings['posts_per_page'] ?? 6 ) ) ),
			'item_classes' => array_slice( $item_classes, 0, 40 ), 'issued' => time(),
		];
		$encoded = self::base64url( wp_json_encode( $payload ) );
		$context = [ 'payload' => $encoded, 'signature' => hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ) ];
		return $content . '<script type="application/json" class="bas-grid-context">' . wp_json_encode( $context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
	}

	public function render( array $ids, int $page, int $page_size, array $context = [], int $columns = 4 ): array {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_template_part' ) ) {
			throw new AIException( 'grid_unavailable' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );
		if ( count( $ids ) > 500 ) {
			throw new \InvalidArgumentException( 'too_many_ids' );
		}
		$page = max( 1, $page );
		$page_size = max( 1, min( 48, $page_size ) );
		$columns = max( 1, min( 6, $columns ) );
		$loop = ! empty( $context );
		if ( $loop ) {
			$context = $this->verify_context( $context );
			$page_size = $context['page_size'];
		}
		$visible = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && 'product' === get_post_type( $id ) && 'publish' === $product->get_status() && in_array( $product->get_catalog_visibility(), [ 'visible', 'search' ], true ) && ( $product->is_visible() || 'outofstock' === $product->get_stock_status() ) && ! post_password_required( $id ) ) {
				$visible[] = $id;
			}
		}
		$total = count( $visible );
		$pages = (int) ceil( $total / $page_size );
		$slice = array_slice( $visible, ( $page - 1 ) * $page_size, $page_size );
		$html = '';
		if ( $slice ) {
			$html = $loop ? $this->render_loop_items( $slice, $context ) : $this->render_woo_items( $slice, $columns, $page_size, $total, $page );
		}
		return [ 'html' => $html, 'total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => $page_size, 'adapter' => $loop ? 'elementor-loop' : 'woocommerce-loop' ];
	}

	private function render_woo_items( array $ids, int $columns, int $page_size, int $total, int $page ): string {
		global $post, $product;
		$old_post = $post;
		$old_product = $product;
		$old_columns = wc_get_loop_prop( 'columns' );
		wc_set_loop_prop( 'columns', $columns );
		wc_set_loop_prop( 'per_page', $page_size );
		wc_set_loop_prop( 'total', $total );
		wc_set_loop_prop( 'current_page', $page );
		ob_start();
		try {
			foreach ( $ids as $index => $id ) {
				$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$product = wc_get_product( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				setup_postdata( $post );
				wc_set_loop_prop( 'loop', $index );
				wc_get_template_part( 'content', 'product' );
			}
		} finally {
			wp_reset_postdata();
			$post = $old_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$product = $old_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wc_set_loop_prop( 'columns', $old_columns );
		}
		return (string) ob_get_clean();
	}

	private function render_loop_items( array $ids, array $context ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::instance()->frontend ) ) {
			throw new AIException( 'elementor_unavailable' );
		}
		global $post, $product, $wp_query;
		$old_post = $post;
		$old_product = $product;
		$old_query = $wp_query;
		$html = '';
		try {
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$wp_query = new \WP_Query( [ 'post_type' => 'product', 'post_status' => 'publish', 'p' => $id, 'posts_per_page' => 1, 'ignore_sticky_posts' => true ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				if ( ! $wp_query->have_posts() ) {
					continue;
				}
				$wp_query->the_post();
				$item = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $context['template'], true );
				$processor = new \WP_HTML_Tag_Processor( $item );
				while ( $processor->next_tag() ) {
					if ( 'DIV' === $processor->get_tag() ) {
						$processor->add_class( 'e-loop-item' );
						$processor->add_class( 'e-loop-item-' . $id );
						$processor->add_class( 'post-' . $id );
						$processor->add_class( 'product' );
						$processor->add_class( 'type-product' );
						$processor->add_class( $product->get_stock_status() );
						foreach ( $context['item_classes'] as $class ) {
							$processor->add_class( $class );
						}
						break;
					}
				}
				$html .= $processor->get_updated_html();
			}
		} finally {
			wp_reset_postdata();
			$wp_query = $old_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$post = $old_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$product = $old_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		return $html;
	}

	private function verify_context( array $context ): array {
		$encoded = $context['payload'] ?? '';
		$signature = $context['signature'] ?? '';
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 8000 || ! is_string( $signature ) || ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ), $signature ) ) {
			throw new \InvalidArgumentException( 'invalid_grid_context' );
		}
		$payload = json_decode( self::base64url( $encoded, true ), true );
		if ( ! is_array( $payload ) || 'elementor-loop' !== ( $payload['type'] ?? '' ) || ! isset( $payload['template'], $payload['issued'] ) || time() - (int) $payload['issued'] > DAY_IN_SECONDS || (int) $payload['issued'] > time() + 60 ) {
			throw new \InvalidArgumentException( 'invalid_grid_context' );
		}
		$template = (int) $payload['template'];
		if ( ! get_post( $template ) || 'trash' === get_post_status( $template ) ) {
			throw new AIException( 'loop_template_missing' );
		}
		return [ 'template' => $template, 'page_size' => max( 1, min( 48, (int) ( $payload['page_size'] ?? 6 ) ) ), 'item_classes' => array_map( 'sanitize_html_class', array_slice( is_array( $payload['item_classes'] ?? null ) ? $payload['item_classes'] : [], 0, 40 ) ), 'query_id' => sanitize_key( $payload['query_id'] ?? '' ) ];
	}

	/** Optional documented Query-ID bridge for custom/third-party adapters. */
	public function custom_query( $query, $widget = null ): void {
		$ids = apply_filters( 'bas_current_grid_ids', null );
		if ( ! is_array( $ids ) ) {
			return;
		}
		$ids = array_map( 'intval', $ids );
		$query->set( 'post_type', 'product' );
		$query->set( 'post_status', 'publish' );
		$query->set( 'post__in', $ids ?: [ 0 ] );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'post__not_in', [] );
		$query->set( 'tax_query', [] );
		$query->set( 'meta_query', [] );
		$query->set( 's', '' );
	}

	private static function base64url( string $value, bool $decode = false ): string {
		return $decode ? (string) base64_decode( strtr( $value, '-_', '+/' ), true ) : rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
