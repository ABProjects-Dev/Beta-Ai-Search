<?php
/** Disposable-site fixture setup. Run with php tests/integration/setup.php from repository root. */
$_SERVER['HTTP_HOST'] = 'localhost:9400';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BAS_TEST_WP' ) ?: __DIR__ . '/../../.tools/wordpress-7/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\Elementor\Plugin' ) ) { exit( "WooCommerce and Elementor must be active.\n" ); }
$settings = \BetaAI\Search\Core\Plugin::instance()->settings;
$settings->update( [ 'language' => 'fa', 'store_query_text' => 1, 'public_meta_keys' => "public_specs\nmaterial_note", 'embedding' => [ 'mode' => 'manual', 'provider' => 'custom', 'model' => 'test-embed', 'dimensions' => 8 ], 'query' => [ 'mode' => 'manual', 'provider' => 'custom', 'model' => 'test-query' ], 'providers' => [ 'custom' => [ 'base_url' => 'https://api.github.com/__bas_test__/v1', 'embedding_capable' => 1, 'chat_capable' => 1, 'rates_json' => '{"test-embed":{"input":1,"output":0,"currency":"USD"},"test-query":{"input":2,"output":4,"currency":"USD"}}' ] ] ] );
\BetaAI\Search\Core\Secrets::set( 'custom', 'test-only-not-a-real-key' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'bas_test_fault', '', false );
update_option( 'bas_test_http_count', 0, false );

$intents = [];
$add = static function ( $queries, $intent ) use ( &$intents ) { foreach ( $queries as $query ) { $intents[ bas_test_normalize( $query ) ] = $intent; } };
$shoe = bas_test_intent( 'comfortable walking shoes کفش راحت پیاده روی', [ 'shoes', 'کفش' ], [ 'walking', 'پیاده روی' ] );
$add( [ 'کفش راحت برای پیاده روی', 'یه کفش راحت میخوام واسه پیاده روی', 'comfortable walking shoes', 'کفش مشکی راحت برای پیاده روی' ], $shoe );
$black = bas_test_intent( 'black walking shoes کفش مشکی پیاده روی', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black', 'مشکی' ] ) ] );
$add( [ 'کفش مشکی برای پیاده روی', 'black walking shoes' ], $black );
$add( [ 'کفش مشکی سایز 42 برای پیاده روی تا ۱٫۳ میلیون تومان' ], bas_test_intent( 'black walking shoes', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black', 'مشکی' ] ), bas_test_attr( 'size', [ '42' ] ) ], null, 1300000, 0.98, 'IRT' ) );
$add( [ 'کفش مشکی برای پیاده روی تا ۲ میلیون تومان' ], bas_test_intent( 'black walking shoes', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black' ] ) ], null, 2000000, 0.98, 'IRT' ) );
$add( [ 'کفش نایکی مشکی برای پیاده روی' ], bas_test_intent( 'black Nike walking shoes', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black' ] ), bas_test_attr( 'brand', [ 'Nike', 'نایکی' ] ) ] ) );
$add( [ 'کفش چرم مشکی برای پیاده روی' ], bas_test_intent( 'black leather walking shoes', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black' ] ), bas_test_attr( 'material', [ 'leather', 'چرم' ] ) ] ) );
$add( [ 'کفش ضدآب مشکی برای پیاده روی' ], bas_test_intent( 'black waterproof walking shoes', [ 'shoes' ], [ 'walking' ], [ bas_test_attr( 'color', [ 'black' ] ), bas_test_attr( 'technical', [ 'waterproof', 'ضدآب' ] ) ] ) );
$add( [ 'لپ تاپ با رم 16 گیگ' ], bas_test_intent( 'laptop 16GB RAM', [ 'laptop' ], [], [ bas_test_attr( 'technical', [ '16GB' ] ) ] ) );
$add( [ 'یک چیز عجیب که در فروشگاه نیست' ], bas_test_intent( 'nonexistent product', [], [], [], null, null, 0.2 ) );
update_option( 'bas_test_intents', $intents, false );

foreach ( [ 'color' => [ 'black', 'white', 'blue' ], 'size' => [ '41', '42', '43' ] ] as $name => $values ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $name ) ) { wc_create_attribute( [ 'name' => ucfirst( $name ), 'slug' => $name, 'type' => 'select' ] ); }
	register_taxonomy( 'pa_' . $name, 'product', [] );
	foreach ( $values as $value ) { if ( ! get_term_by( 'slug', $value, 'pa_' . $name ) ) { wp_insert_term( $value, 'pa_' . $name ); } }
}
$categories = [];
foreach ( [ 'Shoes', 'Bags', 'Electronics', 'Apparel' ] as $name ) {
	$term = get_term_by( 'name', $name, 'product_cat' );
	if ( ! $term ) { $term = (object) wp_insert_term( $name, 'product_cat' ); }
	$categories[ $name ] = (int) ( $term->term_id ?? $term->id );
}
$ids = get_option( 'bas_test_products', [] );
if ( $ids ) { exit( "Fixtures already exist: " . wp_json_encode( $ids ) . "\n" ); }
$make_attr = static function ( $name, $options, $variation = false, $global = false ) {
	$a = new WC_Product_Attribute();
	$a->set_id( $global ? wc_attribute_taxonomy_id_by_name( $name ) : 0 );
	$a->set_name( $global ? 'pa_' . $name : $name );
	$a->set_options( $options ); $a->set_position( 0 ); $a->set_visible( true ); $a->set_variation( $variation );
	return $a;
};
$simple = static function ( $name, $description, $price, $category, $attributes = [], $stock = 'instock', $brand = '' ) use ( $categories, $make_attr ) {
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_description( $description ); $p->set_short_description( $description ); $p->set_regular_price( (string) $price ); $p->set_category_ids( [ $categories[ $category ] ] ); $p->set_stock_status( $stock ); $p->set_attributes( array_map( static fn( $k, $v ) => $make_attr( $k, (array) $v ), array_keys( $attributes ), $attributes ) );
	if ( $brand ) { $p->set_brand_ids( [ wp_insert_term( $brand, 'product_brand' )['term_id'] ] ); }
	$id = $p->save(); update_post_meta( $id, 'public_specs', $attributes['Features'] ?? [] ); update_post_meta( $id, '_private_cost', 'DO_NOT_INDEX_PRIVATE_COST' ); return $id;
};
$variable = new WC_Product_Variable(); $variable->set_name( 'کفش کتانی راحتی مدل WalkFlex' ); $variable->set_status( 'publish' ); $variable->set_description( 'Comfortable walking shoe کفش راحت برای پیاده روی و قدم زدن روزانه. Upper material leather.' ); $variable->set_short_description( 'کفش پیاده روی راحت' ); $variable->set_category_ids( [ $categories['Shoes'] ] );
$color_ids = array_map( static fn( $slug ) => get_term_by( 'slug', $slug, 'pa_color' )->term_id, [ 'black', 'white' ] );
$size_ids = array_map( static fn( $slug ) => get_term_by( 'slug', $slug, 'pa_size' )->term_id, [ '42', '43' ] );
$variable->set_attributes( [ $make_attr( 'color', $color_ids, true, true ), $make_attr( 'size', $size_ids, true, true ), $make_attr( 'Material', [ 'leather', 'چرم' ] ) ] );
$parent_id = $variable->save();
wp_set_object_terms( $parent_id, 'Nike', 'product_brand' );
update_post_meta( $parent_id, 'public_specs', 'lightweight comfortable' ); update_post_meta( $parent_id, '_private_cost', 'DO_NOT_INDEX_PRIVATE_COST' );
$variation_ids = [];
foreach ( [ [ 'black', '42', 12000000, 'outofstock' ], [ 'white', '43', 14000000, 'instock' ] ] as $data ) {
	$v = new WC_Product_Variation(); $v->set_parent_id( $parent_id ); $v->set_status( 'publish' ); $v->set_attributes( [ 'pa_color' => $data[0], 'pa_size' => $data[1] ] ); $v->set_regular_price( (string) $data[2] ); $v->set_stock_status( $data[3] ); $variation_ids[] = $v->save();
}
WC_Product_Variable::sync( $parent_id );
$ids = [ 'walkflex' => $parent_id, 'walkflex_black42' => $variation_ids[0], 'walkflex_white43' => $variation_ids[1] ];
$ids['adidas_walk'] = $simple( 'کفش پیاده روی مشکی Adidas Comfort', 'Comfortable black walking shoe کفش مشکی راحت برای پیاده روی', 11000000, 'Shoes', [ 'Color' => [ 'black', 'مشکی' ], 'Size' => [ '41' ], 'Material' => [ 'leather' ] ], 'instock', 'Adidas' );
$ids['running'] = $simple( 'کفش دویدن آبی RunFast', 'Running shoe for athletic training کفش برای دویدن', 10000000, 'Shoes', [ 'Color' => [ 'blue' ], 'Size' => [ '42' ] ] );
$ids['backpack'] = $simple( 'کوله پشتی مشکی ضدآب TrailPack', 'Waterproof hiking backpack 30L', 20000000, 'Bags', [ 'Color' => [ 'black' ], 'Features' => [ 'waterproof', '30L' ] ] );
$ids['laptop'] = $simple( 'لپ تاپ Nova 16', 'Laptop with 16GB RAM and 512GB SSD', 350000000, 'Electronics', [ 'Features' => [ '16GB', '512GB SSD' ] ] );
$ids['headphones'] = $simple( 'هدفون بی سیم مشکی SoundBlack', 'Wireless headphones with noise cancellation', 30000000, 'Electronics', [ 'Color' => [ 'black' ], 'Features' => [ 'wireless' ] ] );
$ids['shirt'] = $simple( 'تیشرت نخی سفید CottonSoft', 'Cotton shirt for everyday wear', 5000000, 'Apparel', [ 'Color' => [ 'white' ], 'Material' => [ 'cotton' ] ] );
update_option( 'bas_test_products', $ids, false );
$page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AI Search integration test', 'post_name' => 'search-test', 'post_content' => "[beta_ai_search]\n[products limit=\"4\" columns=\"4\" orderby=\"date\" order=\"DESC\"]" ] );
update_option( 'bas_test_page', $page_id, false );
// A real Elementor Free page containing the registered widget and a native Woo products widget.
$widget_id = 'bas' . wp_generate_password( 6, false, false );
$woo_id = 'woo' . wp_generate_password( 6, false, false );
$data = [
	[ 'id' => $widget_id, 'elType' => 'widget', 'settings' => [ 'template' => 'pill', 'target' => '', 'scope' => 'first' ], 'widgets' => [], 'widgetType' => 'beta-ai-search' ],
	[ 'id' => $woo_id, 'elType' => 'widget', 'settings' => [ 'shortcode' => '[products limit="4" columns="4" orderby="date" order="DESC"]' ], 'widgets' => [], 'widgetType' => 'shortcode' ],
];
$elementor_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor Free integration test', 'post_name' => 'elementor-test', 'post_content' => '' ] );
update_post_meta( $elementor_page, '_elementor_edit_mode', 'builder' ); update_post_meta( $elementor_page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) ); update_post_meta( $elementor_page, '_elementor_template_type', 'wp-page' ); update_post_meta( $elementor_page, '_elementor_version', ELEMENTOR_VERSION );
update_option( 'bas_test_elementor_page', $elementor_page, false );
wp_set_object_terms( $ids['shirt'], 'CottonSoft', 'product_tag' );
echo wp_json_encode( [ 'products' => $ids, 'page' => $page_id, 'elementor_page' => $elementor_page, 'category' => $categories['Apparel'] ], JSON_PRETTY_PRINT ) . "\n";
