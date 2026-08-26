<?php
/**
 * Admin overview of every offline order across all authors.
 *
 * Rendered by Admin::render_page() through Templates::render().
 *
 * @var object[] $orders   Order rows.
 * @var int      $total    Total matching rows.
 * @var string   $status   Current payment-status filter.
 * @var string   $search   Current search term.
 * @var int      $paged    Current page, 1-based.
 * @var int      $per_page Page size.
 *
 * @package TutorInstructorOfflinePayment
 */

use Tutor\Ecommerce\OrderController;
use Tutor\Models\OrderModel;
use TutorInstructorOfflinePayment\Admin;
use TutorInstructorOfflinePayment\Orders;
use TutorInstructorOfflinePayment\Settings;
use TutorInstructorOfflinePayment\Uploads;

defined( 'ABSPATH' ) || exit;

$orders   = isset( $orders ) && is_array( $orders ) ? $orders : array();
$total    = isset( $total ) ? (int) $total : 0;
$status   = isset( $status ) ? (string) $status : OrderModel::PAYMENT_UNPAID;
$search   = isset( $search ) ? (string) $search : '';
$paged    = isset( $paged ) ? max( 1, (int) $paged ) : 1;
$per_page = isset( $per_page ) ? max( 1, (int) $per_page ) : 20;

$tioc_pages = (int) ceil( $total / $per_page );
$tioc_base  = admin_url( 'admin.php?page=' . Admin::PAGE_SLUG );

$tioc_tabs = array(
	OrderModel::PAYMENT_UNPAID   => __( 'Awaiting confirmation', 'tutor-instructor-offline-payment' ),
	OrderModel::PAYMENT_PAID     => __( 'Confirmed', 'tutor-instructor-offline-payment' ),
	OrderModel::PAYMENT_FAILED   => __( 'Rejected', 'tutor-instructor-offline-payment' ),
	OrderModel::PAYMENT_REFUNDED => __( 'Refunded', 'tutor-instructor-offline-payment' ),
	'any'                        => __( 'All', 'tutor-instructor-offline-payment' ),
);

$tioc_order_page = class_exists( '\Tutor\Ecommerce\OrderController' )
	? OrderController::get_order_page_url()
	: '';
?>
<div class="wrap tioc-admin-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Offline Payments', 'tutor-instructor-offline-payment' ); ?></h1>

	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ); ?>">
		<?php esc_html_e( 'Settings', 'tutor-instructor-offline-payment' ); ?>
	</a>

	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Orders placed through the offline checkout. Each course author normally confirms their own payments from their dashboard; you can step in here when they cannot.', 'tutor-instructor-offline-payment' ); ?>
	</p>

	<?php if ( ! Settings::get( 'admin_can_approve', 1 ) ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Administrator confirmation is switched off in the settings, so this page is read-only. Only the course author can settle their own payments.', 'tutor-instructor-offline-payment' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php $tioc_last = array_key_last( $tioc_tabs ); ?>
		<?php foreach ( $tioc_tabs as $tioc_key => $tioc_label ) : ?>
			<li>
				<a
					href="<?php echo esc_url( add_query_arg( 'status', $tioc_key, $tioc_base ) ); ?>"
					class="<?php echo esc_attr( $status === $tioc_key ? 'current' : '' ); ?>">
					<?php echo esc_html( $tioc_label ); ?>
				</a>
				<?php echo $tioc_key === $tioc_last ? '' : ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_SLUG ); ?>">
		<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<p class="search-box">
			<label class="screen-reader-text" for="tioc-search-input">
				<?php esc_html_e( 'Search offline orders', 'tutor-instructor-offline-payment' ); ?>
			</label>
			<input type="search" id="tioc-search-input" name="s" value="<?php echo esc_attr( $search ); ?>">
			<?php submit_button( __( 'Search', 'tutor-instructor-offline-payment' ), '', '', false ); ?>
		</p>
	</form>

	<table class="wp-list-table widefat fixed striped tioc-admin-table">
		<thead>
			<tr>
				<th scope="col" class="column-primary"><?php esc_html_e( 'Order', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Student', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Items', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Paid to', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Amount', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Payment', 'tutor-instructor-offline-payment' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'tutor-instructor-offline-payment' ); ?></th>
			</tr>
		</thead>

		<tbody>
			<?php if ( empty( $orders ) ) : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No offline orders match this filter.', 'tutor-instructor-offline-payment' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $orders as $tioc_order ) : ?>
					<?php
					$tioc_instructor = (int) $tioc_order->instructor_id;
					$tioc_items      = Orders::get_items( $tioc_order->id );
					$tioc_proof      = Orders::get_proof( $tioc_order->id );
					$tioc_reference  = Orders::get_meta( $tioc_order->id, Orders::META_REFERENCE );
					$tioc_method     = Orders::get_meta( $tioc_order->id, Orders::META_METHOD_TITLE );
					$tioc_note       = Orders::get_meta( $tioc_order->id, Orders::META_STUDENT_NOTE );
					$tioc_reason     = Orders::get_meta( $tioc_order->id, Orders::META_REJECT_REASON );
					$tioc_pending    = in_array(
						$tioc_order->payment_status,
						array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ),
						true
					);
					?>
					<tr>
						<td class="column-primary">
							<strong>
								<?php if ( $tioc_order_page ) : ?>
									<a href="<?php echo esc_url( $tioc_order_page . '&action=edit&id=' . $tioc_order->id ); ?>">
										#<?php echo esc_html( $tioc_order->id ); ?>
									</a>
								<?php else : ?>
									#<?php echo esc_html( $tioc_order->id ); ?>
								<?php endif; ?>
							</strong>
							<div class="row-actions">
								<span>
									<?php
									echo esc_html(
										date_i18n(
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											get_date_from_gmt( $tioc_order->created_at_gmt, 'U' )
										)
									);
									?>
								</span>
							</div>
						</td>

						<td>
							<?php echo esc_html( $tioc_order->student_name ? $tioc_order->student_name : __( 'Deleted user', 'tutor-instructor-offline-payment' ) ); ?>
							<?php if ( $tioc_order->student_email ) : ?>
								<div class="row-actions">
									<span><a href="<?php echo esc_url( 'mailto:' . $tioc_order->student_email ); ?>"><?php echo esc_html( $tioc_order->student_email ); ?></a></span>
								</div>
							<?php endif; ?>
						</td>

						<td>
							<?php if ( empty( $tioc_items ) ) : ?>
								<span class="tioc-muted">&mdash;</span>
							<?php else : ?>
								<?php foreach ( $tioc_items as $tioc_item ) : ?>
									<div>
										<a href="<?php echo esc_url( get_edit_post_link( $tioc_item->id ) ); ?>">
											<?php echo esc_html( $tioc_item->title ? $tioc_item->title : __( '(deleted)', 'tutor-instructor-offline-payment' ) ); ?>
										</a>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>

						<td>
							<?php if ( $tioc_instructor ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $tioc_instructor ) ); ?>">
									<?php echo esc_html( tioc_get_payee_name( $tioc_instructor ) ); ?>
								</a>
							<?php else : ?>
								<span class="tioc-muted"><?php esc_html_e( 'Unknown', 'tutor-instructor-offline-payment' ); ?></span>
							<?php endif; ?>
						</td>

						<td><?php echo esc_html( tioc_format_price( $tioc_order->total_price ) ); ?></td>

						<td>
							<?php if ( $tioc_method ) : ?>
								<div><?php echo esc_html( $tioc_method ); ?></div>
							<?php endif; ?>

							<?php if ( $tioc_reference ) : ?>
								<div><code><?php echo esc_html( $tioc_reference ); ?></code></div>
							<?php endif; ?>

							<?php if ( ! empty( $tioc_proof['file'] ) ) : ?>
								<div>
									<a href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id ) ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'View receipt', 'tutor-instructor-offline-payment' ); ?>
									</a>
								</div>
							<?php endif; ?>

							<?php if ( $tioc_note ) : ?>
								<div class="tioc-muted"><?php echo esc_html( wp_trim_words( $tioc_note, 18 ) ); ?></div>
							<?php endif; ?>
						</td>

						<td>
							<span class="tioc-badge <?php echo esc_attr( Orders::status_class( $tioc_order->payment_status ) ); ?>">
								<?php echo esc_html( Orders::status_label( $tioc_order->payment_status ) ); ?>
							</span>

							<?php if ( $tioc_reason ) : ?>
								<div class="tioc-muted"><?php echo esc_html( $tioc_reason ); ?></div>
							<?php endif; ?>

							<?php if ( $tioc_pending && Orders::can_manage( $tioc_order->id ) ) : ?>
								<div class="tioc-admin-row-meta">
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="tioc-inline-form"
										onsubmit="return confirm('<?php echo esc_js( __( 'Confirm that this payment reached the instructor? The student will be enrolled straight away.', 'tutor-instructor-offline-payment' ) ); ?>');">
										<?php wp_nonce_field( Admin::DECISION_ACTION . '_' . $tioc_order->id ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Admin::DECISION_ACTION ); ?>">
										<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
										<input type="hidden" name="decision" value="approve">
										<button type="submit" class="button button-primary button-small">
											<?php esc_html_e( 'Confirm', 'tutor-instructor-offline-payment' ); ?>
										</button>
									</form>

									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="tioc-inline-form"
										onsubmit="return confirm('<?php echo esc_js( __( 'Reject this payment? The student will be notified and will not be enrolled.', 'tutor-instructor-offline-payment' ) ); ?>');">
										<?php wp_nonce_field( Admin::DECISION_ACTION . '_' . $tioc_order->id ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Admin::DECISION_ACTION ); ?>">
										<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
										<input type="hidden" name="decision" value="reject">
										<button type="submit" class="button button-small">
											<?php esc_html_e( 'Reject', 'tutor-instructor-offline-payment' ); ?>
										</button>
									</form>
								</div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $tioc_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of orders */
						esc_html( _n( '%s item', '%s items', $total, 'tutor-instructor-offline-payment' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<span class="pagination-links">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', add_query_arg( array( 'status' => $status, 's' => $search ), $tioc_base ) ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $tioc_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</span>
			</div>
		</div>
	<?php endif; ?>
</div>
