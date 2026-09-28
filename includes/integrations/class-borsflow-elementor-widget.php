<?php
/**
 * Elementor widget that embeds a BorsFlow form.
 *
 * Loaded only by BorsFlow_Elementor::register_widget(), i.e. after Elementor is loaded.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * "BorsFlow Form" Elementor widget.
 */
class BorsFlow_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'borsflow_form';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'BorsFlow Form', 'borsflow-forms' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'borsflow', 'general' );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'form', 'contact', 'borsflow', 'crm', 'lead' );
	}

	/**
	 * Front-end scripts this widget needs.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'borsflow-forms' );
	}

	/**
	 * Front-end styles this widget needs.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'borsflow-forms' );
	}

	/**
	 * Controls: a form picker plus a link to the builder.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_form',
			array(
				'label' => __( 'Form', 'borsflow-forms' ),
			)
		);

		$options = array( '' => __( '— Select a form —', 'borsflow-forms' ) );
		foreach ( BorsFlow_Form::options() as $id => $title ) {
			$options[ (string) $id ] = $title;
		}

		$this->add_control(
			'form_id',
			array(
				'label'   => __( 'Form', 'borsflow-forms' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => '',
			)
		);

		$this->add_control(
			'builder_link',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( admin_url( 'admin.php?page=borsflow-forms' ) ),
					esc_html__( 'Manage forms in BorsFlow Forms →', 'borsflow-forms' )
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render on the front end and in the editor preview.
	 */
	protected function render() {
		$id = absint( $this->get_settings_for_display( 'form_id' ) );
		if ( ! $id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p class="borsflow-missing">' . esc_html__( 'Choose a BorsFlow form in the widget settings.', 'borsflow-forms' ) . '</p>';
			}
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes its output.
		echo BorsFlow_Renderer::render_by_id( $id );
	}
}
