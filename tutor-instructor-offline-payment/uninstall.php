<?php
/**
 * Uninstall routine.
 *
 * Removes this plugin's own settings, the per-instructor payment details and the
 * stored receipts.
 *
 * Deliberately left alone:
 *
 * - the orders themselves. They are ordinary Tutor LMS orders and remain part of
 *   the site's sales history; deleting them would silently unpick enrolments and
 *   earnings that really happened.
 * - the `_tioc_*` order meta. It is what makes a historical order legible ("paid
 *   in cash to Amina, ref 4471"), it is tiny, and Tutor removes it with the order.
 *
 * @package TutorInstructorOfflinePayment
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Recursively delete a directory that this plugin created.
 *
 * @param string $dir Absolute path.
 *
 * @return void
 */
function tioc_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );

	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $entry;

		if ( is_dir( $path ) ) {
			tioc_uninstall_rmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
}

/**
 * Clean one site.
 *
 * @return void
 */
function tioc_uninstall_site() {
	global $wpdb;

	delete_option( 'tioc_settings' );
	delete_option( 'tioc_flush_rewrite_rules' );

	// Per-instructor payment methods and notes.
	delete_metadata( 'user', 0, '_tioc_payment_methods', '', true );
	delete_metadata( 'user', 0, '_tioc_payment_note', '', true );

	// Flash messages from an interrupted checkout.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_tioc_checkout_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tioc_checkout_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Uploaded receipts, which live outside the media library.
	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['basedir'] ) ) {
		tioc_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'tutor-offline-payments' );
	}
}

if ( is_multisite() ) {
	$tioc_sites = get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'archived' => 0,
			'deleted'  => 0,
		)
	);

	foreach ( $tioc_sites as $tioc_site_id ) {
		switch_to_blog( $tioc_site_id );
		tioc_uninstall_site();
		restore_current_blog();
	}
} else {
	tioc_uninstall_site();
}
