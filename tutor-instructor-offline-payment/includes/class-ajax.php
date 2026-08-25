<?php
/**
 * AJAX endpoints.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an instructor does from the front-end dashboard goes through here.
 * Every endpoint checks the nonce, requires a logged-in user, and re-checks
 * ownership server-side rather than trusting anything the form sent.
 */
class Ajax {

	/**
	 * Nonce action shared by all endpoints.
	 *
	 * @var string
	 */
	const NONCE = 'tioc_dashboard';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		$actions = array(
			'tioc_save_method'   => 'save_method',
			'tioc_delete_method' => 'delete_method',
			'tioc_save_note'     => 'save_note',
			'tioc_approve_order' => 'approve_order',
			'tioc_reject_order'  => 'reject_order',
		);

		foreach ( $actions as $action => $callback ) {
			add_action( 'wp_ajax_' . $action, array( $this, $callback ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'reject_guest' ) );
		}
	}

	/**
	 * Guests have nothing to do here.
	 *
	 * @return void
	 */
	public function reject_guest() {
		wp_send_json_error( array( 'message' => __( 'Please log in and try again.', 'tutor-instructor-offline-payment' ) ), 401 );
	}

	/**
	 * Save or update a payment method.
	 *
	 * @return void
	 */
	public function save_method() {
		$user_id = self::guard_instructor();

		$input = array(
			'id'            => isset( $_POST['method_id'] ) ? sanitize_key( wp_unslash( $_POST['method_id'] ) ) : '',
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'instructions'  => isset( $_POST['instructions'] ) ? wp_kses_post( wp_unslash( $_POST['instructions'] ) ) : '',
			'is_active'     => empty( $_POST['is_active'] ) ? 0 : 1,
			'attachment_id' => isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0,
		);

		if ( ! empty( $_POST['remove_image'] ) ) {
			$input['attachment_id'] = 0;
		}

		// A newly uploaded QR code or bank slip. Unlike student receipts these are
		// meant to be public, so the media library is the right home for them.
		if ( ! empty( $_FILES['method_image']['name'] ) ) {
			$attachment_id = self::handle_image_upload( 'method_image' );

			if ( is_wp_error( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
			}

			$input['attachment_id'] = $attachment_id;
		}

		$saved = Methods::save( $user_id, $input );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment method saved.', 'tutor-instructor-offline-payment' ),
				'method'  => $saved,
				'reload'  => true,
			)
		);
	}

	/**
	 * Delete a payment method.
	 *
	 * @return void
	 */
	public function delete_method() {
		$user_id   = self::guard_instructor();
		$method_id = isset( $_POST['method_id'] ) ? sanitize_key( wp_unslash( $_POST['method_id'] ) ) : '';

		if ( ! Methods::delete( $user_id, $method_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That payment method could not be found.', 'tutor-instructor-offline-payment' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment method removed.', 'tutor-instructor-offline-payment' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Save the instructor's general note shown at checkout.
	 *
	 * @return void
	 */
	public function save_note() {
		$user_id = self::guard_instructor();
		$note    = isset( $_POST['note'] ) ? wp_kses_post( wp_unslash( $_POST['note'] ) ) : '';

		Methods::save_note( $user_id, $note );

		wp_send_json_success( array( 'message' => __( 'Note saved.', 'tutor-instructor-offline-payment' ) ) );
	}

	/**
	 * Confirm a payment and enrol the student.
	 *
	 * @return void
	 */
	public function approve_order() {
		$user_id  = self::guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$result = Orders::approve( $order_id, $note, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				'tioc_forbidden' === $result->get_error_code() ? 403 : 400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment confirmed. The student has been enrolled.', 'tutor-instructor-offline-payment' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Reject a payment.
	 *
	 * @return void
	 */
	public function reject_order() {
		$user_id  = self::guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = Orders::reject( $order_id, $reason, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				'tioc_forbidden' === $result->get_error_code() ? 403 : 400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment rejected. The student has been notified.', 'tutor-instructor-offline-payment' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Verify the nonce and require a logged-in user.
	 *
	 * @return int Current user ID.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in and try again.', 'tutor-instructor-offline-payment' ) ), 401 );
		}

		return $user_id;
	}

	/**
	 * Same, plus the instructor capability.
	 *
	 * @return int Current user ID.
	 */
	private static function guard_instructor() {
		$user_id = self::guard();

		$allowed = current_user_can( 'manage_options' )
			|| ( function_exists( 'tutor' ) && current_user_can( tutor()->instructor_role ) )
			|| ( function_exists( 'tutor_utils' ) && tutor_utils()->is_instructor( $user_id ) );

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Only instructors can manage payment details.', 'tutor-instructor-offline-payment' ) ), 403 );
		}

		return $user_id;
	}

	/**
	 * Move an uploaded image into the media library.
	 *
	 * @param string $field $_FILES key.
	 *
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function handle_image_upload( $field ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'tioc_cannot_upload', __( 'You are not allowed to upload files.', 'tutor-instructor-offline-payment' ) );
		}

		$file = isset( $_FILES[ $field ] ) ? $_FILES[ $field ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'tioc_upload_failed', __( 'The image could not be uploaded.', 'tutor-instructor-offline-payment' ) );
		}

		if ( (int) $file['size'] > Settings::max_upload_bytes() ) {
			return new \WP_Error(
				'tioc_too_large',
				sprintf(
					/* translators: %s: maximum file size */
					__( 'That image is too large. The maximum is %s.', 'tutor-instructor-offline-payment' ),
					size_format( Settings::max_upload_bytes() )
				)
			);
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ) );

		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			return new \WP_Error( 'tioc_not_image', __( 'Please upload an image file.', 'tutor-instructor-offline-payment' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( $field, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return (int) $attachment_id;
	}
}
