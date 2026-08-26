<?php
/**
 * Plugin Name:       Tutor LMS Instructor Offline Payment
 * Plugin URI:        https://example.com/tutor-instructor-offline-payment
 * Description:       Replaces the default Tutor LMS checkout with an offline checkout in which every course author publishes their own payment details, receives payment directly, and approves or rejects enrolment for their own courses.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-instructor-offline-payment
 * Domain Path:       /languages
 *
 * @package TutorInstructorOfflinePayment
 */

defined( 'ABSPATH' ) || exit;

define( 'TIOC_VERSION', '1.0.1' );
define( 'TIOC_FILE', __FILE__ );
define( 'TIOC_PATH', plugin_dir_path( __FILE__ ) );
define( 'TIOC_URL', plugin_dir_url( __FILE__ ) );
define( 'TIOC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum supported Tutor LMS version.
 *
 * Native (Tutor) monetisation and the ecommerce order tables landed in 3.0.0.
 */
define( 'TIOC_MIN_TUTOR_VERSION', '3.0.0' );

require_once TIOC_PATH . 'includes/helpers.php';
require_once TIOC_PATH . 'includes/class-requirements.php';
require_once TIOC_PATH . 'includes/class-settings.php';
require_once TIOC_PATH . 'includes/class-methods.php';
require_once TIOC_PATH . 'includes/class-uploads.php';
require_once TIOC_PATH . 'includes/class-orders.php';
require_once TIOC_PATH . 'includes/class-emails.php';
require_once TIOC_PATH . 'includes/class-templates.php';
require_once TIOC_PATH . 'includes/class-checkout.php';
require_once TIOC_PATH . 'includes/class-dashboard.php';
require_once TIOC_PATH . 'includes/class-ajax.php';
require_once TIOC_PATH . 'includes/class-admin.php';
require_once TIOC_PATH . 'includes/class-plugin.php';

/**
 * Boot the plugin.
 *
 * Tutor registers its own classes on `plugins_loaded`, so wait until then
 * before deciding whether the environment is usable.
 *
 * @return void
 */
function tioc_boot() {
	\TutorInstructorOfflinePayment\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'tioc_boot', 20 );

/**
 * Activation routine.
 *
 * Seeds default settings, creates the protected proof directory and flushes
 * rewrite rules so the new instructor/student dashboard endpoints resolve.
 *
 * @return void
 */
function tioc_activate() {
	\TutorInstructorOfflinePayment\Settings::install_defaults();
	\TutorInstructorOfflinePayment\Uploads::protect_directory();
	update_option( 'tioc_flush_rewrite_rules', 1, false );
}
register_activation_hook( __FILE__, 'tioc_activate' );

/**
 * Deactivation routine.
 *
 * @return void
 */
function tioc_deactivate() {
	delete_option( 'tioc_flush_rewrite_rules' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tioc_deactivate' );
