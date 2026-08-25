<?php
/**
 * Environment checks and admin notices.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that Tutor LMS is present, recent enough and configured for native
 * monetisation. Every failure produces a dismissible-free admin notice rather
 * than a fatal error, so the site stays usable.
 */
class Requirements {

	/**
	 * Collected failure messages.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Run every check.
	 *
	 * @return bool True when the plugin can safely load its features.
	 */
	public function passes() {
		$this->errors = array();

		if ( ! function_exists( 'tutor' ) || ! function_exists( 'tutor_utils' ) ) {
			$this->errors[] = __( 'Tutor LMS is not active. Activate Tutor LMS to use Instructor Offline Payment.', 'tutor-instructor-offline-payment' );
			return false;
		}

		$tutor_version = defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '0';
		if ( version_compare( $tutor_version, TIOC_MIN_TUTOR_VERSION, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required version, 2: installed version */
				__( 'Instructor Offline Payment needs Tutor LMS %1$s or newer. You are running %2$s.', 'tutor-instructor-offline-payment' ),
				TIOC_MIN_TUTOR_VERSION,
				$tutor_version
			);
			return false;
		}

		if ( ! class_exists( '\Tutor\Models\OrderModel' ) || ! class_exists( '\Tutor\Ecommerce\CheckoutController' ) ) {
			$this->errors[] = __( 'The Tutor LMS ecommerce engine could not be found. Instructor Offline Payment cannot run.', 'tutor-instructor-offline-payment' );
			return false;
		}

		if ( ! tutor_utils()->is_monetize_by_tutor() ) {
			$this->errors[] = sprintf(
				/* translators: %s: link to Tutor monetization settings */
				__( 'Instructor Offline Payment only works with Tutor LMS native monetisation. Set %s to "Native".', 'tutor-instructor-offline-payment' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=tutor_settings&tab_page=monetization' ) ) . '">' . esc_html__( 'Tutor LMS &rarr; Settings &rarr; Monetization', 'tutor-instructor-offline-payment' ) . '</a>'
			);
			return false;
		}

		return true;
	}

	/**
	 * Print the collected notices.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( empty( $this->errors ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( $this->errors as $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Instructor Offline Payment:', 'tutor-instructor-offline-payment' ),
				wp_kses( $error, array( 'a' => array( 'href' => array() ) ) )
			);
		}
	}
}
