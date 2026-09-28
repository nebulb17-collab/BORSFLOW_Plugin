<?php
/**
 * The public submission pipeline: validation, spam checks, storage, queuing.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class SubmissionTest extends BorsFlow_TestCase {

	private function contact_form( $settings = array() ) {
		return $this->make_form(
			array(
				$this->field( 'text', 'name', array( 'required' => true ) ),
				$this->field( 'email', 'email', array( 'required' => true ) ),
				$this->field( 'select', 'topic', array( 'options' => array( array( 'label' => 'Sales', 'value' => 'sales' ), array( 'label' => 'Support', 'value' => 'support' ) ) ) ),
				$this->field(
					'number',
					'budget',
					array(
						'required'   => true,
						'conditions' => array( 'enabled' => true, 'action' => 'show', 'logic' => 'and', 'rules' => array( array( 'field' => 'topic', 'operator' => 'equals', 'value' => 'sales' ) ) ),
					)
				),
			),
			$settings
		);
	}

	private function count_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BorsFlow_Submissions::table() );
	}

	public function test_valid_submission_is_stored_before_sync_and_queued() {
		$this->configure_crm();
		$id     = $this->contact_form();
		$result = $this->submit( $id, array( 'name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'support', 'budget' => '999' ) );

		$this->assertTrue( $result['success'] );
		$row = BorsFlow_Submissions::get( $result['submission_id'] );
		$this->assertSame( 'pending', $row['sync_status'] );
		$this->assertSame( 'Ada', $row['payload']['name']['value'] );
		$this->assertArrayNotHasKey( 'budget', $row['payload'], 'Fields hidden by logic are not stored.' );
		$this->assertSame( '203.0.113.10', $row['ip'] );
		$this->assertNotFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( (int) $row['id'] ) ) );
		$this->assertNotFalse( $this->scheduled( BorsFlow_Mailer::HOOK, array( (int) $row['id'] ) ) );
		$this->assertCount( 0, $this->requests, 'The visitor never waits on the CRM.' );
	}

	public function test_without_crm_submission_is_skipped_not_pending() {
		$result = $this->submit( $this->contact_form(), array( 'name' => 'Ada', 'email' => 'ada@example.com' ) );
		$this->assertSame( 'skipped', BorsFlow_Submissions::get( $result['submission_id'] )['sync_status'] );
	}

	public function test_conditionally_visible_required_field_is_enforced() {
		$result = $this->submit( $this->contact_form(), array( 'name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'sales' ) );
		$this->assertSame( 422, $result['status'] );
		$this->assertArrayHasKey( 'budget', $result['errors'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_invalid_values_are_reported_per_field() {
		$result = $this->submit( $this->contact_form(), array( 'name' => '', 'email' => 'nope', 'topic' => 'hacker' ) );
		$this->assertSame( array( 'name', 'email', 'topic' ), array_keys( $result['errors'] ) );
	}

	public function test_honeypot_pretends_success_and_stores_nothing() {
		$result = $this->submit( $this->contact_form(), array( 'name' => 'Bot', 'email' => 'b@example.com' ), array( BorsFlow_Renderer::HONEYPOT => 'http://spam' ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_forged_and_expired_tokens_are_rejected() {
		$id = $this->contact_form();
		$this->assertSame( 400, $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ), array( 'bf_token' => '1700000000.' . str_repeat( 'a', 32 ) ) )['status'] );
		$this->assertSame( 400, $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ), array( 'bf_token' => BorsFlow_Spam::issue_token( $id + 1 ) ) )['status'], 'Tokens are bound to their form.' );

		$this->set_setting( 'token_max_age', 2 ); // Hours.
		$old = BorsFlow_Spam::issue_token( $id, time() - 3 * HOUR_IN_SECONDS );
		$this->assertSame( 400, $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ), array( 'bf_token' => $old ) )['status'] );

		$this->set_setting( 'token_max_age', 0 );
		$this->assertTrue( $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ), array( 'bf_token' => $old ) )['success'], '0 disables expiry.' );
	}

	public function test_token_refresh_endpoint_issues_current_token() {
		$id  = $this->contact_form();
		$res = rest_do_request( new WP_REST_Request( 'GET', '/borsflow/v1/forms/' . $id . '/token' ) );
		$this->assertSame( 200, $res->get_status() );
		$ts = BorsFlow_Spam::token_time( $id, $res->get_data()['token'] );
		$this->assertEqualsWithDelta( time(), $ts, 5 );
	}

	public function test_time_trap_silently_drops_fast_submissions() {
		$id = $this->contact_form( array( 'spam' => array( 'time_trap' => true, 'time_trap_seconds' => 5 ) ) );
		$fast = $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ), array( 'bf_token' => BorsFlow_Spam::issue_token( $id ) ) );
		$this->assertTrue( $fast['success'] );
		$this->assertSame( 0, $this->count_rows() );
		$this->assertTrue( $this->submit( $id, array( 'name' => 'A', 'email' => 'a@example.com' ) )['success'] );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_rate_limit_per_ip_per_form() {
		$this->set_setting( 'rate_limit_max', 2 );
		$id    = $this->contact_form();
		$other = $this->contact_form();
		$ok    = array( 'name' => 'A', 'email' => 'a@example.com' );
		$this->assertTrue( $this->submit( $id, $ok )['success'] );
		$this->assertTrue( $this->submit( $id, $ok )['success'] );
		$this->assertSame( 429, $this->submit( $id, $ok )['status'] );
		$this->assertTrue( $this->submit( $other, $ok )['success'], 'Limits are per form.' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertTrue( $this->submit( $id, $ok )['success'], 'Limits are per IP.' );
	}

	public function test_ip_not_stored_when_disabled() {
		$this->set_setting( 'store_ip', 0 );
		$row = BorsFlow_Submissions::get( $this->submit( $this->contact_form(), array( 'name' => 'A', 'email' => 'a@example.com' ) )['submission_id'] );
		$this->assertSame( '', $row['ip'] );
		$this->assertSame( '', $row['user_agent'] );
	}

	public function test_rest_route_end_to_end() {
		$id  = $this->contact_form();
		$req = new WP_REST_Request( 'POST', '/borsflow/v1/forms/' . $id . '/submit' );
		$req->set_body_params(
			array(
				'bf'       => array( 'name' => 'Ada', 'email' => 'ada@example.com' ),
				'bf_token' => BorsFlow_Spam::issue_token( $id, time() - 30 ),
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['success'] );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_admin_routes_require_capability() {
		$id = $this->contact_form();
		foreach ( array( array( 'POST', '/borsflow/v1/admin/forms/' . $id ), array( 'POST', '/borsflow/v1/admin/preview' ), array( 'POST', '/borsflow/v1/admin/test-connection' ) ) as $route ) {
			$this->assertSame( 401, rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status(), $route[1] );
		}
		wp_set_current_user( 1 );
		$req = new WP_REST_Request( 'POST', '/borsflow/v1/admin/preview' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'fields' => array( array( 'type' => 'text', 'key' => 'x', 'label' => 'X' ) ) ) ) );
		$this->assertStringContainsString( 'bf[x]', rest_do_request( $req )->get_data()['html'] );
	}
}
