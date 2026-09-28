<?php
/**
 * Spam protection: form token + time trap, honeypot, captcha and rate limiting.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anti-abuse checks for public submissions.
 */
class BorsFlow_Spam {

	/**
	 * Issue a signed render-time token: "<unix time>.<hmac>".
	 *
	 * Public forms are often served from a full-page cache, so a WordPress nonce
	 * (tied to a session and expiring after 24h) would break real visitors.
	 * This token proves the submission came from a form we rendered and carries
	 * the render time for the time trap. It expires after the configured max
	 * age; the front-end script swaps in a fresh one (see refresh_token()) when
	 * the cached page is older than half that, so real visitors never hit it.
	 *
	 * @param int      $form_id Form ID.
	 * @param int|null $ts      Issue time (defaults to now).
	 * @return string
	 */
	public static function issue_token( $form_id, $ts = null ) {
		$ts = null === $ts ? time() : (int) $ts;
		return $ts . '.' . self::sign( $form_id, $ts );
	}

	/**
	 * HMAC of form ID and timestamp.
	 *
	 * @param int $form_id Form ID.
	 * @param int $ts      Timestamp.
	 * @return string
	 */
	private static function sign( $form_id, $ts ) {
		return substr( hash_hmac( 'sha256', 'borsflow|' . (int) $form_id . '|' . (int) $ts, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Verify the token and return its timestamp, or null if forged/missing.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $token   Submitted token.
	 * @return int|null
	 */
	public static function token_time( $form_id, $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return null;
		}
		$ts = (int) $m[1];
		if ( $ts > time() + 60 || ! hash_equals( self::sign( $form_id, $ts ), $m[2] ) ) {
			return null;
		}
		$max_age = self::token_max_age();
		if ( $max_age > 0 && time() - $ts > $max_age ) {
			return null;
		}
		return $ts;
	}

	/**
	 * Token lifetime in seconds (0 = never expires).
	 *
	 * @return int
	 */
	public static function token_max_age() {
		return (int) BorsFlow_Settings::get( 'token_max_age' ) * HOUR_IN_SECONDS;
	}

	/**
	 * REST callback: GET /forms/{id}/token. Lets pages served from a long-lived
	 * cache obtain a current token. Handing out tokens gives bots nothing they
	 * could not scrape from the page; the time trap still measures from issue time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh_token( WP_REST_Request $request ) {
		$form = BorsFlow_Form::get( (int) $request['id'], false );
		if ( ! $form ) {
			return new WP_Error( 'borsflow_not_found', __( 'This form is not available.', 'borsflow-forms' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( array( 'token' => self::issue_token( $form['id'] ) ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Run all checks. Returns null when OK, or a WP_Error. Error code
	 * `borsflow_spam` means "silently pretend success" to not train bots.
	 *
	 * @param array $form    Form.
	 * @param array $request Unslashed request params ($_POST-like).
	 * @return WP_Error|null
	 */
	public static function check( $form, $request ) {
		$settings = $form['settings']['spam'];

		// 1. Honeypot is always on.
		if ( ! empty( $request[ BorsFlow_Renderer::HONEYPOT ] ) ) {
			return new WP_Error( 'borsflow_spam', 'honeypot' );
		}

		// 2. Signed token must be present and genuine.
		$ts = self::token_time( $form['id'], $request['bf_token'] ?? '' );
		if ( null === $ts ) {
			return new WP_Error( 'borsflow_token', __( 'This form has expired. Please reload the page and try again.', 'borsflow-forms' ), array( 'status' => 400 ) );
		}

		// 3. Optional time trap.
		if ( ! empty( $settings['time_trap'] ) && ( time() - $ts ) < (int) $settings['time_trap_seconds'] ) {
			return new WP_Error( 'borsflow_spam', 'time_trap' );
		}

		// 4. Rate limit per IP per form.
		if ( self::rate_limited( $form['id'] ) ) {
			return new WP_Error( 'borsflow_rate_limited', __( 'Too many submissions. Please wait a few minutes and try again.', 'borsflow-forms' ), array( 'status' => 429 ) );
		}

		// 5. Optional captcha.
		$captcha = $settings['captcha'];
		if ( 'recaptcha' === $captcha && '' !== BorsFlow_Settings::get( 'recaptcha_secret' ) && '' !== BorsFlow_Settings::get( 'recaptcha_site_key' ) ) {
			if ( ! self::verify_recaptcha( (string) ( $request['bf_recaptcha'] ?? '' ) ) ) {
				return new WP_Error( 'borsflow_captcha', __( 'We could not verify that you are human. Please try again.', 'borsflow-forms' ), array( 'status' => 400 ) );
			}
		} elseif ( 'turnstile' === $captcha && '' !== BorsFlow_Settings::get( 'turnstile_secret' ) && '' !== BorsFlow_Settings::get( 'turnstile_site_key' ) ) {
			if ( ! self::verify_turnstile( (string) ( $request['cf-turnstile-response'] ?? '' ) ) ) {
				return new WP_Error( 'borsflow_captcha', __( 'We could not verify that you are human. Please try again.', 'borsflow-forms' ), array( 'status' => 400 ) );
			}
		}

		return null;
	}

	/**
	 * Transient key for the rate limiter.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private static function rate_key( $form_id ) {
		return 'borsflow_rl_' . md5( self::client_ip() . '|' . (int) $form_id );
	}

	/**
	 * Whether this IP has hit the per-form limit in the current window.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	public static function rate_limited( $form_id ) {
		$max = (int) BorsFlow_Settings::get( 'rate_limit_max' );
		if ( $max <= 0 ) {
			return false;
		}
		$hits = get_transient( self::rate_key( $form_id ) );
		return is_array( $hits ) && (int) $hits['count'] >= $max && (int) $hits['expires'] > time();
	}

	/**
	 * Count an accepted submission against the limiter.
	 *
	 * @param int $form_id Form ID.
	 */
	public static function record_hit( $form_id ) {
		$max = (int) BorsFlow_Settings::get( 'rate_limit_max' );
		if ( $max <= 0 ) {
			return;
		}
		$window = (int) BorsFlow_Settings::get( 'rate_limit_window' ) * MINUTE_IN_SECONDS;
		$key    = self::rate_key( $form_id );
		$hits   = get_transient( $key );
		if ( ! is_array( $hits ) || (int) $hits['expires'] <= time() ) {
			$hits = array(
				'count'   => 0,
				'expires' => time() + $window,
			);
		}
		++$hits['count'];
		set_transient( $key, $hits, max( 1, $hits['expires'] - time() ) );
	}

	/**
	 * Verify a reCAPTCHA v3 token.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function verify_recaptcha( $token ) {
		if ( '' === $token ) {
			return false;
		}
		$res = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => BorsFlow_Settings::get( 'recaptcha_secret' ),
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['success'] )
			&& (float) ( $body['score'] ?? 0 ) >= (float) BorsFlow_Settings::get( 'recaptcha_threshold' )
			&& ( empty( $body['action'] ) || 'borsflow_submit' === $body['action'] );
	}

	/**
	 * Verify a Cloudflare Turnstile token.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function verify_turnstile( $token ) {
		if ( '' === $token ) {
			return false;
		}
		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => BorsFlow_Settings::get( 'turnstile_secret' ),
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['success'] );
	}

	/**
	 * Client IP.
	 *
	 * REMOTE_ADDR by default. Behind a CDN or load balancer every visitor shares
	 * the proxy's address, so an admin can trust one forwarded header in
	 * Settings. Only do that when the proxy always sets it: otherwise visitors can
	 * spoof it to dodge the rate limit. The `borsflow_client_ip` filter has the
	 * final say.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = self::ip_from_header( (string) BorsFlow_Settings::get( 'proxy_header' ) );
		$ip     = (string) apply_filters( 'borsflow_client_ip', '' !== $ip ? $ip : $remote, $remote );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Read a client IP from a trusted proxy header.
	 *
	 * For X-Forwarded-For ("client, proxy1, proxy2") the right-most address that is
	 * not private/reserved is used: entries to its left were supplied by the
	 * client and can be forged, entries to its right are our own infrastructure.
	 *
	 * @param string $header $_SERVER key, or '' for none.
	 * @return string Valid IP or ''.
	 */
	public static function ip_from_header( $header ) {
		if ( '' === $header || ! array_key_exists( $header, BorsFlow_Settings::proxy_headers() ) || empty( $_SERVER[ $header ] ) ) {
			return '';
		}
		$parts = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) );
		if ( 'HTTP_X_FORWARDED_FOR' === $header ) {
			foreach ( array_reverse( $parts ) as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
			return '';
		}
		return filter_var( $parts[0], FILTER_VALIDATE_IP ) ? $parts[0] : '';
	}
}
