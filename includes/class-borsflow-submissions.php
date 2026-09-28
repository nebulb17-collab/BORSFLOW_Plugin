<?php
/**
 * Submissions repository ({prefix}borsflow_submissions and {prefix}borsflow_sync_log).
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * All SQL touching submissions and the sync log goes through here.
 */
class BorsFlow_Submissions {

	const STATUSES = array( 'pending', 'synced', 'failed', 'skipped' );

	/**
	 * Submissions table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'borsflow_submissions';
	}

	/**
	 * Sync log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'borsflow_sync_log';
	}

	/**
	 * Human labels for sync statuses.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels() {
		return array(
			'pending' => __( 'Pending', 'borsflow-forms' ),
			'synced'  => __( 'Synced', 'borsflow-forms' ),
			'failed'  => __( 'Failed', 'borsflow-forms' ),
			'skipped' => __( 'Skipped', 'borsflow-forms' ),
		);
	}

	/**
	 * Insert a submission.
	 *
	 * @param array $data Column => value.
	 * @return int|false
	 */
	public static function insert( $data ) {
		global $wpdb;
		$row = array(
			'form_id'     => (int) $data['form_id'],
			'payload'     => wp_json_encode( $data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'ip'          => substr( (string) ( $data['ip'] ?? '' ), 0, 45 ),
			'user_agent'  => substr( (string) ( $data['user_agent'] ?? '' ), 0, 255 ),
			'referrer'    => (string) ( $data['referrer'] ?? '' ),
			'page_url'    => (string) ( $data['page_url'] ?? '' ),
			'created_at'  => current_time( 'mysql', true ),
			'is_read'     => 0,
			'sync_status' => in_array( $data['sync_status'] ?? '', self::STATUSES, true ) ? $data['sync_status'] : 'pending',
		);
		$ok = $wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
		self::flush_unread_count();
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch one submission with its payload decoded.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Decode payload.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( $row ) {
		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = is_array( $payload ) ? $payload : array();
		return $row;
	}

	/**
	 * Update columns of a submission.
	 *
	 * @param int   $id   ID.
	 * @param array $data Column => value (nulls are written as NULL).
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Build a prepared WHERE clause from list filters.
	 *
	 * @param array $args Filters: form_id, status, date_from, date_to (Y-m-d, site time), search, ids, is_read.
	 * @return string
	 */
	private static function where( $args ) {
		global $wpdb;
		$sql = array( '1=1' );

		if ( ! empty( $args['form_id'] ) ) {
			$sql[] = $wpdb->prepare( 'form_id = %d', $args['form_id'] );
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$sql[] = $wpdb->prepare( 'sync_status = %s', $args['status'] );
		}
		if ( isset( $args['is_read'] ) && '' !== $args['is_read'] ) {
			$sql[] = $wpdb->prepare( 'is_read = %d', $args['is_read'] ? 1 : 0 );
		}
		if ( ! empty( $args['date_from'] ) && self::valid_date( $args['date_from'] ) ) {
			$sql[] = $wpdb->prepare( 'created_at >= %s', get_gmt_from_date( $args['date_from'] . ' 00:00:00' ) );
		}
		if ( ! empty( $args['date_to'] ) && self::valid_date( $args['date_to'] ) ) {
			$sql[] = $wpdb->prepare( 'created_at <= %s', get_gmt_from_date( $args['date_to'] . ' 23:59:59' ) );
		}
		if ( ! empty( $args['search'] ) ) {
			// Payload is stored as unescaped-unicode JSON so a LIKE finds values as typed.
			$like  = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$sql[] = $wpdb->prepare( '(payload LIKE %s OR ip LIKE %s OR crm_lead_id LIKE %s)', $like, $like, $like );
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids          = array_map( 'absint', (array) $args['ids'] );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql[]        = $wpdb->prepare( "id IN ({$placeholders})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		}
		return implode( ' AND ', $sql );
	}

	/**
	 * Y-m-d check.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function valid_date( $date ) {
		$d = DateTime::createFromFormat( '!Y-m-d', (string) $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Query submissions.
	 *
	 * @param array $args Filters plus orderby, order, per_page, page.
	 * @return array{items: array[], total: int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$where   = self::where( $args );
		$orderby = in_array( $args['orderby'] ?? '', array( 'created_at', 'id', 'sync_status' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$table   = self::table();

		$limit = '';
		if ( ! empty( $args['per_page'] ) ) {
			$per   = max( 1, (int) $args['per_page'] );
			$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
			$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepare(); orderby/order are allowlisted.
		$items = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id {$order} {$limit}", ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $items ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Delete submissions, their files and their sync log.
	 *
	 * @param int[] $ids IDs.
	 * @return int Number deleted.
	 */
	public static function delete( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		foreach ( self::query( array( 'ids' => $ids ) )['items'] as $row ) {
			BorsFlow_Uploads::delete_for_payload( $row['payload'] );
			wp_clear_scheduled_hook( BorsFlow_Sync::HOOK, array( (int) $row['id'] ) );
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . " WHERE submission_id IN ({$in})", $ids ) );
		$count = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$in})", $ids ) );
		// phpcs:enable
		self::flush_unread_count();
		return $count;
	}

	/**
	 * Mark submissions read or unread.
	 *
	 * @param int[] $ids  IDs.
	 * @param bool  $read Read state.
	 */
	public static function mark_read( $ids, $read = true ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge( array( $read ? 1 : 0 ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET is_read = %d WHERE id IN ({$in})", $params ) );
		self::flush_unread_count();
	}

	/**
	 * Unread count for the menu badge (cached).
	 *
	 * @return int
	 */
	public static function unread_count() {
		global $wpdb;
		$count = get_transient( 'borsflow_unread_count' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE is_read = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
			set_transient( 'borsflow_unread_count', $count, HOUR_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Invalidate the unread badge cache.
	 */
	public static function flush_unread_count() {
		delete_transient( 'borsflow_unread_count' );
	}

	/**
	 * Count submissions per form.
	 *
	 * @return array<int,int>
	 */
	public static function counts_by_form() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT form_id, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY form_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['form_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Atomically claim a submission for syncing so cron and a manual retry
	 * cannot send it concurrently. Stale locks (older than 5 minutes) are taken over.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function claim( $id ) {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
		$rows  = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET locked_at = %s WHERE id = %d AND sync_status <> 'synced' AND (locked_at IS NULL OR locked_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
				$now,
				$id,
				$stale
			)
		);
		return 1 === (int) $rows;
	}

	/**
	 * Append a sync log entry.
	 *
	 * @param array $entry submission_id, attempt, status_code, success, message, duration_ms, trigger_source.
	 */
	public static function add_log( $entry ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'submission_id'  => (int) $entry['submission_id'],
				'attempt'        => (int) $entry['attempt'],
				'status_code'    => (int) $entry['status_code'],
				'success'        => $entry['success'] ? 1 : 0,
				'message'        => mb_substr( (string) $entry['message'], 0, 2000 ),
				'duration_ms'    => (int) $entry['duration_ms'],
				'trigger_source' => substr( (string) $entry['trigger_source'], 0, 20 ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Sync history for one submission.
	 *
	 * @param int $id Submission ID.
	 * @return array[]
	 */
	public static function logs( $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::log_table() . ' WHERE submission_id = %d ORDER BY id DESC', $id ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
	}

	/**
	 * Recent sync attempts across all submissions.
	 *
	 * @param int    $per_page Page size.
	 * @param int    $page     Page.
	 * @param string $outcome  '', 'success' or 'error'.
	 * @return array{items: array[], total: int}
	 */
	public static function recent_logs( $per_page = 50, $page = 1, $outcome = '' ) {
		global $wpdb;
		$log   = self::log_table();
		$sub   = self::table();
		$where = '1=1';
		if ( 'success' === $outcome ) {
			$where = 'l.success = 1';
		} elseif ( 'error' === $outcome ) {
			$where = 'l.success = 0';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names internal, $where from allowlist.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, s.form_id FROM {$log} l LEFT JOIN {$sub} s ON s.id = l.submission_id WHERE {$where} ORDER BY l.id DESC LIMIT %d OFFSET %d",
				$per_page,
				( max( 1, $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log} l WHERE {$where}" );
		// phpcs:enable
		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * IDs of submissions created before a cutoff.
	 *
	 * @param int $days  Age in days.
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	public static function ids_older_than( $days, $limit = 500 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE created_at < %s ORDER BY id ASC LIMIT %d', $cutoff, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
	}

	/**
	 * Submissions whose retry should have happened already but has no cron event (e.g. cron was cleared).
	 *
	 * @param int $max_attempts Max attempts.
	 * @return int[]
	 */
	public static function overdue_ids( $max_attempts ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . " WHERE ((sync_status = 'pending' AND created_at < %s) OR (sync_status = 'failed' AND next_retry_at IS NOT NULL AND next_retry_at < %s)) AND sync_attempts < %d ORDER BY id ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
					$cutoff,
					$cutoff,
					$max_attempts
				)
			)
		);
	}
}
