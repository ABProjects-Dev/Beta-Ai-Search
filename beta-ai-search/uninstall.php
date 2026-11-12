<?php
/**
 * Uninstall handler. Data is preserved unless the store manager explicitly opted in
 * under Settings → General → Data removal. Deactivation never removes data.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'bas_settings', [] );
if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( [ 'index', 'queue', 'jobs', 'cache', 'searches', 'usage', 'errors', 'rates' ] as $name ) {
	$table = $wpdb->prefix . 'bas_' . $name;
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the site prefix and a fixed allowlist.
}
foreach ( [ 'bas_settings', 'bas_provider_secrets', 'bas_schema_version', 'bas_index_revision', 'bas_active_model', 'bas_active_config' ] as $option ) {
	delete_option( $option );
}
delete_transient( 'bas_cache_lock' );
wp_clear_scheduled_hook( 'bas_index_worker' );
wp_clear_scheduled_hook( 'bas_index_cleanup' );
wp_clear_scheduled_hook( 'bas_privacy_cleanup' );
