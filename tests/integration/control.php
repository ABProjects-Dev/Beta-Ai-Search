<?php
/** Test-site control endpoint for CLI/browser harnesses. Never ship inside the plugin. */
$_SERVER['HTTP_HOST'] = 'localhost:9400';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BAS_TEST_WP' ) ?: __DIR__ . '/../../.tools/wordpress-7/wp-load.php';
$action = $argv[1] ?? '';
$value = $argv[2] ?? '';
switch ( $action ) {
	case 'fault': update_option( 'woocommerce_coming_soon', 'no' ); update_option( 'bas_test_fault', $value, false ); echo "fault=$value\n"; break;
	case 'language': \BetaAI\Search\Core\Plugin::instance()->settings->update( [ 'language' => 'en' === $value ? 'en' : 'fa' ] ); echo "language=$value\n"; break;
	case 'reset_rate': global $wpdb; $wpdb->query( 'DELETE FROM wp_bas_rates' ); echo "rate reset\n"; break;
	case 'http_count': echo (int) get_option( 'bas_test_http_count', 0 ) . "\n"; break;
	case 'admin_user': wp_set_password( 'BasTest!12345', 1 ); echo "admin user 1 password reset for disposable test site\n"; break;
	case 'tick': \BetaAI\Search\Core\Plugin::instance()->indexer->tick(); echo wp_json_encode( \BetaAI\Search\Core\Plugin::instance()->indexer->status() ) . "\n"; break;
	case 'pages':
		$pages = [];
		foreach ( [ 'pagination-test' => '[beta_ai_search][products limit="1" columns="1" orderby="date" order="DESC"]', 'multi-grid-test' => '[beta_ai_search scope="all"][products limit="2" columns="2" orderby="date" order="DESC"][products limit="2" columns="2" orderby="date" order="ASC"]' ] as $slug => $content ) {
			$page = get_page_by_path( $slug );
			$pages[ $slug ] = $page ? $page->ID : wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ] );
		}
		echo wp_json_encode( $pages ) . "\n"; break;
	case 'drain':
		$p = \BetaAI\Search\Core\Plugin::instance();
		for ( $i = 0; $i < 20; $i++ ) { if ( (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_bas_queue' ) === 0 ) { break; } sleep( 3 ); $p->indexer->tick(); }
		$p->indexer->cleanup_old_models();
		echo "drained\n"; break;
	default: exit( "Unknown action\n" );
}
