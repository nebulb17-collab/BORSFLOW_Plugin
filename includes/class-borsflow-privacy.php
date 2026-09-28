<?php
/**
 * GDPR tooling: personal data exporter, eraser and privacy policy text.
 *
 * Hooks into Tools → Export Personal Data / Erase Personal Data. A submission
 * belongs to an email address when any of its email fields holds that address.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy integration.
 */
class BorsFlow_Privacy {

	const PAGE_SIZE = 50;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['borsflow-forms'] = array(
			'exporter_friendly_name' => __( 'BorsFlow Forms submissions', 'borsflow-forms' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['borsflow-forms'] = array(
			'eraser_friendly_name' => __( 'BorsFlow Forms submissions', 'borsflow-forms' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Submissions whose email fields contain exactly this address.
	 *
	 * @param string $email Email.
	 * @param int    $page  1-based page.
	 * @return array{items: array[], done: bool}
	 */
	public static function find( $email, $page ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return array(
				'items' => array(),
				'done'  => true,
			);
		}
		// The LIKE narrows candidates; the exact check below rules out partial matches.
		$batch = BorsFlow_Submissions::query(
			array(
				'search'   => $email,
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => self::PAGE_SIZE,
				'page'     => $page,
			)
		);
		$items = array_values(
			array_filter(
				$batch['items'],
				static function ( $row ) use ( $email ) {
					foreach ( $row['payload'] as $entry ) {
						if ( 'email' === ( $entry['type'] ?? '' ) && is_string( $entry['value'] ?? null ) && strtolower( $entry['value'] ) === $email ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		return array(
			'items' => $items,
			'done'  => count( $batch['items'] ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$found = self::find( $email, (int) $page );
		$forms = BorsFlow_Form::options();
		$data  = array();

		foreach ( $found['items'] as $row ) {
			$fields = array(
				array(
					'name'  => __( 'Submission ID', 'borsflow-forms' ),
					'value' => $row['id'],
				),
				array(
					'name'  => __( 'Form', 'borsflow-forms' ),
					'value' => $forms[ (int) $row['form_id'] ] ?? '#' . $row['form_id'],
				),
				array(
					'name'  => __( 'Submitted', 'borsflow-forms' ),
					'value' => $row['created_at'] . ' UTC',
				),
			);
			foreach ( $row['payload'] as $entry ) {
				$fields[] = array(
					'name'  => $entry['label'],
					'value' => BorsFlow_Mailer::value_to_string( $entry ),
				);
			}
			foreach ( array(
				'ip'         => __( 'IP address', 'borsflow-forms' ),
				'user_agent' => __( 'User agent', 'borsflow-forms' ),
				'page_url'   => __( 'Page URL', 'borsflow-forms' ),
				'referrer'   => __( 'Referrer', 'borsflow-forms' ),
			) as $col => $label ) {
				if ( ! empty( $row[ $col ] ) ) {
					$fields[] = array(
						'name'  => $label,
						'value' => $row[ $col ],
					);
				}
			}
			$data[] = array(
				'group_id'    => 'borsflow-forms',
				'group_label' => __( 'Form submissions', 'borsflow-forms' ),
				'item_id'     => 'borsflow-submission-' . $row['id'],
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => $found['done'],
		);
	}

	/**
	 * Eraser callback. Deletes matching submissions and their files.
	 *
	 * Deleting shifts later rows onto earlier pages, so we always read page 1 and
	 * keep going until a page yields nothing more to delete.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (ignored; see above).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature required by the eraser API.
		$removed = 0;
		$scan    = 1;
		do {
			$found = self::find( $email, $scan );
			$ids   = wp_list_pluck( $found['items'], 'id' );
			if ( $ids ) {
				$removed += BorsFlow_Submissions::delete( $ids );
			} else {
				++$scan; // Page had only near-misses from the LIKE; move past it.
			}
		} while ( ! $found['done'] && $scan < 1000 );

		$messages = array();
		if ( $removed ) {
			/* translators: %d: number of submissions. */
			$messages[] = sprintf( _n( '%d form submission was deleted. Leads already synced to BorsFlow CRM must be erased there.', '%d form submissions were deleted. Leads already synced to BorsFlow CRM must be erased there.', $removed, 'borsflow-forms' ), $removed );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings → Privacy.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Adjust this text to match the forms and settings you actually use.', 'borsflow-forms' ) . '</p>'
			. '<p>' . esc_html__( 'When you submit a form on this site we store the information you enter, the page you submitted it from and, unless disabled, your IP address and browser user agent. We use this to respond to you and to protect the form from abuse.', 'borsflow-forms' ) . '</p>'
			. '<p>' . esc_html__( 'Submissions are sent to our CRM (BorsFlow) so we can follow up on your enquiry. Files you upload are stored privately and are only accessible to site administrators.', 'borsflow-forms' ) . '</p>'
			. '<p>' . esc_html__( 'If a form uses Google reCAPTCHA or Cloudflare Turnstile, that service receives technical information about your visit to check you are not a bot.', 'borsflow-forms' ) . '</p>';
		wp_add_privacy_policy_content( __( 'BorsFlow Forms', 'borsflow-forms' ), wp_kses_post( $content ) );
	}
}
