<?php
/**
 * Instructor dashboard: confirm or reject payments made to you.
 *
 * Loaded by Tutor as `dashboard/offline-payments`.
 * Override by copying to
 * `<theme>/tutor-offline-payment/dashboard/offline-payments.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use Tutor\Models\OrderModel;
use TutorInstructorOfflinePayment\Dashboard;
use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Orders;
use TutorInstructorOfflinePayment\Uploads;

defined( 'ABSPATH' ) || exit;

$tioc_user_id = get_current_user_id();

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$tioc_status = isset( $_GET['tioc_status'] ) ? sanitize_key( wp_unslash( $_GET['tioc_status'] ) ) : OrderModel::PAYMENT_UNPAID;
$tioc_search = isset( $_GET['tioc_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_search'] ) ) : '';
$tioc_paged  = isset( $_GET['tioc_paged'] ) ? max( 1, absint( $_GET['tioc_paged'] ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$tioc_per_page = 10;
$tioc_args     = array(
	'status' => $tioc_status,
	'search' => $tioc_search,
	'limit'  => $tioc_per_page,
	'offset' => ( $tioc_paged - 1 ) * $tioc_per_page,
);

$tioc_orders = Orders::get_for_instructor( $tioc_user_id, $tioc_args );
$tioc_total  = Orders::count_for_instructor( $tioc_user_id, $tioc_args );
$tioc_pages  = (int) ceil( $tioc_total / $tioc_per_page );
$tioc_base   = tioc_dashboard_url( Dashboard::PAGE_ORDERS );

$tioc_tabs = array(
	OrderModel::PAYMENT_UNPAID => __( 'Awaiting confirmation', 'tutor-instructor-offline-payment' ),
	OrderModel::PAYMENT_PAID   => __( 'Confirmed', 'tutor-instructor-offline-payment' ),
	OrderModel::PAYMENT_FAILED => __( 'Rejected', 'tutor-instructor-offline-payment' ),
	'any'                      => __( 'All', 'tutor-instructor-offline-payment' ),
);
?>
<div class="tioc-dash tioc-dash-orders">

	<div class="tioc-dash-head">
		<h3 class="tioc-dash-title"><?php esc_html_e( 'Offline Payments', 'tutor-instructor-offline-payment' ); ?></h3>
		<p class="tioc-dash-subtitle">
			<?php esc_html_e( 'Payments students say they made to you. Check your own account first, then confirm — confirming enrols the student immediately.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<?php if ( ! Methods::has_active( $tioc_user_id ) ) : ?>
		<div class="tioc-alert tioc-alert-warning">
			<?php esc_html_e( 'You have not published any payment details yet, so students cannot pay you.', 'tutor-instructor-offline-payment' ); ?>
			<a href="<?php echo esc_url( tioc_dashboard_url( Dashboard::PAGE_SETUP ) ); ?>">
				<?php esc_html_e( 'Add them now', 'tutor-instructor-offline-payment' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="tioc-notice-slot" role="status" aria-live="polite"></div>

	<div class="tioc-toolbar">
		<nav class="tioc-tabs">
			<?php foreach ( $tioc_tabs as $tioc_key => $tioc_label ) : ?>
				<a
					class="tioc-tab <?php echo esc_attr( $tioc_status === $tioc_key ? 'is-active' : '' ); ?>"
					href="<?php echo esc_url( add_query_arg( 'tioc_status', $tioc_key, $tioc_base ) ); ?>">
					<?php echo esc_html( $tioc_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form class="tioc-search" method="get" action="<?php echo esc_url( $tioc_base ); ?>">
			<input type="hidden" name="tioc_status" value="<?php echo esc_attr( $tioc_status ); ?>">
			<input
				class="tioc-input"
				type="search"
				name="tioc_search"
				value="<?php echo esc_attr( $tioc_search ); ?>"
				placeholder="<?php esc_attr_e( 'Order number, student name or email', 'tutor-instructor-offline-payment' ); ?>">
			<button type="submit" class="tioc-btn tioc-btn-ghost"><?php esc_html_e( 'Search', 'tutor-instructor-offline-payment' ); ?></button>
		</form>
	</div>

	<?php if ( empty( $tioc_orders ) ) : ?>

		<div class="tioc-empty-state">
			<p><?php esc_html_e( 'Nothing here.', 'tutor-instructor-offline-payment' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="tioc-order-list">
			<?php foreach ( $tioc_orders as $tioc_order ) : ?>
				<?php
				$tioc_items      = Orders::get_items( $tioc_order->id );
				$tioc_proof      = Orders::get_proof( $tioc_order->id );
				$tioc_reference  = Orders::get_meta( $tioc_order->id, Orders::META_REFERENCE );
				$tioc_method     = Orders::get_meta( $tioc_order->id, Orders::META_METHOD_TITLE );
				$tioc_note       = Orders::get_meta( $tioc_order->id, Orders::META_STUDENT_NOTE );
				$tioc_reason     = Orders::get_meta( $tioc_order->id, Orders::META_REJECT_REASON );
				$tioc_submitted  = Orders::get_meta( $tioc_order->id, Orders::META_SUBMITTED_AT );
				$tioc_is_pending = in_array( $tioc_order->payment_status, array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ), true );
				?>
				<li class="tioc-order" id="tioc-order-<?php echo esc_attr( $tioc_order->id ); ?>">

					<div class="tioc-order-head">
						<div>
							<div class="tioc-order-id">
								<?php
								printf(
									/* translators: %s: order number */
									esc_html__( 'Order #%s', 'tutor-instructor-offline-payment' ),
									esc_html( $tioc_order->id )
								);
								?>
							</div>
							<div class="tioc-order-student">
								<?php echo esc_html( $tioc_order->student_name ? $tioc_order->student_name : __( 'Deleted user', 'tutor-instructor-offline-payment' ) ); ?>
								<?php if ( $tioc_order->student_email ) : ?>
									<a class="tioc-muted" href="<?php echo esc_url( 'mailto:' . $tioc_order->student_email ); ?>">
										<?php echo esc_html( $tioc_order->student_email ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>

						<div class="tioc-order-head-right">
							<span class="tioc-badge <?php echo esc_attr( Orders::status_class( $tioc_order->payment_status ) ); ?>">
								<?php echo esc_html( Orders::status_label( $tioc_order->payment_status ) ); ?>
							</span>
							<div class="tioc-order-total"><?php echo esc_html( tioc_format_price( $tioc_order->total_price ) ); ?></div>
						</div>
					</div>

					<ul class="tioc-order-items">
						<?php foreach ( $tioc_items as $tioc_item ) : ?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $tioc_item->id ) ); ?>">
									<?php echo esc_html( $tioc_item->title ? $tioc_item->title : __( '(deleted course)', 'tutor-instructor-offline-payment' ) ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<dl class="tioc-order-meta">
						<?php if ( $tioc_method ) : ?>
							<dt><?php esc_html_e( 'Paid via', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd><?php echo esc_html( $tioc_method ); ?></dd>
						<?php endif; ?>

						<?php if ( $tioc_reference ) : ?>
							<dt><?php esc_html_e( 'Reference', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd><code><?php echo esc_html( $tioc_reference ); ?></code></dd>
						<?php endif; ?>

						<?php if ( $tioc_submitted ) : ?>
							<dt><?php esc_html_e( 'Submitted', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd>
								<?php
								echo esc_html(
									date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										get_date_from_gmt( $tioc_submitted, 'U' )
									)
								);
								?>
							</dd>
						<?php endif; ?>

						<?php if ( ! empty( $tioc_proof['file'] ) ) : ?>
							<dt><?php esc_html_e( 'Receipt', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd class="tioc-receipt-links">
								<a
									href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id ) ); ?>"
									data-tioc-receipt
									data-mime="<?php echo esc_attr( ! empty( $tioc_proof['mime'] ) ? $tioc_proof['mime'] : '' ); ?>"
									target="_blank" rel="noopener"
								>
									<?php echo esc_html( ! empty( $tioc_proof['name'] ) ? $tioc_proof['name'] : __( 'View', 'tutor-instructor-offline-payment' ) ); ?>
								</a>
								&middot;
								<a href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id, true ) ); ?>">
									<?php esc_html_e( 'Download', 'tutor-instructor-offline-payment' ); ?>
								</a>
							</dd>
						<?php endif; ?>
					</dl>

					<?php if ( $tioc_note ) : ?>
						<div class="tioc-order-note">
							<strong><?php esc_html_e( 'Student says:', 'tutor-instructor-offline-payment' ); ?></strong>
							<?php echo esc_html( $tioc_note ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $tioc_reason ) : ?>
						<div class="tioc-order-note is-rejected">
							<strong><?php esc_html_e( 'Rejection reason:', 'tutor-instructor-offline-payment' ); ?></strong>
							<?php echo esc_html( $tioc_reason ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $tioc_is_pending && Orders::can_manage( $tioc_order->id, $tioc_user_id ) ) : ?>
						<div class="tioc-order-actions">
							<form class="tioc-inline-form" data-tioc-action="tioc_approve_order" data-tioc-confirm="approve">
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<button type="submit" class="tioc-btn tioc-btn-primary">
									<?php esc_html_e( 'I received this payment', 'tutor-instructor-offline-payment' ); ?>
								</button>
							</form>

							<form class="tioc-inline-form" data-tioc-action="tioc_reject_order" data-tioc-confirm="reject">
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<input type="hidden" name="reason" value="">
								<button type="submit" class="tioc-btn tioc-btn-danger-ghost">
									<?php esc_html_e( 'Reject', 'tutor-instructor-offline-payment' ); ?>
								</button>
							</form>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $tioc_pages > 1 ) : ?>
			<nav class="tioc-pagination">
				<?php for ( $tioc_page = 1; $tioc_page <= $tioc_pages; $tioc_page++ ) : ?>
					<?php if ( $tioc_page === $tioc_paged ) : ?>
						<span class="tioc-page is-current"><?php echo esc_html( $tioc_page ); ?></span>
					<?php else : ?>
						<a class="tioc-page" href="<?php echo esc_url(
							add_query_arg(
								array(
									'tioc_status' => $tioc_status,
									'tioc_search' => $tioc_search,
									'tioc_paged'  => $tioc_page,
								),
								$tioc_base
							)
						); ?>"><?php echo esc_html( $tioc_page ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>
</div>
