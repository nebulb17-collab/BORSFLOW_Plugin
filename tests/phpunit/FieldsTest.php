<?php
/**
 * Validation, sanitization and conditional logic.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class FieldsTest extends BorsFlow_TestCase {

	private function def( $type, $extra = array() ) {
		return array_replace( BorsFlow_Fields::blank( $type ), array( 'id' => 'f1', 'key' => 'k' ), $extra );
	}

	/** @dataProvider provide_rules */
	public function test_rule_matches( $operator, $expected, $actual, $result ) {
		$this->assertSame( $result, BorsFlow_Fields::rule_matches( array( 'operator' => $operator, 'value' => $expected ), $actual ) );
	}

	public function provide_rules() {
		return array(
			'equals string'          => array( 'equals', 'sales', 'sales', true ),
			'equals is exact'        => array( 'equals', 'sales', 'Sales', false ),
			'equals in array'        => array( 'equals', 'b', array( 'a', 'b' ), true ),
			'not equals'             => array( 'not_equals', 'sales', 'support', true ),
			'not equals empty'       => array( 'not_equals', 'sales', '', true ),
			'contains ci'            => array( 'contains', 'ACME', 'Acme Corp', true ),
			'contains empty needle'  => array( 'contains', '', 'anything', false ),
			'contains array element' => array( 'contains', 'lo', array( 'hello', 'x' ), true ),
			'empty'                  => array( 'empty', '', '', true ),
			'empty array'            => array( 'empty', '', array(), true ),
			'not empty'              => array( 'not_empty', '', 'x', true ),
		);
	}

	public function test_visibility_treats_hidden_source_as_empty() {
		$topic  = $this->def( 'select', array( 'id' => 'topic', 'key' => 'topic' ) );
		$budget = $this->def( 'number', array( 'id' => 'budget', 'key' => 'budget', 'conditions' => array( 'enabled' => true, 'action' => 'show', 'logic' => 'and', 'rules' => array( array( 'field' => 'topic', 'operator' => 'equals', 'value' => 'sales' ) ) ) ) );
		// Shown when budget is filled; but budget is hidden unless topic = sales.
		$note = $this->def( 'text', array( 'id' => 'note', 'key' => 'note', 'conditions' => array( 'enabled' => true, 'action' => 'show', 'logic' => 'and', 'rules' => array( array( 'field' => 'budget', 'operator' => 'not_empty', 'value' => '' ) ) ) ) );
		$fields = array( $topic, $budget, $note );

		$v = BorsFlow_Fields::visibility( $fields, array( 'topic' => 'support', 'budget' => '500', 'note' => '' ) );
		$this->assertFalse( $v['budget'] );
		$this->assertFalse( $v['note'], 'A stale value in a hidden field must not reveal dependants.' );

		$v = BorsFlow_Fields::visibility( $fields, array( 'topic' => 'sales', 'budget' => '500' ) );
		$this->assertTrue( $v['budget'] );
		$this->assertTrue( $v['note'] );
	}

	public function test_visibility_or_and_hide() {
		$f = $this->def(
			'text',
			array(
				'conditions' => array(
					'enabled' => true,
					'action'  => 'hide',
					'logic'   => 'or',
					'rules'   => array(
						array( 'field' => 'a', 'operator' => 'equals', 'value' => '1' ),
						array( 'field' => 'b', 'operator' => 'equals', 'value' => '1' ),
					),
				),
			)
		);
		$a = $this->def( 'text', array( 'id' => 'a', 'key' => 'a' ) );
		$b = $this->def( 'text', array( 'id' => 'b', 'key' => 'b' ) );
		$this->assertFalse( BorsFlow_Fields::visibility( array( $a, $b, $f ), array( 'a' => '0', 'b' => '1' ) )['f1'] );
		$this->assertTrue( BorsFlow_Fields::visibility( array( $a, $b, $f ), array( 'a' => '0', 'b' => '0' ) )['f1'] );
	}

	public function test_required_and_types() {
		$this->assertSame( 'This field is required.', BorsFlow_Fields::validate( $this->def( 'text', array( 'required' => true ) ), '' ) );
		$this->assertSame( '', BorsFlow_Fields::validate( $this->def( 'text' ), '' ) );
		$this->assertStringContainsString( 'email', BorsFlow_Fields::validate( $this->def( 'email' ), '', 'not-an-email' ) );
		$this->assertSame( '', BorsFlow_Fields::validate( $this->def( 'email' ), 'a@example.com' ) );
		$this->assertStringContainsString( 'phone', BorsFlow_Fields::validate( $this->def( 'phone' ), '12' ) );
		$this->assertSame( '', BorsFlow_Fields::validate( $this->def( 'phone' ), '+44 20 7946 0958' ) );
		$this->assertStringContainsString( 'at least 10', BorsFlow_Fields::validate( $this->def( 'number', array( 'min' => '10' ) ), '5' ) );
		$this->assertStringContainsString( 'valid date', BorsFlow_Fields::validate( $this->def( 'date' ), '2026-02-30' ) );
		$this->assertStringContainsString( 'at least 3', BorsFlow_Fields::validate( $this->def( 'text', array( 'min_length' => 3 ) ), 'ab' ) );
		$this->assertStringContainsString( 'check this box', BorsFlow_Fields::validate( $this->def( 'consent', array( 'required' => true ) ), '' ) );
	}

	public function test_choice_values_must_be_options() {
		$select = $this->def( 'select', array( 'options' => array( array( 'label' => 'A', 'value' => 'a' ) ) ) );
		$this->assertSame( '', BorsFlow_Fields::validate( $select, 'a' ) );
		$this->assertStringContainsString( 'valid option', BorsFlow_Fields::validate( $select, 'evil' ) );
		$multi = $this->def( 'checkboxes', array( 'options' => array( array( 'label' => 'A', 'value' => 'a' ) ) ) );
		$this->assertStringContainsString( 'valid option', BorsFlow_Fields::validate( $multi, array( 'a', 'b' ) ) );
	}

	public function test_pattern_uses_custom_message_and_full_match() {
		$f = $this->def( 'text', array( 'pattern' => '[A-Z]{2}\d{2}', 'pattern_message' => 'Use AB12' ) );
		$this->assertSame( 'Use AB12', BorsFlow_Fields::validate( $f, 'AB123' ), 'Pattern must match the whole value.' );
		$this->assertSame( '', BorsFlow_Fields::validate( $f, 'AB12' ) );
	}

	public function test_invalid_pattern_is_dropped_on_save() {
		$f = BorsFlow_Fields::sanitize_definition( array( 'type' => 'text', 'key' => 'x', 'pattern' => '([a-z' ) );
		$this->assertSame( '', $f['pattern'] );
	}

	public function test_field_keys_are_normalised() {
		$this->assertSame( 'first_name', BorsFlow_Fields::sanitize_field_key( 'First Name!' ) );
		$this->assertSame( 'cafe', BorsFlow_Fields::sanitize_field_key( 'Café' ) );
	}

	public function test_sanitize_value_by_type() {
		$this->assertSame( array( 'a', 'b' ), BorsFlow_Fields::sanitize_value( $this->def( 'checkboxes' ), array( 'a', '<b>b</b>', '' ) ) );
		$this->assertSame( 'yes', BorsFlow_Fields::sanitize_value( $this->def( 'consent' ), 'on' ) );
		$this->assertSame( '', BorsFlow_Fields::sanitize_value( $this->def( 'text' ), array( 'x' ) ), 'Arrays are rejected for scalar fields.' );
		$this->assertSame( "line1\nline2", BorsFlow_Fields::sanitize_value( $this->def( 'textarea' ), "line1\nline2<script>" ) );
	}
}
