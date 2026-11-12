<?php
namespace BetaAI\Search\Frontend;

use BetaAI\Search\Core\Settings;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;

defined( 'ABSPATH' ) || exit;

final class ElementorWidget extends Widget_Base {
	// Elementor instantiates widget types with ($data, $args); keep the native signature.
	public function __construct( $data = [], $args = null ) {
		parent::__construct( $data, $args );
	}
	public function get_name(): string { return 'beta-ai-search'; }
	public function get_title(): string { return __( 'AI Product Search', 'beta-ai-search' ); }
	public function get_icon(): string { return 'eicon-search-bold'; }
	public function get_categories(): array { return [ 'beta-ai-search' ]; }
	public function get_keywords(): array { return [ 'woocommerce', 'ai', 'search', 'جستجو', 'محصول' ]; }
	public function get_style_depends(): array { return [ 'bas-frontend' ]; }
	public function get_script_depends(): array { return [ 'bas-frontend' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => __( 'Search settings', 'beta-ai-search' ), 'tab' => Controls_Manager::TAB_CONTENT ] );
		$this->add_control( 'template', [ 'label' => __( 'Ready-made template', 'beta-ai-search' ), 'type' => Controls_Manager::SELECT, 'default' => 'classic', 'options' => [ 'classic' => __( 'Classic', 'beta-ai-search' ), 'minimal' => __( 'Minimal', 'beta-ai-search' ), 'pill' => __( 'Pill', 'beta-ai-search' ) ] ] );
		$this->add_control( 'target', [ 'label' => __( 'Existing grid selector (optional)', 'beta-ai-search' ), 'type' => Controls_Manager::TEXT, 'default' => '', 'description' => __( 'Leave empty to target the next existing product grid. Never creates a replacement grid.', 'beta-ai-search' ), 'label_block' => true ] );
		$this->add_control( 'scope', [ 'label' => __( 'Matching grids', 'beta-ai-search' ), 'type' => Controls_Manager::SELECT, 'default' => 'first', 'options' => [ 'first' => __( 'First following grid', 'beta-ai-search' ), 'all' => __( 'All matching grids', 'beta-ai-search' ) ] ] );
		$this->add_control( 'texts_notice', [ 'type' => Controls_Manager::RAW_HTML, 'raw' => '<p>' . esc_html__( 'All labels, messages and the UI language are managed centrally in Beta AI Search → Settings.', 'beta-ai-search' ) . '</p>' ] );
		$this->end_controls_section();

		$this->start_controls_section( 'box_style', [ 'label' => __( 'Search box', 'beta-ai-search' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'box_background', [ 'label' => __( 'Background', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search' => 'background-color: {{VALUE}};' ] ] );
		$this->add_control( 'box_color', [ 'label' => __( 'Text color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search' => 'color: {{VALUE}};' ] ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'box_typography', 'selector' => '{{WRAPPER}} .bas-search' ] );
		$this->add_responsive_control( 'box_padding', [ 'label' => __( 'Padding', 'beta-ai-search' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => [ 'px', 'em', 'rem' ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'box_margin', [ 'label' => __( 'Margin', 'beta-ai-search' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => [ 'px', 'em', 'rem' ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'box_radius', [ 'label' => __( 'Corner radius', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em', 'rem' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 80 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'border-radius: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [ 'name' => 'box_shadow', 'selector' => '{{WRAPPER}} .bas-search' ] );
		$this->add_responsive_control( 'box_gap', [ 'label' => __( 'Gap', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'selectors' => [ '{{WRAPPER}} .bas-search__form' => 'gap: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'box_width', [ 'label' => __( 'Width', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ '%', 'px', 'vw' ], 'range' => [ '%' => [ 'min' => 20, 'max' => 100 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'width: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'box_min_height', [ 'label' => __( 'Minimum height', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'range' => [ 'px' => [ 'min' => 40, 'max' => 400 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'min-height: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_control( 'box_border_color', [ 'label' => __( 'Border color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search' => 'border-color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'box_border_width', [ 'label' => __( 'Border width', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 20 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search' => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;' ] ] );
		$this->end_controls_section();

		$this->start_controls_section( 'input_style', [ 'label' => __( 'Input', 'beta-ai-search' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'input_background', [ 'label' => __( 'Background', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'background-color: {{VALUE}};' ] ] );
		$this->add_control( 'input_color', [ 'label' => __( 'Text color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__input' => 'color: {{VALUE}};' ] ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'input_typography', 'selector' => '{{WRAPPER}} .bas-search__input' ] );
		$this->add_responsive_control( 'input_font_size', [ 'label' => __( 'Font size', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'rem' ], 'selectors' => [ '{{WRAPPER}} .bas-search__input' => 'font-size: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'input_height', [ 'label' => __( 'Height', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'range' => [ 'px' => [ 'min' => 32, 'max' => 120 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'min-height: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'input_padding', [ 'label' => __( 'Inner padding', 'beta-ai-search' ), 'type' => Controls_Manager::DIMENSIONS, 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'input_radius', [ 'label' => __( 'Corner radius', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'border-radius: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_control( 'input_border_color', [ 'label' => __( 'Border color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'border-color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'input_border_width', [ 'label' => __( 'Border width', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px' ], 'selectors' => [ '{{WRAPPER}} .bas-search__field' => 'border-width: {{SIZE}}{{UNIT}};' ] ] );
		$this->end_controls_section();

		$this->start_controls_section( 'button_style', [ 'label' => __( 'Search button', 'beta-ai-search' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'button_color', [ 'label' => __( 'Text color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'color: {{VALUE}};' ] ] );
		$this->add_control( 'button_background', [ 'label' => __( 'Button color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'background-color: {{VALUE}};' ] ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'button_typography', 'selector' => '{{WRAPPER}} .bas-search__submit' ] );
		$this->add_responsive_control( 'button_font_size', [ 'label' => __( 'Font size', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'rem' ], 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'font-size: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'button_height', [ 'label' => __( 'Height', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'min-height: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'button_width', [ 'label' => __( 'Width', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', '%', 'em' ], 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'width: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'button_padding', [ 'label' => __( 'Padding', 'beta-ai-search' ), 'type' => Controls_Manager::DIMENSIONS, 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'button_radius', [ 'label' => __( 'Corner radius', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em' ], 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'border-radius: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_control( 'button_border_color', [ 'label' => __( 'Border color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'border-color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'button_border_width', [ 'label' => __( 'Border width', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 20 ] ], 'selectors' => [ '{{WRAPPER}} .bas-search__submit' => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;' ] ] );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [ 'name' => 'button_shadow', 'selector' => '{{WRAPPER}} .bas-search__submit' ] );
		$this->end_controls_section();

		$this->start_controls_section( 'icon_style', [ 'label' => __( 'Icons & messages', 'beta-ai-search' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'icon_color', [ 'label' => __( 'Icon color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__leading-icon, {{WRAPPER}} .bas-search__clear-text' => 'color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'icon_size', [ 'label' => __( 'Icon size', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px' ], 'selectors' => [ '{{WRAPPER}} .bas-search__leading-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};', '{{WRAPPER}} .bas-search__clear-text' => 'font-size: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_control( 'status_color', [ 'label' => __( 'Message color', 'beta-ai-search' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .bas-search__status' => 'color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'status_size', [ 'label' => __( 'Message font size', 'beta-ai-search' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'rem' ], 'selectors' => [ '{{WRAPPER}} .bas-search__status' => 'font-size: {{SIZE}}{{UNIT}};' ] ] );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		echo SearchBox::render( \BetaAI\Search\Core\Plugin::instance()->settings, [ 'template' => $settings['template'] ?? 'classic', 'target' => $settings['target'] ?? '', 'scope' => $settings['scope'] ?? 'first' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SearchBox escapes every attribute/text.
	}
}
