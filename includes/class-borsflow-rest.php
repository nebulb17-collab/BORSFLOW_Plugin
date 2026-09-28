<?php
/**
 * REST API routes.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the borsflow/v1 namespace.
 *
 * Admin routes rely on WordPress cookie authentication, which requires a valid
 * `X-WP-Nonce` (wp_rest) header, plus a capability check in permission_callback.
 */
class BorsFlow_Rest {

	const NS = 'borsflow/v1';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'BorsFlow_Submission_Handler', 'handle_rest' ),
				// Public by design: anonymous visitors submit forms. Abuse is handled by
				// the signed form token, honeypot, time trap, captcha and per-IP rate limit.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'BorsFlow_Spam', 'refresh_token' ),
				// Public by design; see the note on BorsFlow_Spam::refresh_token.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/forms/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_form' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_connection' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Permission callback for form management.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return BorsFlow_Plugin::can_manage();
	}

	/**
	 * Save a form from the builder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_form( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! BorsFlow_Form::get( $id ) ) {
			return new WP_Error( 'borsflow_not_found', __( 'Form not found.', 'borsflow-forms' ), array( 'status' => 404 ) );
		}
		$body   = (array) $request->get_json_params();
		$result = BorsFlow_Form::save(
			$id,
			(string) ( $body['title'] ?? '' ),
			$body['fields'] ?? array(),
			$body['settings'] ?? array(),
			! empty( $body['enabled'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::builder_payload( BorsFlow_Form::get( $id ) ) );
	}

	/**
	 * Shape of a form as the builder consumes it.
	 *
	 * @param array $form Form.
	 * @return array
	 */
	public static function builder_payload( $form ) {
		return array(
			'id'       => $form['id'],
			'title'    => $form['title'],
			'enabled'  => $form['enabled'],
			'fields'   => $form['fields'],
			'settings' => $form['settings'],
		);
	}

	/**
	 * Render unsaved builder state for the live preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function preview( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$fields = BorsFlow_Form::sanitize_fields( $body['fields'] ?? array() );
		$form   = array(
			'id'       => absint( $body['id'] ?? 0 ),
			'title'    => sanitize_text_field( $body['title'] ?? '' ),
			'enabled'  => true,
			'fields'   => $fields,
			'settings' => BorsFlow_Form::sanitize_settings( $body['settings'] ?? array(), $fields ),
		);
		return rest_ensure_response( array( 'html' => BorsFlow_Renderer::render( $form, array( 'preview' => true ) ) ) );
	}

	/**
	 * Test CRM credentials. Uses values typed into the settings form when given,
	 * otherwise the saved ones, so admins can test before saving.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function test_connection( WP_REST_Request $request ) {
		$base = esc_url_raw( trim( (string) $request->get_param( 'base_url' ) ), array( 'http', 'https' ) );
		$key  = trim( (string) $request->get_param( 'api_key' ) );

		$client = new BorsFlow_Crm_Client( '' !== $base ? $base : null, '' !== $key ? $key : null );
		$res    = $client->test();

		if ( $res['ok'] ) {
			/* translators: 1: HTTP status, 2: duration in ms. */
			$message = sprintf( __( 'Connected (HTTP %1$d, %2$d ms).', 'borsflow-forms' ), $res['code'], $res['duration_ms'] );
		} elseif ( 401 === $res['code'] || 403 === $res['code'] ) {
			/* translators: %d: HTTP status. */
			$message = sprintf( __( 'The CRM rejected the API key (HTTP %d).', 'borsflow-forms' ), $res['code'] );
		} else {
			$message = '' !== $res['error'] ? $res['error'] : __( 'Connection failed.', 'borsflow-forms' );
		}

		return rest_ensure_response(
			array(
				'success' => $res['ok'],
				'code'    => $res['code'],
				'message' => $message,
			)
		);
	}
}
