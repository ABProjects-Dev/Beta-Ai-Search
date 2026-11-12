<?php
namespace BetaAI\Search\Core;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {
	/** Atomic MySQL window; only a salted hash of the network address is persisted. */
	public static function allow( string $bucket, int $limit = 12, int $window = 60 ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = hash_hmac( 'sha256', $bucket . '|' . $address, wp_salt( 'nonce' ) );
		$table = Schema::table( 'rates' );
		$now = time();
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (rate_key,window_start,hits) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE hits=IF(window_start < %d,1,hits+1),window_start=IF(window_start < %d,%d,window_start)",
			$key, $now, $now - $window, $now - $window, $now
		) );
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM $table WHERE rate_key=%s", $key ) );
		return $hits <= $limit;
	}
}
