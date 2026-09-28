<?php
/**
 * Mock BorsFlow CRM for local development. NOT part of the plugin runtime.
 *
 * Run:   BORSFLOW_MOCK_KEY=test-key php -S 127.0.0.1:4000 tools/mock-crm/server.php
 * Then:  CRM base URL = http://127.0.0.1:4000, API key = test-key
 *
 * Endpoints
 *   GET  /api/health          200 with a valid Bearer key, else 401
 *   POST /api/leads           Creates a lead (201). Same Idempotency-Key => same lead (200).
 *   GET  /api/leads           Lists stored leads (debugging).
 *   GET  /__mode?set=MODE     Switch behaviour: ok | fail500 | fail401 | fail422 | slow | flaky
 *                              flaky = every lead fails twice with 503 before succeeding.
 *   GET  /__reset             Delete stored leads and reset mode.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- standalone dev tool, not loaded by WordPress.

$key   = getenv( 'BORSFLOW_MOCK_KEY' ) ?: 'test-key';
$store = sys_get_temp_dir() . '/borsflow-mock-crm.json';
$state = is_file( $store ) ? json_decode( file_get_contents( $store ), true ) : null;
$state = is_array( $state ) ? $state : array( 'mode' => 'ok', 'leads' => array(), 'attempts' => array(), 'seq' => 1000 );

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

function respond( $code, $body ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	error_log( sprintf( '[mock-crm] %s %s -> %d', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $code ) );
	exit;
}

function save_state( $store, $state ) {
	file_put_contents( $store, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

if ( '/__mode' === $path ) {
	$mode = $_GET['set'] ?? 'ok';
	if ( ! in_array( $mode, array( 'ok', 'fail500', 'fail401', 'fail422', 'slow', 'flaky' ), true ) ) {
		respond( 400, array( 'message' => 'Unknown mode' ) );
	}
	$state['mode'] = $mode;
	save_state( $store, $state );
	respond( 200, array( 'mode' => $mode ) );
}

if ( '/__reset' === $path ) {
	@unlink( $store );
	respond( 200, array( 'reset' => true ) );
}

// Auth for everything under /api.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ( 'Bearer ' . $key !== $auth || 'fail401' === $state['mode'] ) {
	respond( 401, array( 'message' => 'Invalid API key' ) );
}

if ( 'GET' === $method && '/api/health' === $path ) {
	respond( 200, array( 'ok' => true, 'service' => 'borsflow-mock-crm' ) );
}

if ( 'GET' === $method && '/api/leads' === $path ) {
	respond( 200, array( 'data' => array_values( $state['leads'] ) ) );
}

if ( 'POST' === $method && '/api/leads' === $path ) {
	$idem = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
	$body = json_decode( file_get_contents( 'php://input' ), true );

	switch ( $state['mode'] ) {
		case 'fail500':
			respond( 500, array( 'message' => 'Simulated server error' ) );
		case 'fail422':
			respond( 422, array( 'message' => 'Simulated validation error: email is invalid' ) );
		case 'slow':
			sleep( 20 );
			break;
		case 'flaky':
			$state['attempts'][ $idem ] = ( $state['attempts'][ $idem ] ?? 0 ) + 1;
			save_state( $store, $state );
			if ( $state['attempts'][ $idem ] <= 2 ) {
				respond( 503, array( 'message' => 'Simulated temporary outage' ) );
			}
			break;
	}

	if ( ! is_array( $body ) ) {
		respond( 400, array( 'message' => 'Body must be JSON' ) );
	}
	if ( '' !== $idem && isset( $state['leads'][ $idem ] ) ) {
		respond( 200, array( 'id' => $state['leads'][ $idem ]['id'], 'duplicate' => true ) );
	}
	if ( empty( $body['email'] ) && empty( $body['phone'] ) && empty( $body['firstName'] ) ) {
		respond( 422, array( 'message' => 'A lead needs at least an email, phone or name' ) );
	}

	$id    = 'lead_' . ( ++$state['seq'] );
	$lead  = array( 'id' => $id, 'receivedAt' => gmdate( 'c' ), 'idempotencyKey' => $idem ) + $body;
	$state['leads'][ '' !== $idem ? $idem : $id ] = $lead;
	save_state( $store, $state );
	respond( 201, array( 'id' => $id, 'data' => $lead ) );
}

respond( 404, array( 'message' => 'Not found' ) );
