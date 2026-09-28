<?php
/**
 * Admin notification and autoresponder emails with {field_key} merge tags.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends submission emails.
 */
class BorsFlow_Mailer {

	const HOOK = 'borsflow_send_notifications';

	/**
	 * Queue notification emails for a stored submission.
	 *
	 * @param int $id Submission ID.
	 */
	public static function queue( $id ) {
		if ( ! wp_next_scheduled( self::HOOK, array( (int) $id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( (int) $id ) );
		}
	}

	/**
	 * Cron callback: send the emails for a submission, at most once.
	 *
	 * @param int $id Submission ID.
	 */
	public static function send_queued( $id ) {
		$row = BorsFlow_Submissions::get( (int) $id );
		if ( ! $row || ! BorsFlow_Submissions::claim_emails( (int) $id ) ) {
			return;
		}
		$form = BorsFlow_Form::get( (int) $row['form_id'] );
		if ( $form ) {
			self::send_notifications( $form, $row['payload'], (int) $id, (string) $row['page_url'] );
		}
	}

	/**
	 * Send the admin notification and autoresponder for a new submission.
	 *
	 * @param array  $form     Form.
	 * @param array  $payload  Payload.
	 * @param int    $id       Submission ID.
	 * @param string $page_url Page URL.
	 */
	public static function send_notifications( $form, $payload, $id, $page_url ) {
		$s    = $form['settings'];
		$vars = self::variables( $form, $payload, $id, $page_url );

		if ( ! empty( $s['notify']['enabled'] ) ) {
			$to = self::recipients( $s['notify']['recipients'], $vars );
			if ( $to ) {
				$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
				$reply_to = self::field_email( $payload, $s['notify']['reply_to_field'] );
				if ( $reply_to ) {
					$headers[] = 'Reply-To: ' . $reply_to;
				}
				wp_mail(
					$to,
					self::subject( $s['notify']['subject'], $vars ),
					self::body( $s['notify']['body'], $vars, $payload ),
					apply_filters( 'borsflow_notification_headers', $headers, $form, $payload )
				);
			}
		}

		if ( ! empty( $s['autoresponder']['enabled'] ) ) {
			$to = self::field_email( $payload, $s['autoresponder']['to_field'] );
			if ( $to ) {
				wp_mail(
					$to,
					self::subject( $s['autoresponder']['subject'], $vars ),
					self::body( $s['autoresponder']['body'], $vars, $payload ),
					apply_filters( 'borsflow_autoresponder_headers', array( 'Content-Type: text/html; charset=UTF-8' ), $form, $payload )
				);
			}
		}
	}

	/**
	 * Plain-text merge variables.
	 *
	 * @param array  $form     Form.
	 * @param array  $payload  Payload.
	 * @param int    $id       Submission ID.
	 * @param string $page_url Page URL.
	 * @return array<string,string>
	 */
	public static function variables( $form, $payload, $id, $page_url ) {
		$vars = array(
			'form_title'    => $form['title'],
			'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'      => home_url( '/' ),
			'page_url'      => $page_url,
			'submission_id' => (string) $id,
			'admin_email'   => get_option( 'admin_email' ),
			'date'          => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
		foreach ( $payload as $key => $entry ) {
			$vars[ $key ] = self::value_to_string( $entry );
		}
		return $vars;
	}

	/**
	 * Human-readable value of a payload entry.
	 *
	 * @param array $entry Payload entry.
	 * @return string
	 */
	public static function value_to_string( $entry ) {
		$value = $entry['value'] ?? '';
		if ( 'file' === ( $entry['type'] ?? '' ) ) {
			return is_array( $value ) ? (string) ( $value['name'] ?? '' ) : '';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}

	/**
	 * Replace {tags} in a template.
	 *
	 * @param string   $template Template.
	 * @param array    $vars     Variables.
	 * @param callable $escape   Escaper applied to each value.
	 * @return string
	 */
	private static function merge( $template, $vars, $escape ) {
		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static function ( $m ) use ( $vars, $escape ) {
				return array_key_exists( $m[1], $vars ) ? call_user_func( $escape, $vars[ $m[1] ] ) : $m[0];
			},
			(string) $template
		);
	}

	/**
	 * Subject line: merged, single line.
	 *
	 * @param string $template Template.
	 * @param array  $vars     Variables.
	 * @return string
	 */
	private static function subject( $template, $vars ) {
		$subject = self::merge( $template, $vars, 'strval' );
		return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( $subject ) ) );
	}

	/**
	 * HTML body: submitted values are escaped, the admin's template may contain HTML.
	 *
	 * @param string $template Template.
	 * @param array  $vars     Variables.
	 * @param array  $payload  Payload (for {all_fields}).
	 * @return string
	 */
	private static function body( $template, $vars, $payload ) {
		// {all_fields} is left alone by merge() (not in $vars) and swapped for pre-escaped HTML afterwards.
		unset( $vars['all_fields'] );
		$html = self::merge( $template, $vars, static fn( $v ) => nl2br( esc_html( $v ) ) );
		$html = str_replace( '{all_fields}', self::all_fields_html( $payload ), $html );
		return wpautop( $html );
	}

	/**
	 * {all_fields} as an HTML table.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private static function all_fields_html( $payload ) {
		$rows = '';
		foreach ( $payload as $entry ) {
			$rows .= '<tr><th align="left" valign="top" style="padding:4px 12px 4px 0">' . esc_html( $entry['label'] ) . '</th><td style="padding:4px 0">' . nl2br( esc_html( self::value_to_string( $entry ) ) ) . '</td></tr>';
		}
		return '<table cellpadding="0" cellspacing="0">' . $rows . '</table>';
	}

	/**
	 * Parse a comma separated recipient list that may contain {tags}.
	 *
	 * @param string $recipients List.
	 * @param array  $vars       Variables.
	 * @return string[]
	 */
	private static function recipients( $recipients, $vars ) {
		$out = array();
		foreach ( explode( ',', self::merge( $recipients, $vars, 'strval' ) ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_unique( $out );
	}

	/**
	 * Valid email from a payload field, or empty string.
	 *
	 * @param array  $payload Payload.
	 * @param string $key     Field key.
	 * @return string
	 */
	private static function field_email( $payload, $key ) {
		if ( '' === (string) $key || ! isset( $payload[ $key ] ) ) {
			return '';
		}
		$email = sanitize_email( self::value_to_string( $payload[ $key ] ) );
		return is_email( $email ) ? $email : '';
	}
}
