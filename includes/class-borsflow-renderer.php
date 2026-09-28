<?php
/**
 * Front-end form rendering (shortcode, block, Elementor widget and builder preview).
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders accessible form markup.
 */
class BorsFlow_Renderer {

	const HONEYPOT = 'bf_hp_website';

	/**
	 * Per-request instance counter so the same form can appear twice on a page.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Form IDs that already received their anchor ID on this page.
	 *
	 * @var array<int,bool>
	 */
	private static $anchored = array();

	/**
	 * Register front-end assets.
	 */
	public static function register_assets() {
		wp_register_style( 'borsflow-forms', BORSFLOW_URL . 'assets/css/frontend.css', array(), BORSFLOW_VERSION );
		wp_register_script( 'borsflow-forms', BORSFLOW_URL . 'assets/js/frontend.js', array(), BORSFLOW_VERSION, true );
		wp_localize_script(
			'borsflow-forms',
			'borsflowForms',
			array(
				'messages' => BorsFlow_Fields::messages(),
			)
		);
	}

	/**
	 * Enqueue in <head> when the current post obviously contains a form, avoiding a flash of unstyled form.
	 */
	public static function maybe_enqueue_early() {
		$post = get_post();
		if ( ! is_singular() || ! $post ) {
			return;
		}
		if ( has_shortcode( $post->post_content, 'borsflow_form' ) || has_block( 'borsflow/form', $post ) ) {
			wp_enqueue_style( 'borsflow-forms' );
			wp_enqueue_script( 'borsflow-forms' );
		}
	}

	/**
	 * Shortcode callback: [borsflow_form id="12"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'borsflow_form' );
		return self::render_by_id( absint( $atts['id'] ) );
	}

	/**
	 * Render a form by ID, with a friendly placeholder for editors when it is missing.
	 *
	 * @param int $id Form ID.
	 * @return string
	 */
	public static function render_by_id( $id ) {
		$form = $id ? BorsFlow_Form::get( $id, false ) : null;
		if ( ! $form ) {
			if ( BorsFlow_Plugin::can_manage() ) {
				return '<p class="borsflow-missing">' . esc_html__( 'BorsFlow Forms: this form does not exist or is disabled.', 'borsflow-forms' ) . '</p>';
			}
			return '';
		}
		return self::render( $form );
	}

	/**
	 * Render a form.
	 *
	 * @param array $form Form (see BorsFlow_Form::get()).
	 * @param array $args { preview: bool }.
	 * @return string
	 */
	public static function render( $form, $args = array() ) {
		$preview = ! empty( $args['preview'] );
		$s       = $form['settings'];

		if ( ! $preview ) {
			wp_enqueue_style( 'borsflow-forms' );
			wp_enqueue_script( 'borsflow-forms' );
			self::enqueue_captcha( $s['spam']['captcha'] );
		}

		++self::$instance;
		$uid = 'bf' . $form['id'] . '-' . self::$instance;
		// Anchor for the no-JS redirect (#borsflow-{id}); only the first instance of a form gets it.
		$anchor                        = isset( self::$anchored[ $form['id'] ] ) ? '' : 'borsflow-' . $form['id'];
		self::$anchored[ $form['id'] ] = true;

		// Result of a no-JS submission, carried across the redirect in a short-lived transient.
		$result = self::fallback_result( $form['id'] );
		$old    = $result['values'] ?? array();
		$errors = $result['errors'] ?? array();

		$style_vars = sprintf(
			'--bf-accent:%s;--bf-radius:%dpx;',
			esc_attr( $s['style']['accent'] ),
			(int) $s['style']['radius']
		);

		$config = array(
			'rest'        => rest_url( 'borsflow/v1/forms/' . $form['id'] . '/submit' ),
			'tokenUrl'    => rest_url( 'borsflow/v1/forms/' . $form['id'] . '/token' ),
			'tokenMaxAge' => BorsFlow_Spam::token_max_age(),
			'submitText'  => $s['submit_text'],
			'loadingText' => $s['loading_text'],
			'captcha'     => self::captcha_config( $s['spam']['captcha'] ),
			'preview'     => $preview,
		);

		$classes = array(
			'borsflow',
			'borsflow-form-' . $form['id'],
			'bf-preset-' . $s['style']['preset'],
			'bf-size-' . $s['style']['size'],
		);

		ob_start();
		?>
		<div <?php echo $anchor ? 'id="' . esc_attr( $anchor ) . '" ' : ''; ?>class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style_vars ); ?>" data-borsflow-form="<?php echo esc_attr( $form['id'] ); ?>">
			<div class="bf-status" id="<?php echo esc_attr( $uid ); ?>-status" role="status" aria-live="polite" aria-atomic="true">
			<?php
			if ( ! empty( $result['success'] ) ) {
				echo '<div class="bf-success">' . wp_kses_post( wpautop( $result['message'] ) ) . '</div>';
			}
			?>
			</div>
			<div class="bf-alert" id="<?php echo esc_attr( $uid ); ?>-alert" role="alert"<?php echo empty( $result['message'] ) || ! empty( $result['success'] ) ? ' hidden' : ''; ?>>
			<?php
			if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
				echo esc_html( $result['message'] );
			}
			?>
			</div>
			<?php if ( empty( $result['success'] ) ) : ?>
			<form class="bf-form" id="<?php echo esc_attr( $uid ); ?>" method="post" enctype="multipart/form-data" novalidate
				action="<?php echo esc_url( $preview ? '#' : admin_url( 'admin-post.php' ) ); ?>"
				data-bf-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
				<input type="hidden" name="action" value="borsflow_submit">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<input type="hidden" name="bf_token" value="<?php echo esc_attr( BorsFlow_Spam::issue_token( $form['id'] ) ); ?>">
				<input type="hidden" name="bf_page_url" value="<?php echo esc_url( self::current_url() ); ?>">
				<div class="bf-hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $uid ); ?>-hp"><?php esc_html_e( 'Leave this field empty', 'borsflow-forms' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-hp" name="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off">
				</div>
				<div class="bf-grid">
					<?php
					foreach ( $form['fields'] as $field ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
						echo self::render_field( $field, $uid, $old, $errors[ $field['key'] ] ?? '' );
					}
					?>
				</div>
				<?php if ( 'turnstile' === $config['captcha']['type'] ) : ?>
					<div class="bf-captcha cf-turnstile" data-sitekey="<?php echo esc_attr( $config['captcha']['siteKey'] ); ?>"></div>
				<?php elseif ( 'recaptcha' === $config['captcha']['type'] ) : ?>
					<input type="hidden" name="bf_recaptcha" value="">
				<?php endif; ?>
				<div class="bf-actions">
					<button type="submit" class="bf-submit"><?php echo esc_html( $s['submit_text'] ); ?></button>
				</div>
			</form>
			<?php endif; ?>
		</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filter the rendered form markup.
		 *
		 * @param string $html Markup.
		 * @param array  $form Form.
		 * @param array  $args Render arguments.
		 */
		return apply_filters( 'borsflow_form_html', $html, $form, $args );
	}

	/**
	 * Render one field.
	 *
	 * @param array  $f     Field definition.
	 * @param string $uid   Form instance ID prefix.
	 * @param array  $old   Previously submitted values (no-JS error round-trip).
	 * @param string $error Server-side error for this field.
	 * @return string
	 */
	public static function render_field( $f, $uid, $old = array(), $error = '' ) {
		$type  = $f['type'];
		$wrap  = array( 'bf-field', 'bf-type-' . $type, 'bf-w-' . $f['width'] );
		$attrs = '';
		if ( '' !== $f['css_class'] ) {
			$wrap[] = $f['css_class'];
		}
		if ( ! empty( $f['conditions']['enabled'] ) && ! empty( $f['conditions']['rules'] ) ) {
			$attrs .= ' data-bf-cond="' . esc_attr( wp_json_encode( $f['conditions'] ) ) . '"';
		}

		if ( 'heading' === $type ) {
			$tag = in_array( $f['level'], array( 'h2', 'h3', 'h4' ), true ) ? $f['level'] : 'h3';
			return sprintf( '<div class="%s"%s><%s class="bf-heading">%s</%s></div>', esc_attr( implode( ' ', $wrap ) ), $attrs, $tag, esc_html( $f['content'] ?: $f['label'] ), $tag );
		}
		if ( 'html' === $type ) {
			return sprintf( '<div class="%s"%s><div class="bf-html">%s</div></div>', esc_attr( implode( ' ', $wrap ) ), $attrs, wp_kses_post( $f['content'] ) );
		}

		$id       = $uid . '-' . $f['key'];
		$name     = 'bf[' . $f['key'] . ']';
		$help_id  = $id . '-help';
		$err_id   = $id . '-error';
		$value    = array_key_exists( $f['key'], $old ) ? $old[ $f['key'] ] : self::default_value( $f );
		$label    = '' !== $f['label'] ? $f['label'] : $f['key'];
		$describe = array();
		if ( '' !== $f['help'] ) {
			$describe[] = $help_id;
		}
		if ( '' !== $error ) {
			$describe[] = $err_id;
			$wrap[]     = 'bf-has-error';
		}

		if ( 'hidden' === $type ) {
			$query = preg_match( '/^\{query:([A-Za-z0-9_\-]+)\}$/', $f['default'], $m ) ? $m[1] : '';
			return sprintf(
				'<input type="hidden" name="%s" value="%s" data-bf-key="%s"%s>',
				esc_attr( $name ),
				esc_attr( is_array( $value ) ? '' : $value ),
				esc_attr( $f['key'] ),
				'' !== $query ? ' data-bf-query="' . esc_attr( $query ) . '"' : ''
			);
		}

		// Shared ARIA / validation attributes for single inputs.
		$common = array(
			'id'                      => $id,
			'name'                    => $name,
			'aria-describedby'        => implode( ' ', $describe ),
			'aria-invalid'            => '' !== $error ? 'true' : '',
			'required'                => $f['required'],
			'aria-required'           => $f['required'] ? 'true' : '',
			'data-bf-key'             => $f['key'],
			'data-bf-type'            => $type,
			'data-bf-pattern-message' => $f['pattern_message'],
		);

		$req_mark = $f['required'] ? ' <span class="bf-required" aria-hidden="true">*</span>' : '';
		$help     = '' !== $f['help'] ? '<p class="bf-help" id="' . esc_attr( $help_id ) . '">' . esc_html( $f['help'] ) . '</p>' : '';
		$err      = '<p class="bf-error" id="' . esc_attr( $err_id ) . '"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';

		$group = in_array( $type, array( 'radio', 'checkboxes' ), true );
		$html  = '';

		if ( $group ) {
			$html    .= '<fieldset class="bf-group" data-bf-key="' . esc_attr( $f['key'] ) . '" data-bf-type="' . esc_attr( $type ) . '"'
				. ( $f['required'] ? ' data-bf-required="1"' : '' )
				. ( $describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : '' ) . '>';
			$html    .= '<legend class="bf-label">' . esc_html( $label ) . $req_mark . '</legend>';
			$html    .= '<div class="bf-options">';
			$selected = (array) $value;
			foreach ( $f['options'] as $i => $opt ) {
				$oid   = $id . '-' . $i;
				$html .= sprintf(
					'<label class="bf-option" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s%6$s%7$s> <span>%8$s</span></label>',
					esc_attr( $oid ),
					'radio' === $type ? 'radio' : 'checkbox',
					esc_attr( 'radio' === $type ? $name : $name . '[]' ),
					esc_attr( $opt['value'] ),
					checked( in_array( $opt['value'], $selected, true ), true, false ),
					( 'radio' === $type && $f['required'] ) ? ' required' : '',
					'' !== $error ? ' aria-invalid="true"' : '',
					esc_html( $opt['label'] )
				);
			}
			$html .= '</div>' . $help . $err . '</fieldset>';
		} elseif ( 'consent' === $type ) {
			$html .= '<div class="bf-consent"><label class="bf-option" for="' . esc_attr( $id ) . '"><input type="checkbox" value="yes"'
				. self::attrs( $common ) . checked( 'yes', $value, false ) . '> <span>'
				. ( '' !== $f['content'] ? wp_kses_post( $f['content'] ) : esc_html( $label ) ) . $req_mark . '</span></label></div>' . $help . $err;
		} else {
			$html .= '<label class="bf-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_mark . '</label>';
			$html .= self::control( $f, $common, $value );
			$html .= $help . $err;
		}

		return sprintf( '<div class="%s" data-bf-field="%s"%s>%s</div>', esc_attr( implode( ' ', $wrap ) ), esc_attr( $f['key'] ), $attrs, $html );
	}

	/**
	 * Render the input control for single-value fields.
	 *
	 * @param array        $f      Field.
	 * @param array        $common Common attributes.
	 * @param string|array $value  Current value.
	 * @return string
	 */
	private static function control( $f, $common, $value ) {
		$type = $f['type'];

		if ( 'textarea' === $type ) {
			return '<textarea' . self::attrs(
				$common + array(
					'rows'            => $f['rows'],
					'placeholder'     => $f['placeholder'],
					'minlength'       => $f['min_length'],
					'maxlength'       => $f['max_length'],
					'data-bf-pattern' => $f['pattern'],
				)
			) . '>' . esc_textarea( is_array( $value ) ? '' : $value ) . '</textarea>';
		}

		if ( 'select' === $type || 'multiselect' === $type ) {
			$multi   = 'multiselect' === $type;
			$attrs   = $common;
			$current = (array) $value;
			if ( $multi ) {
				$attrs['name']     = $common['name'] . '[]';
				$attrs['multiple'] = true;
				$attrs['size']     = min( 8, max( 3, count( $f['options'] ) ) );
			}
			$html = '<select' . self::attrs( $attrs ) . '>';
			if ( ! $multi ) {
				$html .= '<option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( '— Select —', 'borsflow-forms' ) ) . '</option>';
			}
			foreach ( $f['options'] as $opt ) {
				$html .= '<option value="' . esc_attr( $opt['value'] ) . '"' . selected( in_array( $opt['value'], $current, true ), true, false ) . '>' . esc_html( $opt['label'] ) . '</option>';
			}
			return $html . '</select>';
		}

		if ( 'file' === $type ) {
			$exts = BorsFlow_Fields::file_extensions( $f );
			return '<input type="file"' . self::attrs(
				array_merge(
					$common,
					array(
						'name'                   => 'bf_file_' . $f['key'],
						'accept'                 => implode( ',', array_map( static fn( $e ) => '.' . $e, $exts ) ),
						'data-bf-max-size'       => BorsFlow_Fields::file_max_bytes( $f ),
						'data-bf-max-size-label' => size_format( BorsFlow_Fields::file_max_bytes( $f ) ),
						'data-bf-exts'           => implode( ',', $exts ),
					)
				)
			) . '>';
		}

		$html_types   = array(
			'text'   => 'text',
			'email'  => 'email',
			'phone'  => 'tel',
			'number' => 'number',
			'date'   => 'date',
			'time'   => 'time',
			'url'    => 'url',
		);
		$autocomplete = array(
			'email' => 'email',
			'phone' => 'tel',
			'url'   => 'url',
		);

		$attrs = $common + array(
			'type'         => $html_types[ $type ] ?? 'text',
			'value'        => is_array( $value ) ? '' : $value,
			'placeholder'  => $f['placeholder'],
			'autocomplete' => $autocomplete[ $type ] ?? self::guess_autocomplete( $f['key'] ),
		);
		if ( in_array( $type, array( 'number', 'date', 'time' ), true ) ) {
			$attrs['min']  = $f['min'];
			$attrs['max']  = $f['max'];
			$attrs['step'] = 'number' === $type ? ( '' !== $f['step'] ? $f['step'] : 'any' ) : '';
		} else {
			$attrs['minlength']       = $f['min_length'];
			$attrs['maxlength']       = $f['max_length'];
			$attrs['data-bf-pattern'] = $f['pattern'];
		}
		return '<input' . self::attrs( $attrs ) . '>';
	}

	/**
	 * Autocomplete hints for common keys.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function guess_autocomplete( $key ) {
		$map = array(
			'name'       => 'name',
			'full_name'  => 'name',
			'first_name' => 'given-name',
			'firstname'  => 'given-name',
			'last_name'  => 'family-name',
			'lastname'   => 'family-name',
			'company'    => 'organization',
			'position'   => 'organization-title',
			'job_title'  => 'organization-title',
		);
		return $map[ $key ] ?? '';
	}

	/**
	 * Build an attribute string, skipping empty values and rendering booleans as flags.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private static function attrs( $attrs ) {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( true === $value ) {
				$out .= ' ' . esc_attr( $name );
			} elseif ( false === $value || null === $value || '' === $value ) {
				continue;
			} else {
				$out .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
			}
		}
		return $out;
	}

	/**
	 * Default value, with checked options and hidden-field tokens resolved.
	 *
	 * @param array $f Field.
	 * @return string|string[]
	 */
	private static function default_value( $f ) {
		if ( ! empty( $f['options'] ) ) {
			$selected = array();
			foreach ( $f['options'] as $opt ) {
				if ( ! empty( $opt['selected'] ) ) {
					$selected[] = $opt['value'];
				}
			}
			if ( $selected ) {
				return BorsFlow_Fields::is_multiple( $f['type'] ) ? $selected : $selected[0];
			}
			return BorsFlow_Fields::is_multiple( $f['type'] ) ? array() : $f['default'];
		}
		if ( 'hidden' === $f['type'] ) {
			return self::resolve_tokens( $f['default'] );
		}
		return $f['default'];
	}

	/**
	 * Resolve {query:x}, {page_url}, {page_title}, {user_email} in hidden field defaults.
	 *
	 * @param string $value Template.
	 * @return string
	 */
	private static function resolve_tokens( $value ) {
		return (string) preg_replace_callback(
			'/\{(query:[A-Za-z0-9_\-]+|page_url|page_title|user_email)\}/',
			static function ( $m ) {
				if ( 0 === strpos( $m[1], 'query:' ) ) {
					$param = substr( $m[1], 6 );
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
					return isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
				}
				switch ( $m[1] ) {
					case 'page_url':
						return self::current_url();
					case 'page_title':
						return is_singular() ? get_the_title() : wp_get_document_title();
					case 'user_email':
						return is_user_logged_in() ? wp_get_current_user()->user_email : '';
				}
				return '';
			},
			$value
		);
	}

	/**
	 * Current front-end URL.
	 *
	 * @return string
	 */
	public static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return remove_query_arg( array( 'borsflow_result' ), home_url( $uri ) );
	}

	/**
	 * Captcha config for the front-end script, or type "none" when keys are missing.
	 *
	 * @param string $type none|recaptcha|turnstile.
	 * @return array
	 */
	private static function captcha_config( $type ) {
		$key = '';
		if ( 'recaptcha' === $type ) {
			$key = BorsFlow_Settings::get( 'recaptcha_site_key' );
		} elseif ( 'turnstile' === $type ) {
			$key = BorsFlow_Settings::get( 'turnstile_site_key' );
		}
		return '' === $key ? array(
			'type'    => 'none',
			'siteKey' => '',
		) : array(
			'type'    => $type,
			'siteKey' => $key,
		);
	}

	/**
	 * Enqueue the third-party captcha script.
	 *
	 * @param string $type none|recaptcha|turnstile.
	 */
	private static function enqueue_captcha( $type ) {
		$config = self::captcha_config( $type );
		if ( 'recaptcha' === $config['type'] ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party script.
			wp_enqueue_script( 'borsflow-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $config['siteKey'] ), array(), null, true );
		} elseif ( 'turnstile' === $config['type'] ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party script.
			wp_enqueue_script( 'borsflow-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		}
	}

	/**
	 * Read the no-JS submission result for this form, if any.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	private static function fallback_result( $form_id ) {
		// The token is an unguessable transient key; stripping to [A-Za-z0-9] is the sanitization (sanitize_key would lowercase it).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = isset( $_GET['borsflow_result'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['borsflow_result'] ) ) : '';
		if ( '' === $token ) {
			return array();
		}
		$data = get_transient( 'borsflow_result_' . $token );
		if ( ! is_array( $data ) || (int) ( $data['form_id'] ?? 0 ) !== (int) $form_id ) {
			return array();
		}
		return $data;
	}
}
