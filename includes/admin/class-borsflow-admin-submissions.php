<?php
/**
 * Submissions screens: list, detail, bulk/row actions and CSV export.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Submissions admin controller.
 */
class BorsFlow_Admin_Submissions {

	/**
	 * List table instance.
	 *
	 * @var BorsFlow_Submissions_List_Table|null
	 */
	private static $table = null;

	/**
	 * Base URL of the screen.
	 *
	 * @return string
	 */
	public static function base_url() {
		return admin_url( 'admin.php?page=borsflow-submissions' );
	}

	/**
	 * Detail URL.
	 *
	 * @param int $id Submission ID.
	 * @return string
	 */
	public static function view_url( $id ) {
		return add_query_arg(
			array(
				'view' => (int) $id,
			),
			self::base_url()
		);
	}

	/**
	 * Nonce'd URL for a single-row action.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $action retry|delete|mark_read|mark_unread.
	 * @return string
	 */
	public static function row_action_url( $id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'row_action' => $action,
					'id'         => (int) $id,
				),
				self::base_url()
			),
			'borsflow_sub_' . $action . '_' . (int) $id
		);
	}

	/**
	 * Nonce'd CSV export URL for the given filters.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	public static function export_url( $filters ) {
		$args = array_filter(
			array(
				'action'    => 'borsflow_export',
				'form_id'   => $filters['form_id'] ?: '',
				'status'    => $filters['status'],
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
				's'         => $filters['search'],
			),
			'strlen'
		);
		return wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) ), 'borsflow_export' );
	}

	/**
	 * Parse list filters from the query string.
	 *
	 * @return array
	 */
	public static function filters_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$get = wp_unslash( $_GET );
		// phpcs:enable
		return array(
			'form_id'   => absint( $get['form_id'] ?? 0 ),
			'status'    => in_array( $get['status'] ?? '', BorsFlow_Submissions::STATUSES, true ) ? $get['status'] : '',
			'date_from' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $get['date_from'] ?? '' ) ) ? $get['date_from'] : '',
			'date_to'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $get['date_to'] ?? '' ) ) ? $get['date_to'] : '',
			'search'    => sanitize_text_field( (string) ( $get['s'] ?? '' ) ),
			'orderby'   => 'created_at',
			'order'     => 'asc' === strtolower( (string) ( $get['order'] ?? '' ) ) ? 'ASC' : 'DESC',
		);
	}

	/**
	 * load-{hook}: process actions before any output (so we can redirect or stream CSV).
	 */
	public static function load() {
		if ( ! BorsFlow_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'borsflow-forms' ), 403 );
		}

		// Single-row actions from links and the detail view.
		// phpcs:disable WordPress.Security.NonceVerification -- verified per action below.
		if ( isset( $_REQUEST['row_action'], $_REQUEST['id'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['row_action'] ) );
			$id     = absint( $_REQUEST['id'] );
			check_admin_referer( 'borsflow_sub_' . $action . '_' . $id );
			self::handle_row_action( $action, $id );
		}
		// phpcs:enable

		if ( isset( $_GET['view'] ) ) {
			$id = absint( $_GET['view'] );
			BorsFlow_Submissions::mark_read( array( $id ), true );
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'borsflow_submissions_per_page',
			)
		);

		self::$table = new BorsFlow_Submissions_List_Table();
		$action      = self::$table->current_action();
		if ( $action ) {
			check_admin_referer( 'bulk-submissions' );
			$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
			self::handle_bulk_action( $action, $ids );
		}
		self::$table->prepare_items();
	}

	/**
	 * Execute a single-row action and redirect.
	 *
	 * @param string $action Action.
	 * @param int    $id     Submission ID.
	 */
	private static function handle_row_action( $action, $id ) {
		$back = wp_get_referer() ?: self::base_url();
		$back = remove_query_arg( array( 'row_action', 'id', '_wpnonce', 'notice', 'count', 'bfmsg' ), $back );

		switch ( $action ) {
			case 'retry':
				$result = BorsFlow_Sync::run( $id, 'manual' );
				set_transient( 'borsflow_notice_' . get_current_user_id(), array( $result['ok'] ? 'success' : 'error', $result['message'] ), 60 );
				wp_safe_redirect( add_query_arg( 'bfmsg', 1, $back ) );
				exit;
			case 'delete':
				BorsFlow_Submissions::delete( array( $id ) );
				wp_safe_redirect(
					add_query_arg(
						array(
							'notice' => 'deleted',
							'count'  => 1,
						),
						self::base_url()
					)
				);
				exit;
			case 'mark_read':
			case 'mark_unread':
				BorsFlow_Submissions::mark_read( array( $id ), 'mark_read' === $action );
				// Returning to the detail view would mark it read again.
				wp_safe_redirect(
					add_query_arg(
						array(
							'notice' => $action,
							'count'  => 1,
						),
						remove_query_arg( 'view', $back )
					)
				);
				exit;
		}
	}

	/**
	 * Execute a bulk action and redirect.
	 *
	 * @param string $action Action.
	 * @param int[]  $ids    IDs.
	 */
	private static function handle_bulk_action( $action, $ids ) {
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}
		switch ( $action ) {
			case 'delete':
				$count = BorsFlow_Submissions::delete( $ids );
				break;
			case 'mark_read':
			case 'mark_unread':
				BorsFlow_Submissions::mark_read( $ids, 'mark_read' === $action );
				$count = count( $ids );
				break;
			case 'retry_sync':
				foreach ( $ids as $id ) {
					BorsFlow_Sync::queue_retry( $id );
				}
				$count = count( $ids );
				break;
			case 'export_csv':
				self::stream_csv( array( 'ids' => $ids ) );
				exit;
			default:
				return;
		}
		$back = remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer', 'notice', 'count', 'bfmsg' ), wp_get_referer() ?: self::base_url() );
		wp_safe_redirect(
			add_query_arg(
				array(
					'notice' => $action,
					'count'  => $count,
				),
				$back
			)
		);
		exit;
	}

	/**
	 * admin-post: export the current filtered view.
	 */
	public static function export() {
		BorsFlow_Admin::guard( 'borsflow_export' );
		$filters = self::filters_from_request();
		self::stream_csv( $filters );
		exit;
	}

	/**
	 * Stream submissions as CSV: one column per field.
	 *
	 * @param array $filters Query filters.
	 */
	private static function stream_csv( $filters ) {
		// Pass 1: collect columns. A filtered form contributes its current field order first.
		$columns = array();
		if ( ! empty( $filters['form_id'] ) ) {
			$form = BorsFlow_Form::get( $filters['form_id'] );
			if ( $form ) {
				foreach ( BorsFlow_Form::data_fields( $form ) as $f ) {
					$columns[ $f['key'] ] = '' !== $f['label'] ? $f['label'] : $f['key'];
				}
			}
		}
		$page = 1;
		do {
			$batch = BorsFlow_Submissions::query(
				array_merge(
					$filters,
					array(
						'per_page' => 500,
						'page'     => $page++,
					)
				)
			);
			foreach ( $batch['items'] as $row ) {
				foreach ( $row['payload'] as $key => $entry ) {
					if ( ! isset( $columns[ $key ] ) ) {
						$columns[ $key ] = $entry['label'] ?? $key;
					}
				}
			}
			$more = count( $batch['items'] ) === 500;
		} while ( $more );

		$forms    = BorsFlow_Form::options();
		$filename = 'borsflow-submissions-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming to php://output, not the filesystem.
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel detects the encoding.
		fputcsv(
			$out,
			array_map(
				array( __CLASS__, 'csv_cell' ),
				array_merge(
					array( 'ID', __( 'Date', 'borsflow-forms' ), __( 'Form', 'borsflow-forms' ) ),
					array_values( $columns ),
					array( __( 'Sync status', 'borsflow-forms' ), __( 'CRM lead ID', 'borsflow-forms' ), __( 'Last sync error', 'borsflow-forms' ), 'IP', __( 'Page URL', 'borsflow-forms' ), __( 'Referrer', 'borsflow-forms' ) )
				)
			)
		);

		// Pass 2: rows.
		$page = 1;
		do {
			$batch = BorsFlow_Submissions::query(
				array_merge(
					$filters,
					array(
						'per_page' => 500,
						'page'     => $page++,
					)
				)
			);
			foreach ( $batch['items'] as $row ) {
				$line = array(
					$row['id'],
					BorsFlow_Admin::date( $row['created_at'] ),
					$forms[ (int) $row['form_id'] ] ?? '#' . $row['form_id'],
				);
				foreach ( array_keys( $columns ) as $key ) {
					$line[] = isset( $row['payload'][ $key ] ) ? BorsFlow_Mailer::value_to_string( $row['payload'][ $key ] ) : '';
				}
				array_push( $line, $row['sync_status'], $row['crm_lead_id'], (string) $row['last_error'], $row['ip'], (string) $row['page_url'], (string) $row['referrer'] );
				fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $line ) );
			}
			$more = count( $batch['items'] ) === 500;
		} while ( $more );

		fclose( $out );
		// phpcs:enable
	}

	/**
	 * Neutralise spreadsheet formulas (CSV injection).
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		// Plain numbers and phone numbers such as "+44 20 7946 0958" or "-5" are safe and left intact.
		if ( preg_match( '/^[+\-]?[0-9][0-9\s().\-\/]*$/', $value ) ) {
			return $value;
		}
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Sync status pill with tooltip for the last error.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function status_html( $row ) {
		$labels = BorsFlow_Submissions::status_labels();
		$status = $row['sync_status'];
		$html   = sprintf( '<span class="borsflow-pill borsflow-pill--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
		if ( 'failed' === $status && ! empty( $row['last_error'] ) ) {
			$html .= '<br><span class="borsflow-error-text">' . esc_html( wp_trim_words( $row['last_error'], 14 ) ) . '</span>';
		}
		if ( 'failed' === $status && ! empty( $row['next_retry_at'] ) ) {
			/* translators: %s: date/time. */
			$html .= '<br><span class="description">' . esc_html( sprintf( __( 'Next retry: %s', 'borsflow-forms' ), BorsFlow_Admin::date( $row['next_retry_at'] ) ) ) . '</span>';
		}
		if ( 'synced' === $status && '' !== $row['crm_lead_id'] ) {
			$html .= '<br><span class="description">' . esc_html( $row['crm_lead_id'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * Page callback.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['view'] ) ) {
			self::render_detail( absint( $_GET['view'] ) );
			return;
		}
		// phpcs:enable
		if ( ! self::$table ) {
			self::load();
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'borsflow-forms' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::notices(); ?>
			<form method="get">
				<input type="hidden" name="page" value="borsflow-submissions">
				<?php
				self::$table->search_box( __( 'Search submissions', 'borsflow-forms' ), 'borsflow-submissions' );
				self::$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Post-action notices.
	 */
	private static function notices() {
		$flash = get_transient( 'borsflow_notice_' . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( $flash && isset( $_GET['bfmsg'] ) ) {
			delete_transient( 'borsflow_notice_' . get_current_user_id() );
			BorsFlow_Admin::notice( $flash[1], $flash[0] );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( $_GET['notice'] ) : '';
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		// phpcs:enable
		$messages = array(
			/* translators: %d: count. */
			'delete'      => _n( '%d submission deleted.', '%d submissions deleted.', $count, 'borsflow-forms' ),
			/* translators: %d: count. */
			'deleted'     => _n( '%d submission deleted.', '%d submissions deleted.', $count, 'borsflow-forms' ),
			/* translators: %d: count. */
			'mark_read'   => _n( '%d submission marked as read.', '%d submissions marked as read.', $count, 'borsflow-forms' ),
			/* translators: %d: count. */
			'mark_unread' => _n( '%d submission marked as unread.', '%d submissions marked as unread.', $count, 'borsflow-forms' ),
			/* translators: %d: count. */
			'retry_sync'  => _n( '%d submission queued for CRM sync.', '%d submissions queued for CRM sync.', $count, 'borsflow-forms' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			BorsFlow_Admin::notice( sprintf( $messages[ $notice ], $count ) );
		}
	}

	/**
	 * Detail view.
	 *
	 * @param int $id Submission ID.
	 */
	private static function render_detail( $id ) {
		$row = BorsFlow_Submissions::get( $id );
		echo '<div class="wrap">';
		if ( ! $row ) {
			echo '<h1>' . esc_html__( 'Submission not found', 'borsflow-forms' ) . '</h1>';
			echo '<p><a href="' . esc_url( self::base_url() ) . '">' . esc_html__( '← Back to submissions', 'borsflow-forms' ) . '</a></p></div>';
			return;
		}
		$form = BorsFlow_Form::get( (int) $row['form_id'] );
		$logs = BorsFlow_Submissions::logs( $id );
		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %d: submission ID. */
			echo esc_html( sprintf( __( 'Submission #%d', 'borsflow-forms' ), $id ) );
			?>
		</h1>
		<a class="page-title-action" href="<?php echo esc_url( self::base_url() ); ?>"><?php esc_html_e( '← Back to submissions', 'borsflow-forms' ); ?></a>
		<hr class="wp-header-end">
		<?php self::notices(); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Fields', 'borsflow-forms' ); ?></h2>
						<div class="inside">
							<table class="widefat striped borsflow-detail-table">
								<tbody>
								<?php foreach ( $row['payload'] as $key => $entry ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $entry['label'] ); ?><br><code><?php echo esc_html( $key ); ?></code></th>
										<td>
											<?php
											if ( 'file' === ( $entry['type'] ?? '' ) && is_array( $entry['value'] ) ) {
												printf(
													'<a href="%s">%s</a> <span class="description">(%s)</span>',
													esc_url( BorsFlow_Uploads::download_url( $id, $key ) ),
													esc_html( $entry['value']['name'] ),
													esc_html( size_format( (int) $entry['value']['size'] ) )
												);
											} else {
												echo nl2br( esc_html( BorsFlow_Mailer::value_to_string( $entry ) ) );
											}
											?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Sync history', 'borsflow-forms' ); ?></h2>
						<div class="inside">
							<?php if ( ! $logs ) : ?>
								<p><?php esc_html_e( 'No sync attempts yet.', 'borsflow-forms' ); ?></p>
							<?php else : ?>
								<table class="widefat striped">
									<thead><tr>
										<th><?php esc_html_e( 'When', 'borsflow-forms' ); ?></th>
										<th><?php esc_html_e( 'Attempt', 'borsflow-forms' ); ?></th>
										<th><?php esc_html_e( 'Trigger', 'borsflow-forms' ); ?></th>
										<th><?php esc_html_e( 'HTTP', 'borsflow-forms' ); ?></th>
										<th><?php esc_html_e( 'Result', 'borsflow-forms' ); ?></th>
										<th><?php esc_html_e( 'Duration', 'borsflow-forms' ); ?></th>
									</tr></thead>
									<tbody>
									<?php foreach ( $logs as $log ) : ?>
										<tr>
											<td><?php echo esc_html( BorsFlow_Admin::date( $log['created_at'] ) ); ?></td>
											<td><?php echo esc_html( $log['attempt'] ); ?></td>
											<td><?php echo esc_html( $log['trigger_source'] ); ?></td>
											<td><?php echo $log['status_code'] ? esc_html( $log['status_code'] ) : '—'; ?></td>
											<td><span class="borsflow-pill borsflow-pill--<?php echo $log['success'] ? 'synced' : 'failed'; ?>"><?php echo $log['success'] ? esc_html__( 'OK', 'borsflow-forms' ) : esc_html__( 'Error', 'borsflow-forms' ); ?></span> <?php echo esc_html( $log['message'] ); ?></td>
											<td><?php echo esc_html( $log['duration_ms'] ); ?> ms</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'CRM sync', 'borsflow-forms' ); ?></h2>
						<div class="inside">
							<p><?php echo self::status_html( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html(). ?></p>
							<ul class="borsflow-meta-list">
								<li><strong><?php esc_html_e( 'Attempts:', 'borsflow-forms' ); ?></strong> <?php echo esc_html( $row['sync_attempts'] ); ?></li>
								<?php if ( $row['synced_at'] ) : ?>
									<li><strong><?php esc_html_e( 'Synced at:', 'borsflow-forms' ); ?></strong> <?php echo esc_html( BorsFlow_Admin::date( $row['synced_at'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $row['last_error'] ) ) : ?>
									<li><strong><?php esc_html_e( 'Last error:', 'borsflow-forms' ); ?></strong> <span class="borsflow-error-text"><?php echo esc_html( $row['last_error'] ); ?></span></li>
								<?php endif; ?>
							</ul>
							<?php if ( 'synced' !== $row['sync_status'] ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( self::row_action_url( $id, 'retry' ) ); ?>"><?php esc_html_e( 'Retry sync now', 'borsflow-forms' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Details', 'borsflow-forms' ); ?></h2>
						<div class="inside">
							<ul class="borsflow-meta-list">
								<li><strong><?php esc_html_e( 'Form:', 'borsflow-forms' ); ?></strong>
									<?php
									if ( $form ) {
										printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=borsflow-builder&form_id=' . $form['id'] ) ), esc_html( $form['title'] ) );
									} else {
										/* translators: %d: form ID. */
										echo esc_html( sprintf( __( 'Deleted form #%d', 'borsflow-forms' ), $row['form_id'] ) );
									}
									?>
								</li>
								<li><strong><?php esc_html_e( 'Submitted:', 'borsflow-forms' ); ?></strong> <?php echo esc_html( BorsFlow_Admin::date( $row['created_at'] ) ); ?></li>
								<li><strong><?php esc_html_e( 'IP:', 'borsflow-forms' ); ?></strong> <?php echo esc_html( $row['ip'] ?: '—' ); ?></li>
								<li><strong><?php esc_html_e( 'Page:', 'borsflow-forms' ); ?></strong> <?php echo $row['page_url'] ? '<a href="' . esc_url( $row['page_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['page_url'] ) . '</a>' : '—'; ?></li>
								<li><strong><?php esc_html_e( 'Referrer:', 'borsflow-forms' ); ?></strong> <?php echo esc_html( $row['referrer'] ?: '—' ); ?></li>
								<li><strong><?php esc_html_e( 'User agent:', 'borsflow-forms' ); ?></strong> <span class="description"><?php echo esc_html( $row['user_agent'] ?: '—' ); ?></span></li>
							</ul>
							<p>
								<a href="<?php echo esc_url( self::row_action_url( $id, 'mark_unread' ) ); ?>"><?php esc_html_e( 'Mark as unread', 'borsflow-forms' ); ?></a>
								|
								<a class="submitdelete borsflow-confirm-delete-sub" href="<?php echo esc_url( self::row_action_url( $id, 'delete' ) ); ?>"><?php esc_html_e( 'Delete', 'borsflow-forms' ); ?></a>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		</div>
		<?php
	}
}
