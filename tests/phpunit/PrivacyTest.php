<?php
/**
 * Personal data exporter / eraser, retention and migrations.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class PrivacyTest extends BorsFlow_TestCase {

	private $form_id;

	protected function set_up() {
		parent::set_up();
		$this->form_id = $this->make_form( array( $this->field( 'text', 'name' ), $this->field( 'email', 'email' ), $this->field( 'textarea', 'message' ) ) );
	}

	private function add( $email, $message = '' ) {
		$result = $this->submit( $this->form_id, array( 'name' => 'Someone', 'email' => $email, 'message' => $message ) );
		$this->assertTrue( $result['success'], 'Fixture submission failed: ' . $result['message'] );
		return $result['submission_id'];
	}

	public function test_exporter_is_registered_and_matches_exact_email_only() {
		$this->assertArrayHasKey( 'borsflow-forms', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$mine    = $this->add( 'ada@example.com' );
		$this->add( 'notada@example.com' );
		$this->add( 'bob@example.com', 'Please forward to ada@example.com' );

		$export = BorsFlow_Privacy::export( 'ADA@example.com', 1 );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'borsflow-submission-' . $mine, $export['data'][0]['item_id'] );
		$this->assertContains( 'ada@example.com', wp_list_pluck( $export['data'][0]['data'], 'value' ) );
	}

	public function test_eraser_deletes_all_matching_submissions() {
		for ( $i = 0; $i < BorsFlow_Privacy::PAGE_SIZE + 5; $i++ ) {
			$this->add( 'ada@example.com' );
		}
		$keep   = $this->add( 'bob@example.com', 'mentions ada@example.com' );
		$result = BorsFlow_Privacy::erase( 'ada@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, BorsFlow_Submissions::query( array() )['total'] );
		$this->assertNotNull( BorsFlow_Submissions::get( $keep ) );
	}

	public function test_retention_deletes_old_submissions_only() {
		global $wpdb;
		$old = $this->add( 'a@example.com' );
		$new = $this->add( 'b@example.com' );
		$wpdb->update( BorsFlow_Submissions::table(), array( 'created_at' => '2020-01-01 00:00:00' ), array( 'id' => $old ) );

		BorsFlow_Maintenance::apply_retention();
		$this->assertNotNull( BorsFlow_Submissions::get( $old ), 'Retention 0 keeps everything.' );

		$this->set_setting( 'retention_days', 30 );
		BorsFlow_Maintenance::apply_retention();
		$this->assertNull( BorsFlow_Submissions::get( $old ) );
		$this->assertNotNull( BorsFlow_Submissions::get( $new ) );
	}

	public function test_migration_to_1_1_marks_existing_emails_sent() {
		global $wpdb;
		$id = $this->add( 'a@example.com' );
		$wpdb->update( BorsFlow_Submissions::table(), array( 'emails_sent' => 0 ), array( 'id' => $id ) );
		update_option( 'borsflow_db_version', '1.0.0' );

		BorsFlow_Installer::maybe_upgrade();

		$this->assertSame( 1, (int) BorsFlow_Submissions::get( $id )['emails_sent'] );
		$this->assertSame( BORSFLOW_DB_VERSION, get_option( 'borsflow_db_version' ) );
	}
}
