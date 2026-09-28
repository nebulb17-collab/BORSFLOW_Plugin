<?php
/**
 * Gutenberg block `borsflow/form` (no build step; editor script is plain JS).
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block and passes the form list to the editor.
 */
class BorsFlow_Block {

	/**
	 * Register the block type.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'borsflow-block-editor',
			BORSFLOW_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			BORSFLOW_VERSION,
			true
		);
		wp_set_script_translations( 'borsflow-block-editor', 'borsflow-forms', BORSFLOW_DIR . 'languages' );

		register_block_type(
			'borsflow/form',
			array(
				'api_version'     => 2,
				'title'           => __( 'BorsFlow Form', 'borsflow-forms' ),
				'category'        => 'widgets',
				'icon'            => 'feedback',
				'editor_script'   => 'borsflow-block-editor',
				'style'           => 'borsflow-forms',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
	}

	/**
	 * Provide the form list to the editor script.
	 */
	public static function editor_data() {
		$forms = array();
		foreach ( BorsFlow_Form::options() as $id => $title ) {
			$forms[] = array(
				'id'    => $id,
				'title' => $title,
			);
		}
		wp_add_inline_script(
			'borsflow-block-editor',
			'window.borsflowBlock = ' . wp_json_encode(
				array(
					'forms'      => $forms,
					'newFormUrl' => admin_url( 'admin.php?page=borsflow-builder' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Server-side render.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$html = BorsFlow_Renderer::render_by_id( absint( $attributes['formId'] ?? 0 ) );
		if ( '' === $html ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-borsflow-form"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}
