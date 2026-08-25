<?php
/**
 * Notification emails.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Two messages only: tell the author money has arrived, and tell the student
 * what the author decided. Both subjects and bodies are filterable so a site can
 * reword them without touching the plugin.
 */
class Emails {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'tioc_order_submitted', array( $this, 'notify_instructor' ), 10, 2 );
		add_action( 'tioc_order_approved', array( $this, 'notify_student_approved' ), 10, 3 );
		add_action( 'tioc_order_rejected', array( $this, 'notify_student_rejected' ), 10, 3 );
	}

	/**
	 * Tell the course author a student has paid them.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $data     Submission data.
	 *
	 * @return void
	 */
	public function notify_instructor( $order_id, $data = array() ) {
		if ( ! Settings::get( 'notify_instructor', 1 ) ) {
			return;
		}

		$instructor_id = Orders::get_instructor_id( $order_id );
		$instructor    = get_userdata( $instructor_id );

		if ( ! $instructor || ! is_email( $instructor->user_email ) ) {
			return;
		}

		$row   = Orders::get_row( $order_id );
		$items = Orders::get_items( $order_id );

		$student_name = $row ? self::user_name( $row->user_id ) : '';
		$total        = $row ? tioc_format_price( $row->total_price ) : '';

		$subject = sprintf(
			/* translators: %d: order ID */
			__( 'New offline payment to confirm (order #%d)', 'tutor-instructor-offline-payment' ),
			$order_id
		);

		$lines = array(
			sprintf(
				/* translators: %s: instructor first name or display name */
				__( 'Hi %s,', 'tutor-instructor-offline-payment' ),
				$instructor->first_name ? $instructor->first_name : $instructor->display_name
			),
			'',
			sprintf(
				/* translators: 1: student name, 2: formatted amount */
				__( '%1$s says they have paid you %2$s for:', 'tutor-instructor-offline-payment' ),
				$student_name,
				$total
			),
		);

		foreach ( $items as $item ) {
			$lines[] = '&bull; ' . $item->title;
		}

		$lines[] = '';

		if ( ! empty( $data['method_title'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: payment method title */
				__( 'Payment method: %s', 'tutor-instructor-offline-payment' ),
				$data['method_title']
			);
		}

		if ( ! empty( $data['reference'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: transaction reference */
				__( 'Reference: %s', 'tutor-instructor-offline-payment' ),
				$data['reference']
			);
		}

		if ( ! empty( $data['note'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: student note */
				__( 'Student note: %s', 'tutor-instructor-offline-payment' ),
				$data['note']
			);
		}

		$lines[] = '';
		$lines[] = __( 'The student is not enrolled yet. Check that the money reached you, then confirm or reject the payment here:', 'tutor-instructor-offline-payment' );
		$lines[] = self::link( tioc_dashboard_url( Dashboard::PAGE_ORDERS ) );

		/**
		 * Filter the instructor notification.
		 *
		 * @param array  $email    { subject, body }.
		 * @param int    $order_id Order ID.
		 * @param object $user     Recipient.
		 */
		$email = apply_filters(
			'tioc_instructor_email',
			array(
				'subject' => $subject,
				'body'    => implode( "\n", $lines ),
			),
			$order_id,
			$instructor
		);

		self::send( $instructor->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Tell the student their payment was accepted.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $user_id  Approver.
	 * @param string $note     Note.
	 *
	 * @return void
	 */
	public function notify_student_approved( $order_id, $user_id = 0, $note = '' ) {
		if ( ! Settings::get( 'notify_student', 1 ) ) {
			return;
		}

		$row = Orders::get_row( $order_id );
		if ( ! $row ) {
			return;
		}

		$student = get_userdata( $row->user_id );
		if ( ! $student || ! is_email( $student->user_email ) ) {
			return;
		}

		$items = Orders::get_items( $order_id );

		$lines = array(
			sprintf(
				/* translators: %s: student first name or display name */
				__( 'Hi %s,', 'tutor-instructor-offline-payment' ),
				$student->first_name ? $student->first_name : $student->display_name
			),
			'',
			__( 'Your payment has been confirmed and you are now enrolled in:', 'tutor-instructor-offline-payment' ),
		);

		foreach ( $items as $item ) {
			$lines[] = '&bull; ' . $item->title;
		}

		if ( $note ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: note from the instructor */
				__( 'Note from your instructor: %s', 'tutor-instructor-offline-payment' ),
				$note
			);
		}

		$lines[] = '';
		$lines[] = __( 'Start learning from your dashboard:', 'tutor-instructor-offline-payment' );
		$lines[] = self::link( tioc_dashboard_url( 'enrolled-courses' ) );

		$email = apply_filters(
			'tioc_student_approved_email',
			array(
				'subject' => sprintf(
					/* translators: %d: order ID */
					__( 'Your payment is confirmed (order #%d)', 'tutor-instructor-offline-payment' ),
					$order_id
				),
				'body'    => implode( "\n", $lines ),
			),
			$order_id,
			$student
		);

		self::send( $student->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Tell the student their payment was rejected.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $user_id  Reviewer.
	 * @param string $reason   Reason.
	 *
	 * @return void
	 */
	public function notify_student_rejected( $order_id, $user_id = 0, $reason = '' ) {
		if ( ! Settings::get( 'notify_student', 1 ) ) {
			return;
		}

		$row = Orders::get_row( $order_id );
		if ( ! $row ) {
			return;
		}

		$student = get_userdata( $row->user_id );
		if ( ! $student || ! is_email( $student->user_email ) ) {
			return;
		}

		$instructor_name = tioc_get_payee_name( Orders::get_instructor_id( $order_id ) );

		$lines = array(
			sprintf(
				/* translators: %s: student first name or display name */
				__( 'Hi %s,', 'tutor-instructor-offline-payment' ),
				$student->first_name ? $student->first_name : $student->display_name
			),
			'',
			sprintf(
				/* translators: 1: order ID, 2: instructor name */
				__( 'Order #%1$d could not be confirmed by %2$s, so you have not been enrolled.', 'tutor-instructor-offline-payment' ),
				$order_id,
				$instructor_name
			),
		);

		if ( $reason ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: rejection reason */
				__( 'Reason given: %s', 'tutor-instructor-offline-payment' ),
				$reason
			);
		}

		$lines[] = '';
		$lines[] = __( 'If you believe this is a mistake, reply to this email or contact your instructor. You can review the order here:', 'tutor-instructor-offline-payment' );
		$lines[] = self::link( tioc_dashboard_url( Dashboard::PAGE_STUDENT ) );

		$email = apply_filters(
			'tioc_student_rejected_email',
			array(
				'subject' => sprintf(
					/* translators: %d: order ID */
					__( 'Your payment could not be confirmed (order #%d)', 'tutor-instructor-offline-payment' ),
					$order_id
				),
				'body'    => implode( "\n", $lines ),
			),
			$order_id,
			$student
		);

		self::send( $student->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send one HTML email without leaking the content type filter to other plugins.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body, newline separated, limited HTML.
	 *
	 * @return void
	 */
	private static function send( $to, $subject, $body ) {
		$html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#212327">'
			. wpautop( wp_kses_post( $body ) )
			. '</div>';

		$content_type = static function () {
			return 'text/html';
		};

		add_filter( 'wp_mail_content_type', $content_type );

		wp_mail(
			$to,
			wp_specialchars_decode( $subject, ENT_QUOTES ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		remove_filter( 'wp_mail_content_type', $content_type );
	}

	/**
	 * Anchor markup for a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function link( $url ) {
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
	}

	/**
	 * Display name for a user ID.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 */
	private static function user_name( $user_id ) {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : __( 'A student', 'tutor-instructor-offline-payment' );
	}
}
