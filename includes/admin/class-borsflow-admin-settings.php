<?php
/**
 * Global settings screen (manage_options).
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings API registration and rendering.
 */
class BorsFlow_Admin_Settings {

	const GROUP = 'borsflow_settings_group';

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			BorsFlow_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BorsFlow_Settings', 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Page callback.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'borsflow-forms' ), 403 );
		}
		$s    = BorsFlow_Settings::all();
		$name = BorsFlow_Settings::OPTION;
		?>
		<div class="wrap borsflow-settings">
			<h1><?php esc_html_e( 'BorsFlow Forms Settings', 'borsflow-forms' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'BorsFlow CRM', 'borsflow-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bf-crm-base"><?php esc_html_e( 'CRM base URL', 'borsflow-forms' ); ?></label></th>
						<td>
							<?php if ( BorsFlow_Settings::is_constant( 'crm_base_url' ) ) : ?>
								<input type="url" class="regular-text code" id="bf-crm-base" value="<?php echo esc_attr( $s['crm_base_url'] ); ?>" disabled>
								<p class="description"><?php self::constant_note( 'crm_base_url' ); ?></p>
							<?php else : ?>
							<input type="url" class="regular-text code" id="bf-crm-base" name="<?php echo esc_attr( $name ); ?>[crm_base_url]" value="<?php echo esc_attr( $s['crm_base_url'] ); ?>" placeholder="https://app.borsflow.com">
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'For local development use e.g. http://localhost:4000 (or http://host.docker.internal:4000 when WordPress runs in Docker).', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<?php self::secret_row( 'crm_api_key', __( 'API key', 'borsflow-forms' ), $s ); ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'borsflow-forms' ); ?></th>
						<td>
							<button type="button" class="button" id="borsflow-test-connection"><?php esc_html_e( 'Test connection', 'borsflow-forms' ); ?></button>
							<span id="borsflow-test-result" class="borsflow-test-result" role="status" aria-live="polite"></span>
							<p class="description"><?php esc_html_e( 'Uses the URL and key typed above (or the saved key if the field is empty), so you can test before saving.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-leads-path"><?php esc_html_e( 'Endpoints', 'borsflow-forms' ); ?></label></th>
						<td>
							<label for="bf-leads-path"><?php esc_html_e( 'Create lead (POST)', 'borsflow-forms' ); ?></label><br>
							<input type="text" class="regular-text code" id="bf-leads-path" name="<?php echo esc_attr( $name ); ?>[crm_leads_path]" value="<?php echo esc_attr( $s['crm_leads_path'] ); ?>"><br>
							<label for="bf-health-path"><?php esc_html_e( 'Test connection (GET)', 'borsflow-forms' ); ?></label><br>
							<input type="text" class="regular-text code" id="bf-health-path" name="<?php echo esc_attr( $name ); ?>[crm_health_path]" value="<?php echo esc_attr( $s['crm_health_path'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-timeout"><?php esc_html_e( 'Request timeout', 'borsflow-forms' ); ?></label></th>
						<td><input type="number" min="3" max="60" class="small-text" id="bf-timeout" name="<?php echo esc_attr( $name ); ?>[crm_timeout]" value="<?php echo esc_attr( $s['crm_timeout'] ); ?>"> <?php esc_html_e( 'seconds', 'borsflow-forms' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-max-attempts"><?php esc_html_e( 'Max sync attempts', 'borsflow-forms' ); ?></label></th>
						<td>
							<input type="number" min="1" max="20" class="small-text" id="bf-max-attempts" name="<?php echo esc_attr( $name ); ?>[crm_max_attempts]" value="<?php echo esc_attr( $s['crm_max_attempts'] ); ?>">
							<p class="description"><?php esc_html_e( 'Retries back off: 1 min, 5 min, 30 min, 2 h, then every 6 h.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lead defaults', 'borsflow-forms' ); ?></th>
						<td>
							<fieldset>
								<label><?php esc_html_e( 'Source', 'borsflow-forms' ); ?><br><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crm_default_source]" value="<?php echo esc_attr( $s['crm_default_source'] ); ?>"></label><br>
								<label><?php esc_html_e( 'Pipeline', 'borsflow-forms' ); ?><br><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crm_default_pipeline]" value="<?php echo esc_attr( $s['crm_default_pipeline'] ); ?>"></label><br>
								<label><?php esc_html_e( 'Stage', 'borsflow-forms' ); ?><br><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crm_default_stage]" value="<?php echo esc_attr( $s['crm_default_stage'] ); ?>"></label>
								<p class="description"><?php esc_html_e( 'Each form can override these in its CRM tab.', 'borsflow-forms' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Spam protection', 'borsflow-forms' ); ?></h2>
				<p><?php esc_html_e( 'A honeypot field is always on. Enable a time trap or captcha per form in the builder’s Settings tab.', 'borsflow-forms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bf-recaptcha-site"><?php esc_html_e( 'reCAPTCHA v3 site key', 'borsflow-forms' ); ?></label></th>
						<td><input type="text" class="regular-text code" id="bf-recaptcha-site" name="<?php echo esc_attr( $name ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>"></td>
					</tr>
					<?php self::secret_row( 'recaptcha_secret', __( 'reCAPTCHA v3 secret key', 'borsflow-forms' ), $s ); ?>
					<tr>
						<th scope="row"><label for="bf-recaptcha-threshold"><?php esc_html_e( 'reCAPTCHA score threshold', 'borsflow-forms' ); ?></label></th>
						<td><input type="number" step="0.1" min="0" max="1" class="small-text" id="bf-recaptcha-threshold" name="<?php echo esc_attr( $name ); ?>[recaptcha_threshold]" value="<?php echo esc_attr( $s['recaptcha_threshold'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-turnstile-site"><?php esc_html_e( 'Turnstile site key', 'borsflow-forms' ); ?></label></th>
						<td><input type="text" class="regular-text code" id="bf-turnstile-site" name="<?php echo esc_attr( $name ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></td>
					</tr>
					<?php self::secret_row( 'turnstile_secret', __( 'Turnstile secret key', 'borsflow-forms' ), $s ); ?>
					<tr>
						<th scope="row"><label for="bf-rate-max"><?php esc_html_e( 'Rate limit', 'borsflow-forms' ); ?></label></th>
						<td>
							<?php
							printf(
								/* translators: 1: number input, 2: minutes input. */
								esc_html__( 'At most %1$s submissions per IP per form every %2$s minutes.', 'borsflow-forms' ),
								'<input type="number" min="0" class="small-text" id="bf-rate-max" name="' . esc_attr( $name ) . '[rate_limit_max]" value="' . esc_attr( $s['rate_limit_max'] ) . '">',
								'<input type="number" min="1" class="small-text" aria-label="' . esc_attr__( 'Minutes', 'borsflow-forms' ) . '" name="' . esc_attr( $name ) . '[rate_limit_window]" value="' . esc_attr( $s['rate_limit_window'] ) . '">'
							);
							?>
							<p class="description"><?php esc_html_e( '0 disables the limit.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-proxy-header"><?php esc_html_e( 'Visitor IP source', 'borsflow-forms' ); ?></label></th>
						<td>
							<select id="bf-proxy-header" name="<?php echo esc_attr( $name ); ?>[proxy_header]">
								<?php foreach ( BorsFlow_Settings::proxy_headers() as $header => $label ) : ?>
									<option value="<?php echo esc_attr( $header ); ?>" <?php selected( $s['proxy_header'], $header ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Behind Cloudflare, a load balancer or a reverse proxy every visitor shares the proxy’s address, which makes the rate limit block everyone at once. Pick the header your proxy sets. Only do this if all traffic passes through that proxy, otherwise visitors can fake the header.', 'borsflow-forms' ); ?></p>
							<p class="description">
								<?php
								/* translators: 1: detected IP, 2: REMOTE_ADDR. */
								echo esc_html( sprintf( __( 'Your IP as detected with the saved setting: %1$s (connection address: %2$s).', 'borsflow-forms' ), BorsFlow_Spam::client_ip() ?: '—', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '—' ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-token-age"><?php esc_html_e( 'Form token lifetime', 'borsflow-forms' ); ?></label></th>
						<td>
							<input type="number" min="0" class="small-text" id="bf-token-age" name="<?php echo esc_attr( $name ); ?>[token_max_age]" value="<?php echo esc_attr( $s['token_max_age'] ); ?>"> <?php esc_html_e( 'hours', 'borsflow-forms' ); ?>
							<p class="description"><?php esc_html_e( 'Rejects replayed submissions that use an old form token. Cached pages fetch a fresh token automatically, so visitors are not affected. 0 = never expire.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'File uploads', 'borsflow-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bf-upload-max"><?php esc_html_e( 'Maximum file size', 'borsflow-forms' ); ?></label></th>
						<td>
							<input type="number" min="1" max="<?php echo esc_attr( BorsFlow_Settings::server_upload_limit_mb() ); ?>" class="small-text" id="bf-upload-max" name="<?php echo esc_attr( $name ); ?>[upload_max_mb]" value="<?php echo esc_attr( $s['upload_max_mb'] ); ?>"> MB
							<p class="description">
								<?php
								/* translators: %s: size. */
								echo esc_html( sprintf( __( 'Your server allows up to %s.', 'borsflow-forms' ), size_format( wp_max_upload_size() ) ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bf-upload-ext"><?php esc_html_e( 'Allowed extensions', 'borsflow-forms' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="bf-upload-ext" name="<?php echo esc_attr( $name ); ?>[upload_allowed_ext]" value="<?php echo esc_attr( $s['upload_allowed_ext'] ); ?>">
							<p class="description">
								<?php
								/* translators: %s: directory path. */
								echo esc_html( sprintf( __( 'Comma separated. Files are stored with random names in %s, protected by deny-all rules, and served only to logged-in managers. On nginx, move this folder outside the web root with the borsflow_upload_dir filter.', 'borsflow-forms' ), BorsFlow_Uploads::dir() ) );
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Data', 'borsflow-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bf-retention"><?php esc_html_e( 'Retention', 'borsflow-forms' ); ?></label></th>
						<td>
							<?php
							printf(
								/* translators: %s: number input. */
								esc_html__( 'Automatically delete submissions older than %s days.', 'borsflow-forms' ),
								'<input type="number" min="0" class="small-text" id="bf-retention" name="' . esc_attr( $name ) . '[retention_days]" value="' . esc_attr( $s['retention_days'] ) . '">'
							);
							?>
							<p class="description"><?php esc_html_e( '0 keeps submissions forever. Runs daily via WP-Cron.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Privacy', 'borsflow-forms' ); ?></th>
						<td>
							<label for="bf-store-ip">
								<input type="checkbox" id="bf-store-ip" name="<?php echo esc_attr( $name ); ?>[store_ip]" value="1" <?php checked( $s['store_ip'] ); ?>>
								<?php esc_html_e( 'Store the visitor’s IP address and browser user agent with each submission', 'borsflow-forms' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Rate limiting keeps working when this is off; the IP is only used in memory. Submissions are included in Tools → Export/Erase Personal Data, matched by their email fields.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email delivery', 'borsflow-forms' ); ?></th>
						<td>
							<label for="bf-email-async">
								<input type="checkbox" id="bf-email-async" name="<?php echo esc_attr( $name ); ?>[email_async]" value="1" <?php checked( $s['email_async'] ); ?>>
								<?php esc_html_e( 'Send notification emails in the background (WP-Cron)', 'borsflow-forms' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommended: a slow mail server never delays the visitor. Turn off only if WP-Cron does not run on this site; emails are then sent during the submission.', 'borsflow-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'borsflow-forms' ); ?></th>
						<td>
							<label for="bf-delete-uninstall">
								<input type="checkbox" id="bf-delete-uninstall" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>>
								<?php esc_html_e( 'Delete all forms, submissions and uploaded files when the plugin is deleted', 'borsflow-forms' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * "Set in wp-config.php" note.
	 *
	 * @param string $key Setting key.
	 */
	private static function constant_note( $key ) {
		$map = BorsFlow_Settings::constants();
		/* translators: %s: PHP constant name. */
		echo esc_html( sprintf( __( 'Set by the %s constant in wp-config.php and cannot be changed here.', 'borsflow-forms' ), $map[ $key ] ) );
	}

	/**
	 * Password row for a secret. The stored value is never printed; only a mask.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function secret_row( $key, $label, $s ) {
		$name  = BorsFlow_Settings::OPTION;
		$saved = '' !== (string) $s[ $key ];
		$id    = 'bf-' . str_replace( '_', '-', $key );
		if ( BorsFlow_Settings::is_constant( $key ) ) {
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="password" class="regular-text code" id="<?php echo esc_attr( $id ); ?>" value="" disabled placeholder="<?php echo esc_attr( BorsFlow_Settings::mask( $s[ $key ] ) ); ?>">
					<p class="description"><?php self::constant_note( $key ); ?></p>
				</td>
			</tr>
			<?php
			return;
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="password" class="regular-text code" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="" autocomplete="new-password"
					placeholder="<?php echo esc_attr( $saved ? BorsFlow_Settings::mask( $s[ $key ] ) : '' ); ?>">
				<?php if ( $saved ) : ?>
					<p class="description"><?php esc_html_e( 'Saved. Leave blank to keep the current value, or type a new one to replace it.', 'borsflow-forms' ); ?></p>
					<label><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $key . '_clear]' ); ?>" value="1"> <?php esc_html_e( 'Remove saved value', 'borsflow-forms' ); ?></label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
