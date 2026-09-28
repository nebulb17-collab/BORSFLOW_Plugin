<?php
/**
 * Notification emails.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class MailerTest extends BorsFlow_TestCase {

	private function form() {
		return $this->make_form(
			array( $this->field( 'text', 'name' ), $this->field( 'email', 'email' ) ),
			array(
				'notify'        => array( 'enabled' => true, 'recipients' => 'team@example.com, {admin_email}, not-an-email', 'subject' => "New from {name}\r\nBcc: x@evil.test", 'body' => 'Hi {name} {all_fields}', 'reply_to_field' => 'email' ),
				'autoresponder' => array( 'enabled' => true, 'to_field' => 'email', 'subject' => 'Thanks {name}', 'body' => 'We got it.' ),
			)
		);
	}

	public function test_emails_are_queued_then_sent_once() {
		$id  = $this->submit( $this->form(), array( 'name' => '<b>Ada</b>', 'email' => 'ada@example.com' ) )['submission_id'];
		$this->assertCount( 0, $this->mails, 'Nothing is sent during the request.' );

		BorsFlow_Mailer::send_queued( $id );
		BorsFlow_Mailer::send_queued( $id );
		$this->assertCount( 2, $this->mails, 'Notification + autoresponder, exactly once.' );

		$notify = $this->mails[0];
		$this->assertSame( array( 'team@example.com', 'admin@example.com' ), array_values( $notify['to'] ) );
		$this->assertStringNotContainsString( "\n", $notify['subject'], 'Header injection via subject is neutralised.' );
		$this->assertContains( 'Reply-To: ada@example.com', $notify['headers'] );
		$this->assertStringNotContainsString( '<b>Ada</b>', $notify['message'], 'Submitted values are escaped.' );
		$this->assertStringContainsString( '<table', $notify['message'] );

		$this->assertSame( 'ada@example.com', $this->mails[1]['to'] );
	}

	public function test_sync_mode_sends_during_request() {
		$this->set_setting( 'email_async', 0 );
		$id = $this->submit( $this->form(), array( 'name' => 'Ada', 'email' => 'ada@example.com' ) )['submission_id'];
		$this->assertCount( 2, $this->mails );
		BorsFlow_Mailer::send_queued( $id );
		$this->assertCount( 2, $this->mails, 'Already claimed; no duplicates.' );
	}

	public function test_maintenance_sends_emails_whose_event_was_lost() {
		global $wpdb;
		$id = $this->submit( $this->form(), array( 'name' => 'Ada', 'email' => 'ada@example.com' ) )['submission_id'];
		_set_cron_array( array() );
		$wpdb->update( BorsFlow_Submissions::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
		BorsFlow_Maintenance::send_overdue_emails();
		$this->assertCount( 2, $this->mails );
	}
}
