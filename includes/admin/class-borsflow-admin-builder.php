<?php
/**
 * Form builder screen (jQuery UI sortable, no build step).
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the builder shell; assets/js/builder.js does the rest.
 */
class BorsFlow_Admin_Builder {

	/**
	 * Form ID from the query string.
	 *
	 * @return int
	 */
	private static function form_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		return isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	}

	/**
	 * Enqueue builder assets.
	 */
	public static function assets() {
		$form = BorsFlow_Form::get( self::form_id() );
		if ( ! $form ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'borsflow-builder', BORSFLOW_URL . 'assets/css/builder.css', array( 'borsflow-admin' ), BORSFLOW_VERSION );
		wp_enqueue_script(
			'borsflow-builder',
			BORSFLOW_URL . 'assets/js/builder.js',
			array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-draggable', 'wp-color-picker', 'wp-api-fetch', 'wp-i18n' ),
			BORSFLOW_VERSION,
			true
		);
		wp_set_script_translations( 'borsflow-builder', 'borsflow-forms', BORSFLOW_DIR . 'languages' );

		$blanks = array();
		foreach ( array_keys( BorsFlow_Fields::types() ) as $type ) {
			$blanks[ $type ] = BorsFlow_Fields::blank( $type );
		}

		// Inline JSON (not wp_localize_script) so booleans and numbers keep their types.
		$data = array(
			'form'        => BorsFlow_Rest::builder_payload( $form ),
			'types'       => BorsFlow_Fields::types(),
			'blanks'      => $blanks,
			'crmFields'   => BorsFlow_Form::crm_fields(),
			'crmReady'    => BorsFlow_Crm_Client::is_configured(),
			'captcha'     => array(
				'recaptcha' => '' !== BorsFlow_Settings::get( 'recaptcha_site_key' ) && '' !== BorsFlow_Settings::get( 'recaptcha_secret' ),
				'turnstile' => '' !== BorsFlow_Settings::get( 'turnstile_site_key' ) && '' !== BorsFlow_Settings::get( 'turnstile_secret' ),
			),
			'reserved'    => BorsFlow_Form::reserved_keys(),
			'routes'      => array(
				'save'    => '/borsflow/v1/admin/forms/' . $form['id'],
				'preview' => '/borsflow/v1/admin/preview',
			),
			'previewCss'  => BORSFLOW_URL . 'assets/css/frontend.css?ver=' . BORSFLOW_VERSION,
			'previewJs'   => BORSFLOW_URL . 'assets/js/frontend.js?ver=' . BORSFLOW_VERSION,
			'messages'    => BorsFlow_Fields::messages(),
			'settingsUrl' => admin_url( 'admin.php?page=borsflow-settings' ),
		);
		wp_add_inline_script( 'borsflow-builder', 'window.borsflowBuilder = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Page callback.
	 */
	public static function render() {
		$id = self::form_id();
		if ( ! $id ) {
			self::render_create();
			return;
		}
		$form = BorsFlow_Form::get( $id );
		if ( ! $form ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Form not found', 'borsflow-forms' ) . '</h1></div>';
			return;
		}
		?>
		<div class="wrap borsflow-builder-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Edit form', 'borsflow-forms' ); ?></h1>
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags.
			if ( isset( $_GET['created'] ) ) {
				BorsFlow_Admin::notice( __( 'Form created. Drag fields from the left, then save.', 'borsflow-forms' ) );
			}
			if ( isset( $_GET['duplicated'] ) ) {
				BorsFlow_Admin::notice( __( 'Form duplicated. The copy is disabled until you enable it.', 'borsflow-forms' ), 'info' );
			}
			// phpcs:enable
			?>
			<div id="borsflow-builder" class="borsflow-builder" data-form-id="<?php echo esc_attr( $id ); ?>">
				<p class="borsflow-loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading builder…', 'borsflow-forms' ); ?></p>
				<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The form builder requires JavaScript.', 'borsflow-forms' ); ?></p></div></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * "Add New" screen: name the form, then create it with a nonce'd POST.
	 */
	private static function render_create() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add New Form', 'borsflow-forms' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="borsflow-create-form">
				<input type="hidden" name="action" value="borsflow_create_form">
				<?php wp_nonce_field( 'borsflow_create_form' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="borsflow-new-title"><?php esc_html_e( 'Form name', 'borsflow-forms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="borsflow-new-title" name="title" required placeholder="<?php esc_attr_e( 'Contact us', 'borsflow-forms' ); ?>">
							<p class="description"><?php esc_html_e( 'The form starts with Name, Email and Message fields that you can change.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create form', 'borsflow-forms' ) ); ?>
			</form>
		</div>
		<?php
	}
}
