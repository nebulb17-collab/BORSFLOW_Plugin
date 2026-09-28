<?php
/**
 * Form storage and sanitization.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class FormTest extends BorsFlow_TestCase {

	public function test_create_seeds_starter_fields_and_mapping() {
		$form = BorsFlow_Form::get( BorsFlow_Form::create( 'Contact' ) );
		$this->assertSame( array( 'name', 'email', 'message' ), wp_list_pluck( $form['fields'], 'key' ) );
		$this->assertSame( 'email', $form['settings']['notify']['reply_to_field'] );
		$this->assertSame( 'fullName', $form['settings']['crm']['mapping']['name'] );
		$this->assertTrue( $form['enabled'] );
	}

	public function test_keys_are_unique_and_avoid_reserved_names() {
		$fields = BorsFlow_Form::sanitize_fields(
			array(
				array( 'type' => 'text', 'key' => 'email' ),
				array( 'type' => 'email', 'key' => 'email' ),
				array( 'type' => 'text', 'key' => 'form_title' ),
				array( 'type' => 'text', 'label' => 'Company Name' ),
				array( 'type' => 'heading', 'key' => 'ignored', 'content' => 'Hi' ),
				array( 'type' => 'bogus', 'key' => 'nope' ),
			)
		);
		$this->assertSame( array( 'email', 'email_2', 'form_title_2', 'company_name', '' ), wp_list_pluck( $fields, 'key' ) );
	}

	public function test_dangling_condition_rules_are_removed() {
		$fields = BorsFlow_Form::sanitize_fields(
			array(
				array( 'type' => 'text', 'key' => 'a' ),
				array(
					'type'       => 'text',
					'key'        => 'b',
					'conditions' => array(
						'enabled' => true,
						'rules'   => array(
							array( 'field' => 'deleted_field', 'operator' => 'equals', 'value' => 'x' ),
							array( 'field' => 'b', 'operator' => 'equals', 'value' => 'self' ),
						),
					),
				),
				array(
					'type'       => 'text',
					'key'        => 'c',
					'conditions' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'a', 'operator' => 'equals', 'value' => '1' ) ) ),
				),
			)
		);
		$this->assertSame( array(), $fields[1]['conditions']['rules'] );
		$this->assertFalse( $fields[1]['conditions']['enabled'] );
		$this->assertCount( 1, $fields[2]['conditions']['rules'] );
	}

	public function test_settings_drop_mappings_and_bindings_to_unknown_fields() {
		$fields   = BorsFlow_Form::sanitize_fields( array( array( 'type' => 'email', 'key' => 'email' ) ) );
		$settings = BorsFlow_Form::sanitize_settings(
			array(
				'crm'    => array( 'enabled' => true, 'mapping' => array( 'email' => 'email', 'gone' => 'phone', 'email2' => 'notARealField' ) ),
				'notify' => array( 'enabled' => true, 'reply_to_field' => 'gone' ),
				'style'  => array( 'accent' => 'red;}body{display:none', 'radius' => 999 ),
			),
			$fields
		);
		$this->assertSame( array( 'email' => 'email' ), $settings['crm']['mapping'] );
		$this->assertSame( '', $settings['notify']['reply_to_field'] );
		$this->assertSame( '#2271b1', $settings['style']['accent'], 'Invalid colours fall back to the default.' );
		$this->assertSame( 32, $settings['style']['radius'] );
	}

	public function test_regex_backslashes_survive_a_save_round_trip() {
		$id = $this->make_form( array( $this->field( 'text', 'code', array( 'pattern' => '\d{3}-\w+' ) ) ) );
		$this->assertSame( '\d{3}-\w+', BorsFlow_Form::get( $id )['fields'][0]['pattern'] );
	}

	public function test_duplicate_is_disabled_copy() {
		$id   = $this->make_form( array( $this->field( 'text', 'a' ) ) );
		$copy = BorsFlow_Form::get( BorsFlow_Form::duplicate( $id ) );
		$this->assertFalse( $copy['enabled'] );
		$this->assertStringContainsString( '(copy)', $copy['title'] );
		$this->assertSame( 'a', $copy['fields'][0]['key'] );
	}

	public function test_disabled_form_does_not_render_or_accept_submissions() {
		$id = $this->make_form( array( $this->field( 'text', 'a' ) ), array(), false );
		$this->assertSame( '', BorsFlow_Renderer::render_by_id( $id ) );
		$this->assertSame( 404, $this->submit( $id, array( 'a' => 'x' ) )['status'] );
	}
}
