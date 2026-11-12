<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SearchBox {
	public static function render( Settings $settings, array $attributes = [] ): string {
		wp_enqueue_style( 'bas-frontend' );
		wp_enqueue_script( 'bas-frontend' );
		$language = $settings->get( 'language' );
		$dir = 'fa' === $language ? 'rtl' : 'ltr';
		$template = in_array( $attributes['template'] ?? '', [ 'classic', 'minimal', 'pill' ], true ) ? $attributes['template'] : 'classic';
		$id = wp_unique_id( 'bas-search-' );
		$config = [ 'target' => sanitize_text_field( $attributes['target'] ?? '' ), 'scope' => in_array( $attributes['scope'] ?? '', [ 'first', 'all' ], true ) ? $attributes['scope'] : 'first' ];
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="bas-search bas-template-<?php echo esc_attr( $template ); ?>" dir="<?php echo esc_attr( $dir ); ?>" lang="<?php echo esc_attr( $language ); ?>" data-bas-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<form class="bas-search__form" role="search" aria-label="<?php echo esc_attr( $settings->text( 'label' ) ); ?>" novalidate>
				<label class="bas-search__label screen-reader-text" for="<?php echo esc_attr( $id ); ?>-input"><?php echo esc_html( $settings->text( 'label' ) ); ?></label>
				<div class="bas-search__field">
					<span class="bas-search__leading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><circle cx="10.8" cy="10.8" r="6.8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
					<input id="<?php echo esc_attr( $id ); ?>-input" class="bas-search__input" type="search" maxlength="600" autocomplete="off" enterkeyhint="search" placeholder="<?php echo esc_attr( $settings->text( 'placeholder' ) ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-status" />
					<button type="button" class="bas-search__clear-text" aria-label="<?php echo esc_attr( $settings->text( 'clear_text' ) ); ?>" hidden>&times;</button>
				</div>
				<button type="submit" class="bas-search__submit"><span class="bas-search__spinner" aria-hidden="true" hidden></span><span class="bas-search__submit-label"><?php echo esc_html( $settings->text( 'search' ) ); ?></span></button>
			</form>
			<div class="bas-search__actions">
				<button type="button" class="bas-search__reset" hidden><?php echo esc_html( $settings->text( 'clear_filter' ) ); ?></button>
				<button type="button" class="bas-search__retry" hidden><?php echo esc_html( $settings->text( 'retry' ) ); ?></button>
			</div>
			<p id="<?php echo esc_attr( $id ); ?>-status" class="bas-search__status" role="status" aria-live="polite" aria-atomic="true"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
