<?php
/**
 * Instructor dashboard: publish your own payment details.
 *
 * Loaded by Tutor as `dashboard/offline-payment-setup`.
 * Override by copying to
 * `<theme>/tutor-offline-payment/dashboard/offline-payment-setup.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Settings;

defined( 'ABSPATH' ) || exit;

$tioc_user_id = get_current_user_id();
$tioc_methods = Methods::get( $tioc_user_id );
$tioc_note    = Methods::get_note( $tioc_user_id );
$tioc_active  = 0;

foreach ( $tioc_methods as $tioc_method ) {
	$tioc_active += $tioc_method['is_active'] ? 1 : 0;
}

$tioc_can_add = count( $tioc_methods ) < Methods::MAX_METHODS;
?>
<div class="tioc-dash tioc-dash-setup">

	<div class="tioc-dash-head">
		<h3 class="tioc-dash-title"><?php esc_html_e( 'Payment Details', 'tutor-instructor-offline-payment' ); ?></h3>
		<p class="tioc-dash-subtitle">
			<?php esc_html_e( 'Students pay you directly. Whatever you publish here is what they see at checkout, so include everything they need to send you the money and everything you need to recognise the payment afterwards.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<div class="tioc-notice-slot" role="status" aria-live="polite"></div>

	<?php if ( ! $tioc_active ) : ?>
		<div class="tioc-alert tioc-alert-warning">
			<?php
			echo esc_html(
				Settings::get( 'block_unconfigured', 1 )
					? __( 'You have no active payment method, so your paid courses cannot currently be bought. Add one below and switch it on.', 'tutor-instructor-offline-payment' )
					: __( 'You have no active payment method, so students will not be told how to pay you. Add one below and switch it on.', 'tutor-instructor-offline-payment' )
			);
			?>
		</div>
	<?php endif; ?>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'Your payment methods', 'tutor-instructor-offline-payment' ); ?></h4>

		<?php if ( empty( $tioc_methods ) ) : ?>
			<p class="tioc-muted"><?php esc_html_e( 'Nothing published yet.', 'tutor-instructor-offline-payment' ); ?></p>
		<?php else : ?>
			<ul class="tioc-method-list">
				<?php foreach ( $tioc_methods as $tioc_method ) : ?>
					<?php $tioc_edit_id = 'tioc-edit-' . $tioc_method['id']; ?>
					<li class="tioc-method-row">
						<div class="tioc-method-row-main">
							<div class="tioc-method-row-title">
								<?php echo esc_html( $tioc_method['title'] ); ?>
								<span class="tioc-badge <?php echo esc_attr( $tioc_method['is_active'] ? 'is-paid' : 'is-pending' ); ?>">
									<?php
									echo esc_html(
										$tioc_method['is_active']
											? __( 'Shown at checkout', 'tutor-instructor-offline-payment' )
											: __( 'Hidden', 'tutor-instructor-offline-payment' )
									);
									?>
								</span>
							</div>
							<div class="tioc-method-row-body">
								<?php echo wp_kses_post( wpautop( $tioc_method['instructions'] ) ); ?>
							</div>
							<?php if ( $tioc_method['attachment_id'] ) : ?>
								<?php $tioc_thumb = wp_get_attachment_image_url( $tioc_method['attachment_id'], 'thumbnail' ); ?>
								<?php if ( $tioc_thumb ) : ?>
									<img class="tioc-method-row-thumb" src="<?php echo esc_url( $tioc_thumb ); ?>" alt="">
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<div class="tioc-method-row-actions">
							<button type="button" class="tioc-btn tioc-btn-ghost" data-tioc-toggle="<?php echo esc_attr( $tioc_edit_id ); ?>">
								<?php esc_html_e( 'Edit', 'tutor-instructor-offline-payment' ); ?>
							</button>

							<form class="tioc-inline-form" data-tioc-action="tioc_delete_method" data-tioc-confirm="delete">
								<input type="hidden" name="method_id" value="<?php echo esc_attr( $tioc_method['id'] ); ?>">
								<button type="submit" class="tioc-btn tioc-btn-danger-ghost">
									<?php esc_html_e( 'Remove', 'tutor-instructor-offline-payment' ); ?>
								</button>
							</form>
						</div>

						<div class="tioc-collapse" id="<?php echo esc_attr( $tioc_edit_id ); ?>" hidden>
							<?php
							\TutorInstructorOfflinePayment\Templates::render(
								'dashboard/partials/method-form',
								array(
									'method' => $tioc_method,
									'prefix' => $tioc_method['id'],
								)
							);
							?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'Add a payment method', 'tutor-instructor-offline-payment' ); ?></h4>

		<?php if ( ! $tioc_can_add ) : ?>
			<p class="tioc-muted">
				<?php
				printf(
					/* translators: %d: maximum number of payment methods */
					esc_html__( 'You have reached the limit of %d payment methods. Remove one to add another.', 'tutor-instructor-offline-payment' ),
					absint( Methods::MAX_METHODS )
				);
				?>
			</p>
		<?php else : ?>
			<?php
			\TutorInstructorOfflinePayment\Templates::render(
				'dashboard/partials/method-form',
				array(
					'method' => null,
					'prefix' => 'new',
				)
			);
			?>
		<?php endif; ?>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'General note', 'tutor-instructor-offline-payment' ); ?></h4>
		<p class="tioc-muted">
			<?php esc_html_e( 'Shown once above your payment methods at checkout, whichever method the student picks. Good for opening hours, a phone number, or how long confirmation usually takes.', 'tutor-instructor-offline-payment' ); ?>
		</p>

		<form class="tioc-form" data-tioc-action="tioc_save_note">
			<div class="tioc-field">
				<label class="tioc-label" for="tioc-note-field"><?php esc_html_e( 'Note to students', 'tutor-instructor-offline-payment' ); ?></label>
				<textarea class="tioc-input" id="tioc-note-field" name="note" rows="4" placeholder="<?php esc_attr_e( 'I confirm payments every evening. WhatsApp me on … if it is urgent.', 'tutor-instructor-offline-payment' ); ?>"><?php echo esc_textarea( $tioc_note ); ?></textarea>
			</div>
			<div class="tioc-form-actions">
				<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Save note', 'tutor-instructor-offline-payment' ); ?></button>
			</div>
		</form>
	</section>
</div>
