<?php
namespace BetaAI\Search\Index;

use BetaAI\Search\Core\Schema;
use BetaAI\Search\Core\Reports;

defined( 'ABSPATH' ) || exit;

final class Queue {
	public function hooks(): void {
		add_action( 'woocommerce_new_product', [ $this, 'changed' ], 100 );
		add_action( 'woocommerce_update_product', [ $this, 'changed' ], 100 );
		add_action( 'woocommerce_new_product_variation', [ $this, 'variation_changed' ], 100, 2 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'variation_changed' ], 100, 2 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'changed' ], 100 );
		add_action( 'woocommerce_delete_product_variation', [ $this, 'variation_changed' ], 100 );
		add_action( 'woocommerce_before_delete_product', [ $this, 'delete' ], 100 );
		add_action( 'trashed_post', [ $this, 'post_deleted' ], 100 );
		add_action( 'deleted_post', [ $this, 'post_deleted' ], 100 );
		add_action( 'woocommerce_product_set_stock', [ $this, 'product_object' ], 100 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'product_object' ], 100 );
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'changed' ], 100 );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'changed' ], 100 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'meta_changed' ], 100, 4 );
		}
		foreach ( [ 'set_object_terms', 'deleted_term_relationships' ] as $hook ) {
			add_action( $hook, [ $this, 'terms_changed' ], 100 );
		}
		add_action( 'edited_term', [ $this, 'term_changed' ], 100, 3 );
		add_action( 'delete_term', [ $this, 'term_deleted' ], 100, 4 );
		add_action( 'acf/save_post', [ $this, 'acf_changed' ], 100 );
	}
	public function enqueue( int $id, string $reason = 'change', int $delay = 2 ): void {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			return;
		}
		if ( 'product_variation' === $post->post_type ) {
			$id = (int) $post->post_parent;
		}
		if ( ! $id ) {
			return;
		}
		global $wpdb;
		Reports::bump_revision();
		$table = Schema::table( 'queue' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (product_id,reason,available_at,locked_at,lock_token,attempts,last_error,version) VALUES (%d,%s,%d,0,'',0,'',%d) ON DUPLICATE KEY UPDATE reason=VALUES(reason),available_at=VALUES(available_at),attempts=0,last_error='',version=VALUES(version),lock_token='',locked_at=0",
			$id, substr( sanitize_key( $reason ), 0, 64 ), time() + max( 0, $delay ), Reports::revision()
		) );
		Indexer::schedule();
	}
	public function changed( mixed $id ): void { $this->enqueue( (int) $id ); }
	public function variation_changed( mixed $id, mixed $product = null ): void {
		$this->enqueue( (int) ( $product && is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : $id ), 'variation' );
	}
	public function product_object( mixed $product ): void {
		if ( $product instanceof \WC_Product ) {
			$this->enqueue( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'stock' );
		}
	}
	public function post_deleted( int $id ): void {
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			$post = get_post( $id );
			$this->enqueue( (int) $post?->post_parent, 'deleted_variation' );
		} elseif ( 'product' === $type ) {
			$this->delete( $id );
		}
	}
	public function delete( int $id ): void {
		global $wpdb;
		foreach ( [ 'index', 'queue' ] as $name ) {
			$table = Schema::table( $name );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE product_id=%d", $id ) );
		}
		Reports::bump_revision();
	}
	public function meta_changed( mixed $meta_id, mixed $object_id, mixed $key, mixed $value ): void {
		if ( ! is_string( $key ) || str_starts_with( $key, '_' ) ) {
			return;
		}
		$this->enqueue( (int) $object_id, 'meta' );
	}
	public function terms_changed( mixed $id ): void { $this->enqueue( (int) $id, 'taxonomy' ); }
	public function acf_changed( mixed $id ): void {
		if ( is_numeric( $id ) ) {
			$this->enqueue( (int) $id, 'acf' );
		}
	}
	public function term_changed( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		if ( ! is_string( $taxonomy ) || ! str_starts_with( $taxonomy, 'product_' ) && ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return;
		}
		$this->enqueue_term_products( (int) $term_id, $taxonomy );
	}
	public function term_deleted( mixed $term, mixed $tt_id, mixed $taxonomy, mixed $term_object ): void {
		if ( is_object( $term_object ) && isset( $term_object->name ) ) {
			// Relationships are removed by WP; mark all products conservatively, in bounded background batches.
			$this->enqueue_all();
		}
	}
	private function enqueue_term_products( int $term_id, string $taxonomy ): void {
		$ids = get_objects_in_term( $term_id, $taxonomy );
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$this->enqueue( (int) $id, 'term_name' );
			}
		}
	}
	public function enqueue_all(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		global $wpdb;
		$last = 0;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','draft','pending','private','future') AND ID > %d ORDER BY ID ASC LIMIT 100", $last ) );
			foreach ( $ids as $id ) {
				$this->enqueue( (int) $id, 'initial', 0 );
				$last = (int) $id;
			}
		} while ( count( $ids ) === 100 );
	}

	public function claim( int $id, string $token, bool $force = false ): ?array {
		global $wpdb;
		$table = Schema::table( 'queue' );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET lock_token=%s,locked_at=%d WHERE product_id=%d AND attempts < 6 AND (lock_token='' OR locked_at < %d) AND (available_at <= %d OR %d=1)", $token, time(), $id, time() - 300, time(), (int) $force ) );
		if ( 1 !== $claimed ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE product_id=%d AND lock_token=%s", $id, $token ), ARRAY_A );
		return $row ?: null;
	}

	public function release( int $id, string $token, int $version ): void {
		global $wpdb;
		$table = Schema::table( 'queue' );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE product_id=%d AND lock_token=%s AND version=%d", $id, $token, $version ) );
		if ( $deleted ) {
			// Searches conservatively exclude queued products. Even when re-indexing
			// finds unchanged content, removing the queue entry must invalidate those caches.
			Reports::bump_revision();
		}
	}

	public function fail( int $id, string $token, string $code ): void {
		global $wpdb;
		$table = Schema::table( 'queue' );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET attempts=attempts+1,last_error=%s,lock_token='',locked_at=0,available_at=%d WHERE product_id=%d AND lock_token=%s", substr( sanitize_key( $code ), 0, 64 ), time() + 60, $id, $token ) );
	}

	public function due_ids( int $limit, bool $include_future = false ): array {
		global $wpdb;
		$table = Schema::table( 'queue' );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM $table WHERE attempts < 6 AND (lock_token='' OR locked_at < %d) AND (available_at <= %d OR %d=1) ORDER BY product_id ASC LIMIT %d", time() - 300, time(), (int) $include_future, $limit ) ) );
	}

	public function retry_all(): void {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}bas_queue SET attempts=0,last_error='',available_at=0,lock_token='',locked_at=0" );
		Indexer::schedule();
	}
}
