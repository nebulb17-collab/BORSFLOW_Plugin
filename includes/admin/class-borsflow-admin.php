<?php
/**
 * Admin bootstrap: menus, assets, form lifecycle actions.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin entry point.
 */
class BorsFlow_Admin {

	/**
	 * Page hook suffixes.
	 *
	 * @var array<string,string>
	 */
	private static $hooks = array();

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( 'BorsFlow_Admin_Settings', 'register' ) );

		add_action( 'admin_post_borsflow_create_form', array( __CLASS__, 'create_form' ) );
		add_action( 'admin_post_borsflow_form_action', array( __CLASS__, 'form_action' ) );
		add_action( 'admin_post_borsflow_export', array( 'BorsFlow_Admin_Submissions', 'export' ) );

		// Must be registered before set_screen_options() runs, i.e. before the page's load hook.
		add_filter( 'set_screen_option_borsflow_submissions_per_page', static fn( $status, $option, $value ) => max( 1, min( 200, absint( $value ) ) ), 10, 3 );

		add_filter( 'plugin_action_links_' . plugin_basename( BORSFLOW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register menus.
	 */
	public static function menu() {
		$cap    = BorsFlow_Plugin::can_manage() && ! current_user_can( BorsFlow_Plugin::CAP ) ? 'manage_options' : BorsFlow_Plugin::CAP;
		$unread = BorsFlow_Plugin::can_manage() ? BorsFlow_Submissions::unread_count() : 0;
		$badge  = $unread ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $unread, number_format_i18n( $unread ) ) : '';

		self::$hooks['forms'] = add_menu_page(
			__( 'BorsFlow Forms', 'borsflow-forms' ),
			__( 'BorsFlow Forms', 'borsflow-forms' ) . $badge,
			$cap,
			'borsflow-forms',
			array( 'BorsFlow_Admin_Forms', 'render' ),
			'dashicons-feedback',
			26
		);
		add_submenu_page( 'borsflow-forms', __( 'Forms', 'borsflow-forms' ), __( 'Forms', 'borsflow-forms' ), $cap, 'borsflow-forms', array( 'BorsFlow_Admin_Forms', 'render' ) );
		self::$hooks['builder']     = add_submenu_page( 'borsflow-forms', __( 'Form Builder', 'borsflow-forms' ), __( 'Add New', 'borsflow-forms' ), $cap, 'borsflow-builder', array( 'BorsFlow_Admin_Builder', 'render' ) );
		self::$hooks['submissions'] = add_submenu_page( 'borsflow-forms', __( 'Submissions', 'borsflow-forms' ), __( 'Submissions', 'borsflow-forms' ) . $badge, $cap, 'borsflow-submissions', array( 'BorsFlow_Admin_Submissions', 'render' ) );
		self::$hooks['log']         = add_submenu_page( 'borsflow-forms', __( 'Sync Log', 'borsflow-forms' ), __( 'Sync Log', 'borsflow-forms' ), $cap, 'borsflow-sync-log', array( 'BorsFlow_Admin_Sync_Log', 'render' ) );
		self::$hooks['settings']    = add_submenu_page( 'borsflow-forms', __( 'BorsFlow Settings', 'borsflow-forms' ), __( 'Settings', 'borsflow-forms' ), 'manage_options', 'borsflow-settings', array( 'BorsFlow_Admin_Settings', 'render' ) );

		add_action( 'load-' . self::$hooks['submissions'], array( 'BorsFlow_Admin_Submissions', 'load' ) );
		add_action( 'load-' . self::$hooks['log'], array( 'BorsFlow_Admin_Sync_Log', 'load' ) );
		add_action( 'load-' . self::$hooks['forms'], array( 'BorsFlow_Admin_Forms', 'load' ) );
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current page hook.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'borsflow-admin', BORSFLOW_URL . 'assets/css/admin.css', array(), BORSFLOW_VERSION );
		wp_enqueue_script( 'borsflow-admin', BORSFLOW_URL . 'assets/js/admin.js', array( 'jquery', 'wp-api-fetch' ), BORSFLOW_VERSION, true );
		wp_localize_script(
			'borsflow-admin',
			'borsflowAdmin',
			array(
				'i18n' => array(
					'confirmDelete'     => __( 'Delete this form permanently? Its submissions are kept.', 'borsflow-forms' ),
					'confirmDeleteSubs' => __( 'Delete the selected submissions permanently? Uploaded files are deleted too.', 'borsflow-forms' ),
					'confirmDeleteSub'  => __( 'Delete this submission permanently?', 'borsflow-forms' ),
					'testing'           => __( 'Testing…', 'borsflow-forms' ),
					'requestFailed'     => __( 'Request failed.', 'borsflow-forms' ),
				),
			)
		);

		if ( $hook === self::$hooks['builder'] ) {
			BorsFlow_Admin_Builder::assets();
		}
	}

	/**
	 * "Settings" and "Forms" links on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=borsflow-settings' ) ) . '">' . esc_html__( 'Settings', 'borsflow-forms' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=borsflow-forms' ) ) . '">' . esc_html__( 'Forms', 'borsflow-forms' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Abort unless the user can manage forms and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	public static function guard( $action ) {
		if ( ! BorsFlow_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'borsflow-forms' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * admin-post: create a form and open it in the builder.
	 */
	public static function create_form() {
		self::guard( 'borsflow_create_form' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard() above.
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$id    = BorsFlow_Form::create( $title );
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=borsflow-builder&form_id=' . $id . '&created=1' ) );
		exit;
	}

	/**
	 * admin-post: duplicate / enable / disable / delete a form.
	 */
	public static function form_action() {
		// The nonce action embeds the form ID and operation, so they are read first and verified by guard().
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id        = isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : 0;
		$operation = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		// phpcs:enable
		self::guard( 'borsflow_form_' . $operation . '_' . $id );

		if ( ! BorsFlow_Form::get( $id ) ) {
			wp_die( esc_html__( 'Form not found.', 'borsflow-forms' ), 404 );
		}

		$notice = '';
		switch ( $operation ) {
			case 'duplicate':
				$new = BorsFlow_Form::duplicate( $id );
				if ( ! is_wp_error( $new ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=borsflow-builder&form_id=' . $new . '&duplicated=1' ) );
					exit;
				}
				break;
			case 'enable':
			case 'disable':
				BorsFlow_Form::set_enabled( $id, 'enable' === $operation );
				$notice = $operation . 'd';
				break;
			case 'delete':
				BorsFlow_Form::delete( $id );
				$notice = 'deleted';
				break;
		}
		wp_safe_redirect( add_query_arg( 'notice', $notice, admin_url( 'admin.php?page=borsflow-forms' ) ) );
		exit;
	}

	/**
	 * Nonce'd admin-post URL for a form action.
	 *
	 * @param int    $id Form ID.
	 * @param string $operation duplicate|enable|disable|delete.
	 * @return string
	 */
	public static function form_action_url( $id, $operation ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=borsflow_form_action&do=' . $operation . '&form_id=' . (int) $id ),
			'borsflow_form_' . $operation . '_' . (int) $id
		);
	}

	/**
	 * Print a dismissible notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning|info.
	 */
	public static function notice( $message, $type = 'success' ) {
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * Format a stored UTC datetime in the site's timezone.
	 *
	 * @param string|null $gmt UTC datetime.
	 * @return string
	 */
	public static function date( $gmt ) {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return '—';
		}
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
