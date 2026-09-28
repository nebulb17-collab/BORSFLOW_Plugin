<?php
/**
 * Seed the e2e site: CRM settings, a contact form with conditional logic, and pages embedding it.
 *
 * Usage: php tests/e2e/fixtures.php <wp dir> <crm url>
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test tooling.

$_SERVER['HTTP_HOST'] = 'localhost';
require rtrim( $argv[1], '/' ) . '/wp-load.php';

update_option(
	BorsFlow_Settings::OPTION,
	array_merge( BorsFlow_Settings::all(), array( 'crm_base_url' => $argv[2], 'crm_api_key' => 'test-key', 'rate_limit_max' => 0 ) )
);

$id       = BorsFlow_Form::create( 'Contact us' );
$form     = BorsFlow_Form::get( $id );
$fields   = $form['fields'];
$fields[] = array( 'id' => 'f_topic', 'type' => 'select', 'key' => 'topic', 'label' => 'Topic', 'required' => true, 'options' => array( array( 'label' => 'Sales', 'value' => 'sales' ), array( 'label' => 'Support', 'value' => 'support' ) ) );
$fields[] = array( 'id' => 'f_budget', 'type' => 'number', 'key' => 'budget', 'label' => 'Budget', 'required' => true, 'min' => '100', 'conditions' => array( 'enabled' => true, 'action' => 'show', 'logic' => 'and', 'rules' => array( array( 'field' => 'topic', 'operator' => 'equals', 'value' => 'sales' ) ) ) );
$fields[] = array( 'id' => 'f_code', 'type' => 'text', 'key' => 'code', 'label' => 'Promo code', 'pattern' => '[A-Z]{2}\d{2}', 'pattern_message' => 'Use two letters and two digits' );
$fields[] = array( 'id' => 'f_consent', 'type' => 'consent', 'key' => 'consent', 'label' => 'Consent', 'required' => true, 'content' => 'I agree' );
$settings                        = $form['settings'];
$settings['crm']['mapping']      = array( 'name' => 'fullName', 'email' => 'email', 'budget' => 'value' );
$settings['notify']['body']      = "{all_fields}\n\nTopic was {topic}";
BorsFlow_Form::save( $id, 'Contact us', $fields, $settings, true );

$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact', 'post_content' => '[borsflow_form id="' . $id . '"]' ) );

file_put_contents( rtrim( $argv[1], '/' ) . '/e2e.json', json_encode( array( 'formId' => $id, 'pageId' => $page ) ) );
echo "Seeded form $id on page $page\n";
