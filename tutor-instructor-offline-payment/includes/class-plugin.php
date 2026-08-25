<?php
/**
 * Plugin loader.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin once Tutor LMS is known to be present and usable. When the
 * requirements are not met nothing is registered beyond the admin notice, so a
 * site with Tutor deactivated keeps working normally.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Instantiated components, keyed by short name.
	 *
	 * @var array
	 */
	private $components = array();

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Not instantiable from outside.
	 */
	private function __construct() {}

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'tutor-instructor-offline-payment', false, dirname( TIOC_BASENAME ) . '/languages' );

		// One object for both calls: passes() collects the failure messages into
		// the instance, and render_notices() is what prints them. A fresh
		// instance in the callback would print nothing.
		$requirements = new Requirements();

		if ( ! $requirements->passes() ) {
			add_action( 'admin_notices', array( $requirements, 'render_notices' ) );

			return;
		}

		$this->components = array(
			'settings'   => new Settings(),
			'uploads'    => new Uploads(),
			'orders'     => new Orders(),
			'emails'     => new Emails(),
			'templates'  => new Templates(),
			'checkout'   => new Checkout(),
			'dashboard'  => new Dashboard(),
			'ajax'       => new Ajax(),
			'admin'      => new Admin(),
		);

		foreach ( $this->components as $component ) {
			$component->register();
		}

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 999 );

		/**
		 * Fires once every part of the plugin is registered.
		 *
		 * @param Plugin $plugin The loader.
		 */
		do_action( 'tioc_loaded', $this );
	}

	/**
	 * Fetch a component.
	 *
	 * @param string $key Component key.
	 *
	 * @return object|null
	 */
	public function get( $key ) {
		return isset( $this->components[ $key ] ) ? $this->components[ $key ] : null;
	}

	/**
	 * Flush rewrite rules once after activation.
	 *
	 * The dashboard pages are registered through Tutor's nav filters, which are
	 * read when the rules are generated — so the flush has to happen after this
	 * plugin's filters exist, not during activation.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( ! get_option( 'tioc_flush_rewrite_rules' ) ) {
			return;
		}

		delete_option( 'tioc_flush_rewrite_rules' );
		flush_rewrite_rules();
	}
}
