<?php
namespace BetaAI\Search\Core;

use BetaAI\Search\AI\AIException;

defined( 'ABSPATH' ) || exit;

final class Reports {
	public static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
	public static function revision(): int { return max( 1, (int) get_option( 'bas_index_revision', 1 ) ); }
	public static function bump_revision(): void {
		global $wpdb;
		$table = Schema::table( 'cache' );
		$wpdb->query( "DELETE FROM $table" );
		$value = self::revision() + 1;
		update_option( 'bas_index_revision', $value, false );
	}
	public static function clear_cache(): void {
		global $wpdb;
		$table = Schema::table( 'cache' );
		$wpdb->query( "DELETE FROM $table" );
		delete_transient( 'bas_cache_lock' );
	}
	public static function error( string $context, AIException $error, string $provider = '', string $model = '' ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'errors' ), [
			'context' => substr( sanitize_key( $context ), 0, 32 ),
			'code' => substr( sanitize_key( $error->safe_code ), 0, 64 ),
			'provider' => substr( sanitize_key( $provider ), 0, 64 ),
			'model' => substr( sanitize_text_field( $model ), 0, 160 ),
			'http_status' => max( 0, min( 599, $error->http_status ) ),
			'created_at' => self::now(),
		], [ '%s', '%s', '%s', '%s', '%d', '%s' ] );
	}
	public static function usage( string $provider, string $model, string $task, array $usage ): void {
		global $wpdb;
		$settings = Plugin::instance()->settings->all();
		$rate = $settings['providers'][ $provider ]['rates'][ $model ] ?? null;
		$cost = null;
		$currency = '';
		if ( $rate && null !== $rate['input'] && null !== $rate['output'] && null !== $usage['input'] && null !== $usage['output'] ) {
			$cost = ( $usage['input'] * $rate['input'] + $usage['output'] * $rate['output'] ) / 1000000;
			$currency = $rate['currency'];
		}
		$wpdb->insert( Schema::table( 'usage' ), [
			'provider' => sanitize_key( $provider ), 'model' => substr( sanitize_text_field( $model ), 0, 160 ), 'task' => sanitize_key( $task ),
			'requests' => 1, 'input_tokens' => $usage['input'], 'output_tokens' => $usage['output'], 'total_tokens' => $usage['total'],
			'cost' => $cost, 'currency' => $currency, 'created_at' => self::now(),
		], [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s' ] );
	}
	public static function search( string $normalized, string $status, int $count, int $duration, bool $cached = false ): void {
		$settings = Plugin::instance()->settings;
		if ( ! $settings->get( 'history_enabled' ) ) {
			return;
		}
		global $wpdb;
		$hash = hash_hmac( 'sha256', $normalized, wp_salt( 'nonce' ) );
		$wpdb->insert( Schema::table( 'searches' ), [
			'query_hash' => $hash,
			'query_text' => $settings->get( 'store_query_text' ) ? mb_substr( $normalized, 0, 600 ) : null,
			'status' => sanitize_key( $status ), 'result_count' => max( 0, $count ), 'duration_ms' => max( 0, $duration ),
			'from_cache' => (int) $cached, 'created_at' => self::now(),
		], [ '%s', '%s', '%s', '%d', '%d', '%d', '%s' ] );
	}
	public static function dashboard(): array {
		global $wpdb;
		$s = Schema::table( 'searches' );
		$u = Schema::table( 'usage' );
		$row = $wpdb->get_row( "SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) successful, COALESCE(SUM(CASE WHEN status='no_results' THEN 1 ELSE 0 END),0) no_results, COALESCE(SUM(CASE WHEN status='error' THEN 1 ELSE 0 END),0) error_count, COALESCE(SUM(from_cache),0) cached, COALESCE(AVG(duration_ms),0) latency FROM $s", ARRAY_A );
		$top = $wpdb->get_results( "SELECT query_hash, MAX(query_text) query_text, COUNT(*) hits, SUM(CASE WHEN status='no_results' THEN 1 ELSE 0 END) no_results FROM $s GROUP BY query_hash ORDER BY hits DESC LIMIT 15", ARRAY_A );
		$usage = $wpdb->get_results( "SELECT provider,model,task,SUM(requests) requests,SUM(input_tokens) input_tokens,SUM(output_tokens) output_tokens,SUM(total_tokens) total_tokens,currency,SUM(cost) cost,SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) unknown_costs FROM $u GROUP BY provider,model,task,currency ORDER BY requests DESC LIMIT 100", ARRAY_A );
		return [ 'searches' => $row ?: [], 'top' => $top ?: [], 'usage' => $usage ?: [] ];
	}
	public static function cleanup(): void {
		global $wpdb;
		$days = max( 1, min( 365, (int) Plugin::instance()->settings->get( 'retention_days' ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		foreach ( [ 'searches', 'errors', 'usage' ] as $name ) {
			$table = Schema::table( $name );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) );
		}
		$cache = Schema::table( 'cache' );
		$rates = Schema::table( 'rates' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $cache WHERE expires_at < %d", time() ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $rates WHERE window_start < %d", time() - DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bas_queue SET lock_token='',locked_at=0 WHERE locked_at > 0 AND locked_at < %d", time() - 300 ) );
	}
}
