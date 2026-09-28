<?php
/**
 * CRM sync: lead building, HTTP contract, retries and idempotency.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class SyncTest extends BorsFlow_TestCase {

	private $form_id;

	protected function set_up() {
		parent::set_up();
		$this->configure_crm();
		$this->form_id = $this->make_form(
			array(
				$this->field( 'text', 'name' ),
				$this->field( 'email', 'email' ),
				$this->field( 'text', 'company' ),
				$this->field( 'number', 'budget' ),
				$this->field( 'textarea', 'message' ),
				$this->field( 'checkboxes', 'interests', array( 'options' => array( array( 'label' => 'A', 'value' => 'a' ), array( 'label' => 'B', 'value' => 'b' ) ) ) ),
			),
			array(
				'crm' => array(
					'enabled' => true,
					'mapping' => array( 'name' => 'fullName', 'email' => 'email', 'company' => 'company', 'budget' => 'value', 'message' => 'notes' ),
					'source'  => 'landing-page',
					'stage'   => 'new',
				),
			)
		);
	}

	private function submission() {
		return $this->submit( $this->form_id, array( 'name' => 'Ada King Lovelace', 'email' => 'ada@example.com', 'company' => 'Analytical', 'budget' => '2500', 'message' => 'Hello', 'interests' => array( 'a', 'b' ) ) )['submission_id'];
	}

	public function test_lead_payload_mapping() {
		$lead = BorsFlow_Sync::build_lead( BorsFlow_Submissions::get( $this->submission() ), BorsFlow_Form::get( $this->form_id ) );
		$this->assertSame( 'Ada', $lead['firstName'] );
		$this->assertSame( 'King Lovelace', $lead['lastName'] );
		$this->assertSame( 2500, $lead['value'] );
		$this->assertSame( "Hello\n\nInterests: a, b", $lead['notes'], 'Mapped notes first, then unmapped fields as Label: value.' );
		$this->assertSame( 'landing-page', $lead['source'] );
		$this->assertSame( 'new', $lead['stage'] );
		$this->assertArrayNotHasKey( 'pipeline', $lead, 'Empty overrides are omitted.' );
		$this->assertSame( $this->form_id, $lead['metadata']['formId'] );
	}

	public function test_successful_sync_sends_auth_and_idempotency_key() {
		$id = $this->submission();
		$this->mock_http( fn() => array( 201, array( 'data' => array( 'id' => 'lead_42' ) ) ) );
		$this->assertTrue( BorsFlow_Sync::run( $id )['ok'] );

		$req = $this->requests[0];
		$this->assertSame( 'http://crm.test/api/leads', $req['url'] );
		$this->assertSame( 'Bearer secret-key-123456', $req['args']['headers']['Authorization'] );
		$this->assertSame( BorsFlow_Sync::idempotency_key( $id ), $req['args']['headers']['Idempotency-Key'] );
		$this->assertSame( 'ada@example.com', json_decode( $req['args']['body'], true )['email'] );

		$row = BorsFlow_Submissions::get( $id );
		$this->assertSame( 'synced', $row['sync_status'] );
		$this->assertSame( 'lead_42', $row['crm_lead_id'] );
		$this->assertCount( 1, BorsFlow_Submissions::logs( $id ) );
	}

	public function test_retryable_failure_backs_off_then_gives_up() {
		$id = $this->submission();
		$this->set_setting( 'crm_max_attempts', 3 );
		$this->mock_http( fn() => array( 503, array( 'message' => 'down' ) ) );

		BorsFlow_Sync::run( $id );
		$row = BorsFlow_Submissions::get( $id );
		$this->assertSame( 'failed', $row['sync_status'] );
		$this->assertSame( 'HTTP 503: down', $row['last_error'] );
		$this->assertEqualsWithDelta( time() + 60, $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ), 5 );

		BorsFlow_Sync::run( $id );
		$this->assertEqualsWithDelta( time() + 300, $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ), 5 );

		BorsFlow_Sync::run( $id );
		$this->assertFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ), 'No retry after max attempts.' );
		$this->assertSame( 3, (int) BorsFlow_Submissions::get( $id )['sync_attempts'] );
	}

	public function test_network_error_is_retried() {
		$id = $this->submission();
		$this->mock_http( fn() => new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		BorsFlow_Sync::run( $id );
		$this->assertNotFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ) );
	}

	public function test_client_error_is_not_retried_and_clears_queue() {
		$id = $this->submission();
		$this->assertNotFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ), 'Queued at submission time.' );
		$this->mock_http( fn() => array( 422, array( 'message' => 'bad email' ) ) );
		BorsFlow_Sync::run( $id, 'manual' );
		$this->assertFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ) );
		$this->assertNull( BorsFlow_Submissions::get( $id )['next_retry_at'] );
	}

	public function test_conflict_with_lead_id_counts_as_synced() {
		$id = $this->submission();
		$this->mock_http( fn() => array( 409, array( 'id' => 'lead_existing' ) ) );
		BorsFlow_Sync::run( $id );
		$this->assertSame( 'lead_existing', BorsFlow_Submissions::get( $id )['crm_lead_id'] );
	}

	public function test_synced_submission_is_never_resent() {
		$id = $this->submission();
		$this->mock_http( fn() => array( 201, array( 'id' => 'x' ) ) );
		BorsFlow_Sync::run( $id );
		BorsFlow_Sync::run( $id );
		$this->assertCount( 1, $this->requests );
	}

	public function test_concurrent_run_is_blocked_by_lock() {
		$id = $this->submission();
		$this->assertTrue( BorsFlow_Submissions::claim( $id ) );
		$this->mock_http( fn() => array( 201, array( 'id' => 'x' ) ) );
		$this->assertFalse( BorsFlow_Sync::run( $id )['ok'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_api_key_never_appears_in_errors() {
		$id = $this->submission();
		$this->mock_http( fn() => array( 401, array( 'message' => 'Bad token secret-key-123456' ) ) );
		BorsFlow_Sync::run( $id );
		$row  = BorsFlow_Submissions::get( $id );
		$logs = BorsFlow_Submissions::logs( $id );
		$this->assertStringNotContainsString( 'secret-key-123456', $row['last_error'] );
		$this->assertStringNotContainsString( 'secret-key-123456', $logs[0]['message'] );
	}

	public function test_deleted_form_still_syncs_email() {
		$id = $this->submission();
		BorsFlow_Form::delete( $this->form_id );
		$lead = BorsFlow_Sync::build_lead( BorsFlow_Submissions::get( $id ), null );
		$this->assertSame( 'ada@example.com', $lead['email'] );
	}

	public function test_bulk_retry_requeues() {
		$id = $this->submission();
		$this->mock_http( fn() => array( 422, array() ) );
		BorsFlow_Sync::run( $id );
		BorsFlow_Sync::queue_retry( $id );
		$this->assertSame( 'pending', BorsFlow_Submissions::get( $id )['sync_status'] );
		$this->assertNotFalse( $this->scheduled( BorsFlow_Sync::HOOK, array( $id ) ) );
	}
}
