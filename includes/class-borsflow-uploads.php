<?php
/**
 * Private file uploads: validation, protected storage, authenticated download.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles file-upload fields.
 */
class BorsFlow_Uploads {

	/**
	 * Absolute storage directory. Point it outside the web root with the
	 * `borsflow_upload_dir` filter (recommended on nginx, which ignores .htaccess).
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) apply_filters( 'borsflow_upload_dir', $uploads['basedir'] . '/borsflow-private' ) );
	}

	/**
	 * Create the storage directory with deny-all rules for Apache and IIS.
	 *
	 * @return bool
	 */
	public static function ensure_protected_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess'  => "# BorsFlow Forms: uploads are served only through an authenticated handler.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small static file at install time.
				file_put_contents( $dir . '/' . $name, $contents );
			}
		}
		return true;
	}

	/**
	 * Validate and store one uploaded file.
	 *
	 * @param array $field Field definition.
	 * @param array $file  Entry from $_FILES.
	 * @return array|WP_Error|null Stored file info, error, or null when nothing was uploaded.
	 */
	public static function store( $field, $file ) {
		if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return null;
		}
		$messages = BorsFlow_Fields::messages();
		$max      = BorsFlow_Fields::file_max_bytes( $field );
		$exts     = BorsFlow_Fields::file_extensions( $field );

		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $file['size'] > $max ) {
			return new WP_Error( 'borsflow_file_size', sprintf( $messages['file_size'], size_format( $max ) ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'borsflow_file_error', __( 'The file could not be uploaded. Please try again.', 'borsflow-forms' ) );
		}

		$name  = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name );
		$ext   = strtolower( (string) ( $check['ext'] ?: pathinfo( $name, PATHINFO_EXTENSION ) ) );
		if ( empty( $check['type'] ) || ! in_array( $ext, $exts, true ) ) {
			return new WP_Error( 'borsflow_file_type', sprintf( $messages['file_type'], implode( ', ', $exts ) ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}

		if ( ! self::ensure_protected_dir() ) {
			return new WP_Error( 'borsflow_file_error', __( 'The file could not be saved.', 'borsflow-forms' ) );
		}
		$sub = gmdate( 'Y/m' );
		wp_mkdir_p( self::dir() . '/' . $sub );

		// Random name without the original extension: nothing in this folder is ever executable.
		$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.upload';
		if ( ! move_uploaded_file( $file['tmp_name'], self::dir() . '/' . $stored ) ) {
			return new WP_Error( 'borsflow_file_error', __( 'The file could not be saved.', 'borsflow-forms' ) );
		}

		return array(
			'name'   => $name,
			'stored' => $stored,
			'size'   => (int) $file['size'],
			'mime'   => $check['type'],
		);
	}

	/**
	 * Absolute path of a stored file, confined to the storage directory.
	 *
	 * @param string $stored Relative stored path.
	 * @return string|null
	 */
	public static function path( $stored ) {
		if ( ! is_string( $stored ) || ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.upload$#', $stored ) ) {
			return null;
		}
		$path = self::dir() . '/' . $stored;
		return is_file( $path ) ? $path : null;
	}

	/**
	 * Authenticated download URL for a file in a submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $key           Field key.
	 * @return string
	 */
	public static function download_url( $submission_id, $key ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'borsflow_download',
					'submission' => (int) $submission_id,
					'field'      => rawurlencode( $key ),
				),
				admin_url( 'admin-post.php' )
			),
			'borsflow_download_' . (int) $submission_id
		);
	}

	/**
	 * admin-post handler that streams a private file to an authorised user.
	 */
	public static function handle_download() {
		$id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		if ( ! BorsFlow_Plugin::can_manage() || ! check_admin_referer( 'borsflow_download_' . $id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'borsflow-forms' ), 403 );
		}
		$key = isset( $_GET['field'] ) ? BorsFlow_Fields::sanitize_field_key( wp_unslash( $_GET['field'] ) ) : '';
		$row = BorsFlow_Submissions::get( $id );
		$val = $row['payload'][ $key ]['value'] ?? null;
		$path = is_array( $val ) ? self::path( $val['stored'] ?? '' ) : null;
		if ( ! $path ) {
			wp_die( esc_html__( 'File not found.', 'borsflow-forms' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $val['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $val['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
		readfile( $path );
		exit;
	}

	/**
	 * Delete every stored file referenced by a payload.
	 *
	 * @param array $payload Submission payload.
	 */
	public static function delete_for_payload( $payload ) {
		foreach ( (array) $payload as $entry ) {
			if ( isset( $entry['type'] ) && 'file' === $entry['type'] && is_array( $entry['value'] ?? null ) ) {
				$path = self::path( $entry['value']['stored'] ?? '' );
				if ( $path ) {
					wp_delete_file( $path );
				}
			}
		}
	}
}
