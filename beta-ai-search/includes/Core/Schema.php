<?php
namespace BetaAI\Search\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '1.0.0';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'bas_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'bas_schema_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = static fn( string $name ) => self::table( $name );
		$statements = [
			"CREATE TABLE {$t('index')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				entity_id bigint(20) unsigned NOT NULL,
				entity_type varchar(20) NOT NULL,
				model_key char(64) NOT NULL,
				content_hash char(64) NOT NULL,
				document mediumtext NOT NULL,
				facts longtext NOT NULL,
				vector longtext NOT NULL,
				dimensions int unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				error_code varchar(64) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				indexed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entity_model (entity_id,model_key),
				KEY product_model (product_id,model_key),
				KEY status_model (status,model_key)
			) $charset;",
			"CREATE TABLE {$t('queue')} (
				product_id bigint(20) unsigned NOT NULL,
				reason varchar(64) NOT NULL DEFAULT 'change',
				available_at int unsigned NOT NULL DEFAULT 0,
				locked_at int unsigned NOT NULL DEFAULT 0,
				lock_token char(32) NOT NULL DEFAULT '',
				attempts int unsigned NOT NULL DEFAULT 0,
				last_error varchar(64) NOT NULL DEFAULT '',
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (product_id),
				KEY due (available_at,locked_at)
			) $charset;",
			"CREATE TABLE {$t('jobs')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(20) NOT NULL DEFAULT 'running',
				mode varchar(20) NOT NULL,
				model_key char(64) NOT NULL,
				config longtext NOT NULL,
				cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				max_id bigint(20) unsigned NOT NULL DEFAULT 0,
				total int unsigned NOT NULL DEFAULT 0,
				processed int unsigned NOT NULL DEFAULT 0,
				failed int unsigned NOT NULL DEFAULT 0,
				lock_token char(32) NOT NULL DEFAULT '',
				lock_until int unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_lock (status,lock_until)
			) $charset;",
			"CREATE TABLE {$t('cache')} (
				cache_key char(64) NOT NULL,
				payload longtext NOT NULL,
				expires_at int unsigned NOT NULL,
				revision bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (cache_key),
				KEY expires (expires_at)
			) $charset;",
			"CREATE TABLE {$t('searches')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				query_hash char(64) NOT NULL,
				query_text text DEFAULT NULL,
				status varchar(20) NOT NULL,
				result_count int unsigned NOT NULL DEFAULT 0,
				duration_ms int unsigned NOT NULL DEFAULT 0,
				from_cache tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created_at),
				KEY query_hash (query_hash)
			) $charset;",
			"CREATE TABLE {$t('usage')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				provider varchar(64) NOT NULL,
				model varchar(160) NOT NULL,
				task varchar(20) NOT NULL,
				requests int unsigned NOT NULL DEFAULT 1,
				input_tokens bigint(20) unsigned DEFAULT NULL,
				output_tokens bigint(20) unsigned DEFAULT NULL,
				total_tokens bigint(20) unsigned DEFAULT NULL,
				cost decimal(20,8) DEFAULT NULL,
				currency varchar(8) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY provider_model (provider,model),
				KEY created (created_at)
			) $charset;",
			"CREATE TABLE {$t('errors')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				context varchar(32) NOT NULL,
				code varchar(64) NOT NULL,
				provider varchar(64) NOT NULL DEFAULT '',
				model varchar(160) NOT NULL DEFAULT '',
				http_status smallint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created_at),
				KEY context_code (context,code)
			) $charset;",
			"CREATE TABLE {$t('rates')} (
				rate_key char(64) NOT NULL,
				window_start int unsigned NOT NULL,
				hits int unsigned NOT NULL,
				PRIMARY KEY  (rate_key)
			) $charset;",
		];
		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}
		update_option( 'bas_schema_version', self::VERSION, false );
		add_option( 'bas_index_revision', 1, '', false );
		add_option( 'bas_active_model', '', '', false );
	}
}
