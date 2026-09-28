<?php
/**
 * WP_List_Table for forms.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists `borsflow_form` posts.
 */
class BorsFlow_Forms_List_Table extends WP_List_Table {

	/**
	 * Submission counts keyed by form ID.
	 *
	 * @var array<int,int>
	 */
	private $counts = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'form',
				'plural'   => 'forms',
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
			'title'       => __( 'Title', 'borsflow-forms' ),
			'shortcode'   => __( 'Shortcode', 'borsflow-forms' ),
			'status'      => __( 'Status', 'borsflow-forms' ),
			'submissions' => __( 'Submissions', 'borsflow-forms' ),
			'date'        => __( 'Last modified', 'borsflow-forms' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'modified', true ),
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'title', 'modified' ), true ) ? sanitize_key( $_GET['orderby'] ) : 'modified';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$query = new WP_Query(
			array(
				'post_type'      => BorsFlow_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $per_page,
				'paged'          => $this->get_pagenum(),
				'orderby'        => $orderby,
				'order'          => $order,
				's'              => $search,
			)
		);

		$this->items           = $query->posts;
		$this->counts          = BorsFlow_Submissions::counts_by_form();
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		printf(
			'%s <a href="%s">%s</a>',
			esc_html__( 'No forms yet.', 'borsflow-forms' ),
			esc_url( admin_url( 'admin.php?page=borsflow-builder' ) ),
			esc_html__( 'Create your first form', 'borsflow-forms' )
		);
	}

	/**
	 * Title column with row actions.
	 *
	 * @param WP_Post $post Form post.
	 * @return string
	 */
	protected function column_title( $post ) {
		$edit    = admin_url( 'admin.php?page=borsflow-builder&form_id=' . $post->ID );
		$enabled = 'publish' === $post->post_status;
		$title   = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'borsflow-forms' );

		$actions = array(
			'edit'        => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'borsflow-forms' ) ),
			'submissions' => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=borsflow-submissions&form_id=' . $post->ID ) ), esc_html__( 'Submissions', 'borsflow-forms' ) ),
			'duplicate'   => sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin::form_action_url( $post->ID, 'duplicate' ) ), esc_html__( 'Duplicate', 'borsflow-forms' ) ),
			'toggle'      => $enabled
				? sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin::form_action_url( $post->ID, 'disable' ) ), esc_html__( 'Disable', 'borsflow-forms' ) )
				: sprintf( '<a href="%s">%s</a>', esc_url( BorsFlow_Admin::form_action_url( $post->ID, 'enable' ) ), esc_html__( 'Enable', 'borsflow-forms' ) ),
			'delete'      => sprintf( '<a href="%s" class="submitdelete borsflow-confirm-delete-form">%s</a>', esc_url( BorsFlow_Admin::form_action_url( $post->ID, 'delete' ) ), esc_html__( 'Delete', 'borsflow-forms' ) ),
		);

		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $title ) ) . $this->row_actions( $actions );
	}

	/**
	 * Shortcode column.
	 *
	 * @param WP_Post $post Form post.
	 * @return string
	 */
	protected function column_shortcode( $post ) {
		$code = sprintf( '[borsflow_form id="%d"]', $post->ID );
		return sprintf( '<input type="text" class="borsflow-shortcode code" readonly value="%s" aria-label="%s">', esc_attr( $code ), esc_attr__( 'Shortcode', 'borsflow-forms' ) );
	}

	/**
	 * Status column.
	 *
	 * @param WP_Post $post Form post.
	 * @return string
	 */
	protected function column_status( $post ) {
		return 'publish' === $post->post_status
			? '<span class="borsflow-pill borsflow-pill--synced">' . esc_html__( 'Enabled', 'borsflow-forms' ) . '</span>'
			: '<span class="borsflow-pill">' . esc_html__( 'Disabled', 'borsflow-forms' ) . '</span>';
	}

	/**
	 * Submissions count column.
	 *
	 * @param WP_Post $post Form post.
	 * @return string
	 */
	protected function column_submissions( $post ) {
		$count = $this->counts[ $post->ID ] ?? 0;
		return sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=borsflow-submissions&form_id=' . $post->ID ) ), esc_html( number_format_i18n( $count ) ) );
	}

	/**
	 * Date column.
	 *
	 * @param WP_Post $post Form post.
	 * @return string
	 */
	protected function column_date( $post ) {
		return esc_html( BorsFlow_Admin::date( $post->post_modified_gmt ) );
	}
}
