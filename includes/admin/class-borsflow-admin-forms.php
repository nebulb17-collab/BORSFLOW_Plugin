<?php
/**
 * Forms list screen.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the list of forms.
 */
class BorsFlow_Admin_Forms {

	/**
	 * List table instance.
	 *
	 * @var BorsFlow_Forms_List_Table|null
	 */
	private static $table = null;

	/**
	 * load-{hook}: prepare the table before output.
	 */
	public static function load() {
		self::$table = new BorsFlow_Forms_List_Table();
		self::$table->prepare_items();
	}

	/**
	 * Page callback.
	 */
	public static function render() {
		if ( ! self::$table ) {
			self::load();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag.
		$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$messages = array(
			'enabled'  => __( 'Form enabled.', 'borsflow-forms' ),
			'disabled' => __( 'Form disabled.', 'borsflow-forms' ),
			'deleted'  => __( 'Form deleted.', 'borsflow-forms' ),
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Forms', 'borsflow-forms' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=borsflow-builder' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Form', 'borsflow-forms' ); ?></a>
			<hr class="wp-header-end">
			<?php
			if ( isset( $messages[ $notice ] ) ) {
				BorsFlow_Admin::notice( $messages[ $notice ] );
			}
			if ( current_user_can( 'manage_options' ) && ! BorsFlow_Crm_Client::is_configured() ) {
				printf(
					'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Submissions are stored locally but not sent to BorsFlow CRM until you add your API credentials.', 'borsflow-forms' ),
					esc_url( admin_url( 'admin.php?page=borsflow-settings' ) ),
					esc_html__( 'Open settings', 'borsflow-forms' )
				);
			}
			?>
			<form method="get">
				<input type="hidden" name="page" value="borsflow-forms">
				<?php
				self::$table->search_box( __( 'Search forms', 'borsflow-forms' ), 'borsflow-forms' );
				self::$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
