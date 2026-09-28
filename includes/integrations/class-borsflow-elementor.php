<?php
/**
 * Elementor integration: registers the "BorsFlow Form" widget when Elementor is active.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into Elementor without requiring it.
 */
class BorsFlow_Elementor {

	/**
	 * Hook in. Safe to call when Elementor is not installed.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'register_editor_hook' ) );
	}

	/**
	 * Add a "BorsFlow" panel category.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 */
	public static function register_category( $manager ) {
		$manager->add_category(
			'borsflow',
			array(
				'title' => __( 'BorsFlow', 'borsflow-forms' ),
				'icon'  => 'eicon-form-horizontal',
			)
		);
	}

	/**
	 * Register the widget (Elementor 3.5+).
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 */
	public static function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$widgets_manager->register( new BorsFlow_Elementor_Widget() );
	}

	/**
	 * Initialise forms that Elementor injects after page load (editor preview, popups).
	 */
	public static function register_editor_hook() {
		wp_add_inline_script(
			'borsflow-forms',
			"window.addEventListener('elementor/frontend/init',function(){if(window.elementorFrontend&&window.BorsFlowForms){elementorFrontend.hooks.addAction('frontend/element_ready/borsflow_form.default',function(\$scope){var el=\$scope[0]||\$scope;el.querySelectorAll('form.bf-form').forEach(window.BorsFlowForms.init);});}});"
		);
	}
}
