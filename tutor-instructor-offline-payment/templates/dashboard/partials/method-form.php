<?php
/**
 * One payment-method form, used both for adding and for editing.
 *
 * @var array|null $method Existing method, or null when adding.
 * @var string     $prefix Unique suffix for element IDs.
 *
 * @package TutorInstructorOfflinePayment
 */

use TutorInstructorOfflinePayment\Settings;

defined( 'ABSPATH' ) || exit;

$method = isset( $method ) && is_array( $method ) ? $method : null;
$prefix = isset( $prefix ) ? sanitize_key( $prefix ) : 'new';

$tioc_id           = $method ? $method['id'] : '';
$tioc_title        = $method ? $method['title'] : '';
$tioc_instructions = $method ? $method['instructions'] : '';
$tioc_is_active    = $method ? (bool) $method['is_active'] : true;
$tioc_attachment   = $method ? (int) $method['attachment_id'] : 0;
$tioc_thumb        = $tioc_attachment ? wp_get_attachment_image_url( $tioc_attachment, 'thumbnail' ) : '';
?>
<form class="tioc-form tioc-method-form" data-tioc-action="tioc_save_method" enctype="multipart/form-data">
	<input type="hidden" name="method_id" value="<?php echo esc_attr( $tioc_id ); ?>">
	<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $tioc_attachment ); ?>">

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-title-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Name', 'tutor-instructor-offline-payment' ); ?>
		</label>
		<input
			class="tioc-input"
			type="text"
			id="tioc-title-<?php echo esc_attr( $prefix ); ?>"
			name="title"
			value="<?php echo esc_attr( $tioc_title ); ?>"
			placeholder="<?php esc_attr_e( 'Bank transfer, Mobile money, Cash in person…', 'tutor-instructor-offline-payment' ); ?>"
			maxlength="120"
			required>
		<p class="tioc-help"><?php esc_html_e( 'What the student sees as the option label.', 'tutor-instructor-offline-payment' ); ?></p>
	</div>

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-instructions-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Instructions', 'tutor-instructor-offline-payment' ); ?>
		</label>
		<textarea
			class="tioc-input"
			id="tioc-instructions-<?php echo esc_attr( $prefix ); ?>"
			name="instructions"
			rows="5"
			placeholder="<?php esc_attr_e( "Account name: …\nAccount number: …\nPlease put your name in the transfer reference.", 'tutor-instructor-offline-payment' ); ?>"
			required><?php echo esc_textarea( $tioc_instructions ); ?></textarea>
		<p class="tioc-help">
			<?php esc_html_e( 'Everything the student needs in order to pay you. Basic formatting is allowed. Only publish details you are happy for every buyer to see.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-image-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Image (optional)', 'tutor-instructor-offline-payment' ); ?>
		</label>

		<?php if ( $tioc_thumb ) : ?>
			<div class="tioc-current-image">
				<img src="<?php echo esc_url( $tioc_thumb ); ?>" alt="">
				<label class="tioc-checkbox">
					<input type="checkbox" name="remove_image" value="1">
					<?php esc_html_e( 'Remove this image', 'tutor-instructor-offline-payment' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<input
			class="tioc-input"
			type="file"
			id="tioc-image-<?php echo esc_attr( $prefix ); ?>"
			name="method_image"
			accept="image/*">
		<p class="tioc-help">
			<?php
			printf(
				/* translators: %s: maximum file size */
				esc_html__( 'A QR code or a photo of your payment details. Up to %s. This image is public.', 'tutor-instructor-offline-payment' ),
				esc_html( size_format( Settings::max_upload_bytes() ) )
			);
			?>
		</p>
	</div>

	<div class="tioc-field">
		<label class="tioc-checkbox">
			<input type="checkbox" name="is_active" value="1" <?php checked( $tioc_is_active ); ?>>
			<?php esc_html_e( 'Show this method at checkout', 'tutor-instructor-offline-payment' ); ?>
		</label>
	</div>

	<div class="tioc-form-actions">
		<button type="submit" class="tioc-btn tioc-btn-primary">
			<?php
			echo esc_html(
				$method
					? __( 'Save changes', 'tutor-instructor-offline-payment' )
					: __( 'Add payment method', 'tutor-instructor-offline-payment' )
			);
			?>
		</button>
	</div>
</form>
