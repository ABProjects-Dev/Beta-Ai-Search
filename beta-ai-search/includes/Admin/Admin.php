<?php
namespace BetaAI\Search\Admin;

use BetaAI\Search\AI\AIException;
use BetaAI\Search\AI\ProviderRegistry;
use BetaAI\Search\Core\Reports;
use BetaAI\Search\Core\Schema;
use BetaAI\Search\Core\Secrets;
use BetaAI\Search\Core\Settings;
use BetaAI\Search\Index\Indexer;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public function __construct( private Settings $settings, private Indexer $indexer, private ProviderRegistry $providers ) {}

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		foreach ( [ 'index_status', 'index_start', 'index_pause', 'index_resume', 'index_rebuild', 'index_retry', 'clear_cache', 'clear_logs', 'test_provider', 'list_models' ] as $action ) {
			add_action( 'wp_ajax_bas_' . $action, [ $this, 'ajax_' . $action ] );
		}
	}
	public function menu(): void {
		add_menu_page( 'Beta AI Search', 'Beta AI Search', ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search', [ $this, 'dashboard' ], 'dashicons-search', 56 );
		add_submenu_page( 'beta-ai-search', $this->t( 'داشبورد', 'Dashboard' ), $this->t( 'داشبورد', 'Dashboard' ), ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search', [ $this, 'dashboard' ] );
		add_submenu_page( 'beta-ai-search', $this->t( 'تنظیمات', 'Settings' ), $this->t( 'تنظیمات', 'Settings' ), ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search-settings', [ $this, 'settings_page' ] );
		add_submenu_page( 'beta-ai-search', $this->t( 'ایندکس محصولات', 'Product index' ), $this->t( 'ایندکس محصولات', 'Product index' ), ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search-index', [ $this, 'index_page' ] );
		add_submenu_page( 'beta-ai-search', $this->t( 'گزارش‌ها', 'Reports' ), $this->t( 'گزارش‌ها', 'Reports' ), ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search-reports', [ $this, 'reports_page' ] );
		add_submenu_page( 'beta-ai-search', $this->t( 'خطاها', 'Errors' ), $this->t( 'خطاها', 'Errors' ), ( function_exists( 'WC' ) ? 'manage_woocommerce' : 'manage_options' ), 'beta-ai-search-errors', [ $this, 'errors_page' ] );
	}
	public function register(): void {
		register_setting( 'bas_settings_group', Settings::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize_settings' ], 'default' => Settings::defaults(), 'show_in_rest' => false ] );
	}
	public function sanitize_settings( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		foreach ( ( $input['providers'] ?? [] ) as $id => $raw ) {
			$id = sanitize_key( $id );
			if ( ! array_key_exists( $id, apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) ) || ! is_array( $raw ) ) {
				continue;
			}
			if ( ! empty( $raw['delete_key'] ) ) {
				Secrets::delete( $id );
			} elseif ( isset( $raw['api_key'] ) && is_string( $raw['api_key'] ) && '' !== trim( $raw['api_key'] ) ) {
				$key = trim( wp_unslash( $raw['api_key'] ) );
				if ( strlen( $key ) > 512 || preg_match( '/\s/', $key ) || ! Secrets::set( $id, $key ) ) {
					add_settings_error( Settings::OPTION, 'secret_storage', $this->t( 'کلید ذخیره نشد؛ OpenSSL و طول کلید را بررسی کنید.', 'Key was not saved; check OpenSSL and key length.' ) );
				}
			}
		}
		Reports::bump_revision();
		return $this->settings->sanitize( $input );
	}

	private function t( string $fa, string $en ): string { return 'fa' === $this->settings->get( 'language' ) ? $fa : $en; }
	private function guard(): void {
		if ( ! \BetaAI\Search\Core\Plugin::can_manage() ) {
			wp_die( esc_html__( 'Access denied.', 'beta-ai-search' ), 403 );
		}
	}
	private function ajax_guard(): void {
		check_ajax_referer( 'bas_admin', 'nonce' );
		if ( ! \BetaAI\Search\Core\Plugin::can_manage() ) {
			wp_send_json_error( [ 'message' => 'Access denied.', 'code' => 'forbidden' ], 403 );
		}
	}
	private function header( string $title ): void {
		$this->guard();
		echo '<div class="wrap bas-admin" dir="' . ( 'fa' === $this->settings->get( 'language' ) ? 'rtl' : 'ltr' ) . '"><div class="bas-admin__header"><div><h1>Beta AI Search</h1><p>' . esc_html( $title ) . '</p></div><span class="bas-badge">v' . esc_html( BAS_VERSION ) . '</span></div>';
		settings_errors( Settings::OPTION );
	}
	private function footer(): void { echo '</div>'; }
	private function tabs( string $active ): void {
		$tabs = [
			'general' => $this->t( 'عمومی', 'General' ), 'providers' => $this->t( 'سرویس‌های AI', 'AI providers' ),
			'embedding' => $this->t( 'مدل Embedding', 'Embedding model' ), 'query' => $this->t( 'درک درخواست', 'Query understanding' ),
			'language' => $this->t( 'زبان', 'Language' ), 'matching' => $this->t( 'انعطاف تطبیق', 'Matching flexibility' ),
			'index' => $this->t( 'ایندکس', 'Product index' ), 'cache' => $this->t( 'کش', 'Cache' ),
			'texts' => $this->t( 'متن‌های ویجت', 'Widget texts' ), 'reports' => $this->t( 'گزارش‌ها', 'Reports & history' ),
			'diagnostics' => $this->t( 'عیب‌یابی', 'Diagnostics' ), 'integrations' => $this->t( 'اتصال‌ها', 'Integrations' ),
		];
		echo '<nav class="bas-tabs" aria-label="' . esc_attr__( 'Settings sections', 'beta-ai-search' ) . '">';
		foreach ( $tabs as $id => $label ) {
			echo '<a class="' . ( $active === $id ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=beta-ai-search-settings&tab=' . $id ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	public function settings_page(): void {
		$this->header( $this->t( 'تنظیمات مرکزی افزونه', 'Central plugin settings' ) );
		$tab = sanitize_key( $_GET['tab'] ?? 'general' );
		if ( ! in_array( $tab, [ 'general', 'providers', 'embedding', 'query', 'language', 'matching', 'index', 'cache', 'texts', 'reports', 'diagnostics', 'integrations' ], true ) ) {
			$tab = 'general';
		}
		$this->tabs( $tab );
		echo '<form method="post" action="options.php">';
		settings_fields( 'bas_settings_group' );
		$s = $this->settings->all();
		echo '<div class="bas-panel">';
		switch ( $tab ) {
			case 'general': $this->general_tab( $s ); break;
			case 'providers': $this->providers_tab( $s ); break;
			case 'embedding': $this->task_tab( 'embedding', $s ); break;
			case 'query': $this->task_tab( 'query', $s ); break;
			case 'language': $this->language_tab( $s ); break;
			case 'matching': $this->matching_tab( $s ); break;
			case 'index': $this->index_tab( $s ); break;
			case 'cache': $this->cache_tab( $s ); break;
			case 'texts': $this->texts_tab( $s ); break;
			case 'reports': $this->reports_tab( $s ); break;
			case 'diagnostics': $this->diagnostics_tab( $s ); break;
			case 'integrations': $this->integrations_tab(); break;
		}
		echo '</div>';
		submit_button( $this->t( 'ذخیره تنظیمات', 'Save settings' ) );
		echo '</form>';
		$this->footer();
	}

	private function name( string $key ): string { return 'bas_settings[' . $key . ']'; }
	private function checkbox( string $key, int $value, string $label ): void {
		echo '<label><input type="hidden" name="' . esc_attr( $this->name( $key ) ) . '" value="0"><input type="checkbox" value="1" name="' . esc_attr( $this->name( $key ) ) . '" ' . checked( $value, 1, false ) . '> ' . esc_html( $label ) . '</label>';
	}
	private function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fields built with escaped attributes below.
	}
	private function input( string $key, mixed $value, string $type = 'text', array $attrs = [] ): string {
		$extra = '';
		foreach ( $attrs as $name => $attr ) { $extra .= ' ' . $name . '="' . esc_attr( (string) $attr ) . '"'; }
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( (string) $value ) . '"' . $extra . '>';
	}
	private function general_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'تنظیمات عمومی', 'General settings' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		ob_start(); $this->checkbox( 'enabled', $s['enabled'], $this->t( 'جستجوی هوشمند فعال باشد', 'Enable AI search' ) ); $this->row( $this->t( 'وضعیت', 'Status' ), ob_get_clean() );
		ob_start(); $this->checkbox( 'delete_on_uninstall', $s['delete_on_uninstall'], $this->t( 'هنگام حذف افزونه، جدول‌ها و تنظیمات افزونه نیز حذف شوند', 'Delete plugin tables and settings when the plugin is uninstalled' ) ); $this->row( $this->t( 'حذف داده‌ها', 'Data removal' ), ob_get_clean(), $this->t( 'پیش‌فرض خاموش است؛ deactivate کردن داده‌ها را حذف نمی‌کند.', 'Off by default; deactivation never deletes data.' ) );
		$this->row( $this->t( 'حداکثر نتیجه', 'Maximum results' ), $this->input( 'max_results', $s['max_results'], 'number', [ 'min' => 1, 'max' => 500 ] ), $this->t( 'همه کاتالوگ بررسی می‌شود؛ این گزینه فقط تعداد نهایی قابل نمایش را محدود می‌کند.', 'The whole catalog is evaluated; this limits only the final displayed matches.' ) );
		foreach ( [ 'toman_rate' => $this->t( 'واحد فروشگاه به ازای هر تومان', 'Store currency units per toman' ), 'usd_rate' => $this->t( 'واحد فروشگاه به ازای هر USD', 'Store currency units per USD' ), 'eur_rate' => $this->t( 'واحد فروشگاه به ازای هر EUR', 'Store currency units per EUR' ) ] as $key => $label ) {
			$this->row( $label, $this->input( $key, $s[ $key ], 'number', [ 'step' => 'any', 'min' => '0' ] ), $this->t( 'خالی = بدون نرخ ساختگی. تبدیل تومان/ریال برای فروشگاه IRR/IRT خودکار است.', 'Empty = no invented exchange rate. Toman/rial conversion is automatic for IRR/IRT stores.' ) );
		}
		echo '</tbody></table>';
	}

	private function providers_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'مدیریت ارائه‌دهندگان AI', 'AI provider management' ) ) . '</h2><p>' . esc_html( $this->t( 'کلیدها فقط در سرور ذخیره می‌شوند. برای تست یا دریافت مدل‌ها، ابتدا تنظیمات را ذخیره کنید. کلید خالی کلید قبلی را حفظ می‌کند.', 'Keys stay server-side. Save settings before testing or fetching models. An empty key field preserves the existing key.' ) ) . '</p>';
		if ( ! defined( 'BAS_ENCRYPTION_KEY' ) ) {
			echo '<div class="bas-notice">' . esc_html( $this->t( 'کلیدها encrypted هستند؛ برای محافظت در برابر افشای هم‌زمان دیتابیس و فایل‌ها، BAS_ENCRYPTION_KEY را در wp-config.php تعریف کنید.', 'Keys are encrypted; define BAS_ENCRYPTION_KEY in wp-config.php to harden against combined database/file disclosure.' ) ) . '</div>';
		}
		foreach ( apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) as $id => $definition ) {
			$config = $s['providers'][ $id ] ?? [];
			$configured = Secrets::configured( $id );
			echo '<section class="bas-provider"><div class="bas-provider__head"><h3>' . esc_html( $definition['label'] ) . '</h3><span class="bas-badge ' . ( $configured ? 'bas-badge--ok' : 'bas-badge--warn' ) . '">' . esc_html( $configured ? $this->t( 'کلید ذخیره شده', 'Key configured' ) : $this->t( 'کلید وارد نشده', 'No key' ) ) . '</span></div><div class="bas-provider__grid">';
			echo '<div><label>' . esc_html( $this->t( 'آدرس API (HTTPS)', 'API base URL (HTTPS)' ) ) . '</label><input type="text" name="' . esc_attr( $this->name( 'providers][' . $id . '][base_url' ) ) . '" value="' . esc_attr( $config['base_url'] ?? $definition['base_url'] ) . '"></div>';
			echo '<div><label>API Key</label><input type="password" autocomplete="new-password" name="' . esc_attr( $this->name( 'providers][' . $id . '][api_key' ) ) . '" value="" placeholder="' . esc_attr( $configured ? '••••••••••••••••' : $this->t( 'کلید را وارد کنید', 'Enter API key' ) ) . '"><label style="font-weight:400;margin-top:.4rem"><input type="checkbox" value="1" name="' . esc_attr( $this->name( 'providers][' . $id . '][delete_key' ) ) . '"> ' . esc_html( $this->t( 'حذف کلید ذخیره‌شده', 'Delete stored key' ) ) . '</label></div></div>';
			if ( 'custom' === $id ) {
				foreach ( [ 'chat_capable' => $this->t( 'این endpoint از chat/completions پشتیبانی می‌کند', 'This endpoint supports chat/completions' ), 'embedding_capable' => $this->t( 'این endpoint از embeddings پشتیبانی می‌کند', 'This endpoint supports embeddings' ) ] as $key => $label ) {
					echo '<label style="font-weight:400"><input type="hidden" name="' . esc_attr( $this->name( 'providers][' . $id . '][' . $key ) ) . '" value="0"><input type="checkbox" value="1" name="' . esc_attr( $this->name( 'providers][' . $id . '][' . $key ) ) . '" ' . checked( $config[ $key ] ?? 0, 1, false ) . '> ' . esc_html( $label ) . '</label>';
				}
			}
			echo '<label style="margin-top:.8rem">' . esc_html( $this->t( 'نرخ قیمت‌گذاری مدل‌ها (JSON، به ازای یک میلیون توکن)', 'Model pricing rates (JSON, per million tokens)' ) ) . '</label><textarea name="' . esc_attr( $this->name( 'providers][' . $id . '][rates_json' ) ) . '" spellcheck="false">' . esc_textarea( wp_json_encode( $config['rates'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</textarea><p class="description">' . esc_html__( 'Example: {"model-id":{"input":0.15,"output":0.60,"currency":"USD"}}. Missing rates or usage produce unknown cost, never zero.', 'beta-ai-search' ) . '</p>';
			echo '<div class="bas-provider__actions"><button type="button" class="button" data-provider-test="' . esc_attr( $id ) . '" data-task="query">' . esc_html( $this->t( 'تست درک درخواست', 'Test query model' ) ) . '</button><span class="bas-test-result" data-test-result="' . esc_attr( $id ) . '-query"></span><button type="button" class="button" data-provider-test="' . esc_attr( $id ) . '" data-task="embedding">' . esc_html( $this->t( 'تست Embedding', 'Test embedding' ) ) . '</button><span class="bas-test-result" data-test-result="' . esc_attr( $id ) . '-embedding"></span><button type="button" class="button" data-provider-models="' . esc_attr( $id ) . '">' . esc_html( $this->t( 'دریافت مدل‌ها', 'Fetch models' ) ) . '</button><span class="bas-test-result" data-models-result="' . esc_attr( $id ) . '"></span></div></section>';
		}
	}

	private function task_tab( string $task, array $s ): void {
		$selection = $s[ $task ];
		echo '<h2>' . esc_html( 'embedding' === $task ? $this->t( 'مدل تولید بردار معنایی', 'Semantic embedding model' ) : $this->t( 'مدل درک درخواست', 'Query understanding model' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		$mode = '<select name="' . esc_attr( $this->name( $task . '][mode' ) ) . '"><option value="auto" ' . selected( $selection['mode'], 'auto', false ) . '>' . esc_html( $this->t( 'خودکار، فقط مدل دارای قابلیت', 'Automatic, capable models only' ) ) . '</option><option value="manual" ' . selected( $selection['mode'], 'manual', false ) . '>' . esc_html( $this->t( 'انتخاب دستی', 'Manual selection' ) ) . '</option></select>';
		$this->row( $this->t( 'حالت انتخاب', 'Selection mode' ), $mode );
		$provider = '<select name="' . esc_attr( $this->name( $task . '][provider' ) ) . '">';
		foreach ( apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) as $id => $definition ) { $provider .= '<option value="' . esc_attr( $id ) . '" ' . selected( $selection['provider'], $id, false ) . '>' . esc_html( $definition['label'] ) . '</option>'; }
		$provider .= '</select>';
		$this->row( $this->t( 'سرویس', 'Provider' ), $provider );
		$model = '<input type="text" list="bas-models-' . esc_attr( $selection['provider'] ) . '" name="' . esc_attr( $this->name( $task . '][model' ) ) . '" value="' . esc_attr( $selection['model'] ) . '">';
		foreach ( apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) as $id => $definition ) {
			$model .= '<datalist id="bas-models-' . esc_attr( $id ) . '" data-model-list="' . esc_attr( $id ) . '">';
			foreach ( $definition['models'] as $entry ) { if ( ! empty( $entry[ 'embedding' === $task ? 'embedding' : 'chat' ] ) ) { $model .= '<option value="' . esc_attr( $entry['id'] ) . '"></option>'; } }
			$model .= '</datalist>';
		}
		$this->row( $this->t( 'شناسه مدل', 'Model ID' ), $model, $this->t( 'در حالت خودکار، اولین مدل دارای قابلیت و کلید معتبر انتخاب می‌شود. تست اتصال، دسترسی واقعی را بررسی می‌کند.', 'Automatic mode selects the first declared-capable model with a stored key. Test the connection to verify actual access.' ) );
		if ( 'embedding' === $task ) {
			$this->row( $this->t( 'ابعاد بردار', 'Vector dimensions' ), $this->input( 'embedding][dimensions', $selection['dimensions'], 'number', [ 'min' => 0, 'max' => 8192 ] ), $this->t( '۰ = مقدار پیش‌فرض مدل شناخته‌شده. برای endpoint سفارشی مقدار واقعی را وارد کنید. تغییر مدل/ابعاد نیازمند بازسازی ایندکس است.', '0 = known model default. Enter the actual dimension for custom endpoints. Changing model/dimensions requires rebuilding the index.' ) );
		}
		echo '</tbody></table>';
	}

	private function language_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'زبان و جهت رابط', 'Interface language & direction' ) ) . '</h2><p>' . esc_html( $this->t( 'زبان رابط مستقل از زبان جستجوی کاربر است؛ فارسی و انگلیسی هر دو پذیرفته می‌شوند.', 'Interface language is independent of search language; Persian and English requests are both accepted.' ) ) . '</p>';
		foreach ( [ 'fa' => $this->t( 'فارسی — راست‌چین', 'Persian — RTL' ), 'en' => $this->t( 'انگلیسی — چپ‌چین', 'English — LTR' ) ] as $value => $label ) {
			echo '<label style="display:block;margin:.7rem 0"><input type="radio" name="' . esc_attr( $this->name( 'language' ) ) . '" value="' . esc_attr( $value ) . '" ' . checked( $s['language'], $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
	}
	private function matching_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'انعطاف‌پذیری تطبیق', 'Matching flexibility' ) ) . '</h2><p>' . esc_html( $this->t( 'انعطاف بیشتر آستانه را پایین می‌آورد، اما ویژگی صریح ناموجود را مطابق فرض نمی‌کند و محصول نامرتبط اضافه نمی‌شود.', 'More flexibility lowers thresholds, but never treats a missing explicit attribute as a match or adds unrelated products.' ) ) . '</p><table class="form-table bas-form-table"><tbody>';
		$labels = [ 'price' => $this->t( 'قیمت', 'Price' ), 'color' => $this->t( 'رنگ', 'Color' ), 'brand' => $this->t( 'برند', 'Brand' ), 'size' => $this->t( 'سایز', 'Size' ), 'material' => $this->t( 'جنس', 'Material' ), 'technical' => $this->t( 'ویژگی فنی', 'Technical features' ), 'other' => $this->t( 'سایر خصوصیات', 'Other attributes' ) ];
		$this->row( $this->t( 'انعطاف عمومی', 'Global flexibility' ), '<input type="range" min="0" max="100" name="' . esc_attr( $this->name( 'global_flex' ) ) . '" value="' . esc_attr( $s['global_flex'] ) . '"> <output data-range-output="' . esc_attr( $this->name( 'global_flex' ) ) . '"></output>' );
		foreach ( $labels as $key => $label ) {
			$name = $this->name( 'attribute_flex][' . $key );
			$this->row( $label, '<input type="range" min="0" max="100" name="' . esc_attr( $name ) . '" value="' . esc_attr( $s['attribute_flex'][ $key ] ) . '"> <output data-range-output="' . esc_attr( $name ) . '"></output>' );
		}
		echo '</tbody></table><p class="bas-muted">' . esc_html( $this->t( 'حداقل شباهت معنایی: 0.76 − 0.26×G؛ حداقل امتیاز کل: 0.70 − 0.15×G؛ حداقل ویژگی صریح: 1 − 0.5×Flex. قیمت با نرخ ۲۰٪ حداکثر ۵٪ بالاتر از سقف را می‌پذیرد.', 'Semantic minimum: 0.76 − 0.26×G; overall minimum: 0.70 − 0.15×G; explicit attribute minimum: 1 − 0.5×Flex. Price flexibility 20% accepts at most 5% above the ceiling.' ) ) . '</p>';
	}
	private function index_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'ایندکس محصولات', 'Product indexing' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		$this->row( $this->t( 'اندازه هر دسته', 'Batch size' ), $this->input( 'batch_size', $s['batch_size'], 'number', [ 'min' => 1, 'max' => 20 ] ), $this->t( 'پردازش در پس‌زمینه با صف و lease انجام می‌شود.', 'Background processing uses a queue and database lease.' ) );
		$this->row( $this->t( 'فیلدهای عمومی مجاز', 'Allowed public custom fields' ), '<textarea name="' . esc_attr( $this->name( 'public_meta_keys' ) ) . '">' . esc_textarea( $s['public_meta_keys'] ) . '</textarea>', $this->t( 'نام فیلدها را با خط جدید جدا کنید. فیلدهای protected/internal و کلیدهای حساس وارد AI نمی‌شوند. برای ACF نیز نام فیلد عمومی را اضافه کنید.', 'One field name per line. Protected/internal and sensitive keys are excluded. Add public ACF field names here too.' ) );
		echo '</tbody></table><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=beta-ai-search-index' ) ) . '">' . esc_html( $this->t( 'مدیریت ساخت و بازسازی ایندکس', 'Manage indexing & rebuild' ) ) . '</a></p>';
	}
	private function cache_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'کش جستجو', 'Search cache' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		ob_start(); $this->checkbox( 'cache_enabled', $s['cache_enabled'], $this->t( 'کش فعال باشد', 'Enable cache' ) ); $this->row( $this->t( 'وضعیت', 'Status' ), ob_get_clean() );
		$this->row( $this->t( 'مدت اعتبار (ثانیه)', 'TTL (seconds)' ), $this->input( 'cache_ttl', $s['cache_ttl'], 'number', [ 'min' => 60, 'max' => 604800 ] ) );
		echo '</tbody></table><button type="button" class="button" data-bas-action="clear_cache">' . esc_html( $this->t( 'پاک‌سازی کش', 'Clear cache' ) ) . '</button><p class="description">' . esc_html( $this->t( 'تغییر محصول یا تنظیمات مؤثر، کش را خودکار نامعتبر می‌کند.', 'Product or effective-setting changes invalidate the cache automatically.' ) ) . '</p>';
	}
	private function texts_tab( array $s ): void {
		$language = $s['language'];
		$labels = [ 'placeholder' => $this->t( 'متن داخل باکس', 'Placeholder' ), 'search' => $this->t( 'دکمه جستجو', 'Search button' ), 'loading' => $this->t( 'پیام جستجو', 'Loading message' ), 'empty' => $this->t( 'نبود نتیجه', 'No results' ), 'clear_filter' => $this->t( 'حذف فیلتر', 'Clear filter' ), 'error' => $this->t( 'خطا', 'Error' ), 'retry' => $this->t( 'تلاش مجدد', 'Retry' ), 'clear_text' => $this->t( 'پاک کردن متن', 'Clear text' ), 'label' => $this->t( 'برچسب دسترس‌پذیری', 'Accessible label' ), 'results' => $this->t( 'شمارش نتایج', 'Result count' ), 'previous' => $this->t( 'صفحه قبل', 'Previous' ), 'next' => $this->t( 'صفحه بعد', 'Next' ) ];
		echo '<h2>' . esc_html( $this->t( 'متن‌های مرکزی ویجت', 'Central widget texts' ) ) . '</h2><p>' . esc_html( $this->t( 'این متن‌ها برای همه ویجت‌ها اعمال می‌شوند. زبان فعلی:', 'These texts apply to every widget. Current language:' ) ) . ' ' . esc_html( $language ) . '</p><table class="form-table bas-form-table"><tbody>';
		foreach ( $labels as $key => $label ) {
			$this->row( $label, '<input type="text" name="' . esc_attr( $this->name( 'texts][' . $language . '][' . $key ) ) . '" value="' . esc_attr( $s['texts'][ $key ] ) . '">' );
		}
		echo '</tbody></table>';
	}
	private function reports_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'گزارش‌ها و حریم خصوصی', 'Reports & privacy' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		ob_start(); $this->checkbox( 'history_enabled', $s['history_enabled'], $this->t( 'ثبت آمار جستجو', 'Record search statistics' ) ); $this->row( $this->t( 'تاریخچه', 'History' ), ob_get_clean() );
		ob_start(); $this->checkbox( 'store_query_text', $s['store_query_text'], $this->t( 'متن جستجو نیز ذخیره شود (رضایت/سیاست حریم خصوصی لازم است)', 'Also store query text (consent/privacy policy required)' ) ); $this->row( $this->t( 'متن درخواست', 'Query text' ), ob_get_clean(), $this->t( 'پیش‌فرض فقط hash نگهداری می‌شود؛ IP و اطلاعات مشتری ذخیره نمی‌شود.', 'By default only a hash is retained; no IP or customer data is stored.' ) );
		$this->row( $this->t( 'نگهداری گزارش‌ها (روز)', 'Log retention (days)' ), $this->input( 'retention_days', $s['retention_days'], 'number', [ 'min' => 1, 'max' => 365 ] ) );
		echo '</tbody></table><button type="button" class="button" data-bas-action="clear_logs">' . esc_html( $this->t( 'پاک‌سازی تاریخچه و گزارش‌ها', 'Clear history & logs' ) ) . '</button>';
	}
	private function diagnostics_tab( array $s ): void {
		echo '<h2>' . esc_html( $this->t( 'عیب‌یابی', 'Diagnostics' ) ) . '</h2><table class="form-table bas-form-table"><tbody>';
		ob_start(); $this->checkbox( 'debug', $s['debug'], $this->t( 'ثبت خطاهای ساختاریافته بدون داده محرمانه', 'Record structured errors without sensitive data' ) ); $this->row( 'Debug', ob_get_clean() );
		echo '</tbody></table><table class="bas-table"><tbody>';
		$rows = [ 'WordPress' => get_bloginfo( 'version' ), 'WooCommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : $this->t( 'غیرفعال', 'inactive' ), 'Elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : $this->t( 'غیرفعال', 'inactive' ), 'Elementor Pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : $this->t( 'شناسایی نشد', 'not detected' ), 'PHP' => PHP_VERSION, 'OpenSSL' => function_exists( 'openssl_encrypt' ) ? 'OK' : 'Missing', 'mbstring' => extension_loaded( 'mbstring' ) ? 'OK' : 'Missing', 'Encryption key constant' => defined( 'BAS_ENCRYPTION_KEY' ) ? 'Configured' : 'Site-salt fallback', 'WP-Cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Disabled (system cron required)' : 'Enabled', 'Index revision' => Reports::revision() ];
		foreach ( $rows as $key => $value ) { echo '<tr><th>' . esc_html( $key ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>'; }
		echo '</tbody></table><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=beta-ai-search-errors' ) ) . '">' . esc_html( $this->t( 'مشاهده خطاها', 'View errors' ) ) . '</a></p>';
	}
	private function integrations_tab(): void {
		echo '<h2>' . esc_html( $this->t( 'اتصال به فروشگاه و گرید', 'Store & grid integration' ) ) . '</h2><table class="bas-table"><tbody>';
		$rows = [ [ 'WooCommerce', function_exists( 'WC' ) ? $this->t( 'متصل', 'connected' ) : $this->t( 'فعال نیست', 'inactive' ) ], [ 'Elementor Free', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : $this->t( 'فعال نیست', 'inactive' ) ], [ 'Elementor Pro', defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : $this->t( 'لازم نیست؛ برای Loop Grid استفاده می‌شود', 'not required; used by Loop Grid' ) ], [ 'Shortcode', '[beta_ai_search]' ], [ $this->t( 'گرید معمولی / Products / Archive Products', 'Default loop / Products / Archive Products' ), $this->t( 'قالب فعلی کارت ووکامرس', 'Current WooCommerce card template' ) ], [ 'Loop Grid', $this->t( 'قالب Loop Item موجود؛ نیازمند تست واقعی Pro', 'Existing Loop Item template; requires real Pro testing' ) ] ];
		foreach ( $rows as $row ) { echo '<tr><th>' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>'; }
		echo '</tbody></table><p>' . esc_html( $this->t( 'ویجت را بالای گرید قرار دهید. برای هدف‌گیری دقیق، selector گرید موجود را در تنظیمات ویجت وارد کنید. گرید جدید ساخته نمی‌شود.', 'Place the widget above the grid. For precise targeting, enter the existing grid selector in widget settings. No replacement grid is created.' ) ) . '</p>';
	}

	public function dashboard(): void {
		$this->header( $this->t( 'جستجوی معنایی محصولات ووکامرس', 'Semantic WooCommerce product search' ) );
		$stats = Reports::dashboard();
		$s = $stats['searches'];
		$cards = [ $this->t( 'کل جستجوها', 'Total searches' ) => $s['total'] ?? 0, $this->t( 'موفق', 'Successful' ) => $s['successful'] ?? 0, $this->t( 'بدون نتیجه', 'No results' ) => $s['no_results'] ?? 0, $this->t( 'خطا', 'Errors' ) => $s['error_count'] ?? 0, $this->t( 'از کش', 'Cache hits' ) => $s['cached'] ?? 0, $this->t( 'میانگین زمان (ms)', 'Average latency (ms)' ) => round( (float) ( $s['latency'] ?? 0 ) ) ];
		echo '<div class="bas-grid">';
		foreach ( $cards as $label => $value ) { echo '<div class="bas-stat"><span class="bas-stat__label">' . esc_html( $label ) . '</span><strong class="bas-stat__value">' . esc_html( (string) $value ) . '</strong></div>'; }
		echo '</div>';
		if ( ! Secrets::configured( 'openai' ) && ! array_filter( array_keys( apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) ), [ Secrets::class, 'configured' ] ) ) {
			echo '<div class="bas-notice">' . esc_html( $this->t( 'برای شروع، کلید یک سرویس دارای Embedding و یک سرویس دارای Query Understanding را در تنظیمات وارد کنید، سپس ایندکس را بسازید.', 'To start, configure an embedding-capable provider and a query-understanding provider, then build the index.' ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=beta-ai-search-settings&tab=providers' ) ) . '">' . esc_html( $this->t( 'تنظیم سرویس‌ها', 'Configure providers' ) ) . '</a></div>';
		}
		$this->index_status_panel();
		echo '<div class="bas-panel"><h2>' . esc_html( $this->t( 'مصرف AI', 'AI usage' ) ) . '</h2>';
		$this->usage_table( $stats['usage'] );
		echo '</div>';
		$this->footer();
	}

	private function index_status_panel(): void {
		$status = $this->indexer->status();
		echo '<section class="bas-panel" data-bas-index-status><h2>' . esc_html( $this->t( 'وضعیت ایندکس', 'Index status' ) ) . '</h2><div class="bas-progress"><div class="bas-progress__bar" style="width:' . esc_attr( (string) $status['percent'] ) . '%"></div></div><div class="bas-index-meta">';
		$fields = [ 'percent' => $this->t( 'پیشرفت', 'Progress' ), 'status' => $this->t( 'وضعیت', 'Status' ), 'processed' => $this->t( 'پردازش‌شده', 'Processed' ), 'total' => $this->t( 'کل محصولات', 'Total products' ), 'remaining' => $this->t( 'باقی‌مانده', 'Remaining' ), 'pending' => $this->t( 'صف', 'Queue' ), 'indexed' => $this->t( 'محصول ایندکس‌شده', 'Indexed products' ), 'entities' => $this->t( 'تنوع/Entity', 'Entities' ), 'failed' => $this->t( 'خطا', 'Failed' ), 'revision' => $this->t( 'نسخه ایندکس', 'Index revision' ) ];
		foreach ( $fields as $key => $label ) {
			$value = in_array( $key, [ 'percent' ], true ) ? $status[ $key ] . '%' : ( in_array( $key, [ 'status', 'processed', 'total', 'failed' ], true ) ? ( $status['job'][ $key ] ?? ( 'status' === $key ? 'idle' : 0 ) ) : ( $status[ $key ] ?? ( $status['indexed_products'] ?? 0 ) ) );
			if ( 'indexed' === $key ) { $value = $status['indexed_products']; }
			if ( 'entities' === $key ) { $value = $status['indexed_entities']; }
			echo '<div><span>' . esc_html( $label ) . '</span><strong data-field="' . esc_attr( $key ) . '">' . esc_html( (string) $value ) . '</strong></div>';
		}
		echo '</div><p>' . esc_html( $this->t( 'مدل فعال:', 'Active model:' ) ) . ' <code data-field="model">' . esc_html( isset( $status['active_model']['model'] ) ? $status['active_model']['provider'] . ' / ' . $status['active_model']['model'] : '—' ) . '</code></p><div class="bas-notice" data-bas-rebuild-warning ' . ( $status['rebuild_required'] ? '' : 'hidden' ) . '>' . esc_html( $this->t( 'مدل Embedding تغییر کرده است؛ برای اعمال آن ایندکس را بازسازی کنید.', 'The embedding model changed; rebuild the index to apply it.' ) ) . '</div><div class="bas-actions">';
		foreach ( [ 'start' => $this->t( 'شروع', 'Start' ), 'pause' => $this->t( 'توقف', 'Pause' ), 'resume' => $this->t( 'ادامه', 'Resume' ), 'rebuild' => $this->t( 'بازسازی کامل', 'Full rebuild' ), 'retry' => $this->t( 'تلاش مجدد خطاها', 'Retry failures' ) ] as $action => $label ) {
			echo '<button type="button" class="button" data-index-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div><p class="bas-muted">' . esc_html( $this->t( 'پردازش با WP-Cron ادامه می‌یابد. در production، cron سیستمی را فعال کنید. بستن این صفحه عملیات را متوقف نمی‌کند.', 'Processing continues through WP-Cron. Enable a system cron in production. Closing this page does not stop the job.' ) ) . '</p></section>';
	}
	public function index_page(): void {
		$this->header( $this->t( 'ساخت، توقف، ادامه و بازسازی ایندکس', 'Build, pause, resume & rebuild the index' ) );
		$this->index_status_panel();
		echo '<div class="bas-panel"><h2>' . esc_html( $this->t( 'رفتار ایندکس', 'Index behavior' ) ) . '</h2><p>' . esc_html( $this->t( 'هر تنوع جداگانه پردازش می‌شود. تغییر محصول، قیمت، موجودی، ویژگی، متا و تنوع به صف می‌رود. بازسازی تا پایان موفق، ایندکس قبلی را برای جستجو نگه می‌دارد.', 'Each variation is processed independently. Product, price, stock, attribute, meta and variation changes are queued. A rebuild keeps the previous index searchable until it completes successfully.' ) ) . '</p></div>';
		$this->footer();
	}
	private function usage_table( array $rows ): void {
		echo '<table class="bas-table"><thead><tr><th>Provider</th><th>Model</th><th>Task</th><th>' . esc_html( $this->t( 'درخواست', 'Requests' ) ) . '</th><th>' . esc_html( $this->t( 'توکن ورودی/خروجی', 'Input/output tokens' ) ) . '</th><th>' . esc_html( $this->t( 'هزینه', 'Cost' ) ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html( $this->t( 'داده‌ای ثبت نشده است.', 'No data recorded.' ) ) . '</td></tr>'; }
		foreach ( $rows as $row ) {
			$cost = null === $row['cost'] ? $this->t( 'نامشخص', 'Unknown' ) : number_format_i18n( (float) $row['cost'], 6 ) . ' ' . $row['currency'];
			if ( (int) $row['unknown_costs'] > 0 && null !== $row['cost'] ) { $cost .= ' + ' . (int) $row['unknown_costs'] . ' ' . $this->t( 'نامشخص', 'unknown' ); }
			echo '<tr><td>' . esc_html( $row['provider'] ) . '</td><td><code>' . esc_html( $row['model'] ) . '</code></td><td>' . esc_html( $row['task'] ) . '</td><td>' . esc_html( $row['requests'] ) . '</td><td>' . esc_html( ( $row['input_tokens'] ?? '—' ) . ' / ' . ( $row['output_tokens'] ?? '—' ) ) . '</td><td>' . esc_html( $cost ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	public function reports_page(): void {
		global $wpdb;
		$this->header( $this->t( 'آمار، تاریخچه و مصرف', 'Statistics, history & usage' ) );
		$stats = Reports::dashboard();
		echo '<div class="bas-panel"><h2>' . esc_html( $this->t( 'عبارات پرتکرار', 'Frequent requests' ) ) . '</h2><table class="bas-table"><thead><tr><th>' . esc_html( $this->t( 'درخواست', 'Request' ) ) . '</th><th>' . esc_html( $this->t( 'تعداد', 'Count' ) ) . '</th><th>' . esc_html( $this->t( 'بدون نتیجه', 'No results' ) ) . '</th></tr></thead><tbody>';
		foreach ( $stats['top'] as $row ) { $label = $row['query_text'] ?: $this->t( 'درخواست خصوصی #', 'Private request #' ) . substr( $row['query_hash'], 0, 8 ); echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $row['hits'] ) . '</td><td>' . esc_html( $row['no_results'] ) . '</td></tr>'; }
		if ( ! $stats['top'] ) { echo '<tr><td colspan="3">' . esc_html( $this->t( 'تاریخچه‌ای وجود ندارد.', 'No history yet.' ) ) . '</td></tr>'; }
		echo '</tbody></table></div><div class="bas-panel"><h2>' . esc_html( $this->t( 'مصرف به تفکیک سرویس و مدل', 'Usage by provider & model' ) ) . '</h2>';
		$this->usage_table( $stats['usage'] );
		echo '</div><div class="bas-panel"><h2>' . esc_html( $this->t( 'تاریخچه جستجو', 'Search history' ) ) . '</h2><table class="bas-table"><thead><tr><th>' . esc_html( $this->t( 'زمان', 'Time' ) ) . '</th><th>' . esc_html( $this->t( 'درخواست', 'Request' ) ) . '</th><th>' . esc_html( $this->t( 'وضعیت', 'Status' ) ) . '</th><th>' . esc_html( $this->t( 'نتیجه', 'Results' ) ) . '</th><th>ms</th><th>Cache</th></tr></thead><tbody>';
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bas_searches ORDER BY id DESC LIMIT 100", ARRAY_A );
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( $row['query_text'] ?: '#' . substr( $row['query_hash'], 0, 10 ) ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['result_count'] ) . '</td><td>' . esc_html( $row['duration_ms'] ) . '</td><td>' . esc_html( $row['from_cache'] ? '✓' : '—' ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html( $this->t( 'تاریخچه‌ای وجود ندارد.', 'No history yet.' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$this->footer();
	}
	public function errors_page(): void {
		global $wpdb;
		$this->header( $this->t( 'گزارش خطاهای ساختاریافته', 'Structured error log' ) );
		echo '<div class="bas-panel"><p>' . esc_html( $this->t( 'کلید API، متن درخواست و پاسخ خام provider در این گزارش ذخیره نمی‌شود.', 'API keys, query text and raw provider responses are not stored in this log.' ) ) . '</p><table class="bas-table"><thead><tr><th>' . esc_html( $this->t( 'زمان', 'Time' ) ) . '</th><th>' . esc_html( $this->t( 'بخش', 'Context' ) ) . '</th><th>Code</th><th>HTTP</th><th>Provider</th><th>Model</th></tr></thead><tbody>';
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bas_errors ORDER BY id DESC LIMIT 100", ARRAY_A );
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( $row['context'] ) . '</td><td><code>' . esc_html( $row['code'] ) . '</code></td><td>' . esc_html( $row['http_status'] ?: '—' ) . '</td><td>' . esc_html( $row['provider'] ?: '—' ) . '</td><td>' . esc_html( $row['model'] ?: '—' ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html( $this->t( 'خطایی ثبت نشده است.', 'No errors recorded.' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$this->footer();
	}

	public function ajax_index_status(): void { $this->ajax_guard(); wp_send_json_success( $this->indexer->status() ); }
	public function ajax_index_start(): void { $this->ajax_guard(); $this->index_action( 'start' ); }
	public function ajax_index_pause(): void { $this->ajax_guard(); $this->index_action( 'pause' ); }
	public function ajax_index_resume(): void { $this->ajax_guard(); $this->index_action( 'resume' ); }
	public function ajax_index_rebuild(): void { $this->ajax_guard(); $this->index_action( 'rebuild' ); }
	public function ajax_index_retry(): void { $this->ajax_guard(); $this->index_action( 'resume' ); }
	private function index_action( string $action ): void {
		try {
			$this->indexer->{$action}();
			wp_send_json_success( [ 'message' => $this->t( 'عملیات ثبت شد و در پس‌زمینه ادامه می‌یابد.', 'Action accepted; processing continues in the background.' ), 'status' => $this->indexer->status() ] );
		} catch ( AIException $e ) {
			wp_send_json_error( [ 'code' => $e->safe_code, 'message' => $this->t( 'ابتدا سرویس و مدل دارای قابلیت را تنظیم کنید.', 'Configure a capable provider and model first.' ) ], 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'code' => 'index_action_failed', 'message' => $this->t( 'عملیات ایندکس انجام نشد.', 'Index action failed.' ) ], 500 );
		}
	}
	public function ajax_clear_cache(): void { $this->ajax_guard(); Reports::clear_cache(); wp_send_json_success( [ 'message' => $this->t( 'کش پاک شد.', 'Cache cleared.' ) ] ); }
	public function ajax_clear_logs(): void {
		$this->ajax_guard(); global $wpdb;
		foreach ( [ 'searches', 'usage', 'errors' ] as $name ) { $wpdb->query( 'DELETE FROM ' . Schema::table( $name ) ); }
		wp_send_json_success( [ 'message' => $this->t( 'گزارش‌ها پاک شدند.', 'Logs cleared.' ) ] );
	}
	public function ajax_test_provider(): void {
		$this->ajax_guard();
		$id = sanitize_key( $_POST['provider'] ?? '' );
		$task = sanitize_key( $_POST['task'] ?? '' );
		if ( ! array_key_exists( $id, apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) ) || ! in_array( $task, [ 'query', 'embedding' ], true ) ) {
			wp_send_json_error( [ 'code' => 'invalid_request' ], 400 );
		}
		try {
			$selection = $this->settings->get( $task );
			$definition = apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() )[ $id ];
			$models = array_values( array_filter( $definition['models'], static fn( $entry ) => ! empty( $entry[ 'embedding' === $task ? 'embedding' : 'chat' ] ) ) );
			$model = $selection['provider'] === $id ? $selection['model'] : ( $models[0]['id'] ?? $selection['model'] );
			if ( 'embedding' === $task ) {
				$dimensions = $selection['provider'] === $id && $selection['dimensions'] > 0 ? (int) $selection['dimensions'] : (int) ( $models[0]['dimensions'] ?? $selection['dimensions'] );
				$config = [ 'provider' => $id, 'model' => $model, 'dimensions' => $dimensions ];
				$this->providers->embed( [ 'Beta AI Search connection test.' ], $config, true );
			} else {
				$result = $this->providers->get( $id )->understand( 'comfortable black walking shoes', [ 'currency' => get_woocommerce_currency(), 'attributes' => [] ], $model );
				if ( ! $result ) { throw new AIException( 'invalid_ai_schema' ); }
				Reports::usage( $id, $result['model'], 'query_test', $result['usage'] );
			}
			wp_send_json_success( [ 'message' => $this->t( 'اتصال واقعی موفق بود:', 'Live connection succeeded:' ) . ' ' . $model ] );
		} catch ( AIException $e ) {
			Reports::error( 'connection_test', $e, $id );
			wp_send_json_error( [ 'code' => $e->safe_code ], 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'code' => 'connection_test_failed' ], 500 );
		}
	}
	public function ajax_list_models(): void {
		$this->ajax_guard();
		$id = sanitize_key( $_POST['provider'] ?? '' );
		if ( ! array_key_exists( $id, apply_filters( 'bas_ai_provider_definitions', ProviderRegistry::definitions() ) ) ) { wp_send_json_error( [ 'code' => 'invalid_provider' ], 400 ); }
		try { wp_send_json_success( [ 'models' => $this->providers->get( $id )->list_models() ] ); }
		catch ( AIException $e ) { Reports::error( 'model_list', $e, $id ); wp_send_json_error( [ 'code' => $e->safe_code ], 400 ); }
		catch ( \Throwable $e ) { wp_send_json_error( [ 'code' => 'model_list_failed' ], 500 ); }
	}
}
