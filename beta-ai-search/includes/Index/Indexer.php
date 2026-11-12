<?php
namespace BetaAI\Search\Index;

use BetaAI\Search\AI\AIException;
use BetaAI\Search\AI\ProviderRegistry;
use BetaAI\Search\Core\Reports;
use BetaAI\Search\Core\Schema;
use BetaAI\Search\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Indexer {
	public function __construct( private Settings $settings, private ProviderRegistry $providers, private Queue $queue ) {}

	public function hooks(): void {
		add_action( 'bas_index_worker', [ $this, 'tick' ] );
		add_action( 'bas_index_cleanup', [ $this, 'cleanup_old_models' ] );
		add_filter( 'cron_schedules', static function ( $schedules ) {
			$schedules['bas_minute'] = [ 'interval' => 60, 'display' => 'Beta AI Search minute' ];
			return $schedules;
		} );
	}
	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'bas_index_worker' ) ) {
			wp_schedule_single_event( time() + 1, 'bas_index_worker' );
		}
	}
	public function active_job(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bas_jobs WHERE status IN ('running','paused','error') ORDER BY id DESC LIMIT 1", ARRAY_A );
		return $row ?: null;
	}
	public function start( bool $rebuild = false ): int {
		global $wpdb;
		$existing = $this->active_job();
		if ( $existing && ! $rebuild ) {
			return (int) $existing['id'];
		}
		$config = $this->providers->resolve( 'embedding' );
		if ( $existing && $rebuild ) {
			$wpdb->update( Schema::table( 'jobs' ), [ 'status' => 'cancelled', 'lock_token' => '', 'lock_until' => 0, 'updated_at' => Reports::now() ], [ 'id' => (int) $existing['id'] ] );
		}
		$wpdb->insert( Schema::table( 'jobs' ), [
			'status' => 'running', 'mode' => $rebuild ? 'rebuild' : 'initial', 'model_key' => $config['model_key'],
			'config' => wp_json_encode( $config ), 'cursor_id' => 0,
			'max_id' => (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts} WHERE post_type='product'" ),
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" ),
			'processed' => 0, 'failed' => 0, 'lock_token' => '', 'lock_until' => 0,
			'created_at' => Reports::now(), 'updated_at' => Reports::now(),
		] );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			throw new \RuntimeException( 'job_create_failed' );
		}
		self::schedule();
		return $id;
	}
	private function start_update( array $config ): void {
		global $wpdb;
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_queue" );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}bas_jobs (status,mode,model_key,config,cursor_id,max_id,total,processed,failed,lock_token,lock_until,created_at,updated_at) SELECT 'running','update',%s,%s,0,0,%d,0,0,'',0,%s,%s WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}bas_jobs WHERE status IN ('running','paused','error'))",
			$config['model_key'], wp_json_encode( $config ), $pending, Reports::now(), Reports::now()
		) );
	}
	public function pause(): void {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}bas_jobs SET status='paused',updated_at=UTC_TIMESTAMP() WHERE status='running'" );
	}
	public function resume(): void {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}bas_jobs SET status='running',lock_token='',lock_until=0,updated_at=UTC_TIMESTAMP() WHERE status IN ('paused','error')" );
		$this->queue->retry_all();
		self::schedule();
	}
	public function rebuild(): int { return $this->start( true ); }

	public function tick(): void {
		global $wpdb;
		$job = $this->active_job();
		if ( ! $job && $this->queue->due_ids( 1, true ) ) {
			// Incremental changes must still run after the initial job has completed.
			// Never activate a different embedding model during an incremental update.
			try {
				$config = $this->providers->resolve( 'embedding', (bool) get_option( 'bas_active_model' ) );
				$this->start_update( $config );
				$job = $this->active_job();
			} catch ( AIException ) {
				return;
			}
		}
		if ( ! $job || 'running' !== $job['status'] ) {
			return;
		}
		$table = Schema::table( 'jobs' );
		$token = bin2hex( random_bytes( 16 ) );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET lock_token=%s,lock_until=%d,updated_at=%s WHERE id=%d AND status='running' AND (lock_token='' OR lock_until < %d)", $token, time() + 180, Reports::now(), (int) $job['id'], time() ) );
		if ( 1 !== $claimed ) {
			return;
		}
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND lock_token=%s", (int) $job['id'], $token ), ARRAY_A );
		if ( ! $job ) {
			return;
		}
		$config = json_decode( $job['config'] ?? '', true );
		if ( ! is_array( $config ) || ( $config['model_key'] ?? '' ) !== $job['model_key'] ) {
			$this->finish_job( (int) $job['id'], $token, 'error' );
			return;
		}
		$start = microtime( true );
		$batch = max( 1, min( 20, (int) $this->settings->get( 'batch_size' ) ) );
		$budget = (float) apply_filters( 'bas_index_worker_budget', 12.0 );
		$worked = 0;
		try {
			while ( microtime( true ) - $start < $budget && $worked < $batch ) {
				if ( ! $this->owns_job( (int) $job['id'], $token ) ) {
					return;
				}
				$id = 0;
				if ( (int) $job['cursor_id'] < (int) $job['max_id'] ) {
					$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT 1", (int) $job['cursor_id'], (int) $job['max_id'] ) );
					if ( ! $id ) {
						$wpdb->update( $table, [ 'cursor_id' => (int) $job['max_id'], 'updated_at' => Reports::now() ], [ 'id' => (int) $job['id'], 'lock_token' => $token ] );
						$job['cursor_id'] = (int) $job['max_id'];
						continue;
					}
					$this->queue->enqueue( $id, 'scan', 0 );
				} else {
					$ids = $this->queue->due_ids( 1 );
					$id = $ids[0] ?? 0;
				}
				if ( ! $id ) {
					break;
				}
				$item = $this->queue->claim( $id, $token, true );
				if ( ! $item ) {
					if ( (int) $job['cursor_id'] < (int) $job['max_id'] ) {
						$wpdb->update( $table, [ 'cursor_id' => $id ], [ 'id' => (int) $job['id'], 'lock_token' => $token ] );
						$job['cursor_id'] = $id;
					}
					continue;
				}
				try {
					$this->index_product( $id, $config );
					$this->queue->release( $id, $token, (int) $item['version'] );
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET processed=processed+1,cursor_id=GREATEST(cursor_id,%d),updated_at=%s WHERE id=%d AND lock_token=%s", $id, Reports::now(), (int) $job['id'], $token ) );
				} catch ( AIException $e ) {
					$this->queue->fail( $id, $token, $e->safe_code );
					Reports::error( 'index', $e, $config['provider'], $config['model'] );
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET failed=failed+1,cursor_id=GREATEST(cursor_id,%d),updated_at=%s WHERE id=%d AND lock_token=%s", $id, Reports::now(), (int) $job['id'], $token ) );
				} catch ( \Throwable $e ) {
					$this->queue->fail( $id, $token, 'index_processing_error' );
					Reports::error( 'index', new AIException( 'index_processing_error' ), $config['provider'], $config['model'] );
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET failed=failed+1,cursor_id=GREATEST(cursor_id,%d) WHERE id=%d AND lock_token=%s", $id, (int) $job['id'], $token ) );
				}
				$job['cursor_id'] = max( (int) $job['cursor_id'], $id );
				++$worked;
			}
			if ( ! $this->owns_job( (int) $job['id'], $token ) ) {
				return;
			}
			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_queue" );
			$blocked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_queue WHERE attempts >= 6" );
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND ID > %d AND ID <= %d", (int) $job['cursor_id'], (int) $job['max_id'] ) );
			if ( 0 === $pending && 0 === $remaining ) {
				if ( 'rebuild' === $job['mode'] || ! get_option( 'bas_active_model' ) ) {
					update_option( 'bas_active_model', $config['model_key'], false );
					update_option( 'bas_active_config', $config, false );
					Reports::bump_revision();
					if ( ! wp_next_scheduled( 'bas_index_cleanup' ) ) {
						wp_schedule_single_event( time() + 1, 'bas_index_cleanup' );
					}
				}
				$this->finish_job( (int) $job['id'], $token, 'completed' );
				return;
			}
			if ( 0 === $remaining && $blocked === $pending && $pending > 0 ) {
				$this->finish_job( (int) $job['id'], $token, 'error' );
				return;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET lock_token='',lock_until=0,updated_at=%s WHERE id=%d AND lock_token=%s", Reports::now(), (int) $job['id'], $token ) );
			if ( ! wp_next_scheduled( 'bas_index_worker' ) ) {
				wp_schedule_single_event( time() + 5, 'bas_index_worker' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET lock_token='',lock_until=0,updated_at=%s WHERE id=%d AND lock_token=%s", Reports::now(), (int) $job['id'], $token ) );
			Reports::error( 'index', new AIException( 'worker_error' ) );
			self::schedule();
		}
	}

	public function cleanup_old_models(): void {
		global $wpdb;
		$key = get_option( 'bas_active_model', '' );
		if ( ! $key ) {
			return;
		}
		$table = Schema::table( 'index' );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE model_key != %s LIMIT 500", $key ) );
		if ( $deleted >= 500 ) {
			wp_schedule_single_event( time() + 5, 'bas_index_cleanup' );
		}
	}

	private function owns_job( int $id, string $token ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bas_jobs WHERE id=%d AND lock_token=%s AND status='running' AND lock_until >= %d", $id, $token, time() ) );
	}
	private function finish_job( int $id, string $token, string $status ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'jobs' ), [ 'status' => $status, 'lock_token' => '', 'lock_until' => 0, 'updated_at' => Reports::now() ], [ 'id' => $id, 'lock_token' => $token ] );
	}

	public function index_product( int $id, array $config ): void {
		global $wpdb;
		$entities = ( new ProductExtractor() )->extract( $id );
		$table = Schema::table( 'index' );
		if ( ! $entities ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE product_id=%d", $id ) );
			Reports::bump_revision();
			return;
		}
		$entity_ids = [];
		foreach ( $entities as $entity ) {
			$entity_ids[] = (int) $entity['entity_id'];
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT content_hash,status,dimensions FROM $table WHERE entity_id=%d AND model_key=%s", $entity['entity_id'], $config['model_key'] ), ARRAY_A );
			if ( $existing && $existing['content_hash'] === $entity['content_hash'] && 'ready' === $existing['status'] && (int) $existing['dimensions'] === $config['dimensions'] ) {
				continue;
			}
			$chunks = self::chunks( $entity['document'] );
			$vectors = [];
			foreach ( array_chunk( $chunks, 16 ) as $batch ) {
				$vectors = array_merge( $vectors, $this->providers->embed( $batch, $config ) );
			}
			if ( count( $vectors ) !== count( $chunks ) ) {
				throw new AIException( 'invalid_embeddings' );
			}
			$vector = self::encode_vectors( $vectors );
			$facts = wp_json_encode( $entity['facts'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			$now = Reports::now();
			// One atomic row per entity: readers never observe a partially embedded variation/document.
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO $table (product_id,entity_id,entity_type,model_key,content_hash,document,facts,vector,dimensions,status,error_code,updated_at,indexed_at) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%d,'ready','',%s,%s) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),entity_type=VALUES(entity_type),content_hash=VALUES(content_hash),document=VALUES(document),facts=VALUES(facts),vector=VALUES(vector),dimensions=VALUES(dimensions),status='ready',error_code='',updated_at=VALUES(updated_at),indexed_at=VALUES(indexed_at)",
				$entity['product_id'], $entity['entity_id'], $entity['entity_type'], $config['model_key'], $entity['content_hash'], $entity['document'], $facts, $vector, $config['dimensions'], $now, $now
			) );
			if ( false === $result ) {
				throw new \RuntimeException( 'index_write_failed' );
			}
			Reports::bump_revision();
		}
		if ( $entity_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE product_id=%d AND model_key=%s AND entity_id NOT IN ($placeholders)", array_merge( [ $id, $config['model_key'] ], $entity_ids ) ) );
		}
	}

	public function status(): array {
		global $wpdb;
		$job = $this->active_job();
		if ( ! $job ) {
			$job = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bas_jobs ORDER BY id DESC LIMIT 1", ARRAY_A ) ?: [];
		}
		$key = get_option( 'bas_active_model', '' );
		$indexed = $key ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}bas_index WHERE model_key=%s AND status='ready'", $key ) ) : 0;
		$entities = $key ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_index WHERE model_key=%s AND status='ready'", $key ) ) : 0;
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_queue" );
		$total = (int) ( $job['total'] ?? 0 );
		$processed = (int) ( $job['processed'] ?? 0 );
		$percent = 'completed' === ( $job['status'] ?? '' ) ? 100 : ( $total ? min( 99, (int) floor( 100 * $processed / max( $total, $processed ) ) ) : 0 );
		$cursor = (int) ( $job['cursor_id'] ?? 0 );
		$max = (int) ( $job['max_id'] ?? 0 );
		$remaining_scan = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND ID > %d AND ID <= %d", $cursor, $max ) );
		$extra_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bas_queue q LEFT JOIN {$wpdb->posts} p ON p.ID=q.product_id WHERE q.product_id > %d OR p.ID IS NULL OR p.post_type != 'product'", $max ) );
		$config = get_option( 'bas_active_config', [] );
		$target = null;
		try { $target = $this->providers->resolve( 'embedding' ); } catch ( AIException ) {}
		return [
			'job' => $job, 'percent' => $percent, 'indexed_products' => $indexed, 'indexed_entities' => $entities,
			'pending' => $pending, 'remaining' => $remaining_scan + $extra_pending,
			'revision' => Reports::revision(), 'active_model' => $config,
			'rebuild_required' => $target && $key && $target['model_key'] !== $key,
			'cron_next' => wp_next_scheduled( 'bas_index_worker' ),
		];
	}

	public static function chunks( string $document ): array {
		$size = (int) apply_filters( 'bas_embedding_chunk_chars', 5000 );
		$size = max( 1000, min( 6000, $size ) );
		$chunks = [];
		$length = mb_strlen( $document );
		for ( $offset = 0; $offset < $length; $offset += $size - 200 ) {
			$chunk = trim( mb_substr( $document, $offset, $size ) );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}
			if ( $offset + $size >= $length ) {
				break;
			}
		}
		return $chunks ?: [ 'Empty product' ];
	}
	public static function encode_vectors( array $vectors ): string {
		return wp_json_encode( array_map( static fn( array $vector ) => base64_encode( pack( 'g*', ...$vector ) ), $vectors ) );
	}
	public static function decode_vectors( string $encoded, int $dimensions ): array {
		$rows = json_decode( $encoded, true );
		if ( ! is_array( $rows ) || ! $rows || count( $rows ) > 100 || $dimensions < 2 || $dimensions > 8192 ) {
			return [];
		}
		$vectors = [];
		foreach ( $rows as $row ) {
			if ( ! is_string( $row ) ) {
				return [];
			}
			$binary = base64_decode( $row, true );
			if ( false === $binary || strlen( $binary ) !== 4 * $dimensions ) {
				return [];
			}
			$vector = array_values( unpack( 'g*', $binary ) );
			foreach ( $vector as $value ) {
				if ( ! is_finite( (float) $value ) || abs( $value ) > 1.001 ) {
					return [];
				}
			}
			$vectors[] = $vector;
		}
		return $vectors;
	}
}
