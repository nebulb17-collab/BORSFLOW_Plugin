<?php
/**
 * WP_List_Table for submissions.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists submissions with filters, search, sorting and bulk actions.
 */
class BorsFlow_Submissions_List_Table extends WP_List_Table {

	/**
	 * Active filters.
	 *
	 * @var array
	 */
	public $filters = array();

	/**
	 * Form titles keyed by ID.
	 *
	 * @var array<int,string>
	 */
	private $forms = array();

	/**
	 * Data fields of the filtered form, used as columns.
	 *
	 * @var array[]
	 */
	private $field_columns = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
		$this->filters = BorsFlow_Admin_Submissions::filters_from_request();
		$this->forms   = BorsFlow_Form::options();

		if ( $this->filters['form_id'] ) {
			$form = BorsFlow_Form::get( $this->filters['form_id'] );
			if ( $form ) {
				$this->field_columns = array_slice(
					array_values( array_filter( BorsFlow_Form::data_fields( $form ), static fn( $f ) => ! in_array( $f['type'], array( 'hidden', 'file', 'consent' ), true ) ) ),
					0,
					3
				);
			}
		}
	}

	/**
	 * Columns. With a form filter, the first three fields become columns; otherwise a summary.
	 *
	 * @return array
	 */
	public function get_columns() {
		$cols = array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'borsflow-forms' ),
			'form'       => __( 'Form', 'borsflow-forms' ),
		);
		if ( $this->field_columns ) {
			foreach ( $this->field_columns as $f ) {
				$cols[ 'field_' . $f['key'] ] = esc_html( '' !== $f['label'] ? $f['label'] : $f['key'] );
			}
		} else {
			$cols['summary'] = __( 'Summary', 'borsflow-forms' );
		}
		$cols['sync_status'] = __( 'CRM sync', 'borsflow-forms' );
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'created_at';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'mark_read'   => __( 'Mark as read', 'borsflow-forms' ),
			'mark_unread' => __( 'Mark as unread', 'borsflow-forms' ),
			'retry_sync'  => __( 'Retry CRM sync', 'borsflow-forms' ),
			'export_csv'  => __( 'Export selected to CSV', 'borsflow-forms' ),
			'delete'      => __( 'Delete permanently', 'borsflow-forms' ),
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		$per_page              = $this->get_items_per_page( 'borsflow_submissions_per_page', 20 );
		$result                = BorsFlow_Submissions::query(
			array_merge(
				$this->filters,
				array(
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
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
		esc_html_e( 'No submissions found.', 'borsflow-forms' );
	}

	/**
	 * Unread rows are highlighted like unapproved comments.
	 *
	 * @param array $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr class="' . ( $item['is_read'] ? 'borsflow-read' : 'borsflow-unread' ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="bf-sub-%1$d">%2$s</label><input type="checkbox" id="bf-sub-%1$d" name="ids[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %d: submission ID. */
			esc_html( sprintf( __( 'Select submission %d', 'borsflow-forms' ), $item['id'] ) )
		);
	}

	/**
	 * Date column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$view    = BorsFlow_Admin_Submissions::view_url( $item['id'] );
		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'borsflow-forms' ) ),
		);
		if ( 'synced' !== $item['sync_status'] ) {
			$actions['retry'] = sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin_Submissions::row_action_url( $item['id'], 'retry' ) ), esc_html__( 'Retry sync', 'borsflow-forms' ) );
		}
		$actions['toggle_read'] = $item['is_read']
			? sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin_Submissions::row_action_url( $item['id'], 'mark_unread' ) ), esc_html__( 'Mark unread', 'borsflow-forms' ) )
			: sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin_Submissions::row_action_url( $item['id'], 'mark_read' ) ), esc_html__( 'Mark read', 'borsflow-forms' ) );
		$actions['delete']      = sprintf( '<a href="%s" class="submitdelete borsflow-confirm-delete-sub">%s</a>', esc_url( BorsFlow_Admin_Submissions::row_action_url( $item['id'], 'delete' ) ), esc_html__( 'Delete', 'borsflow-forms' ) );

		return sprintf(
			'<a class="row-title" href="%s">%s</a><br><span class="description">#%d</span>%s',
			esc_url( $view ),
			esc_html( BorsFlow_Admin::date( $item['created_at'] ) ),
			(int) $item['id'],
			$this->row_actions( $actions )
		);
	}

	/**
	 * Form column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_form( $item ) {
		$id = (int) $item['form_id'];
		if ( ! isset( $this->forms[ $id ] ) ) {
			/* translators: %d: form ID. */
			return esc_html( sprintf( __( 'Deleted form #%d', 'borsflow-forms' ), $id ) );
		}
		return sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'form_id', $id, BorsFlow_Admin_Submissions::base_url() ) ), esc_html( $this->forms[ $id ] ) );
	}

	/**
	 * Summary of the first few values.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_summary( $item ) {
		$lines = array();
		foreach ( $item['payload'] as $entry ) {
			if ( in_array( $entry['type'] ?? '', array( 'hidden', 'consent' ), true ) ) {
				continue;
			}
			$value = BorsFlow_Mailer::value_to_string( $entry );
			if ( '' === $value ) {
				continue;
			}
			$lines[] = '<strong>' . esc_html( $entry['label'] ) . ':</strong> ' . esc_html( wp_trim_words( $value, 12 ) );
			if ( count( $lines ) >= 3 ) {
				break;
			}
		}
		return implode( '<br>', $lines );
	}

	/**
	 * Sync status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_sync_status( $item ) {
		return BorsFlow_Admin_Submissions::status_html( $item );
	}

	/**
	 * Per-field columns (field_{key}).
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		if ( 0 === strpos( $column_name, 'field_' ) ) {
			$key = substr( $column_name, 6 );
			return isset( $item['payload'][ $key ] ) ? esc_html( wp_trim_words( BorsFlow_Mailer::value_to_string( $item['payload'][ $key ] ), 15 ) ) : '—';
		}
		return '';
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = $this->filters;
		?>
		<div class="alignleft actions borsflow-filters">
			<label class="screen-reader-text" for="borsflow-filter-form"><?php esc_html_e( 'Filter by form', 'borsflow-forms' ); ?></label>
			<select name="form_id" id="borsflow-filter-form">
				<option value=""><?php esc_html_e( 'All forms', 'borsflow-forms' ); ?></option>
				<?php foreach ( $this->forms as $id => $title ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $f['form_id'], $id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="borsflow-filter-status"><?php esc_html_e( 'Filter by sync status', 'borsflow-forms' ); ?></label>
			<select name="status" id="borsflow-filter-status">
				<option value=""><?php esc_html_e( 'Any sync status', 'borsflow-forms' ); ?></option>
				<?php foreach ( BorsFlow_Submissions::status_labels() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="borsflow-filter-from"><?php esc_html_e( 'From', 'borsflow-forms' ); ?></label>
			<input type="date" name="date_from" id="borsflow-filter-from" value="<?php echo esc_attr( $f['date_from'] ); ?>">
			<label for="borsflow-filter-to"><?php esc_html_e( 'To', 'borsflow-forms' ); ?></label>
			<input type="date" name="date_to" id="borsflow-filter-to" value="<?php echo esc_attr( $f['date_to'] ); ?>">
			<?php submit_button( __( 'Filter', 'borsflow-forms' ), '', 'filter_action', false ); ?>
			<a class="button" href="<?php echo esc_url( BorsFlow_Admin_Submissions::export_url( $f ) ); ?>"><?php esc_html_e( 'Export CSV', 'borsflow-forms' ); ?></a>
		</div>
		<?php
	}
}
