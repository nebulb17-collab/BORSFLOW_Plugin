<?php
/**
 * Asynchronous CRM sync with exponential backoff.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns submissions into CRM leads.
 */
class BorsFlow_Sync {

	const HOOK = 'borsflow_sync_submission';

	/**
	 * Retry delays in seconds after attempt 1, 2, 3... The last value repeats.
	 *
	 * @return int[]
	 */
	public static function backoff() {
		return array_map( 'absint', (array) apply_filters( 'borsflow_sync_backoff', array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Schedule a sync run. Replaces any pending event for the same submission.
	 *
	 * @param int $id    Submission ID.
	 * @param int $delay Seconds from now.
	 */
	public static function schedule( $id, $delay = 0 ) {
		wp_clear_scheduled_hook( self::HOOK, array( (int) $id ) );
		wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK, array( (int) $id ) );
	}

	/**
	 * Queue a manual retry (used by bulk actions).
	 *
	 * @param int $id Submission ID.
	 */
	public static function queue_retry( $id ) {
		$row = BorsFlow_Submissions::get( $id );
		if ( ! $row || 'synced' === $row['sync_status'] ) {
			return;
		}
		BorsFlow_Submissions::update(
			$id,
			array(
				'sync_status'   => 'pending',
				'next_retry_at' => null,
			)
		);
		self::schedule( $id, 0 );
	}

	/**
	 * Stable idempotency key: identical for every retry of the same submission on this site.
	 *
	 * @param int $id Submission ID.
	 * @return string
	 */
	public static function idempotency_key( $id ) {
		return 'wp-' . substr( md5( home_url() ), 0, 12 ) . '-' . (int) $id;
	}

	/**
	 * Run a sync attempt. Cron callback, also used for manual per-row retries.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $source cron|manual.
	 * @return array{ ok: bool, message: string }
	 */
	public static function run( $id, $source = 'cron' ) {
		$id  = (int) $id;
		$row = BorsFlow_Submissions::get( $id );
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'Submission not found.', 'borsflow-forms' ) );
		}
		if ( 'synced' === $row['sync_status'] ) {
			return array( 'ok' => true, 'message' => __( 'Already synced.', 'borsflow-forms' ) );
		}
		if ( ! BorsFlow_Crm_Client::is_configured() ) {
			$message = __( 'CRM is not configured; sync skipped.', 'borsflow-forms' );
			BorsFlow_Submissions::update( $id, array( 'sync_status' => 'skipped', 'last_error' => $message, 'next_retry_at' => null ) );
			return array( 'ok' => false, 'message' => $message );
		}
		if ( ! BorsFlow_Submissions::claim( $id ) ) {
			return array( 'ok' => false, 'message' => __( 'A sync for this submission is already in progress.', 'borsflow-forms' ) );
		}
		// This run supersedes any queued attempt (e.g. a manual retry before cron fired).
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );

		$attempt = (int) $row['sync_attempts'] + 1;
		$form    = BorsFlow_Form::get( (int) $row['form_id'] );
		$lead    = self::build_lead( $row, $form );
		$client  = new BorsFlow_Crm_Client();
		$res     = $client->create_lead( $lead, self::idempotency_key( $id ) );

		// A 409 carrying a lead ID means the CRM recognised our idempotency key: treat as synced.
		$lead_id = BorsFlow_Crm_Client::lead_id( $res['body'] );
		$ok      = $res['ok'] || ( 409 === $res['code'] && '' !== $lead_id );

		BorsFlow_Submissions::add_log(
			array(
				'submission_id'  => $id,
				'attempt'        => $attempt,
				'status_code'    => $res['code'],
				'success'        => $ok,
				'message'        => $ok ? ( '' !== $lead_id ? sprintf( 'Lead %s', $lead_id ) : 'OK' ) : $res['error'],
				'duration_ms'    => $res['duration_ms'],
				'trigger_source' => $source,
			)
		);

		if ( $ok ) {
			BorsFlow_Submissions::update(
				$id,
				array(
					'sync_status'   => 'synced',
					'sync_attempts' => $attempt,
					'crm_lead_id'   => $lead_id,
					'synced_at'     => current_time( 'mysql', true ),
					'last_error'    => null,
					'next_retry_at' => null,
					'locked_at'     => null,
				)
			);
			do_action( 'borsflow_submission_synced', $id, $lead_id, $res );
			/* translators: %s: CRM lead ID. */
			return array( 'ok' => true, 'message' => '' !== $lead_id ? sprintf( __( 'Synced as lead %s.', 'borsflow-forms' ), $lead_id ) : __( 'Synced.', 'borsflow-forms' ) );
		}

		$max        = (int) BorsFlow_Settings::get( 'crm_max_attempts' );
		$will_retry = $res['retryable'] && $attempt < $max;
		$next       = null;
		if ( $will_retry ) {
			$delays = self::backoff();
			$delay  = $delays[ min( $attempt - 1, count( $delays ) - 1 ) ] ?? HOUR_IN_SECONDS;
			$next   = gmdate( 'Y-m-d H:i:s', time() + $delay );
			self::schedule( $id, $delay );
		}

		BorsFlow_Submissions::update(
			$id,
			array(
				'sync_status'   => 'failed',
				'sync_attempts' => $attempt,
				'last_error'    => $res['error'],
				'next_retry_at' => $next,
				'locked_at'     => null,
			)
		);
		do_action( 'borsflow_submission_sync_failed', $id, $res, $will_retry );

		return array( 'ok' => false, 'message' => $res['error'] );
	}

	/**
	 * Build the CRM lead body from a stored submission and the form's mapping.
	 *
	 * @param array      $row  Submission row.
	 * @param array|null $form Form, or null if it was deleted.
	 * @return array
	 */
	public static function build_lead( $row, $form ) {
		$crm     = $form ? $form['settings']['crm'] : array( 'mapping' => array(), 'pipeline' => '', 'stage' => '', 'source' => '' );
		$mapping = (array) $crm['mapping'];
		if ( ! $form ) {
			// Form deleted before sync: infer the obvious mappings from the stored field types.
			foreach ( $row['payload'] as $key => $entry ) {
				$type = $entry['type'] ?? '';
				if ( 'email' === $type && ! in_array( 'email', $mapping, true ) ) {
					$mapping[ $key ] = 'email';
				} elseif ( 'phone' === $type && ! in_array( 'phone', $mapping, true ) ) {
					$mapping[ $key ] = 'phone';
				}
			}
		}
		$lead    = array();
		$notes   = array();
		$extra   = array();

		foreach ( $row['payload'] as $key => $entry ) {
			$value = BorsFlow_Mailer::value_to_string( $entry );
			if ( '' === trim( $value ) ) {
				continue;
			}
			$target = $mapping[ $key ] ?? '';
			switch ( $target ) {
				case '':
					$extra[] = $entry['label'] . ': ' . $value;
					break;
				case 'notes':
					$notes[] = $value;
					break;
				case 'fullName':
					$parts = preg_split( '/\s+/', trim( $value ), 2 );
					if ( empty( $lead['firstName'] ) ) {
						$lead['firstName'] = $parts[0];
					}
					if ( empty( $lead['lastName'] ) && isset( $parts[1] ) ) {
						$lead['lastName'] = $parts[1];
					}
					break;
				case 'value':
					$number        = preg_replace( '/[^0-9.\-]/', '', $value );
					$lead['value'] = is_numeric( $number ) ? $number + 0 : 0;
					break;
				default:
					$lead[ $target ] = $value;
			}
		}

		if ( $extra ) {
			$notes[] = implode( "\n", $extra );
		}
		if ( $notes ) {
			$lead['notes'] = implode( "\n\n", $notes );
		}

		$lead['source'] = '' !== $crm['source'] ? $crm['source'] : (string) BorsFlow_Settings::get( 'crm_default_source' );
		$pipeline       = '' !== $crm['pipeline'] ? $crm['pipeline'] : (string) BorsFlow_Settings::get( 'crm_default_pipeline' );
		$stage          = '' !== $crm['stage'] ? $crm['stage'] : (string) BorsFlow_Settings::get( 'crm_default_stage' );
		if ( '' !== $pipeline ) {
			$lead['pipeline'] = $pipeline;
		}
		if ( '' !== $stage ) {
			$lead['stage'] = $stage;
		}

		$lead['externalId'] = self::idempotency_key( (int) $row['id'] );
		$lead['metadata']   = array(
			'formId'       => (int) $row['form_id'],
			'formTitle'    => $form ? $form['title'] : '',
			'submissionId' => (int) $row['id'],
			'pageUrl'      => (string) $row['page_url'],
			'referrer'     => (string) $row['referrer'],
			'submittedAt'  => gmdate( 'c', (int) strtotime( $row['created_at'] . ' UTC' ) ),
			'site'         => home_url( '/' ),
		);

		/**
		 * Filter the CRM lead body.
		 *
		 * @param array      $lead Lead.
		 * @param array      $row  Submission row.
		 * @param array|null $form Form.
		 */
		return apply_filters( 'borsflow_crm_lead_payload', $lead, $row, $form );
	}
}
