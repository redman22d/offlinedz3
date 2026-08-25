<?php
/**
 * Front-end dashboard pages.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

use TUTOR\Icon;

defined( 'ABSPATH' ) || exit;

/**
 * Three pages are added to the Tutor dashboard:
 *
 * - instructors publish their payment details ("Payment details"),
 * - instructors confirm or reject payments made to them ("Offline payments"),
 * - students watch the status of what they submitted ("My payments").
 *
 * Tutor builds its own rewrite rules from the same nav filters, so registering the
 * items is all that is needed for the URLs to exist; the rules are flushed once
 * after activation.
 */
class Dashboard {

	/**
	 * Instructor page: publish payment details.
	 *
	 * @var string
	 */
	const PAGE_SETUP = 'offline-payment-setup';

	/**
	 * Instructor page: confirm payments.
	 *
	 * @var string
	 */
	const PAGE_ORDERS = 'offline-payments';

	/**
	 * Student page: payment status.
	 *
	 * @var string
	 */
	const PAGE_STUDENT = 'my-offline-payments';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_dashboard/instructor_nav_items', array( $this, 'add_instructor_pages' ) );
		add_filter( 'tutor_dashboard/nav_items', array( $this, 'add_student_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the instructor pages.
	 *
	 * The student page is added here too: an instructor buying someone else's
	 * course still needs to see their own payment status.
	 *
	 * @param array $items Nav items.
	 *
	 * @return array
	 */
	public function add_instructor_pages( $items ) {
		$pending = Orders::pending_count( get_current_user_id() );

		$items[ self::PAGE_ORDERS ] = array(
			'title'       => $pending
				? sprintf(
					/* translators: %d: number of payments awaiting confirmation */
					__( 'Offline Payments (%d)', 'tutor-instructor-offline-payment' ),
					$pending
				)
				: __( 'Offline Payments', 'tutor-instructor-offline-payment' ),
			'auth_cap'    => tutor()->instructor_role,
			'icon'        => Icon::DOLLAR,
			'active_icon' => Icon::DOLLAR,
		);

		$items[ self::PAGE_SETUP ] = array(
			'title'       => __( 'Payment Details', 'tutor-instructor-offline-payment' ),
			'auth_cap'    => tutor()->instructor_role,
			'icon'        => Icon::WALLET,
			'active_icon' => Icon::WALLET,
		);

		$items[ self::PAGE_STUDENT ] = array(
			'title'       => __( 'My Payments', 'tutor-instructor-offline-payment' ),
			'icon'        => Icon::RECEIPT_PERCENT,
			'active_icon' => Icon::RECEIPT_PERCENT,
		);

		return $items;
	}

	/**
	 * Add the student page.
	 *
	 * @param array $items Nav items.
	 *
	 * @return array
	 */
	public function add_student_page( $items ) {
		$items[ self::PAGE_STUDENT ] = array(
			'title'       => __( 'My Payments', 'tutor-instructor-offline-payment' ),
			'icon'        => Icon::RECEIPT_PERCENT,
			'active_icon' => Icon::RECEIPT_PERCENT,
		);

		return $items;
	}

	/**
	 * All dashboard page slugs this plugin owns.
	 *
	 * @return string[]
	 */
	public static function pages() {
		return array( self::PAGE_SETUP, self::PAGE_ORDERS, self::PAGE_STUDENT );
	}

	/**
	 * Whether the current request is one of this plugin's dashboard pages.
	 *
	 * @return bool
	 */
	public static function is_current_page() {
		global $wp_query;

		if ( empty( $wp_query->query_vars['tutor_dashboard_page'] ) ) {
			return false;
		}

		return in_array( $wp_query->query_vars['tutor_dashboard_page'], self::pages(), true );
	}

	/**
	 * Whether the current request is the Tutor checkout page.
	 *
	 * @return bool
	 */
	public static function is_checkout_page() {
		if ( ! class_exists( '\Tutor\Ecommerce\CheckoutController' ) ) {
			return false;
		}

		$page_id = \Tutor\Ecommerce\CheckoutController::get_page_id();

		return $page_id && is_page( $page_id );
	}

	/**
	 * Load styles and scripts where they are needed.
	 *
	 * @return void
	 */
	public function enqueue() {
		$is_checkout  = tioc_is_enabled() && self::is_checkout_page();
		$is_dashboard = self::is_current_page();

		if ( ! $is_checkout && ! $is_dashboard ) {
			return;
		}

		wp_enqueue_style( 'tioc', TIOC_URL . 'assets/css/tioc.css', array(), TIOC_VERSION );

		if ( $is_checkout ) {
			wp_enqueue_script( 'tioc-checkout', TIOC_URL . 'assets/js/tioc-checkout.js', array(), TIOC_VERSION, true );
			wp_localize_script(
				'tioc-checkout',
				'TIOC_Checkout',
				array(
					'maxUploadBytes' => Settings::max_upload_bytes(),
					'maxUploadLabel' => size_format( Settings::max_upload_bytes() ),
					'extensions'     => Settings::allowed_extensions(),
					'i18n'           => array(
						'tooLarge' => __( 'That file is too large. The maximum is %s.', 'tutor-instructor-offline-payment' ),
						'badType'  => __( 'That file type is not accepted. Allowed types: %s.', 'tutor-instructor-offline-payment' ),
						'confirm'  => __( 'Submit this order? Your instructor will confirm your payment before you are enrolled.', 'tutor-instructor-offline-payment' ),
					),
				)
			);
		}

		if ( $is_dashboard ) {
			wp_enqueue_script( 'tioc-dashboard', TIOC_URL . 'assets/js/tioc-dashboard.js', array( 'wp-i18n' ), TIOC_VERSION, true );
			wp_localize_script(
				'tioc-dashboard',
				'TIOC_Dashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Ajax::NONCE ),
					'i18n'    => array(
						'confirmDelete'  => __( 'Remove this payment method? Students will no longer see it at checkout.', 'tutor-instructor-offline-payment' ),
						'confirmApprove' => __( 'Confirm that you received this payment? The student will be enrolled straight away.', 'tutor-instructor-offline-payment' ),
						'rejectPrompt'   => __( 'Why are you rejecting this payment? The student will see this message.', 'tutor-instructor-offline-payment' ),
						'saving'         => __( 'Saving…', 'tutor-instructor-offline-payment' ),
						'error'          => __( 'Something went wrong. Please try again.', 'tutor-instructor-offline-payment' ),
					),
				)
			);
		}
	}
}
