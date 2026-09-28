<?php
/**
 * HTTP client for the BorsFlow CRM API.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request(). Never logs or returns the API key.
 */
class BorsFlow_Crm_Client {

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $base Base URL (defaults to settings).
	 * @param string|null $key  API key (defaults to settings).
	 */
	public function __construct( $base = null, $key = null ) {
		$this->base = untrailingslashit( (string) ( $base ?? BorsFlow_Settings::get( 'crm_base_url' ) ) );
		$this->key  = (string) ( $key ?? BorsFlow_Settings::get( 'crm_api_key' ) );
	}

	/**
	 * Whether the global CRM settings are filled in.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== (string) BorsFlow_Settings::get( 'crm_base_url' ) && '' !== (string) BorsFlow_Settings::get( 'crm_api_key' );
	}

	/**
	 * Create a lead.
	 *
	 * @param array  $lead            Lead payload.
	 * @param string $idempotency_key Stable key per submission.
	 * @return array Response summary (see request()).
	 */
	public function create_lead( $lead, $idempotency_key ) {
		return $this->request( 'POST', (string) BorsFlow_Settings::get( 'crm_leads_path' ), $lead, array( 'Idempotency-Key' => $idempotency_key ) );
	}

	/**
	 * Check credentials against the health endpoint.
	 *
	 * @return array Response summary.
	 */
	public function test() {
		return $this->request( 'GET', (string) BorsFlow_Settings::get( 'crm_health_path' ) );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path relative to the base URL.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers.
	 * @return array{ ok: bool, code: int, body: mixed, error: string, retryable: bool, duration_ms: int }
	 */
	public function request( $method, $path, $body = null, $headers = array() ) {
		$out = array(
			'ok'          => false,
			'code'        => 0,
			'body'        => null,
			'error'       => '',
			'retryable'   => false,
			'duration_ms' => 0,
		);
		if ( '' === $this->base || '' === $this->key ) {
			$out['error'] = __( 'CRM base URL or API key is not configured.', 'borsflow-forms' );
			return $out;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => (int) BorsFlow_Settings::get( 'crm_timeout' ),
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'BorsFlowForms/' . BORSFLOW_VERSION . '; ' . home_url( '/' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Filter CRM request arguments (e.g. to add headers or adjust sslverify for a local CRM).
		 *
		 * @param array  $args   wp_remote_request() args.
		 * @param string $method Method.
		 * @param string $path   Path.
		 */
		$args = apply_filters( 'borsflow_crm_request_args', $args, $method, $path );

		$start              = microtime( true );
		$response           = wp_remote_request( $this->base . $path, $args );
		$out['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$out['error']     = $this->redact( $response->get_error_message() );
			$out['retryable'] = true;
			return $out;
		}

		$code        = (int) wp_remote_retrieve_response_code( $response );
		$raw         = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw, true );
		$out['code'] = $code;
		$out['body'] = is_array( $decoded ) ? $decoded : null;
		$out['ok']   = $code >= 200 && $code < 300;

		if ( ! $out['ok'] ) {
			$detail = '';
			if ( is_array( $decoded ) ) {
				$detail = (string) ( $decoded['message'] ?? $decoded['error']['message'] ?? ( is_string( $decoded['error'] ?? null ) ? $decoded['error'] : '' ) );
			}
			if ( '' === $detail ) {
				$detail = wp_strip_all_tags( $raw );
			}
			/* translators: 1: HTTP status code, 2: response detail. */
			$out['error']     = $this->redact( sprintf( __( 'HTTP %1$d: %2$s', 'borsflow-forms' ), $code, mb_substr( trim( $detail ), 0, 300 ) ) );
			$out['retryable'] = in_array( $code, array( 408, 425, 429 ), true ) || $code >= 500;
		}

		return $out;
	}

	/**
	 * Extract a lead ID from common response shapes: {id}, {data:{id}}, {lead:{id}}.
	 *
	 * @param mixed $body Decoded body.
	 * @return string
	 */
	public static function lead_id( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}
		foreach ( array( array( 'id' ), array( 'data', 'id' ), array( 'lead', 'id' ), array( 'leadId' ), array( 'data', 'leadId' ) ) as $path ) {
			$v = $body;
			foreach ( $path as $k ) {
				$v = is_array( $v ) && isset( $v[ $k ] ) ? $v[ $k ] : null;
			}
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				return substr( sanitize_text_field( (string) $v ), 0, 191 );
			}
		}
		return '';
	}

	/**
	 * Remove the API key from any message, in case a server echoes the header back.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function redact( $message ) {
		return '' !== $this->key ? str_replace( $this->key, '[redacted]', $message ) : $message;
	}
}
