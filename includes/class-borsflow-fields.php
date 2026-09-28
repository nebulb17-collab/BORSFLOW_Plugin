<?php
/**
 * Field type registry, server-side sanitization, validation and conditional logic.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field type definitions shared by the builder, renderer and submission handler.
 */
class BorsFlow_Fields {

	/**
	 * Field type definitions. `supports` lists the settings the builder shows.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types() {
		$text_like = array( 'placeholder', 'default', 'min_length', 'max_length', 'pattern' );

		$types = array(
			'text'        => array( 'label' => __( 'Text', 'borsflow-forms' ), 'icon' => 'editor-textcolor', 'supports' => $text_like ),
			'email'       => array( 'label' => __( 'Email', 'borsflow-forms' ), 'icon' => 'email', 'supports' => array( 'placeholder', 'default', 'max_length', 'pattern' ) ),
			'phone'       => array( 'label' => __( 'Phone', 'borsflow-forms' ), 'icon' => 'phone', 'supports' => $text_like ),
			'number'      => array( 'label' => __( 'Number', 'borsflow-forms' ), 'icon' => 'calculator', 'supports' => array( 'placeholder', 'default', 'min', 'max', 'step' ) ),
			'textarea'    => array( 'label' => __( 'Paragraph text', 'borsflow-forms' ), 'icon' => 'editor-paragraph', 'supports' => array( 'placeholder', 'default', 'min_length', 'max_length', 'pattern', 'rows' ) ),
			'select'      => array( 'label' => __( 'Dropdown', 'borsflow-forms' ), 'icon' => 'arrow-down-alt2', 'supports' => array( 'placeholder', 'options' ) ),
			'multiselect' => array( 'label' => __( 'Multi-select', 'borsflow-forms' ), 'icon' => 'list-view', 'supports' => array( 'options' ) ),
			'radio'       => array( 'label' => __( 'Radio group', 'borsflow-forms' ), 'icon' => 'marker', 'supports' => array( 'options' ) ),
			'checkboxes'  => array( 'label' => __( 'Checkbox group', 'borsflow-forms' ), 'icon' => 'yes-alt', 'supports' => array( 'options' ) ),
			'consent'     => array( 'label' => __( 'Consent checkbox', 'borsflow-forms' ), 'icon' => 'saved', 'supports' => array( 'content' ) ),
			'date'        => array( 'label' => __( 'Date', 'borsflow-forms' ), 'icon' => 'calendar-alt', 'supports' => array( 'default', 'min', 'max' ) ),
			'time'        => array( 'label' => __( 'Time', 'borsflow-forms' ), 'icon' => 'clock', 'supports' => array( 'default', 'min', 'max' ) ),
			'file'        => array( 'label' => __( 'File upload', 'borsflow-forms' ), 'icon' => 'upload', 'supports' => array( 'accept', 'max_size_mb' ) ),
			'url'         => array( 'label' => __( 'Website / URL', 'borsflow-forms' ), 'icon' => 'admin-links', 'supports' => array( 'placeholder', 'default', 'max_length', 'pattern' ) ),
			'hidden'      => array( 'label' => __( 'Hidden field', 'borsflow-forms' ), 'icon' => 'hidden', 'supports' => array( 'default' ) ),
			'heading'     => array( 'label' => __( 'Section heading', 'borsflow-forms' ), 'icon' => 'heading', 'supports' => array( 'content', 'level' ), 'layout' => true ),
			'html'        => array( 'label' => __( 'Paragraph / HTML', 'borsflow-forms' ), 'icon' => 'editor-code', 'supports' => array( 'content' ), 'layout' => true ),
		);

		foreach ( $types as $type => &$def ) {
			$def['layout']      = ! empty( $def['layout'] );
			$def['has_options'] = in_array( 'options', $def['supports'], true );
			$def['multiple']    = in_array( $type, array( 'multiselect', 'checkboxes' ), true );
		}
		unset( $def );

		/**
		 * Filter the available field types.
		 *
		 * @param array $types Field type definitions.
		 */
		return apply_filters( 'borsflow_field_types', $types );
	}

	/**
	 * Whether a type only renders content and collects no data.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_layout( $type ) {
		$types = self::types();
		return ! empty( $types[ $type ]['layout'] );
	}

	/**
	 * Whether a type holds an array of values.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_multiple( $type ) {
		return in_array( $type, array( 'multiselect', 'checkboxes' ), true );
	}

	/**
	 * Default definition for a new field of the given type.
	 *
	 * @param string $type Field type.
	 * @return array<string,mixed>
	 */
	public static function blank( $type ) {
		return array(
			'id'              => '',
			'type'            => $type,
			'key'             => '',
			'label'           => '',
			'placeholder'     => '',
			'help'            => '',
			'default'         => '',
			'required'        => false,
			'width'           => 'full',
			'min_length'      => '',
			'max_length'      => '',
			'min'             => '',
			'max'             => '',
			'step'            => '',
			'rows'            => 4,
			'pattern'         => '',
			'pattern_message' => '',
			'options'         => array(),
			'content'         => '',
			'level'           => 'h3',
			'accept'          => '',
			'max_size_mb'     => '',
			'css_class'       => '',
			'conditions'      => array(
				'enabled' => false,
				'action'  => 'show',
				'logic'   => 'and',
				'rules'   => array(),
			),
		);
	}

	/**
	 * Sanitize one field definition coming from the builder.
	 *
	 * @param mixed $raw Raw field.
	 * @return array<string,mixed>|null
	 */
	public static function sanitize_definition( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$types = self::types();
		$type  = sanitize_key( $raw['type'] ?? '' );
		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}
		$f = self::blank( $type );

		$f['id']              = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $raw['id'] ?? '' ) ) ?: 'f_' . wp_generate_password( 8, false );
		$f['key']             = self::sanitize_field_key( $raw['key'] ?? '' );
		$f['label']           = sanitize_text_field( $raw['label'] ?? '' );
		$f['placeholder']     = sanitize_text_field( $raw['placeholder'] ?? '' );
		$f['help']            = sanitize_text_field( $raw['help'] ?? '' );
		$f['default']         = sanitize_textarea_field( $raw['default'] ?? '' );
		$f['required']        = ! empty( $raw['required'] ) && ! $types[ $type ]['layout'] && 'hidden' !== $type;
		$f['width']           = in_array( $raw['width'] ?? '', array( 'full', 'half', 'third' ), true ) ? $raw['width'] : 'full';
		$f['min_length']      = self::int_or_blank( $raw['min_length'] ?? '' );
		$f['max_length']      = self::int_or_blank( $raw['max_length'] ?? '' );
		$f['min']             = sanitize_text_field( $raw['min'] ?? '' );
		$f['max']             = sanitize_text_field( $raw['max'] ?? '' );
		$f['step']            = sanitize_text_field( $raw['step'] ?? '' );
		$f['rows']            = max( 2, min( 20, absint( $raw['rows'] ?? 4 ) ) );
		$f['pattern']         = self::sanitize_pattern( $raw['pattern'] ?? '' );
		$f['pattern_message'] = sanitize_text_field( $raw['pattern_message'] ?? '' );
		$f['level']           = in_array( $raw['level'] ?? '', array( 'h2', 'h3', 'h4' ), true ) ? $raw['level'] : 'h3';
		$f['accept']          = self::sanitize_accept( $raw['accept'] ?? '' );
		$f['max_size_mb']     = self::int_or_blank( $raw['max_size_mb'] ?? '' );
		$f['css_class']       = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) ( $raw['css_class'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ) );

		// Content: headings are plain text, HTML blocks and consent text allow post HTML.
		$f['content'] = 'heading' === $type ? sanitize_text_field( $raw['content'] ?? '' ) : wp_kses_post( $raw['content'] ?? '' );

		if ( $types[ $type ]['has_options'] ) {
			$f['options'] = array();
			foreach ( (array) ( $raw['options'] ?? array() ) as $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}
				$label = sanitize_text_field( $opt['label'] ?? '' );
				$value = sanitize_text_field( $opt['value'] ?? '' );
				if ( '' === $label && '' === $value ) {
					continue;
				}
				$f['options'][] = array(
					'label'    => '' === $label ? $value : $label,
					'value'    => '' === $value ? $label : $value,
					'selected' => ! empty( $opt['selected'] ),
				);
			}
		}

		$cond               = is_array( $raw['conditions'] ?? null ) ? $raw['conditions'] : array();
		$f['conditions']    = array(
			'enabled' => ! empty( $cond['enabled'] ),
			'action'  => 'hide' === ( $cond['action'] ?? '' ) ? 'hide' : 'show',
			'logic'   => 'or' === ( $cond['logic'] ?? '' ) ? 'or' : 'and',
			'rules'   => array(),
		);
		foreach ( (array) ( $cond['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$f['conditions']['rules'][] = array(
				'field'    => self::sanitize_field_key( $rule['field'] ),
				'operator' => in_array( $rule['operator'] ?? '', array( 'equals', 'not_equals', 'contains', 'not_empty', 'empty' ), true ) ? $rule['operator'] : 'equals',
				'value'    => sanitize_text_field( $rule['value'] ?? '' ),
			);
		}

		return $f;
	}

	/**
	 * Field keys become merge tags, CSV headers and POST names: keep them tame.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_field_key( $key ) {
		$key = strtolower( remove_accents( (string) $key ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		return substr( trim( $key, '_' ), 0, 64 );
	}

	/**
	 * Accept only patterns that compile as PCRE (and therefore very likely as JS).
	 *
	 * @param string $pattern Raw pattern.
	 * @return string
	 */
	private static function sanitize_pattern( $pattern ) {
		$pattern = trim( wp_unslash( (string) $pattern ) );
		if ( '' === $pattern || strlen( $pattern ) > 500 ) {
			return '';
		}
		return null === self::compile_pattern( $pattern ) ? '' : $pattern;
	}

	/**
	 * Build an anchored PCRE regex from an HTML `pattern` attribute value.
	 *
	 * @param string $pattern Pattern.
	 * @return string|null Regex, or null if it does not compile.
	 */
	public static function compile_pattern( $pattern ) {
		$regex = '~^(?:' . str_replace( '~', '\~', $pattern ) . ')$~u';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid user regex must not emit warnings.
		return false === @preg_match( $regex, '' ) ? null : $regex;
	}

	/**
	 * Comma separated extension list for file fields, limited to the global allowlist.
	 *
	 * @param string $accept Raw list.
	 * @return string
	 */
	private static function sanitize_accept( $accept ) {
		$exts = array();
		foreach ( explode( ',', strtolower( (string) $accept ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( '' !== $ext ) {
				$exts[] = $ext;
			}
		}
		return implode( ',', array_unique( $exts ) );
	}

	/**
	 * Absint or empty string.
	 *
	 * @param mixed $v Value.
	 * @return int|string
	 */
	private static function int_or_blank( $v ) {
		return ( '' === $v || null === $v ) ? '' : absint( $v );
	}

	/**
	 * Allowed file extensions for a field: its own list intersected with the global allowlist.
	 *
	 * @param array $field Field.
	 * @return string[]
	 */
	public static function file_extensions( $field ) {
		$global = BorsFlow_Settings::allowed_extensions();
		if ( '' === $field['accept'] ) {
			return $global;
		}
		return array_values( array_intersect( explode( ',', $field['accept'] ), $global ) );
	}

	/**
	 * Maximum upload size for a field, in bytes.
	 *
	 * @param array $field Field.
	 * @return int
	 */
	public static function file_max_bytes( $field ) {
		$global = (int) BorsFlow_Settings::get( 'upload_max_mb' );
		$own    = (int) $field['max_size_mb'];
		$mb     = $own > 0 ? min( $own, $global ) : $global;
		return min( $mb * MB_IN_BYTES, wp_max_upload_size() );
	}

	/**
	 * Read and sanitize a raw submitted value according to the field type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Raw (already unslashed) request value.
	 * @return string|string[]
	 */
	public static function sanitize_value( $field, $raw ) {
		if ( self::is_multiple( $field['type'] ) ) {
			$raw = is_array( $raw ) ? $raw : ( null === $raw || '' === $raw ? array() : array( $raw ) );
			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $raw ) ), 'strlen' ) );
		}
		if ( is_array( $raw ) ) {
			$raw = '';
		}
		$raw = (string) $raw;

		switch ( $field['type'] ) {
			case 'email':
				return sanitize_email( $raw );
			case 'url':
				return '' === trim( $raw ) ? '' : esc_url_raw( trim( $raw ), array( 'http', 'https' ) );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'number':
				$raw = trim( $raw );
				return is_numeric( $raw ) ? $raw : ( '' === $raw ? '' : sanitize_text_field( $raw ) );
			case 'consent':
				return '' === $raw ? '' : 'yes';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Validate a sanitized value. Returns an error message or empty string.
	 *
	 * @param array           $field Field definition.
	 * @param string|string[] $value Sanitized value.
	 * @param mixed           $raw   Raw value (to detect values sanitization stripped).
	 * @return string
	 */
	public static function validate( $field, $value, $raw = null ) {
		$is_empty = is_array( $value ) ? 0 === count( $value ) : '' === trim( (string) $value );

		if ( $is_empty ) {
			if ( ! $field['required'] ) {
				// A non-empty raw value that sanitized to empty (e.g. "not-an-email") is invalid, not blank.
				if ( is_string( $raw ) && '' !== trim( $raw ) && in_array( $field['type'], array( 'email', 'url' ), true ) ) {
					return self::type_message( $field['type'] );
				}
				return '';
			}
			if ( 'consent' === $field['type'] ) {
				return __( 'Please check this box to continue.', 'borsflow-forms' );
			}
			if ( is_string( $raw ) && '' !== trim( $raw ) && in_array( $field['type'], array( 'email', 'url' ), true ) ) {
				return self::type_message( $field['type'] );
			}
			return __( 'This field is required.', 'borsflow-forms' );
		}

		$type = $field['type'];

		if ( is_array( $value ) || in_array( $type, array( 'select', 'radio' ), true ) ) {
			$allowed = wp_list_pluck( $field['options'], 'value' );
			foreach ( (array) $value as $v ) {
				if ( ! in_array( $v, $allowed, true ) ) {
					return __( 'Please choose a valid option.', 'borsflow-forms' );
				}
			}
			return '';
		}

		$length = mb_strlen( $value );
		if ( '' !== $field['min_length'] && $length < (int) $field['min_length'] ) {
			/* translators: %d: minimum number of characters. */
			return sprintf( _n( 'Please enter at least %d character.', 'Please enter at least %d characters.', (int) $field['min_length'], 'borsflow-forms' ), (int) $field['min_length'] );
		}
		if ( '' !== $field['max_length'] && $length > (int) $field['max_length'] ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( _n( 'Please enter no more than %d character.', 'Please enter no more than %d characters.', (int) $field['max_length'], 'borsflow-forms' ), (int) $field['max_length'] );
		}

		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					return self::type_message( $type );
				}
				break;
			case 'url':
				if ( ! wp_http_validate_url( $value ) && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return self::type_message( $type );
				}
				break;
			case 'phone':
				if ( ! preg_match( '/^[0-9+().\-\s\/]{5,30}$/', $value ) || preg_match_all( '/\d/', $value ) < 5 ) {
					return self::type_message( $type );
				}
				break;
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return self::type_message( $type );
				}
				if ( '' !== $field['min'] && is_numeric( $field['min'] ) && (float) $value < (float) $field['min'] ) {
					/* translators: %s: minimum value. */
					return sprintf( __( 'Please enter a value of at least %s.', 'borsflow-forms' ), $field['min'] );
				}
				if ( '' !== $field['max'] && is_numeric( $field['max'] ) && (float) $value > (float) $field['max'] ) {
					/* translators: %s: maximum value. */
					return sprintf( __( 'Please enter a value no greater than %s.', 'borsflow-forms' ), $field['max'] );
				}
				break;
			case 'date':
				$d = DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
					return self::type_message( $type );
				}
				if ( ( '' !== $field['min'] && $value < $field['min'] ) || ( '' !== $field['max'] && $value > $field['max'] ) ) {
					return __( 'Please choose a date within the allowed range.', 'borsflow-forms' );
				}
				break;
			case 'time':
				if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value ) ) {
					return self::type_message( $type );
				}
				if ( ( '' !== $field['min'] && $value < $field['min'] ) || ( '' !== $field['max'] && $value > $field['max'] ) ) {
					return __( 'Please choose a time within the allowed range.', 'borsflow-forms' );
				}
				break;
		}

		if ( '' !== $field['pattern'] ) {
			$regex = self::compile_pattern( $field['pattern'] );
			if ( $regex && ! preg_match( $regex, $value ) ) {
				return '' !== $field['pattern_message'] ? $field['pattern_message'] : __( 'Please match the requested format.', 'borsflow-forms' );
			}
		}

		return '';
	}

	/**
	 * Type mismatch messages, shared with the front-end script.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	public static function type_message( $type ) {
		$messages = self::messages();
		return $messages[ $type ] ?? $messages['invalid'];
	}

	/**
	 * Validation messages exposed to JavaScript so both sides say the same thing.
	 *
	 * @return array<string,string>
	 */
	public static function messages() {
		return array(
			'required'   => __( 'This field is required.', 'borsflow-forms' ),
			'consent'    => __( 'Please check this box to continue.', 'borsflow-forms' ),
			'email'      => __( 'Please enter a valid email address.', 'borsflow-forms' ),
			'url'        => __( 'Please enter a valid URL, including http:// or https://.', 'borsflow-forms' ),
			'phone'      => __( 'Please enter a valid phone number.', 'borsflow-forms' ),
			'number'     => __( 'Please enter a number.', 'borsflow-forms' ),
			'date'       => __( 'Please enter a valid date.', 'borsflow-forms' ),
			'time'       => __( 'Please enter a valid time.', 'borsflow-forms' ),
			'invalid'    => __( 'Please enter a valid value.', 'borsflow-forms' ),
			'pattern'    => __( 'Please match the requested format.', 'borsflow-forms' ),
			/* translators: %d: number of characters. */
			'min_length' => __( 'Please enter at least %d characters.', 'borsflow-forms' ),
			/* translators: %d: number of characters. */
			'max_length' => __( 'Please enter no more than %d characters.', 'borsflow-forms' ),
			/* translators: %s: number. */
			'min'        => __( 'Please enter a value of at least %s.', 'borsflow-forms' ),
			/* translators: %s: number. */
			'max'        => __( 'Please enter a value no greater than %s.', 'borsflow-forms' ),
			'range'      => __( 'Please choose a value within the allowed range.', 'borsflow-forms' ),
			/* translators: %s: comma separated list of file extensions. */
			'file_type'  => __( 'This file type is not allowed. Allowed: %s.', 'borsflow-forms' ),
			/* translators: %s: maximum size, e.g. "5 MB". */
			'file_size'  => __( 'This file is too large. Maximum size: %s.', 'borsflow-forms' ),
			'generic'    => __( 'Please fix the highlighted fields and try again.', 'borsflow-forms' ),
			'network'    => __( 'Something went wrong. Please try again.', 'borsflow-forms' ),
			'preview'    => __( 'Preview: the form is valid and would be submitted now.', 'borsflow-forms' ),
		);
	}

	/**
	 * Evaluate conditional logic for every field against submitted values.
	 *
	 * A field whose rules reference a hidden field sees that field's value as
	 * empty, so chains of conditions collapse the same way they do in the browser.
	 *
	 * @param array[]              $fields Field definitions.
	 * @param array<string,mixed>  $values Values keyed by field key.
	 * @return array<string,bool> Visibility keyed by field id.
	 */
	public static function visibility( $fields, $values ) {
		$by_key = array();
		foreach ( $fields as $f ) {
			if ( '' !== $f['key'] ) {
				$by_key[ $f['key'] ] = $f;
			}
		}
		$memo    = array();
		$resolve = function ( $field, $depth = 0 ) use ( &$resolve, &$memo, $by_key, $values ) {
			if ( isset( $memo[ $field['id'] ] ) ) {
				return $memo[ $field['id'] ];
			}
			$c = $field['conditions'];
			if ( empty( $c['enabled'] ) || empty( $c['rules'] ) || $depth > 20 ) {
				return $memo[ $field['id'] ] = true;
			}
			$results = array();
			foreach ( $c['rules'] as $rule ) {
				$actual = '';
				if ( isset( $by_key[ $rule['field'] ] ) ) {
					$source = $by_key[ $rule['field'] ];
					$actual = ( $source['id'] !== $field['id'] && $resolve( $source, $depth + 1 ) ) ? ( $values[ $rule['field'] ] ?? '' ) : '';
				}
				$results[] = self::rule_matches( $rule, $actual );
			}
			$match = 'or' === $c['logic'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
			return $memo[ $field['id'] ] = ( 'show' === $c['action'] ) ? $match : ! $match;
		};

		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f['id'] ] = $resolve( $f );
		}
		return $out;
	}

	/**
	 * Evaluate one rule. Mirrors `ruleMatches()` in assets/js/frontend.js.
	 *
	 * @param array           $rule   Rule.
	 * @param string|string[] $actual Current value of the referenced field.
	 * @return bool
	 */
	public static function rule_matches( $rule, $actual ) {
		$values   = is_array( $actual ) ? array_map( 'strval', $actual ) : ( '' === (string) $actual ? array() : array( (string) $actual ) );
		$expected = (string) $rule['value'];

		switch ( $rule['operator'] ) {
			case 'empty':
				return 0 === count( $values );
			case 'not_empty':
				return count( $values ) > 0;
			case 'not_equals':
				return ! in_array( $expected, $values, true );
			case 'contains':
				foreach ( $values as $v ) {
					if ( '' !== $expected && false !== mb_stripos( $v, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'equals':
			default:
				return in_array( $expected, $values, true );
		}
	}
}
