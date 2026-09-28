<?php
/**
 * Client IP detection, settings secrets, CSV safety, uploads path confinement.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test code.

class SecurityTest extends BorsFlow_TestCase {

	public function test_forwarded_headers_ignored_unless_trusted() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$this->assertSame( '203.0.113.10', BorsFlow_Spam::client_ip() );
	}

	public function test_cloudflare_header_when_trusted() {
		$this->set_setting( 'proxy_header', 'HTTP_CF_CONNECTING_IP' );
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.23';
		$this->assertSame( '198.51.100.23', BorsFlow_Spam::client_ip() );
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'garbage';
		$this->assertSame( '203.0.113.10', BorsFlow_Spam::client_ip(), 'Invalid header falls back to REMOTE_ADDR.' );
	}

	public function test_x_forwarded_for_uses_rightmost_public_address() {
		$this->set_setting( 'proxy_header', 'HTTP_X_FORWARDED_FOR' );
		// Client forged "8.8.8.8"; the proxy appended the real client, then our internal LB.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 198.51.100.99, 10.0.0.5';
		$this->assertSame( '198.51.100.99', BorsFlow_Spam::client_ip() );
	}

	public function test_blank_secret_keeps_saved_value_and_clear_removes_it() {
		$this->configure_crm();
		$out = BorsFlow_Settings::sanitize( array( 'crm_base_url' => 'http://crm.test', 'crm_api_key' => '' ) );
		$this->assertSame( 'secret-key-123456', $out['crm_api_key'] );
		$out = BorsFlow_Settings::sanitize( array( 'crm_api_key' => '', 'crm_api_key_clear' => '1' ) );
		$this->assertSame( '', $out['crm_api_key'] );
		$this->assertSame( '••••••••3456', BorsFlow_Settings::mask( 'secret-key-123456' ) );
	}

	public function test_dangerous_upload_extensions_cannot_be_allowed() {
		wp_set_current_user( 1 ); // Admins may have unfiltered_html and so broader WP mime lists.
		$out = BorsFlow_Settings::sanitize( array( 'upload_allowed_ext' => 'pdf, PHP, html, svg, js, jpg, exe' ) );
		$this->assertSame( 'pdf,jpg', $out['upload_allowed_ext'] );
	}

	public function test_upload_paths_are_confined() {
		$this->assertNull( BorsFlow_Uploads::path( '../../wp-config.php' ) );
		$this->assertNull( BorsFlow_Uploads::path( '2026/09/' . str_repeat( 'a', 32 ) . '.php' ) );
		$this->assertTrue( is_file( BorsFlow_Uploads::dir() . '/.htaccess' ) );
	}

	public function test_csv_cells_neutralise_formulas_but_keep_numbers() {
		$this->assertSame( "'=HYPERLINK(\"x\")", BorsFlow_Admin_Submissions::csv_cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'@SUM(1)", BorsFlow_Admin_Submissions::csv_cell( '@SUM(1)' ) );
		$this->assertSame( "'+cmd|x", BorsFlow_Admin_Submissions::csv_cell( '+cmd|x' ) );
		$this->assertSame( '+44 20 7946 0958', BorsFlow_Admin_Submissions::csv_cell( '+44 20 7946 0958' ) );
		$this->assertSame( '-5', BorsFlow_Admin_Submissions::csv_cell( '-5' ) );
	}

	public function test_rendered_markup_escapes_admin_supplied_text() {
		$id   = $this->make_form( array( $this->field( 'text', 'x', array( 'label' => '"><script>alert(1)</script>', 'placeholder' => '" onfocus="alert(1)' ) ) ) );
		$html = BorsFlow_Renderer::render_by_id( $id );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '" onfocus="', $html );
	}
}
