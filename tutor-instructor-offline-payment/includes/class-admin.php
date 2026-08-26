<?php
/**
 * WP admin integration.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

use Tutor\Models\OrderModel;

defined( 'ABSPATH' ) || exit;

/**
 * Administrators keep working in Tutor's own order screens; this class only adds
 * what those screens cannot know about — which author is owed the money, what the
 * student said they paid, and the two buttons to settle it. There is also a
 * read-only overview listing every offline order across all authors.
 */
class Admin {

	/**
	 * Overview page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'tioc-orders';

	/**
	 * admin-post.php action for the approve/reject buttons.
	 *
	 * @var string
	 */
	const DECISION_ACTION = 'tioc_admin_decision';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 21 );
		add_action( 'admin_post_' . self::DECISION_ACTION, array( $this, 'handle_decision' ) );
		add_action( 'tutor_after_order_edit_link', array( $this, 'render_row_actions' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . TIOC_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the overview page under Tutor LMS.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'tutor',
			__( 'Offline Payments', 'tutor-instructor-offline-payment' ),
			__( 'Offline Payments', 'tutor-instructor-offline-payment' ),
			'manage_tutor',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Styles for the overview page.
	 *
	 * @param string $hook Current screen hook.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) && false === strpos( (string) $hook, Settings::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'tioc-admin', TIOC_URL . 'assets/css/tioc.css', array(), TIOC_VERSION );
	}

	/**
	 * Settings shortcut on the plugins screen.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'tutor-instructor-offline-payment' )
		);

		return $links;
	}

	/**
	 * Mark offline rows in Tutor's own order list and offer the two decisions.
	 *
	 * @param object $order Order row from Tutor's list.
	 *
	 * @return void
	 */
	public function render_row_actions( $order ) {
		if ( ! is_object( $order ) || empty( $order->id ) || ! Orders::is_offline( $order->id ) ) {
			return;
		}

		$instructor_id = Orders::get_instructor_id( $order->id );
		$reference     = Orders::get_meta( $order->id, Orders::META_REFERENCE );
		$proof         = Orders::get_proof( $order->id );
		$is_pending    = OrderModel::PAYMENT_UNPAID === $order->payment_status || OrderModel::PAYMENT_PENDING === $order->payment_status;

		echo '<div class="tioc-admin-row-meta">';

		printf(
			'<span class="tioc-chip">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: instructor name */
					__( 'Paid to %s', 'tutor-instructor-offline-payment' ),
					tioc_get_payee_name( $instructor_id )
				)
			)
		);

		if ( $reference ) {
			printf(
				'<span class="tioc-chip">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: transaction reference given by the student */
						__( 'Ref: %s', 'tutor-instructor-offline-payment' ),
						$reference
					)
				)
			);
		}

		if ( ! empty( $proof['file'] ) ) {
			printf(
				'<a class="tioc-chip tioc-chip-link" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( Uploads::proof_url( $order->id ) ),
				esc_html__( 'View receipt', 'tutor-instructor-offline-payment' )
			);
		}

		if ( $is_pending && Orders::can_manage( $order->id ) ) {
			echo $this->decision_form( $order->id, 'approve', __( 'Confirm payment', 'tutor-instructor-offline-payment' ), 'tutor-btn-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo $this->decision_form( $order->id, 'reject', __( 'Reject', 'tutor-instructor-offline-payment' ), 'tutor-btn-outline-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}

		echo '</div>';
	}

	/**
	 * One approve or reject button as a self-contained form.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $decision approve|reject.
	 * @param string $label    Button label.
	 * @param string $class    Button class.
	 *
	 * @return string
	 */
	private function decision_form( $order_id, $decision, $label, $class ) {
		$confirm = 'approve' === $decision
			? __( 'Confirm that this payment reached the instructor? The student will be enrolled straight away.', 'tutor-instructor-offline-payment' )
			: __( 'Reject this payment? The student will be notified and will not be enrolled.', 'tutor-instructor-offline-payment' );

		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tioc-inline-form" onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');">
			<?php wp_nonce_field( self::DECISION_ACTION . '_' . $order_id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DECISION_ACTION ); ?>">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
			<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( self::current_url() ); ?>">
			<button type="submit" class="tutor-btn tutor-btn-sm <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php

		return ob_get_clean();
	}

	/**
	 * Handle an approve or reject submission.
	 *
	 * @return void
	 */
	public function handle_decision() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing order.', 'tutor-instructor-offline-payment' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::DECISION_ACTION . '_' . $order_id );

		$decision = isset( $_POST['decision'] ) && 'reject' === $_POST['decision'] ? 'reject' : 'approve';
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = 'reject' === $decision
			? Orders::reject( $order_id, $reason )
			: Orders::approve( $order_id, $reason );

		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$redirect = remove_query_arg( array( 'tioc-done', 'tioc-error' ), $redirect );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'tioc-error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'tioc-done', $decision, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show the result of a decision.
	 *
	 * @return void
	 */
	public function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tioc-error'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['tioc-error'] ) ) )
			);

			return;
		}

		if ( ! isset( $_GET['tioc-done'] ) ) {
			return;
		}

		$message = 'reject' === $_GET['tioc-done']
			? __( 'Payment rejected. The student has been notified.', 'tutor-instructor-offline-payment' )
			: __( 'Payment confirmed. The student has been enrolled.', 'tutor-instructor-offline-payment' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Render the overview page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_tutor' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'tutor-instructor-offline-payment' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : OrderModel::PAYMENT_UNPAID;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$args     = array(
			'status' => $status,
			'search' => $search,
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		);

		$orders = Orders::get_all( $args );
		$total  = Orders::count_all( $args );

		Templates::render(
			'admin/order-overview',
			array(
				'orders'   => $orders,
				'total'    => $total,
				'status'   => $status,
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Current admin URL, for returning after a decision.
	 *
	 * @return string
	 */
	private static function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return esc_url_raw( home_url( $path ) );
	}
}
