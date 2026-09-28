<?php
/**
 * Sync Log screen: recent CRM attempts across all submissions.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Sync log list table.
 */
class BorsFlow_Sync_Log_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sync_attempt',
				'plural'   => 'sync_attempts',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at'    => __( 'When', 'borsflow-forms' ),
			'submission_id' => __( 'Submission', 'borsflow-forms' ),
			'attempt'       => __( 'Attempt', 'borsflow-forms' ),
			'trigger'       => __( 'Trigger', 'borsflow-forms' ),
			'status_code'   => __( 'HTTP status', 'borsflow-forms' ),
			'result'        => __( 'Result', 'borsflow-forms' ),
			'duration_ms'   => __( 'Duration', 'borsflow-forms' ),
		);
	}

	/**
	 * Views: all / successful / errors.
	 *
	 * @return array
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current = isset( $_GET['outcome'] ) ? sanitize_key( $_GET['outcome'] ) : '';
		$base    = admin_url( 'admin.php?page=borsflow-sync-log' );
		$views   = array();
		foreach ( array(
			''        => __( 'All', 'borsflow-forms' ),
			'success' => __( 'Successful', 'borsflow-forms' ),
			'error'   => __( 'Errors', 'borsflow-forms' ),
		) as $key => $label ) {
			$views[ $key ?: 'all' ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $key ? add_query_arg( 'outcome', $key, $base ) : $base ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		return $views;
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$outcome  = isset( $_GET['outcome'] ) ? sanitize_key( $_GET['outcome'] ) : '';
		$per_page = 50;
		$result   = BorsFlow_Submissions::recent_logs( $per_page, $this->get_pagenum(), $outcome );

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No sync attempts yet.', 'borsflow-forms' );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( BorsFlow_Admin::date( $item['created_at'] ) );
			case 'submission_id':
				return sprintf( '<a href="%s">#%d</a>', esc_url( BorsFlow_Admin_Submissions::view_url( $item['submission_id'] ) ), (int) $item['submission_id'] );
			case 'attempt':
				return esc_html( $item['attempt'] );
			case 'trigger':
				return esc_html( $item['trigger_source'] );
			case 'status_code':
				return $item['status_code'] ? esc_html( $item['status_code'] ) : '—';
			case 'result':
				return sprintf(
					'<span class="borsflow-pill borsflow-pill--%s">%s</span> %s',
					$item['success'] ? 'synced' : 'failed',
					$item['success'] ? esc_html__( 'OK', 'borsflow-forms' ) : esc_html__( 'Error', 'borsflow-forms' ),
					esc_html( $item['message'] )
				);
			case 'duration_ms':
				return esc_html( $item['duration_ms'] . ' ms' );
		}
		return '';
	}
}

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
