<?php
/**
 * Protected storage for payment receipts.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Receipts are financial documents that often show account numbers, so they are
 * deliberately kept out of the media library: files land in a dedicated uploads
 * subdirectory with a randomised name, direct web access is denied where the
 * server honours .htaccess, and every download is served through a capability
 * check.
 */
class Uploads {

	/**
	 * Directory name inside wp-content/uploads.
	 *
	 * @var string
	 */
	const DIR_NAME = 'tutor-offline-payments';

	/**
	 * admin-post.php action used to serve a receipt.
	 *
	 * @var string
	 */
	const SERVE_ACTION = 'tioc_view_proof';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::SERVE_ACTION, array( $this, 'serve' ) );
		add_action( 'admin_post_nopriv_' . self::SERVE_ACTION, array( $this, 'serve_denied' ) );
	}

	/**
	 * Absolute path of the storage directory.
	 *
	 * @return string Trailing-slashed path.
	 */
	public static function base_dir() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME . '/';
	}

	/**
	 * Create the storage directory and block direct access.
	 *
	 * Runs on activation and again lazily before the first upload, because a
	 * migration or a cleanup plugin can remove it at any time.
	 *
	 * @return bool
	 */
	public static function protect_directory() {
		$dir = self::base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Receipts are served through admin-post.php after a capability check.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Validate and store an uploaded receipt.
	 *
	 * @param array $file One entry from $_FILES.
	 *
	 * @return array|\WP_Error {
	 *     @type string $file      Relative path below the storage directory.
	 *     @type string $name      Original filename, sanitised.
	 *     @type string $mime      Detected MIME type.
	 *     @type int    $size      Size in bytes.
	 * }
	 */
	public static function store( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
			return new \WP_Error( 'tioc_no_file', __( 'No file was received.', 'tutor-instructor-offline-payment' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'tioc_upload_error', self::upload_error_message( (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'tioc_not_uploaded', __( 'The file could not be verified as an upload.', 'tutor-instructor-offline-payment' ) );
		}

		$max = Settings::max_upload_bytes();
		if ( (int) $file['size'] > $max ) {
			return new \WP_Error(
				'tioc_too_large',
				sprintf(
					/* translators: %s: maximum file size, already formatted */
					__( 'That file is too large. The maximum is %s.', 'tutor-instructor-offline-payment' ),
					size_format( $max )
				)
			);
		}

		$name      = sanitize_file_name( isset( $file['name'] ) ? $file['name'] : 'receipt' );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed   = Settings::allowed_extensions();

		if ( ! $extension || ! in_array( $extension, $allowed, true ) ) {
			return new \WP_Error(
				'tioc_bad_type',
				sprintf(
					/* translators: %s: comma separated list of extensions */
					__( 'That file type is not accepted. Allowed types: %s.', 'tutor-instructor-offline-payment' ),
					implode( ', ', $allowed )
				)
			);
		}

		// Cross-check the real content type against the extension.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'tioc_bad_content', __( 'The file contents do not match its extension.', 'tutor-instructor-offline-payment' ) );
		}

		if ( strtolower( $check['ext'] ) !== $extension ) {
			return new \WP_Error( 'tioc_bad_content', __( 'The file contents do not match its extension.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! self::protect_directory() ) {
			return new \WP_Error( 'tioc_no_dir', __( 'The receipt folder could not be created. Contact the site administrator.', 'tutor-instructor-offline-payment' ) );
		}

		$subdir = gmdate( 'Y/m' );
		$target = self::base_dir() . $subdir . '/';

		if ( ! wp_mkdir_p( $target ) ) {
			return new \WP_Error( 'tioc_no_dir', __( 'The receipt folder could not be created. Contact the site administrator.', 'tutor-instructor-offline-payment' ) );
		}

		// Randomised filename: the path is never guessable even if the server
		// ignores .htaccess.
		$filename = 'proof-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 20, false, false ) . '.' . $extension;

		if ( ! @move_uploaded_file( $file['tmp_name'], $target . $filename ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'tioc_move_failed', __( 'The file could not be saved. Please try again.', 'tutor-instructor-offline-payment' ) );
		}

		@chmod( $target . $filename, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod

		return array(
			'file' => $subdir . '/' . $filename,
			'name' => $name,
			'mime' => $check['type'],
			'size' => (int) $file['size'],
		);
	}

	/**
	 * Delete a stored receipt.
	 *
	 * @param string $relative_path Path as returned by store().
	 *
	 * @return bool
	 */
	public static function delete( $relative_path ) {
		$path = self::resolve( $relative_path );

		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		return (bool) wp_delete_file( $path ) || ! file_exists( $path );
	}

	/**
	 * Turn a stored relative path into a safe absolute path.
	 *
	 * Rejects anything that escapes the storage directory.
	 *
	 * @param string $relative_path Stored path.
	 *
	 * @return string|false
	 */
	public static function resolve( $relative_path ) {
		$relative_path = (string) $relative_path;

		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || false !== strpos( $relative_path, "\0" ) ) {
			return false;
		}

		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$base          = self::base_dir();
		$candidate     = $base . $relative_path;

		$real_base = realpath( $base );
		$real_file = realpath( $candidate );

		if ( ! $real_base || ! $real_file ) {
			return false;
		}

		$real_base = trailingslashit( str_replace( '\\', '/', $real_base ) );
		$real_file = str_replace( '\\', '/', $real_file );

		if ( 0 !== strpos( $real_file, $real_base ) ) {
			return false;
		}

		return $real_file;
	}

	/**
	 * Download URL for an order's receipt.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public static function proof_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::SERVE_ACTION,
					'order_id' => absint( $order_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'tioc_view_proof_' . absint( $order_id )
		);
	}

	/**
	 * Stream a receipt to an authorised viewer.
	 *
	 * @return void
	 */
	public function serve() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing order.', 'tutor-instructor-offline-payment' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'tioc_view_proof_' . $order_id );

		if ( ! Orders::can_view( $order_id, get_current_user_id() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this receipt.', 'tutor-instructor-offline-payment' ), '', array( 'response' => 403 ) );
		}

		$proof = Orders::get_proof( $order_id );
		if ( empty( $proof['file'] ) ) {
			wp_die( esc_html__( 'No receipt is attached to this order.', 'tutor-instructor-offline-payment' ), '', array( 'response' => 404 ) );
		}

		$path = self::resolve( $proof['file'] );
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The receipt file is missing from the server.', 'tutor-instructor-offline-payment' ), '', array( 'response' => 404 ) );
		}

		$mime = ! empty( $proof['mime'] ) ? $proof['mime'] : 'application/octet-stream';
		$name = ! empty( $proof['name'] ) ? $proof['name'] : basename( $path );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; object-src \'none\'; sandbox' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Logged-out visitors never have a legitimate reason to be here.
	 *
	 * @return void
	 */
	public function serve_denied() {
		auth_redirect();
	}

	/**
	 * Translate a PHP upload error code.
	 *
	 * @param int $code Error constant.
	 *
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: server upload limit */
					__( 'That file exceeds the server upload limit of %s.', 'tutor-instructor-offline-payment' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please try again.', 'tutor-instructor-offline-payment' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was selected.', 'tutor-instructor-offline-payment' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the file. Contact the site administrator.', 'tutor-instructor-offline-payment' );
			default:
				return __( 'The file could not be uploaded.', 'tutor-instructor-offline-payment' );
		}
	}
}
