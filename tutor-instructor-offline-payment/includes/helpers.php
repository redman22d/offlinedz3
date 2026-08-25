<?php
/**
 * Shared helper functions.
 *
 * @package TutorInstructorOfflinePayment
 */

defined( 'ABSPATH' ) || exit;

use TutorInstructorOfflinePayment\Settings;

if ( ! function_exists( 'tioc_get_setting' ) ) {
	/**
	 * Read a single plugin setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is absent.
	 *
	 * @return mixed
	 */
	function tioc_get_setting( $key, $default = null ) {
		return Settings::get( $key, $default );
	}
}

if ( ! function_exists( 'tioc_is_enabled' ) ) {
	/**
	 * Whether the offline checkout replacement is switched on.
	 *
	 * @return bool
	 */
	function tioc_is_enabled() {
		return (bool) Settings::get( 'enabled', true );
	}
}

if ( ! function_exists( 'tioc_get_payee_id' ) ) {
	/**
	 * Resolve the user who collects payment for a course or bundle.
	 *
	 * Defaults to the post author. Co-instructors added through Tutor's
	 * instructor picker are deliberately ignored — only one person can hold
	 * the cash for an item.
	 *
	 * @param int $item_id Course or bundle post ID.
	 *
	 * @return int User ID, or 0 when it cannot be resolved.
	 */
	function tioc_get_payee_id( $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return 0;
		}

		$author_id = (int) get_post_field( 'post_author', $item_id );

		/**
		 * Filter the payee for an item.
		 *
		 * Use this to route payment to a school owner, a co-instructor, or a
		 * fallback account when the author is no longer an instructor.
		 *
		 * @param int $author_id Resolved payee user ID.
		 * @param int $item_id   Course or bundle ID.
		 */
		return (int) apply_filters( 'tioc_course_payee_id', $author_id, $item_id );
	}
}

if ( ! function_exists( 'tioc_get_payee_name' ) ) {
	/**
	 * Human readable name for a payee.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 */
	function tioc_get_payee_name( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Unknown instructor', 'tutor-instructor-offline-payment' );
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );

		return $name ? $name : $user->display_name;
	}
}

if ( ! function_exists( 'tioc_dashboard_url' ) ) {
	/**
	 * URL of a Tutor dashboard endpoint.
	 *
	 * @param string $page_key Dashboard page slug.
	 *
	 * @return string
	 */
	function tioc_dashboard_url( $page_key = '' ) {
		if ( function_exists( 'tutor_utils' ) ) {
			return tutor_utils()->tutor_dashboard_url( $page_key );
		}

		return home_url( '/' );
	}
}

if ( ! function_exists( 'tioc_format_price' ) ) {
	/**
	 * Format an amount using Tutor's currency settings.
	 *
	 * @param float $amount Amount.
	 *
	 * @return string
	 */
	function tioc_format_price( $amount ) {
		if ( function_exists( 'tutor_get_formatted_price' ) ) {
			return tutor_get_formatted_price( $amount );
		}

		return number_format_i18n( (float) $amount, 2 );
	}
}

if ( ! function_exists( 'tioc_log' ) ) {
	/**
	 * Write to the PHP error log when WP_DEBUG is on.
	 *
	 * @param mixed $message Message or throwable.
	 *
	 * @return void
	 */
	function tioc_log( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( $message instanceof \Throwable ) {
			$message = $message->getMessage() . ' in ' . $message->getFile() . ':' . $message->getLine();
		} elseif ( ! is_scalar( $message ) ) {
			$message = wp_json_encode( $message );
		}

		error_log( '[tutor-instructor-offline-payment] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
