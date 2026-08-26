<?php
/**
 * Per-instructor offline payment method storage.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Each instructor owns a list of offline payment methods stored in user meta.
 *
 * A method is a label plus free-form instructions ("Bank: ACME, IBAN: …",
 * "Mobile money to +212…", "Cash at the studio, Tue/Thu"), optionally with an
 * image such as a QR code.
 */
class Methods {

	/**
	 * User meta key holding the method list.
	 *
	 * @var string
	 */
	const META_KEY = '_tioc_payment_methods';

	/**
	 * User meta key holding the instructor's general payment note.
	 *
	 * @var string
	 */
	const NOTE_META_KEY = '_tioc_payment_note';

	/**
	 * Maximum methods per instructor. Keeps the meta row and the checkout page
	 * a sane size.
	 *
	 * @var int
	 */
	const MAX_METHODS = 10;

	/**
	 * Read an instructor's methods.
	 *
	 * @param int  $user_id     Instructor user ID.
	 * @param bool $active_only Return only enabled methods.
	 *
	 * @return array[] List of method arrays.
	 */
	public static function get( $user_id, $active_only = false ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$methods = array();
		foreach ( $stored as $method ) {
			if ( ! is_array( $method ) || empty( $method['id'] ) || empty( $method['title'] ) ) {
				continue;
			}

			$method = self::normalise( $method );

			if ( $active_only && ! $method['is_active'] ) {
				continue;
			}

			$methods[] = $method;
		}

		return $methods;
	}

	/**
	 * Find one method belonging to an instructor.
	 *
	 * @param int    $user_id   Instructor user ID.
	 * @param string $method_id Method ID.
	 *
	 * @return array|null
	 */
	public static function find( $user_id, $method_id ) {
		$method_id = sanitize_key( $method_id );
		if ( ! $method_id ) {
			return null;
		}

		foreach ( self::get( $user_id ) as $method ) {
			if ( $method['id'] === $method_id ) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * Whether an instructor has at least one usable method.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return bool
	 */
	public static function has_active( $user_id ) {
		return (bool) self::get( $user_id, true );
	}

	/**
	 * Insert or update a method.
	 *
	 * @param int   $user_id Instructor user ID.
	 * @param array $input   Raw, unsanitised input.
	 *
	 * @return array|\WP_Error The saved method, or an error.
	 */
	public static function save( $user_id, array $input ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new \WP_Error( 'tioc_invalid_user', __( 'Invalid user.', 'tutor-instructor-offline-payment' ) );
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'tioc_missing_title', __( 'Give the payment method a name, for example "Bank transfer".', 'tutor-instructor-offline-payment' ) );
		}

		$instructions = isset( $input['instructions'] ) ? wp_kses_post( trim( $input['instructions'] ) ) : '';
		if ( '' === wp_strip_all_tags( $instructions ) ) {
			return new \WP_Error( 'tioc_missing_instructions', __( 'Add the payment details students need, such as an account number or where to pay in person.', 'tutor-instructor-offline-payment' ) );
		}

		$methods   = self::get( $user_id );
		$method_id = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
		$found     = false;

		$record = array(
			'id'            => $method_id ? $method_id : self::generate_id(),
			'title'         => $title,
			'instructions'  => $instructions,
			'is_active'     => empty( $input['is_active'] ) ? 0 : 1,
			'attachment_id' => isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0,
		);

		// Only allow images the instructor can actually see, to stop attachment ID probing.
		if ( $record['attachment_id'] && ! self::can_use_attachment( $user_id, $record['attachment_id'] ) ) {
			$record['attachment_id'] = 0;
		}

		foreach ( $methods as $index => $existing ) {
			if ( $existing['id'] === $record['id'] ) {
				$methods[ $index ] = $record;
				$found             = true;
				break;
			}
		}

		if ( ! $found ) {
			if ( count( $methods ) >= self::MAX_METHODS ) {
				return new \WP_Error(
					'tioc_too_many',
					sprintf(
						/* translators: %d: maximum number of methods */
						__( 'You can publish at most %d payment methods.', 'tutor-instructor-offline-payment' ),
						self::MAX_METHODS
					)
				);
			}

			$methods[] = $record;
		}

		update_user_meta( $user_id, self::META_KEY, $methods );

		/**
		 * Fires after an instructor saves a payment method.
		 *
		 * @param int   $user_id Instructor user ID.
		 * @param array $record  Saved method.
		 */
		do_action( 'tioc_method_saved', $user_id, $record );

		return $record;
	}

	/**
	 * Delete a method.
	 *
	 * @param int    $user_id   Instructor user ID.
	 * @param string $method_id Method ID.
	 *
	 * @return bool
	 */
	public static function delete( $user_id, $method_id ) {
		$user_id   = absint( $user_id );
		$method_id = sanitize_key( $method_id );

		if ( ! $user_id || ! $method_id ) {
			return false;
		}

		$methods = self::get( $user_id );
		$kept    = array();
		$removed = false;

		foreach ( $methods as $method ) {
			if ( $method['id'] === $method_id ) {
				$removed = true;
				continue;
			}
			$kept[] = $method;
		}

		if ( ! $removed ) {
			return false;
		}

		update_user_meta( $user_id, self::META_KEY, $kept );

		return true;
	}

	/**
	 * Read the instructor's general note.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return string
	 */
	public static function get_note( $user_id ) {
		$note = get_user_meta( absint( $user_id ), self::NOTE_META_KEY, true );

		return is_string( $note ) ? $note : '';
	}

	/**
	 * Save the instructor's general note.
	 *
	 * @param int    $user_id Instructor user ID.
	 * @param string $note    Note, HTML allowed.
	 *
	 * @return void
	 */
	public static function save_note( $user_id, $note ) {
		update_user_meta( absint( $user_id ), self::NOTE_META_KEY, wp_kses_post( trim( (string) $note ) ) );
	}

	/**
	 * Fill in missing keys and coerce types.
	 *
	 * @param array $method Raw stored method.
	 *
	 * @return array
	 */
	private static function normalise( array $method ) {
		return array(
			'id'            => sanitize_key( $method['id'] ),
			'title'         => sanitize_text_field( $method['title'] ),
			'instructions'  => isset( $method['instructions'] ) ? wp_kses_post( $method['instructions'] ) : '',
			'is_active'     => empty( $method['is_active'] ) ? 0 : 1,
			'attachment_id' => isset( $method['attachment_id'] ) ? absint( $method['attachment_id'] ) : 0,
		);
	}

	/**
	 * Unique-enough identifier for a method.
	 *
	 * @return string
	 */
	private static function generate_id() {
		return 'm' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/**
	 * Whether the instructor may attach the given media item.
	 *
	 * @param int $user_id       Instructor user ID.
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	private static function can_use_attachment( $user_id, $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		if ( (int) $attachment->post_author === (int) $user_id ) {
			return true;
		}

		return user_can( $user_id, 'edit_post', $attachment_id );
	}
}
