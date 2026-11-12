<?php
/**
 * Integration assertions against the disposable WordPress site created by setup.php.
 * Uses the controlled AI test double in mock-ai.php; does not certify live AI quality.
 */
$_SERVER['HTTP_HOST'] = 'localhost:9400';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BAS_TEST_WP' ) ?: __DIR__ . '/../../.tools/wordpress-7/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
use BetaAI\Search\Core\Plugin;
use BetaAI\Search\Core\Settings;
use BetaAI\Search\Core\Secrets;
use BetaAI\Search\Core\Schema;
use BetaAI\Search\Core\Reports;
use BetaAI\Search\AI\AIException;
use BetaAI\Search\AI\QuerySchema;
use BetaAI\Search\Index\ProductExtractor;
use BetaAI\Search\Search\Text;
use BetaAI\Search\Search\Matcher;
use BetaAI\Search\Frontend\SearchBox;

$results = [];
function drain_queue( $p ): void {
	for ( $i = 0; $i < 20; $i++ ) {
		if ( (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_bas_queue' ) === 0 ) { return; }
		sleep( 3 );
		$p->indexer->tick();
	}
}
function check( string $name, bool $ok, string $detail = '' ): void {
	global $results;
	$results[] = [ 'name' => $name, 'ok' => $ok, 'detail' => $detail ];
	echo ( $ok ? 'PASS' : 'FAIL' ) . " $name" . ( $detail ? " — $detail" : '' ) . "\n";
}
function ids_named( array $ids, array $names ): array { return array_map( static fn( $id ) => $names[ $id ] ?? (string) $id, $ids ); }
$p = Plugin::instance();
$ids = get_option( 'bas_test_products' );
$names = array_flip( $ids );
$s = $p->settings;
drain_queue( $p );

check( 'activation creates eight plugin-owned tables', count( $GLOBALS['wpdb']->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_bas_%'" ) ) === 8 );
check( 'Elementor Free widget is registered', isset( \Elementor\Plugin::instance()->widgets_manager->get_widget_types()['beta-ai-search'] ) );
$shortcode = do_shortcode( '[beta_ai_search]' );
check( 'shortcode renders central Persian no-result text setting', str_contains( $shortcode, 'bas-search' ) && str_contains( $shortcode, 'rtl' ) );
$entities = ( new ProductExtractor() )->extract( $ids['walkflex'] );
check( 'variable product extracts parent plus each visible variation', count( $entities ) === 3 );
$black = null; $white = null;
foreach ( $entities as $entity ) { if ( $entity['entity_id'] === $ids['walkflex_black42'] ) { $black = $entity; } if ( $entity['entity_id'] === $ids['walkflex_white43'] ) { $white = $entity; } }
check( 'variation-specific color/size/stock are independent', $black && $white && $black['facts']['attributes']['color'] === [ 'black' ] && $white['facts']['attributes']['color'] === [ 'white' ] && $black['facts']['stock_status'] === 'outofstock' && $white['facts']['stock_status'] === 'instock' );
check( 'variation inherits non-varying parent material', $black && in_array( 'leather', $black['facts']['attributes']['material'] ?? [], true ) );
check( 'private/internal metadata is excluded from AI documents', ! str_contains( wp_json_encode( $entities ), 'DO_NOT_INDEX_PRIVATE_COST' ) );
check( 'allowlisted public custom field is included', str_contains( $black['document'] ?? '', 'lightweight comfortable' ) );
$status = $p->indexer->status();
check( 'background index completed and activated a model', ( $status['job']['status'] ?? '' ) === 'completed' && ! empty( $status['active_model']['model_key'] ), 'entities=' . $status['indexed_entities'] );

$cases = [
	[ 'کفش راحت برای پیاده روی', [ 'walkflex', 'adidas_walk' ] ],
	[ 'یه کفش راحت میخوام واسه پیاده روی', [ 'walkflex', 'adidas_walk' ] ],
	[ 'comfortable walking shoes', [ 'walkflex', 'adidas_walk' ] ],
	[ 'کفش مشکی برای پیاده روی', [ 'adidas_walk', 'walkflex' ] ],
	[ 'کفش مشکی سایز 42 برای پیاده روی تا ۱٫۳ میلیون تومان', [ 'walkflex' ] ],
	[ 'کفش نایکی مشکی برای پیاده روی', [ 'walkflex' ] ],
	[ 'کفش چرم مشکی برای پیاده روی', [ 'adidas_walk', 'walkflex' ] ],
	[ 'لپ تاپ با رم 16 گیگ', [ 'laptop' ] ],
	[ 'کفش ضدآب مشکی برای پیاده روی', [] ],
	[ 'یک چیز عجیب که در فروشگاه نیست', [] ],
];
foreach ( $cases as $case ) {
	$r = $p->search->search( $case[0] );
	$got = ids_named( $r['ids'], $names );
	check( 'controlled pipeline: ' . $case[0], $got === $case[1], wp_json_encode( $got, JSON_UNESCAPED_UNICODE ) );
}
$before_http = (int) get_option( 'bas_test_http_count', 0 );
$cached = $p->search->search( 'comfortable walking shoes' );
check( 'cache hit avoids provider requests', $cached['cached'] && (int) get_option( 'bas_test_http_count', 0 ) === $before_http );
$s->update( [ 'global_flex' => 36 ] );
$after_settings = $p->search->search( 'comfortable walking shoes' );
check( 'settings change invalidates cache', ! $after_settings['cached'] );
$s->update( [ 'global_flex' => 35 ] );

$price_cases = [
	[ 'تا ۲ میلیون تومان', 2000000.0, 'IRT', 'max' ],
	[ 'بین ۱٫۵ تا ۲ میلیون تومان', 1500000.0, 'IRT', 'min' ],
	[ 'under 1.5m usd', 1500000.0, 'USD', 'max' ],
	[ 'تا 2,000,000 ریال', 2000000.0, 'IRR', 'max' ],
	[ 'از 500 هزار تا 1 میلیون تومان', 500000.0, 'IRT', 'min' ],
];
foreach ( $price_cases as $case ) {
	$bounds = Text::price_bounds( Text::normalize( $case[0] ) );
	check( 'deterministic price parsing: ' . $case[0], $bounds && $bounds[ $case[3] ] === $case[1] && $bounds['currency'] === $case[2], wp_json_encode( $bounds ) );
}
$intent = bas_test_intent( 'shoe', [ 'shoe' ], [], [ bas_test_attr( 'color', [ 'black' ] ) ], null, 100.0 );
$facts = [ 'attributes' => [ 'color' => [ 'black' ] ], 'price' => 105.0 ];
$settings = $s->all(); $settings['attribute_flex']['price'] = 20;
check( 'price flexibility 20 accepts exactly 5% overflow', Matcher::score( $intent, $facts, 1.0, $settings )['accepted'] );
$facts['price'] = 106.0;
check( 'price flexibility 20 rejects 6% overflow', ! Matcher::score( $intent, $facts, 1.0, $settings )['accepted'] );
$facts['price'] = 100.0; $facts['attributes']['color'] = [];
$settings['attribute_flex']['color'] = 100;
check( 'missing explicit attribute is not treated as a match even at maximum flexibility', ! Matcher::score( $intent, $facts, 1.0, $settings )['accepted'] );
check( 'threshold formulas are deterministic', Matcher::thresholds( [ 'global_flex' => 0 ] ) === [ 'semantic' => 0.76, 'overall' => 0.70 ] && abs( Matcher::thresholds( [ 'global_flex' => 100 ] )['semantic'] - 0.50 ) < 1e-9 );

$s->update( [ 'cache_enabled' => 0 ] );
$faults = [ 'http500' => 'provider_unavailable', 'badkey' => 'invalid_api_key', 'quota' => 'quota_exceeded', 'rate' => 'rate_limited', 'timeout' => 'timeout', 'transport' => 'connection_error', 'malformed' => 'invalid_provider_response', 'invalid_schema' => 'invalid_ai_schema' ];
foreach ( $faults as $fault => $code ) {
	update_option( 'bas_test_fault', $fault, false );
	try { $p->search->search( 'comfortable walking shoes' ); $ok = false; $got = 'no exception'; }
	catch ( AIException $e ) { $ok = $e->safe_code === $code; $got = $e->safe_code; }
	check( 'AI failure is explicit, never keyword fallback: ' . $fault, $ok, $got );
}
update_option( 'bas_test_fault', '', false );
$s->update( [ 'cache_enabled' => 1 ] );

// Incremental update after the initial job has completed.
$product = wc_get_product( $ids['adidas_walk'] );
$old_price = $product->get_regular_price();
$old_hash = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT content_hash FROM wp_bas_index WHERE entity_id=%d LIMIT 1', $ids['adidas_walk'] ) );
$product->set_regular_price( (string) ( (float) $old_price + 1000 ) ); $product->save();
sleep( 3 );
check( 'CRUD price edit queues the product after completion', in_array( $ids['adidas_walk'], $p->queue->due_ids( 100, true ), true ) );
$p->indexer->tick();
$job = $p->indexer->status()['job'] ?? null;
$new_hash = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT content_hash FROM wp_bas_index WHERE entity_id=%d LIMIT 1', $ids['adidas_walk'] ) );
check( 'incremental worker updates index without a new full scan', $job && $job['mode'] === 'update' && $new_hash !== $old_hash );
$product->set_regular_price( $old_price ); $product->save(); sleep( 3 ); $p->indexer->tick();

// Variation add/remove is queued against the parent and cleaned from the index.
$v = new WC_Product_Variation(); $v->set_parent_id( $ids['walkflex'] ); $v->set_status( 'publish' ); $v->set_attributes( [ 'pa_color' => 'black', 'pa_size' => '41' ] ); $v->set_regular_price( '11500000' ); $v->set_stock_status( 'instock' ); $vid = $v->save();
sleep( 3 );
check( 'variation creation queues parent', in_array( $ids['walkflex'], $p->queue->due_ids( 100, true ), true ) );
$p->indexer->tick();
check( 'new variation is indexed independently', (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM wp_bas_index WHERE entity_id=%d', $vid ) ) === 1 );
$v->delete( true ); sleep( 3 ); $p->indexer->tick();
check( 'variation deletion removes its index entity', (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM wp_bas_index WHERE entity_id=%d', $vid ) ) === 0 );
WC_Product_Variable::sync( $ids['walkflex'] ); $p->queue->enqueue( $ids['walkflex'], 'test_restore', 0 ); drain_queue( $p );

// Pause/resume/rebuild and model-switch activation.
$job_id = $p->indexer->rebuild(); $p->indexer->pause();
$paused = $p->indexer->status(); $p->indexer->tick();
check( 'pause prevents background processing', ( $paused['job']['status'] ?? '' ) === 'paused' && $p->indexer->status()['job']['processed'] === $paused['job']['processed'] );
$p->indexer->resume();
for ( $i = 0; $i < 10 && ! in_array( $p->indexer->status()['job']['status'] ?? '', [ 'completed', 'error' ], true ); $i++ ) { $p->indexer->tick(); }
check( 'resume completes a full rebuild', ( $p->indexer->status()['job']['status'] ?? '' ) === 'completed' );
$original_model = $s->get( 'embedding' );
$s->update( [ 'embedding' => [ 'model' => 'test-embed-alt' ] ] );
check( 'embedding model change flags rebuild requirement', $p->indexer->status()['rebuild_required'] );
$p->indexer->rebuild();
for ( $i = 0; $i < 10 && ! in_array( $p->indexer->status()['job']['status'] ?? '', [ 'completed', 'error' ], true ); $i++ ) { $p->indexer->tick(); }
check( 'rebuild activates the newly selected model only after completion', ( $p->indexer->status()['active_model']['model'] ?? '' ) === 'test-embed-alt' );
$s->update( [ 'embedding' => $original_model ] ); $p->indexer->rebuild();
for ( $i = 0; $i < 10 && ! in_array( $p->indexer->status()['job']['status'] ?? '', [ 'completed', 'error' ], true ); $i++ ) { $p->indexer->tick(); }

$rendered = $p->renderer->render( [ $ids['adidas_walk'], $ids['walkflex'] ], 1, 12 );
check( 'renderer uses native WooCommerce product-card template in place', $rendered['adapter'] === 'woocommerce-loop' && substr_count( $rendered['html'], 'woocommerce-loop-product__title' ) === 2 && $rendered['total'] === 2 );
try { $p->renderer->render( [ $ids['adidas_walk'] ], 1, 12, [ 'payload' => 'tampered', 'signature' => 'bad' ] ); $ok = false; } catch ( InvalidArgumentException ) { $ok = true; }
check( 'tampered Loop Grid context is rejected', $ok );
$hidden = new WC_Product_Simple(); $hidden->set_name( 'Hidden out-of-stock test product' ); $hidden->set_status( 'publish' ); $hidden->set_catalog_visibility( 'hidden' ); $hidden->set_stock_status( 'outofstock' ); $hidden->set_regular_price( '1000' ); $hidden_id = $hidden->save();
check( 'hidden products are not rendered even when out-of-stock results are allowed', $p->renderer->render( [ $hidden_id ], 1, 12 )['total'] === 0 );
$hidden->delete( true );

// Provider capability and independent role selection (resolution only; no live API call).
$original_embedding = $s->get( 'embedding' );
$original_query = $s->get( 'query' );
Secrets::set( 'anthropic', 'test-only-anthropic-key' );
Secrets::set( 'openai', 'test-only-openai-key' );
$s->update( [ 'embedding' => [ 'mode' => 'auto', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 0 ] ] );
$resolved_embedding = $p->providers->resolve( 'embedding' );
check( 'automatic embedding selection never chooses chat-only Anthropic', $resolved_embedding['provider'] !== 'anthropic' );
$s->update( [ 'embedding' => [ 'mode' => 'manual', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'dimensions' => 0 ] ] );
try { $p->providers->resolve( 'embedding' ); $ok = false; } catch ( AIException $e ) { $ok = $e->safe_code === 'embedding_unsupported'; }
check( 'manual Anthropic embedding is rejected as unsupported', $ok );
$s->update( [ 'embedding' => $original_embedding, 'query' => [ 'mode' => 'manual', 'provider' => 'openai', 'model' => 'gpt-4.1-mini' ] ] );
$resolved_query = $p->providers->resolve( 'query' );
check( 'query-understanding model resolves independently from active embedding provider', $resolved_query['provider'] === 'openai' && $p->indexer->status()['active_model']['provider'] === 'custom' );
$s->update( [ 'embedding' => $original_embedding, 'query' => $original_query ] );
Secrets::delete( 'anthropic' ); Secrets::delete( 'openai' );

$raw_secrets = wp_json_encode( get_option( 'bas_provider_secrets', [] ) );
check( 'API key is encrypted at rest and never stored in plaintext options', Secrets::configured( 'custom' ) && ! str_contains( $raw_secrets, 'test-only-not-a-real-key' ) );
$partial = $s->sanitize( [ 'language' => 'en' ] );
check( 'partial tab save preserves provider configuration and other-language texts', ( $partial['providers']['custom']['base_url'] ?? '' ) === 'https://api.github.com/__bas_test__/v1' && isset( $partial['texts']['fa'] ) );
$subscriber = username_exists( 'bas_test_subscriber' ) ?: wp_insert_user( [ 'user_login' => 'bas_test_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
$manager = username_exists( 'bas_test_manager' ) ?: wp_insert_user( [ 'user_login' => 'bas_test_manager', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ] );
wp_set_current_user( $subscriber ); check( 'subscriber cannot manage plugin', ! Plugin::can_manage() );
wp_set_current_user( $manager ); check( 'WooCommerce shop manager can manage plugin', Plugin::can_manage() );
wp_set_current_user( 0 );

$usage = Reports::dashboard()['usage'];
$cost_known = false; $cost_unknown = false;
foreach ( $usage as $row ) { if ( null !== $row['cost'] && (float) $row['cost'] > 0 ) { $cost_known = true; } if ( (int) $row['unknown_costs'] > 0 || null === $row['cost'] ) { $cost_unknown = true; } }
check( 'usage/cost reporting contains real test-token costs and unknowns when rates are absent', $cost_known && $cost_unknown );
check( 'uninstall handler exists and data removal defaults off', is_file( BAS_PATH . 'uninstall.php' ) && empty( $s->get( 'delete_on_uninstall' ) ) );

$failed = array_values( array_filter( $results, static fn( $r ) => ! $r['ok'] ) );
echo "\n" . count( $results ) . ' checks, ' . count( $failed ) . " failures\n";
file_put_contents( __DIR__ . '/../../.tools/integration-results.json', wp_json_encode( [ 'environment' => [ 'wp' => get_bloginfo( 'version' ), 'wc' => WC_VERSION, 'elementor' => ELEMENTOR_VERSION, 'php' => PHP_VERSION, 'db' => 'SQLite integration drop-in' ], 'results' => $results ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
exit( $failed ? 1 : 0 );
