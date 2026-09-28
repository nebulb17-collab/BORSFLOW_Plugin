<?php
/**
 * Sync Log screen: recent CRM attempts across all submissions.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sync Log page controller.
 */
class BorsFlow_Admin_Sync_Log {

	/**
	 * Table.
	 *
	 * @var BorsFlow_Sync_Log_List_Table|null
	 */
	private static $table = null;

	/**
	 * load-{hook}.
	 */
	public static function load() {
		self::$table = new BorsFlow_Sync_Log_List_Table();
		self::$table->prepare_items();
	}

	/**
	 * Page callback.
	 */
	public static function render() {
		if ( ! self::$table ) {
			self::load();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CRM Sync Log', 'borsflow-forms' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: comma separated retry delays. */
					esc_html__( 'Failed syncs are retried automatically with backoff (%s) until the maximum number of attempts is reached. Client errors such as 400/401/422 are not retried automatically; fix the cause and use “Retry sync”.', 'borsflow-forms' ),
					esc_html( implode( ', ', array_map( 'human_time_diff', array_fill( 0, count( BorsFlow_Sync::backoff() ), 0 ), BorsFlow_Sync::backoff() ) ) )
				);
				?>
			</p>
			<?php self::$table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="borsflow-sync-log">
				<?php self::$table->display(); ?>
			</form>
		</div>
		<?php
	}
}
