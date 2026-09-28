<?php
/**
 * Global plugin settings.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single `borsflow_settings` option.
 */
class BorsFlow_Settings {

	const OPTION = 'borsflow_settings';

	/**
	 * Keys whose values are secrets: never echoed back into the settings form.
	 *
	 * @var string[]
	 */
	const SECRET_KEYS = array( 'crm_api_key', 'recaptcha_secret', 'turnstile_secret' );

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'crm_base_url'         => '',
			'crm_api_key'          => '',
			'crm_leads_path'       => '/api/leads',
			'crm_health_path'      => '/api/health',
			'crm_timeout'          => 15,
			'crm_max_attempts'     => 5,
			'crm_default_source'   => 'wordpress',
			'crm_default_pipeline' => '',
			'crm_default_stage'    => '',
			'recaptcha_site_key'   => '',
			'recaptcha_secret'     => '',
			'recaptcha_threshold'  => 0.5,
			'turnstile_site_key'   => '',
			'turnstile_secret'     => '',
			'rate_limit_max'       => 5,
			'rate_limit_window'    => 10,
			'upload_max_mb'        => 5,
			'upload_allowed_ext'   => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv',
			'retention_days'       => 0,
			'delete_on_uninstall'  => 0,
			'proxy_header'         => '',
			'token_max_age'        => 48,
			'store_ip'             => 1,
			'email_async'          => 1,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		foreach ( self::constants() as $key => $constant ) {
			if ( defined( $constant ) ) {
				$all[ $key ] = (string) constant( $constant );
			}
		}
		return $all;
	}

	/**
	 * Settings that can be pinned in wp-config.php so secrets never touch the database.
	 *
	 * @return array<string,string> Setting key => constant name.
	 */
	public static function constants() {
		return array(
			'crm_base_url'     => 'BORSFLOW_CRM_BASE_URL',
			'crm_api_key'      => 'BORSFLOW_API_KEY',
			'recaptcha_secret' => 'BORSFLOW_RECAPTCHA_SECRET',
			'turnstile_secret' => 'BORSFLOW_TURNSTILE_SECRET',
		);
	}

	/**
	 * Whether a setting is locked by a wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		$map = self::constants();
		return isset( $map[ $key ] ) && defined( $map[ $key ] );
	}

	/**
	 * Proxy headers an admin may choose to trust for the visitor IP.
	 *
	 * @return array<string,string> $_SERVER key => label.
	 */
	public static function proxy_headers() {
		return array(
			''                      => __( 'None – use the connection address (REMOTE_ADDR)', 'borsflow-forms' ),
			'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
			'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
			'HTTP_X_REAL_IP'        => 'X-Real-IP',
			'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP (Akamai, Cloudflare Enterprise)',
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitize a submitted settings array. Secret fields left blank keep their stored value.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$out      = array();

		$out['crm_base_url']         = untrailingslashit( esc_url_raw( trim( (string) ( $input['crm_base_url'] ?? '' ) ), array( 'http', 'https' ) ) );
		$out['crm_leads_path']       = self::sanitize_path( $input['crm_leads_path'] ?? '', $defaults['crm_leads_path'] );
		$out['crm_health_path']      = self::sanitize_path( $input['crm_health_path'] ?? '', $defaults['crm_health_path'] );
		$out['crm_timeout']          = max( 3, min( 60, absint( $input['crm_timeout'] ?? 15 ) ) );
		$out['crm_max_attempts']     = max( 1, min( 20, absint( $input['crm_max_attempts'] ?? 5 ) ) );
		$out['crm_default_source']   = sanitize_text_field( $input['crm_default_source'] ?? '' );
		$out['crm_default_pipeline'] = sanitize_text_field( $input['crm_default_pipeline'] ?? '' );
		$out['crm_default_stage']    = sanitize_text_field( $input['crm_default_stage'] ?? '' );
		$out['recaptcha_site_key']   = sanitize_text_field( $input['recaptcha_site_key'] ?? '' );
		$out['recaptcha_threshold']  = max( 0.0, min( 1.0, (float) ( $input['recaptcha_threshold'] ?? 0.5 ) ) );
		$out['turnstile_site_key']   = sanitize_text_field( $input['turnstile_site_key'] ?? '' );
		$out['rate_limit_max']       = absint( $input['rate_limit_max'] ?? 5 );
		$out['rate_limit_window']    = max( 1, absint( $input['rate_limit_window'] ?? 10 ) );
		$out['upload_max_mb']        = max( 1, min( self::server_upload_limit_mb(), absint( $input['upload_max_mb'] ?? 5 ) ) );
		$out['upload_allowed_ext']   = self::sanitize_ext_list( $input['upload_allowed_ext'] ?? '' );
		$out['retention_days']       = absint( $input['retention_days'] ?? 0 );
		$out['delete_on_uninstall']  = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		$header                      = (string) ( $input['proxy_header'] ?? '' );
		$out['proxy_header']         = array_key_exists( $header, self::proxy_headers() ) ? $header : '';
		$out['token_max_age']        = min( 24 * 90, absint( $input['token_max_age'] ?? 48 ) );
		$out['store_ip']             = empty( $input['store_ip'] ) ? 0 : 1;
		$out['email_async']          = empty( $input['email_async'] ) ? 0 : 1;

		$out['crm_base_url'] = self::is_constant( 'crm_base_url' ) ? '' : $out['crm_base_url'];

		foreach ( self::SECRET_KEYS as $key ) {
			if ( self::is_constant( $key ) ) {
				$out[ $key ] = ''; // The constant wins; never copy it into the database.
				continue;
			}
			$submitted = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			} elseif ( '' === $submitted ) {
				$out[ $key ] = $current[ $key ];
			} else {
				$out[ $key ] = sanitize_text_field( $submitted );
			}
		}

		return $out;
	}

	/**
	 * Mask a secret for display: only the last four characters survive.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . ( strlen( $secret ) > 8 ? substr( $secret, -4 ) : '' );
	}

	/**
	 * Largest upload the server accepts, in MB.
	 *
	 * @return int
	 */
	public static function server_upload_limit_mb() {
		return max( 1, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) );
	}

	/**
	 * Allowed upload extensions as an array.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get( 'upload_allowed_ext' ) ) ) ) );
	}

	/**
	 * Normalise a relative API path.
	 *
	 * @param string $path     Raw.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function sanitize_path( $path, $fallback ) {
		$path = preg_replace( '#[^A-Za-z0-9/_\-.{}]#', '', (string) $path );
		if ( '' === $path ) {
			return $fallback;
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Keep only extensions WordPress itself would allow, lower-cased and de-duplicated.
	 *
	 * @param string $extensions Comma separated list.
	 * @return string
	 */
	private static function sanitize_ext_list( $extensions ) {
		$wp_allowed = array();
		foreach ( array_keys( get_allowed_mime_types() ) as $pattern ) {
			$wp_allowed = array_merge( $wp_allowed, explode( '|', $pattern ) );
		}
		// Never accept types a browser could execute or render as a page, even for admins with unfiltered_html.
		$denied = array( 'htm', 'html', 'shtml', 'svg', 'svgz', 'xml', 'js', 'css', 'swf', 'php', 'phtml', 'exe' );
		$exts   = array();
		foreach ( explode( ',', strtolower( (string) $extensions ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( '' !== $ext && in_array( $ext, $wp_allowed, true ) && ! in_array( $ext, $denied, true ) ) {
				$exts[] = $ext;
			}
		}
		return implode( ',', array_unique( $exts ) );
	}
}
