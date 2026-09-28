<?php
/**
 * Base test case: clean state per test plus helpers for forms, HTTP and mail.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

abstract class BorsFlow_TestCase extends TestCase {

	/** @var array[] Captured outgoing HTTP requests. */
	protected $requests = array();

	/** @var array[] Captured emails. */
	protected $mails = array();

	/** @var callable|null Responder for mocked HTTP. */
	private $responder = null;

	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . BorsFlow_Submissions::table() );
		$wpdb->query( 'DELETE FROM ' . BorsFlow_Submissions::log_table() );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%borsflow%'" );
		foreach ( get_posts( array( 'post_type' => BorsFlow_Post_Type::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		wp_cache_flush();
		// Rate limiting off by default so fixtures can submit freely; RateLimit tests opt in.
		update_option( BorsFlow_Settings::OPTION, array_merge( BorsFlow_Settings::defaults(), array( 'rate_limit_max' => 0 ) ) );
		_set_cron_array( array() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );

		$this->requests  = array();
		$this->mails     = array();
		$this->responder = null;
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 5, 2 );
		wp_set_current_user( 0 );
	}

	protected function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		remove_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 5 );
		parent::tear_down();
	}

	/* HTTP ---------------------------------------------------------------- */

	/**
	 * Respond to every outgoing request with this callback (args: url, args) => [code, body] | WP_Error.
	 */
	protected function mock_http( callable $responder ) {
		$this->responder = $responder;
	}

	public function intercept_http( $pre, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( ! $this->responder ) {
			return new WP_Error( 'http_blocked', 'No HTTP mock configured in test' );
		}
		$result = call_user_func( $this->responder, $url, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'headers'  => array(),
			'body'     => is_string( $result[1] ) ? $result[1] : wp_json_encode( $result[1] ),
			'response' => array( 'code' => $result[0], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function intercept_mail( $null, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/* Fixtures ------------------------------------------------------------ */

	protected function configure_crm( $extra = array() ) {
		update_option(
			BorsFlow_Settings::OPTION,
			array_merge( BorsFlow_Settings::all(), array( 'crm_base_url' => 'http://crm.test', 'crm_api_key' => 'secret-key-123456' ), $extra )
		);
	}

	protected function set_setting( $key, $value ) {
		update_option( BorsFlow_Settings::OPTION, array_merge( BorsFlow_Settings::all(), array( $key => $value ) ) );
	}

	protected function field( $type, $key, $extra = array() ) {
		return array_merge(
			array(
				'id'    => 'f_' . $key,
				'type'  => $type,
				'key'   => $key,
				'label' => ucfirst( str_replace( '_', ' ', $key ) ),
			),
			$extra
		);
	}

	/**
	 * Create a form. $settings is merged one level deep into the defaults.
	 */
	protected function make_form( array $fields, array $settings = array(), $enabled = true ) {
		$id = BorsFlow_Form::create( 'Test form' );
		$s  = BorsFlow_Form::default_settings();
		$s['notify']['enabled'] = false;
		foreach ( $settings as $k => $v ) {
			$s[ $k ] = is_array( $v ) && isset( $s[ $k ] ) && is_array( $s[ $k ] ) ? array_replace( $s[ $k ], $v ) : $v;
		}
		$result = BorsFlow_Form::save( $id, 'Test form', $fields, $s, $enabled );
		$this->assertTrue( $result );
		return $id;
	}

	/**
	 * Submit through the real pipeline with a valid, aged token.
	 */
	protected function submit( $form_id, array $values, array $extra = array(), array $files = array() ) {
		$params = array_merge(
			array(
				'bf'          => $values,
				'bf_token'    => $this->token( $form_id, time() - 60 ),
				'bf_page_url' => 'http://borsflow.test/contact/',
			),
			$extra
		);
		return BorsFlow_Submission_Handler::process( $form_id, $params, $files, BorsFlow_Submission_Handler::request_meta() );
	}

	/**
	 * A validly signed token for an arbitrary issue time.
	 */
	protected function token( $form_id, $ts ) {
		return BorsFlow_Spam::issue_token( $form_id, $ts );
	}

	protected function scheduled( $hook, $args ) {
		return wp_next_scheduled( $hook, $args );
	}
}
