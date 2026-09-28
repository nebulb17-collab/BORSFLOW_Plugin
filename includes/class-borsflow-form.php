<?php
/**
 * Form model: load, save, duplicate forms stored as `borsflow_form` posts.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static repository for form definitions.
 */
class BorsFlow_Form {

	const META_FIELDS   = '_borsflow_fields';
	const META_SETTINGS = '_borsflow_settings';

	/**
	 * CRM lead fields a form field can be mapped to.
	 *
	 * @return array<string,string>
	 */
	public static function crm_fields() {
		return array(
			'firstName' => __( 'First name', 'borsflow-forms' ),
			'lastName'  => __( 'Last name', 'borsflow-forms' ),
			'fullName'  => __( 'Full name (split into first/last)', 'borsflow-forms' ),
			'email'     => __( 'Email', 'borsflow-forms' ),
			'phone'     => __( 'Phone', 'borsflow-forms' ),
			'company'   => __( 'Company', 'borsflow-forms' ),
			'position'  => __( 'Position', 'borsflow-forms' ),
			'value'     => __( 'Deal value', 'borsflow-forms' ),
			'notes'     => __( 'Notes', 'borsflow-forms' ),
		);
	}

	/**
	 * Default per-form settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'submit_text'     => __( 'Send', 'borsflow-forms' ),
			'loading_text'    => __( 'Sending…', 'borsflow-forms' ),
			'after_submit'    => 'message',
			'success_message' => __( 'Thanks! Your message has been sent.', 'borsflow-forms' ),
			'redirect_url'    => '',
			'notify'          => array(
				'enabled'        => true,
				'recipients'     => '{admin_email}',
				'subject'        => __( 'New submission: {form_title}', 'borsflow-forms' ),
				'body'           => "{all_fields}\n\n---\n{page_url}",
				'reply_to_field' => '',
			),
			'autoresponder'   => array(
				'enabled'  => false,
				'to_field' => '',
				'subject'  => __( 'We received your message', 'borsflow-forms' ),
				'body'     => __( "Hi,\n\nThanks for getting in touch. We'll get back to you shortly.\n\n{site_name}", 'borsflow-forms' ),
			),
			'spam'            => array(
				'time_trap'         => false,
				'time_trap_seconds' => 3,
				'captcha'           => 'none',
			),
			'style'           => array(
				'preset' => 'inherit',
				'accent' => '#2271b1',
				'radius' => 4,
				'size'   => 'md',
			),
			'crm'             => array(
				'enabled'  => true,
				'mapping'  => array(),
				'pipeline' => '',
				'stage'    => '',
				'source'   => '',
			),
		);
	}

	/**
	 * Load a form.
	 *
	 * @param int  $id               Post ID.
	 * @param bool $include_disabled Return disabled forms too.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id, $include_disabled = true ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || BorsFlow_Post_Type::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$enabled = 'publish' === $post->post_status;
		if ( ! $enabled && ! $include_disabled ) {
			return null;
		}

		$fields = json_decode( (string) get_post_meta( $post->ID, self::META_FIELDS, true ), true );
		$raw    = json_decode( (string) get_post_meta( $post->ID, self::META_SETTINGS, true ), true );

		$normalised = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) ) {
				$normalised[] = array_replace( BorsFlow_Fields::blank( $field['type'] ), $field );
			}
		}

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'enabled'  => $enabled,
			'fields'   => $normalised,
			'settings' => self::merge_settings( is_array( $raw ) ? $raw : array() ),
			'modified' => $post->post_modified_gmt,
		);
	}

	/**
	 * Deep-merge stored settings with defaults (one level of nesting).
	 *
	 * @param array $stored Stored settings.
	 * @return array
	 */
	private static function merge_settings( $stored ) {
		$settings = self::default_settings();
		foreach ( $settings as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			$settings[ $key ] = is_array( $default ) && is_array( $stored[ $key ] ) ? array_replace( $default, $stored[ $key ] ) : $stored[ $key ];
		}
		return $settings;
	}

	/**
	 * Fields that collect data (no headings/HTML blocks).
	 *
	 * @param array $form Form.
	 * @return array[]
	 */
	public static function data_fields( $form ) {
		return array_values(
			array_filter(
				$form['fields'],
				static function ( $f ) {
					return ! BorsFlow_Fields::is_layout( $f['type'] ) && '' !== $f['key'];
				}
			)
		);
	}

	/**
	 * Create a new form.
	 *
	 * @param string $title Title.
	 * @return int|WP_Error
	 */
	public static function create( $title = '' ) {
		$id = wp_insert_post(
			array(
				'post_type'   => BorsFlow_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '' !== $title ? $title : __( 'Untitled form', 'borsflow-forms' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$starter = array();
		foreach ( array(
			array( 'text', 'name', __( 'Name', 'borsflow-forms' ), true ),
			array( 'email', 'email', __( 'Email', 'borsflow-forms' ), true ),
			array( 'textarea', 'message', __( 'Message', 'borsflow-forms' ), false ),
		) as $i => $def ) {
			$field             = BorsFlow_Fields::blank( $def[0] );
			$field['id']       = 'f_' . wp_generate_password( 8, false );
			$field['key']      = $def[1];
			$field['label']    = $def[2];
			$field['required'] = $def[3];
			$starter[]         = $field;
		}
		$settings                              = self::default_settings();
		$settings['notify']['reply_to_field']  = 'email';
		$settings['autoresponder']['to_field'] = 'email';
		$settings['crm']['mapping']            = array(
			'name'    => 'fullName',
			'email'   => 'email',
			'message' => 'notes',
		);
		self::save_schema( $id, $starter, $settings );
		return $id;
	}

	/**
	 * Sanitize and persist a builder payload.
	 *
	 * @param int    $id       Form ID.
	 * @param string $title    Title.
	 * @param mixed  $fields   Raw fields.
	 * @param mixed  $settings Raw settings.
	 * @param bool   $enabled  Enabled.
	 * @return true|WP_Error
	 */
	public static function save( $id, $title, $fields, $settings, $enabled ) {
		$fields   = self::sanitize_fields( $fields );
		$settings = self::sanitize_settings( $settings, $fields );

		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_title'  => sanitize_text_field( $title ),
				'post_status' => $enabled ? 'publish' : 'draft',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::save_schema( $id, $fields, $settings );
		return true;
	}

	/**
	 * Write meta.
	 *
	 * @param int   $id       Form ID.
	 * @param array $fields   Sanitized fields.
	 * @param array $settings Sanitized settings.
	 */
	private static function save_schema( $id, $fields, $settings ) {
		// wp_slash: update_post_meta unslashes, which would eat backslashes in regex patterns.
		update_post_meta( $id, self::META_FIELDS, wp_slash( wp_json_encode( array_values( $fields ) ) ) );
		update_post_meta( $id, self::META_SETTINGS, wp_slash( wp_json_encode( $settings ) ) );
	}

	/**
	 * Sanitize the field list and make keys unique.
	 *
	 * @param mixed $raw Raw fields.
	 * @return array[]
	 */
	public static function sanitize_fields( $raw ) {
		$out  = array();
		$keys = array();
		$ids  = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$f = BorsFlow_Fields::sanitize_definition( $item );
			if ( ! $f ) {
				continue;
			}
			while ( isset( $ids[ $f['id'] ] ) ) {
				$f['id'] = 'f_' . wp_generate_password( 8, false );
			}
			$ids[ $f['id'] ] = true;

			if ( ! BorsFlow_Fields::is_layout( $f['type'] ) ) {
				$base = '' !== $f['key'] ? $f['key'] : ( BorsFlow_Fields::sanitize_field_key( $f['label'] ) ?: $f['type'] );
				$key  = $base;
				$n    = 2;
				while ( isset( $keys[ $key ] ) || in_array( $key, self::reserved_keys(), true ) ) {
					$key = $base . '_' . $n;
					++$n;
				}
				$f['key']     = $key;
				$keys[ $key ] = true;
			} else {
				$f['key'] = '';
			}
			$out[] = $f;
		}

		// Rules that point at a field that no longer exists would silently hide or show forever.
		foreach ( $out as &$f ) {
			if ( empty( $f['conditions']['rules'] ) ) {
				continue;
			}
			$f['conditions']['rules'] = array_values(
				array_filter( $f['conditions']['rules'], static fn( $r ) => isset( $keys[ $r['field'] ] ) && $r['field'] !== $f['key'] )
			);
			if ( ! $f['conditions']['rules'] ) {
				$f['conditions']['enabled'] = false;
			}
		}
		unset( $f );

		return $out;
	}

	/**
	 * Keys used by the plugin's own hidden inputs, and merge tags with a fixed meaning.
	 *
	 * @return string[]
	 */
	public static function reserved_keys() {
		return array( 'action', 'form_id', 'all_fields', 'form_title', 'site_name', 'site_url', 'page_url', 'submission_id', 'admin_email', 'date' );
	}

	/**
	 * Sanitize per-form settings.
	 *
	 * @param mixed   $raw    Raw settings.
	 * @param array[] $fields Sanitized fields (for validating key references).
	 * @return array
	 */
	public static function sanitize_settings( $raw, $fields ) {
		$raw          = is_array( $raw ) ? $raw : array();
		$d            = self::default_settings();
		$keys         = wp_list_pluck( array_filter( $fields, static fn( $f ) => '' !== $f['key'] ), 'key' );
		$key_or_blank = static fn( $k ) => in_array( $k, $keys, true ) ? $k : '';

		$notify = is_array( $raw['notify'] ?? null ) ? $raw['notify'] : array();
		$auto   = is_array( $raw['autoresponder'] ?? null ) ? $raw['autoresponder'] : array();
		$spam   = is_array( $raw['spam'] ?? null ) ? $raw['spam'] : array();
		$style  = is_array( $raw['style'] ?? null ) ? $raw['style'] : array();
		$crm    = is_array( $raw['crm'] ?? null ) ? $raw['crm'] : array();

		$mapping    = array();
		$crm_fields = self::crm_fields();
		foreach ( (array) ( $crm['mapping'] ?? array() ) as $field_key => $crm_field ) {
			$field_key = BorsFlow_Fields::sanitize_field_key( $field_key );
			if ( in_array( $field_key, $keys, true ) && isset( $crm_fields[ $crm_field ] ) ) {
				$mapping[ $field_key ] = $crm_field;
			}
		}

		return array(
			'submit_text'     => sanitize_text_field( $raw['submit_text'] ?? '' ) ?: $d['submit_text'],
			'loading_text'    => sanitize_text_field( $raw['loading_text'] ?? '' ) ?: $d['loading_text'],
			'after_submit'    => 'redirect' === ( $raw['after_submit'] ?? '' ) ? 'redirect' : 'message',
			'success_message' => wp_kses_post( $raw['success_message'] ?? '' ) ?: $d['success_message'],
			'redirect_url'    => esc_url_raw( $raw['redirect_url'] ?? '' ),
			'notify'          => array(
				'enabled'        => ! empty( $notify['enabled'] ),
				'recipients'     => sanitize_text_field( $notify['recipients'] ?? '' ),
				'subject'        => sanitize_text_field( $notify['subject'] ?? '' ),
				'body'           => wp_kses_post( $notify['body'] ?? '' ),
				'reply_to_field' => $key_or_blank( $notify['reply_to_field'] ?? '' ),
			),
			'autoresponder'   => array(
				'enabled'  => ! empty( $auto['enabled'] ),
				'to_field' => $key_or_blank( $auto['to_field'] ?? '' ),
				'subject'  => sanitize_text_field( $auto['subject'] ?? '' ),
				'body'     => wp_kses_post( $auto['body'] ?? '' ),
			),
			'spam'            => array(
				'time_trap'         => ! empty( $spam['time_trap'] ),
				'time_trap_seconds' => max( 1, min( 120, absint( $spam['time_trap_seconds'] ?? 3 ) ) ),
				'captcha'           => in_array( $spam['captcha'] ?? '', array( 'none', 'recaptcha', 'turnstile' ), true ) ? $spam['captcha'] : 'none',
			),
			'style'           => array(
				'preset' => in_array( $style['preset'] ?? '', array( 'inherit', 'card', 'minimal' ), true ) ? $style['preset'] : 'inherit',
				'accent' => sanitize_hex_color( $style['accent'] ?? '' ) ?: $d['style']['accent'],
				'radius' => min( 32, absint( $style['radius'] ?? 4 ) ),
				'size'   => in_array( $style['size'] ?? '', array( 'sm', 'md', 'lg' ), true ) ? $style['size'] : 'md',
			),
			'crm'             => array(
				'enabled'  => ! empty( $crm['enabled'] ),
				'mapping'  => $mapping,
				'pipeline' => sanitize_text_field( $crm['pipeline'] ?? '' ),
				'stage'    => sanitize_text_field( $crm['stage'] ?? '' ),
				'source'   => sanitize_text_field( $crm['source'] ?? '' ),
			),
		);
	}

	/**
	 * Duplicate a form (always created disabled so it does not go live by accident).
	 *
	 * @param int $id Source form ID.
	 * @return int|WP_Error New ID.
	 */
	public static function duplicate( $id ) {
		$form = self::get( $id );
		if ( ! $form ) {
			return new WP_Error( 'borsflow_not_found', __( 'Form not found.', 'borsflow-forms' ) );
		}
		$new = wp_insert_post(
			array(
				'post_type'   => BorsFlow_Post_Type::POST_TYPE,
				'post_status' => 'draft',
				/* translators: %s: original form title. */
				'post_title'  => sprintf( __( '%s (copy)', 'borsflow-forms' ), $form['title'] ),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		self::save_schema( $new, $form['fields'], $form['settings'] );
		return $new;
	}

	/**
	 * Enable or disable a form.
	 *
	 * @param int  $id      Form ID.
	 * @param bool $enabled State.
	 */
	public static function set_enabled( $id, $enabled ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $enabled ? 'publish' : 'draft',
			)
		);
	}

	/**
	 * Permanently delete a form. Its submissions are kept.
	 *
	 * @param int $id Form ID.
	 */
	public static function delete( $id ) {
		$post = get_post( $id );
		if ( $post && BorsFlow_Post_Type::POST_TYPE === $post->post_type ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * ID => title list of forms, for pickers.
	 *
	 * @param bool $enabled_only Only enabled forms.
	 * @return array<int,string>
	 */
	public static function options( $enabled_only = false ) {
		$posts = get_posts(
			array(
				'post_type'      => BorsFlow_Post_Type::POST_TYPE,
				'post_status'    => $enabled_only ? 'publish' : array( 'publish', 'draft' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- form pickers need every form; sites have few.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			/* translators: %d: form ID. */
			$out[ $p->ID ] = '' !== $p->post_title ? $p->post_title : sprintf( __( 'Form #%d', 'borsflow-forms' ), $p->ID );
		}
		return $out;
	}
}
