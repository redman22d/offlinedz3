<?php
/**
 * Offline checkout: view model and order placement.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

use Tutor\Ecommerce\CartController;
use Tutor\Ecommerce\CheckoutController;
use Tutor\Ecommerce\Settings as TutorEcommerceSettings;
use Tutor\Models\BillingModel;
use Tutor\Models\CartModel;
use Tutor\Models\CouponModel;
use Tutor\Models\OrderModel;

defined( 'ABSPATH' ) || exit;

/**
 * The cart is split by course author and each group becomes its own Tutor order,
 * created unpaid and tagged with that author. Nothing is enrolled until the author
 * confirms the money arrived.
 */
class Checkout {

	/**
	 * Value of the `tutor_action` field our form submits.
	 *
	 * @var string
	 */
	const ACTION = 'tioc_place_order';

	/**
	 * Transient prefixes for post-redirect messaging.
	 */
	const ERROR_TRANSIENT  = 'tioc_checkout_errors_';
	const NOTICE_TRANSIENT = 'tioc_checkout_notice_';

	/**
	 * Memoised view model.
	 *
	 * @var object|null
	 */
	private static $view = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'tutor_action_' . self::ACTION, array( $this, 'place_order' ) );
		add_filter( 'tutor_after_pay_button', array( $this, 'filter_pay_button' ), 10, 2 );
	}

	/**
	 * Whether the offline checkout should take over this request.
	 *
	 * Subscription plans are priced and billed by the site, not by an author, so
	 * plan checkouts are left to Tutor.
	 *
	 * @return bool
	 */
	public static function should_replace() {
		if ( ! tioc_is_enabled() ) {
			return false;
		}

		$plan_id = isset( $_REQUEST['plan'] ) ? absint( $_REQUEST['plan'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $plan_id && apply_filters( 'tutor_get_plan_info', null, $plan_id ) ) {
			return false;
		}

		// The "pay online instead" link on our own checkout, which hands the
		// request back to Tutor's gateways.
		if ( ! empty( $_REQUEST['tioc_online'] ) && Settings::get( 'allow_online_gateways', 0 ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		/**
		 * Filter whether to replace the Tutor checkout on this request.
		 *
		 * @param bool $replace Current decision.
		 */
		return (bool) apply_filters( 'tioc_should_replace_checkout', true );
	}

	/**
	 * Build everything the checkout template needs.
	 *
	 * @return object
	 */
	public static function get_view() {
		if ( null !== self::$view ) {
			return self::$view;
		}

		$user_id    = get_current_user_id();
		$controller = new CheckoutController( false );

		$view = new \stdClass();

		$view->user_id         = $user_id;
		$view->order_type      = OrderModel::TYPE_SINGLE_ORDER;
		$view->groups          = array();
		$view->object_ids      = array();
		$view->subtotal        = 0.0;
		$view->sale_discount   = 0.0;
		$view->coupon_discount = 0.0;
		$view->tax_amount      = 0.0;
		$view->total           = 0.0;
		$view->coupon_code     = '';
		$view->coupon_applied  = false;
		$view->coupon_title    = '';
		$view->notices         = array();
		$view->errors          = self::take_errors( $user_id );
		$view->flash           = self::take_notice( $user_id );
		$view->blocked         = false;
		$view->gateways        = array();
		$view->show_gateways   = false;

		$course_list = self::get_items_in_checkout();

		if ( empty( $course_list ) ) {
			self::$view = $view;

			return $view;
		}

		$coupon_code = isset( $_REQUEST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['coupon_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$coupon_code = apply_filters( 'tutor_checkout_coupon_code', $coupon_code, $view->order_type, wp_list_pluck( $course_list, 'ID' ) );

		// Group the cart by the person who gets paid.
		$grouped = array();
		foreach ( $course_list as $course ) {
			if ( ! $course instanceof \WP_Post ) {
				continue;
			}

			$payee_id = tioc_get_payee_id( $course->ID );

			if ( ! isset( $grouped[ $payee_id ] ) ) {
				$grouped[ $payee_id ] = array();
			}

			$grouped[ $payee_id ][] = $course;
		}

		// A flat-amount coupon would be deducted once per author, multiplying the
		// discount, so it is dropped when the cart spans several authors.
		if ( count( $grouped ) > 1 && $coupon_code && self::is_flat_coupon( $coupon_code ) ) {
			$view->notices[] = __( 'That coupon is a fixed-amount discount and cannot be split between several instructors. Please check out one instructor at a time to use it.', 'tutor-instructor-offline-payment' );
			$coupon_code     = '';
		}

		$view->coupon_code = $coupon_code;

		foreach ( $grouped as $payee_id => $courses ) {
			$item_ids = wp_list_pluck( $courses, 'ID' );
			$checkout = $controller->prepare_checkout_items( $item_ids, $view->order_type, $coupon_code );

			$group                   = new \stdClass();
			$group->instructor_id    = (int) $payee_id;
			$group->instructor_name  = tioc_get_payee_name( $payee_id );
			$group->instructor_photo = get_avatar_url( $payee_id, array( 'size' => 96 ) );
			$group->courses          = $courses;
			$group->item_ids         = array_map( 'absint', $item_ids );
			$group->checkout         = $checkout;
			$group->methods          = Methods::get( $payee_id, true );
			$group->note             = Methods::get_note( $payee_id );
			$group->total            = (float) $checkout->total_price;
			$group->is_free          = ( 0 >= (float) $checkout->total_price );
			$group->configured       = $group->is_free || ! empty( $group->methods );

			$view->groups[]        = $group;
			$view->object_ids      = array_merge( $view->object_ids, $group->item_ids );
			$view->subtotal       += (float) $checkout->subtotal_price;
			$view->sale_discount  += (float) $checkout->sale_discount;
			$view->coupon_discount += (float) $checkout->coupon_discount;
			$view->tax_amount     += (float) $checkout->tax_amount;
			$view->total          += (float) $checkout->total_price;

			if ( $checkout->is_coupon_applied ) {
				$view->coupon_applied = true;
				$view->coupon_title   = $checkout->coupon_title;
			}

			if ( ! $group->configured ) {
				$view->blocked = $view->blocked || (bool) Settings::get( 'block_unconfigured', 1 );
			}
		}

		$view->multi_instructor = count( $view->groups ) > 1;
		$view->is_zero_price    = ( 0 >= $view->total );

		if ( Settings::get( 'allow_online_gateways', 0 ) && ! $view->is_zero_price && function_exists( 'tutor_get_all_active_payment_gateways' ) ) {
			$view->gateways      = tutor_get_all_active_payment_gateways();
			$view->show_gateways = ! empty( $view->gateways );
		}

		self::$view = $view;

		return $view;
	}

	/**
	 * Resolve which courses or bundles are being bought.
	 *
	 * @return \WP_Post[]
	 */
	private static function get_items_in_checkout() {
		$course_id = isset( $_REQUEST['course_id'] ) ? absint( $_REQUEST['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $course_id && class_exists( '\Tutor\Ecommerce\Settings' ) && TutorEcommerceSettings::is_buy_now_enabled() ) {
			$post = get_post( $course_id );

			return $post ? array( $post ) : array();
		}

		$cart_controller = new CartController( false );
		$cart            = $cart_controller->get_cart_items();

		if ( empty( $cart['courses']['results'] ) ) {
			return array();
		}

		return $cart['courses']['results'];
	}

	/**
	 * Whether a coupon code is a fixed-amount discount.
	 *
	 * @param string $coupon_code Coupon code.
	 *
	 * @return bool
	 */
	private static function is_flat_coupon( $coupon_code ) {
		if ( ! class_exists( '\Tutor\Models\CouponModel' ) ) {
			return false;
		}

		$model  = new CouponModel();
		$coupon = $model->get_coupon_details_for_checkout( $coupon_code );

		return is_object( $coupon ) && CouponModel::DISCOUNT_TYPE_FLAT === $coupon->discount_type;
	}

	/**
	 * Handle the checkout submission.
	 *
	 * One Tutor order is created per course author. All of them are unpaid until
	 * that author confirms the payment.
	 *
	 * @return void
	 */
	public function place_order() {
		$nonce = isset( $_POST['tioc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tioc_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			self::set_errors( get_current_user_id(), array( __( 'Your session expired. Please review your order and submit it again.', 'tutor-instructor-offline-payment' ) ) );
			self::redirect_back();
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			self::set_errors( 0, array( __( 'Please log in before placing an order.', 'tutor-instructor-offline-payment' ) ) );
			self::redirect_back();
		}

		$view = self::get_view();

		if ( empty( $view->groups ) ) {
			self::set_errors( $user_id, array( __( 'Your cart is empty.', 'tutor-instructor-offline-payment' ) ) );
			self::redirect_back();
		}

		$errors = array();

		// Honour Tutor's own consent checkboxes, exactly as its checkout does.
		$consent = self::validate_consent();
		if ( is_wp_error( $consent ) ) {
			$errors[] = $consent->get_error_message();
		}

		foreach ( $view->groups as $group ) {
			$can_buy_errors = self::validate_purchasable( $group->item_ids );
			if ( $can_buy_errors ) {
				$errors = array_merge( $errors, $can_buy_errors );
			}
		}

		// Collect and validate each group's payment details before writing anything.
		$submissions = array();

		foreach ( $view->groups as $group ) {
			if ( $group->is_free ) {
				$submissions[ $group->instructor_id ] = array(
					'instructor_id' => $group->instructor_id,
					'method_id'     => '',
					'method_title'  => '',
					'reference'     => '',
					'note'          => '',
					'proof'         => array(),
				);
				continue;
			}

			if ( ! $group->configured ) {
				if ( Settings::get( 'block_unconfigured', 1 ) ) {
					$errors[] = sprintf(
						/* translators: %s: instructor name */
						__( '%s has not published any payment details yet, so this course cannot be ordered right now.', 'tutor-instructor-offline-payment' ),
						$group->instructor_name
					);
					continue;
				}
			}

			$method_id = isset( $_POST['tioc_method'][ $group->instructor_id ] ) ? sanitize_key( wp_unslash( $_POST['tioc_method'][ $group->instructor_id ] ) ) : '';
			$method    = $method_id ? Methods::find( $group->instructor_id, $method_id ) : null;

			if ( ! empty( $group->methods ) && ( ! $method || ! $method['is_active'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: instructor name */
					__( 'Choose how you paid %s.', 'tutor-instructor-offline-payment' ),
					$group->instructor_name
				);
				continue;
			}

			$reference = isset( $_POST['tioc_reference'][ $group->instructor_id ] ) ? sanitize_text_field( wp_unslash( $_POST['tioc_reference'][ $group->instructor_id ] ) ) : '';
			$note      = isset( $_POST['tioc_note'][ $group->instructor_id ] ) ? sanitize_textarea_field( wp_unslash( $_POST['tioc_note'][ $group->instructor_id ] ) ) : '';

			if ( Settings::get( 'collect_reference', 1 ) && '' === trim( $reference ) ) {
				$errors[] = sprintf(
					/* translators: %s: instructor name */
					__( 'Enter the transaction reference for your payment to %s.', 'tutor-instructor-offline-payment' ),
					$group->instructor_name
				);
				continue;
			}

			$proof = array();
			$file  = self::file_from_request( 'tioc_proof', $group->instructor_id );

			if ( $file ) {
				$stored = Uploads::store( $file );

				if ( is_wp_error( $stored ) ) {
					$errors[] = $stored->get_error_message();
					continue;
				}

				$proof = $stored;
			} elseif ( Settings::get( 'require_proof', 0 ) ) {
				$errors[] = sprintf(
					/* translators: %s: instructor name */
					__( 'Attach a receipt for your payment to %s.', 'tutor-instructor-offline-payment' ),
					$group->instructor_name
				);
				continue;
			}

			$submissions[ $group->instructor_id ] = array(
				'instructor_id' => $group->instructor_id,
				'method_id'     => $method ? $method['id'] : '',
				'method_title'  => $method ? $method['title'] : __( 'Offline payment', 'tutor-instructor-offline-payment' ),
				'reference'     => $reference,
				'note'          => $note,
				'proof'         => $proof,
			);
		}

		if ( $errors ) {
			self::cleanup_proofs( $submissions );
			self::set_errors( $user_id, $errors );
			self::redirect_back();
		}

		self::save_billing( $user_id );

		$created = array();

		foreach ( $view->groups as $group ) {
			if ( ! isset( $submissions[ $group->instructor_id ] ) ) {
				continue;
			}

			$order_id = self::create_group_order( $user_id, $group );

			if ( is_wp_error( $order_id ) ) {
				$errors[] = $order_id->get_error_message();
				continue;
			}

			Orders::save_submission( $order_id, $submissions[ $group->instructor_id ] );
			$created[] = $order_id;
		}

		if ( empty( $created ) ) {
			self::cleanup_proofs( $submissions );
			self::set_errors( $user_id, $errors ? $errors : array( __( 'Your order could not be placed. Please try again.', 'tutor-instructor-offline-payment' ) ) );
			self::redirect_back();
		}

		// Only clear the cart for items that actually made it into an order.
		self::clear_cart( $user_id, $view, $created );

		do_action( 'tutor_after_checkout_consent', $user_id, $consent );

		if ( $errors ) {
			self::set_errors( $user_id, $errors );
		}

		/**
		 * Fires after a complete offline checkout.
		 *
		 * @param int[] $created Order IDs created.
		 * @param int   $user_id Student ID.
		 */
		do_action( 'tioc_checkout_completed', $created, $user_id );

		self::set_notice(
			$user_id,
			array(
				'type'    => 'success',
				'message' => count( $created ) > 1
					? __( 'Your orders have been submitted. Each instructor will confirm their payment and you will be enrolled as soon as they do.', 'tutor-instructor-offline-payment' )
					: __( 'Your order has been submitted. You will be enrolled as soon as your instructor confirms the payment.', 'tutor-instructor-offline-payment' ),
			)
		);

		wp_safe_redirect(
			apply_filters(
				'tioc_after_checkout_redirect',
				add_query_arg( 'tioc_order_placed', 1, tioc_dashboard_url( Dashboard::PAGE_STUDENT ) ),
				$created,
				$user_id
			)
		);
		exit;
	}

	/**
	 * Create the Tutor order for one author's items.
	 *
	 * @param int    $user_id Student ID.
	 * @param object $group   Group from the view model.
	 *
	 * @return int|\WP_Error Order ID.
	 */
	private static function create_group_order( $user_id, $group ) {
		$items = array();

		foreach ( $group->checkout->items as $item ) {
			$items[] = array(
				'item_id'        => $item->item_id,
				'regular_price'  => $item->regular_price,
				'sale_price'     => $item->sale_price,
				'discount_price' => $item->discount_price,
				'coupon_code'    => ! empty( $item->is_coupon_applied ) ? $item->coupon_code : null,
			);
		}

		if ( empty( $items ) ) {
			return new \WP_Error( 'tioc_no_items', __( 'No items found for purchase.', 'tutor-instructor-offline-payment' ) );
		}

		$args = apply_filters(
			'tutor_order_create_args',
			array(
				'payment_method'  => Orders::PAYMENT_METHOD,
				'coupon_amount'   => $group->checkout->coupon_discount,
				'discount_amount' => $group->checkout->sale_discount,
			)
		);

		try {
			$order = ( new \Tutor\Ecommerce\OrderController( false ) )->create_order(
				$user_id,
				$items,
				OrderModel::PAYMENT_UNPAID,
				OrderModel::TYPE_SINGLE_ORDER,
				$group->checkout->coupon_code,
				$args,
				true
			);
		} catch ( \Throwable $e ) {
			tioc_log( $e );

			return new \WP_Error( 'tioc_order_failed', __( 'Your order could not be created. Please try again.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! $order ) {
			return new \WP_Error( 'tioc_order_failed', __( 'Your order could not be created. Please try again.', 'tutor-instructor-offline-payment' ) );
		}

		return (int) $order;
	}

	/**
	 * Run Tutor's checkout consent validation when the GDPR module is present.
	 *
	 * @return mixed True, the consent snapshot, or WP_Error.
	 */
	private static function validate_consent() {
		if ( ! class_exists( '\Tutor\GDPR\Controllers\LegalConsent' ) ) {
			return true;
		}

		return \Tutor\GDPR\Controllers\LegalConsent::validate_consent(
			\Tutor\GDPR\Controllers\LegalConsent::DISPLAY_ON_CHECKOUT,
			$_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked above; handled by Tutor.
		);
	}

	/**
	 * Let other plugins veto a purchase, the same way Tutor's own checkout does.
	 *
	 * @param int[] $item_ids Course or bundle IDs.
	 *
	 * @return string[] Error messages.
	 */
	private static function validate_purchasable( array $item_ids ) {
		$errors = array();

		foreach ( $item_ids as $item_id ) {
			$can_buy = apply_filters( 'tutor_can_purchase_course', true, $item_id );

			if ( is_wp_error( $can_buy ) ) {
				$errors[] = $can_buy->get_error_message();
			}
		}

		return $errors;
	}

	/**
	 * Persist the billing address, reusing Tutor's own table and field list.
	 *
	 * @param int $user_id Student ID.
	 *
	 * @return void
	 */
	private static function save_billing( $user_id ) {
		if ( ! class_exists( '\Tutor\Models\BillingModel' ) ) {
			return;
		}

		$model  = new BillingModel();
		$fields = array();

		foreach ( $model->get_fillable_fields() as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

			if ( 'billing_email' === $field ) {
				$value = sanitize_email( $value );
			}

			$fields[ $field ] = $value;
		}

		if ( empty( $fields ) ) {
			return;
		}

		try {
			if ( $model->get_info( $user_id ) ) {
				$model->update( $fields, array( 'user_id' => $user_id ) );
			} else {
				$fields['user_id'] = $user_id;
				$model->insert( $fields );
			}
		} catch ( \Throwable $e ) {
			tioc_log( $e );
		}
	}

	/**
	 * Remove ordered items from the cart.
	 *
	 * @param int    $user_id Student ID.
	 * @param object $view    View model.
	 * @param int[]  $created Order IDs created.
	 *
	 * @return void
	 */
	private static function clear_cart( $user_id, $view, array $created ) {
		if ( ! class_exists( '\Tutor\Models\CartModel' ) ) {
			return;
		}

		$ordered = array();
		foreach ( $created as $order_id ) {
			foreach ( Orders::get_items( $order_id ) as $item ) {
				$ordered[] = (int) $item->id;
			}
		}

		if ( empty( $ordered ) ) {
			return;
		}

		$cart = new CartModel();

		// Everything in the cart was ordered, so drop the cart outright.
		$remaining = array_diff( $view->object_ids, $ordered );

		if ( empty( $remaining ) ) {
			$cart->clear_user_cart( $user_id );

			return;
		}

		foreach ( $ordered as $item_id ) {
			$cart->delete_course_from_cart( $user_id, $item_id );
		}
	}

	/**
	 * Delete receipts that were stored before a later validation error aborted
	 * the checkout, so failed attempts do not leave orphan files behind.
	 *
	 * @param array $submissions Collected submissions.
	 *
	 * @return void
	 */
	private static function cleanup_proofs( array $submissions ) {
		foreach ( $submissions as $submission ) {
			if ( ! empty( $submission['proof']['file'] ) ) {
				Uploads::delete( $submission['proof']['file'] );
			}
		}
	}

	/**
	 * Pull one entry out of an array-style $_FILES field.
	 *
	 * @param string     $field Field name.
	 * @param int|string $index Array index.
	 *
	 * @return array|null
	 */
	private static function file_from_request( $field, $index ) {
		if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['name'][ $index ] ) ) {
			return null;
		}

		$file = array(
			'name'     => $_FILES[ $field ]['name'][ $index ],
			'type'     => isset( $_FILES[ $field ]['type'][ $index ] ) ? $_FILES[ $field ]['type'][ $index ] : '',
			'tmp_name' => isset( $_FILES[ $field ]['tmp_name'][ $index ] ) ? $_FILES[ $field ]['tmp_name'][ $index ] : '',
			'error'    => isset( $_FILES[ $field ]['error'][ $index ] ) ? (int) $_FILES[ $field ]['error'][ $index ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES[ $field ]['size'][ $index ] ) ? (int) $_FILES[ $field ]['size'][ $index ] : 0,
		);

		if ( UPLOAD_ERR_NO_FILE === $file['error'] || '' === $file['tmp_name'] ) {
			return null;
		}

		return $file;
	}

	/**
	 * Replace Tutor's gateway "Pay now" button on offline orders.
	 *
	 * There is no gateway to send the student to; what they need is the page where
	 * they can see whether their instructor has confirmed the payment.
	 *
	 * @param string $html  Button markup.
	 * @param object $order Order object.
	 *
	 * @return string
	 */
	public function filter_pay_button( $html, $order ) {
		if ( ! is_object( $order ) || empty( $order->id ) || ! Orders::is_offline( $order->id ) ) {
			return $html;
		}

		return sprintf(
			'<a class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" href="%s">%s</a>',
			esc_url( tioc_dashboard_url( Dashboard::PAGE_STUDENT ) ),
			esc_html__( 'Payment status', 'tutor-instructor-offline-payment' )
		);
	}

	/**
	 * Store validation errors for the next page load.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $errors  Messages.
	 *
	 * @return void
	 */
	public static function set_errors( $user_id, array $errors ) {
		set_transient( self::ERROR_TRANSIENT . absint( $user_id ), array_values( array_unique( $errors ) ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and clear stored errors.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string[]
	 */
	public static function take_errors( $user_id ) {
		$key    = self::ERROR_TRANSIENT . absint( $user_id );
		$errors = get_transient( $key );

		if ( $errors ) {
			delete_transient( $key );
		}

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Store a flash notice.
	 *
	 * @param int   $user_id User ID.
	 * @param array $notice  { type, message }.
	 *
	 * @return void
	 */
	public static function set_notice( $user_id, array $notice ) {
		set_transient( self::NOTICE_TRANSIENT . absint( $user_id ), $notice, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and clear the flash notice.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	public static function take_notice( $user_id ) {
		$key    = self::NOTICE_TRANSIENT . absint( $user_id );
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
		}

		return is_array( $notice ) ? $notice : array();
	}

	/**
	 * Send the student back to the checkout page, keeping buy-now context.
	 *
	 * @return void
	 */
	private static function redirect_back() {
		$url = CheckoutController::get_page_url();

		$carry = array();
		foreach ( array( 'course_id', 'coupon_code', 'plan' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$carry[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( $carry ) {
			$url = add_query_arg( $carry, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
