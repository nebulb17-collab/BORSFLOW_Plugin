<?php
/**
 * Public submission pipeline shared by the REST route and the no-JS admin-post fallback.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates, stores, notifies and queues CRM sync for a submission.
 */
class BorsFlow_Submission_Handler {

	/**
	 * Process a submission.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $params  Unslashed request params.
	 * @param array $files   $_FILES-style array.
	 * @param array $meta    ip, user_agent, referrer.
	 * @return array{ success: bool, status: int, message: string, errors: array<string,string>, redirect: string, submission_id: int, values: array }
	 */
	public static function process( $form_id, $params, $files, $meta ) {
		$result = array(
			'success'       => false,
			'status'        => 400,
			'message'       => '',
			'errors'        => array(),
			'redirect'      => '',
			'submission_id' => 0,
			'values'        => array(),
		);

		$form = BorsFlow_Form::get( $form_id, false );
		if ( ! $form ) {
			$result['status']  = 404;
			$result['message'] = __( 'This form is not available.', 'borsflow-forms' );
			return $result;
		}

		$spam = BorsFlow_Spam::check( $form, $params );
		if ( is_wp_error( $spam ) ) {
			if ( 'borsflow_spam' === $spam->get_error_code() ) {
				// Look successful to the bot; store nothing.
				do_action( 'borsflow_spam_blocked', $form, $spam->get_error_message() );
				return self::success_result( $form, $result, 0 );
			}
			$data              = $spam->get_error_data();
			$result['status']  = (int) ( $data['status'] ?? 400 );
			$result['message'] = $spam->get_error_message();
			return $result;
		}

		$raw_values = is_array( $params['bf'] ?? null ) ? $params['bf'] : array();
		$fields     = BorsFlow_Form::data_fields( $form );

		// Pass 1: sanitize every non-file value so conditional logic sees what the browser saw.
		$values = array();
		foreach ( $fields as $f ) {
			if ( 'file' === $f['type'] ) {
				$has_file            = isset( $files[ 'bf_file_' . $f['key'] ]['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $files[ 'bf_file_' . $f['key'] ]['error'];
				$values[ $f['key'] ] = $has_file ? 'file' : '';
				continue;
			}
			$values[ $f['key'] ] = BorsFlow_Fields::sanitize_value( $f, $raw_values[ $f['key'] ] ?? null );
		}
		$visible           = BorsFlow_Fields::visibility( $form['fields'], $values );
		$result['values']  = array_filter( $values, static fn( $v ) => 'file' !== $v );

		// Pass 2: validate visible fields. Hidden-by-logic fields are neither required nor stored.
		$errors = array();
		foreach ( $fields as $f ) {
			if ( empty( $visible[ $f['id'] ] ) || 'file' === $f['type'] ) {
				continue;
			}
			$message = BorsFlow_Fields::validate( $f, $values[ $f['key'] ], is_string( $raw_values[ $f['key'] ] ?? null ) ? $raw_values[ $f['key'] ] : null );
			if ( '' !== $message ) {
				$errors[ $f['key'] ] = $message;
			}
		}

		/**
		 * Filter validation errors before files are stored.
		 *
		 * @param array $errors Errors keyed by field key.
		 * @param array $values Sanitized values.
		 * @param array $form   Form.
		 */
		$errors = apply_filters( 'borsflow_validation_errors', $errors, $values, $form );

		// Pass 3: files, only once everything else is valid so we never orphan uploads.
		$stored_files = array();
		if ( ! $errors ) {
			foreach ( $fields as $f ) {
				if ( 'file' !== $f['type'] || empty( $visible[ $f['id'] ] ) ) {
					continue;
				}
				$stored = BorsFlow_Uploads::store( $f, $files[ 'bf_file_' . $f['key'] ] ?? null );
				if ( is_wp_error( $stored ) ) {
					$errors[ $f['key'] ] = $stored->get_error_message();
				} elseif ( null === $stored && $f['required'] ) {
					$errors[ $f['key'] ] = __( 'This field is required.', 'borsflow-forms' );
				} else {
					$values[ $f['key'] ] = $stored ? $stored : '';
					if ( $stored ) {
						$stored_files[] = array( 'type' => 'file', 'value' => $stored );
					}
				}
			}
		}

		if ( $errors ) {
			BorsFlow_Uploads::delete_for_payload( $stored_files );
			$result['status']  = 422;
			$result['errors']  = $errors;
			$result['message'] = BorsFlow_Fields::messages()['generic'];
			return $result;
		}

		// Payload snapshots label and type so the submission stays readable if the form changes later.
		$payload = array();
		foreach ( $fields as $f ) {
			if ( empty( $visible[ $f['id'] ] ) ) {
				continue;
			}
			$payload[ $f['key'] ] = array(
				'label' => '' !== $f['label'] ? $f['label'] : $f['key'],
				'type'  => $f['type'],
				'value' => $values[ $f['key'] ],
			);
		}

		$page_url = esc_url_raw( (string) ( $params['bf_page_url'] ?? '' ) );
		$crm_on   = ! empty( $form['settings']['crm']['enabled'] ) && BorsFlow_Crm_Client::is_configured();

		$id = BorsFlow_Submissions::insert(
			array(
				'form_id'     => $form['id'],
				'payload'     => $payload,
				'ip'          => $meta['ip'] ?? '',
				'user_agent'  => $meta['user_agent'] ?? '',
				'referrer'    => esc_url_raw( (string) ( $params['bf_referrer'] ?? ( $meta['referrer'] ?? '' ) ) ),
				'page_url'    => $page_url,
				'sync_status' => $crm_on ? 'pending' : 'skipped',
			)
		);

		if ( ! $id ) {
			BorsFlow_Uploads::delete_for_payload( $stored_files );
			$result['status']  = 500;
			$result['message'] = __( 'Your submission could not be saved. Please try again.', 'borsflow-forms' );
			return $result;
		}

		// Stored first, synced later: the visitor never waits on the CRM.
		BorsFlow_Spam::record_hit( $form['id'] );
		if ( $crm_on ) {
			BorsFlow_Sync::schedule( $id, 0 );
		}

		BorsFlow_Mailer::send_notifications( $form, $payload, $id, $page_url );

		/**
		 * Fires after a submission is stored.
		 *
		 * @param int   $id      Submission ID.
		 * @param array $form    Form.
		 * @param array $payload Payload.
		 */
		do_action( 'borsflow_submission_created', $id, $form, $payload );

		return self::success_result( $form, $result, $id );
	}

	/**
	 * Fill a success result.
	 *
	 * @param array $form   Form.
	 * @param array $result Result skeleton.
	 * @param int   $id     Submission ID.
	 * @return array
	 */
	private static function success_result( $form, $result, $id ) {
		$s                       = $form['settings'];
		$result['success']       = true;
		$result['status']        = 200;
		$result['submission_id'] = $id;
		$result['message']       = $s['success_message'];
		$result['values']        = array();
		if ( 'redirect' === $s['after_submit'] && '' !== $s['redirect_url'] ) {
			$result['redirect'] = $s['redirect_url'];
		}
		return $result;
	}

	/**
	 * Request metadata.
	 *
	 * @return array
	 */
	public static function request_meta() {
		return array(
			'ip'         => BorsFlow_Spam::client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		);
	}

	/**
	 * REST callback: POST /borsflow/v1/forms/{id}/submit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_rest( WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( ! $params ) {
			$params = (array) $request->get_json_params();
		}
		$result = self::process( (int) $request['id'], $params, $request->get_file_params(), self::request_meta() );

		return new WP_REST_Response(
			array(
				'success'  => $result['success'],
				'message'  => $result['success'] ? wp_kses_post( wpautop( $result['message'] ) ) : $result['message'],
				'errors'   => (object) $result['errors'],
				'redirect' => $result['redirect'],
			),
			$result['status']
		);
	}

	/**
	 * admin-post fallback for browsers without JavaScript.
	 */
	public static function handle_post_fallback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form; protected by the signed form token in BorsFlow_Spam.
		$params  = wp_unslash( $_POST );
		$form_id = absint( $params['form_id'] ?? 0 );
		$result  = self::process( $form_id, $params, $_FILES, self::request_meta() );

		if ( $result['success'] && '' !== $result['redirect'] ) {
			wp_redirect( $result['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-configured destination.
			exit;
		}

		// Only ever send the visitor back to a page on this site.
		$back = wp_validate_redirect( esc_url_raw( (string) ( $params['bf_page_url'] ?? '' ) ), '' );
		if ( '' === $back ) {
			$back = wp_validate_redirect( esc_url_raw( (string) wp_get_referer() ), '' );
		}
		if ( '' === $back ) {
			$back = home_url( '/' );
		}

		$token = wp_generate_password( 20, false );
		set_transient(
			'borsflow_result_' . $token,
			array(
				'form_id' => $form_id,
				'success' => $result['success'],
				'message' => $result['message'],
				'errors'  => $result['errors'],
				'values'  => $result['values'],
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'borsflow_result', $token, $back ) . '#borsflow-' . $form_id );
		exit;
	}
}
