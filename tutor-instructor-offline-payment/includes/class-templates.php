<?php
/**
 * Template resolution.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Tutor resolves every front-end view through `tutor_get_template()`, which ends
 * with a `tutor_get_template_path` filter. Hooking that one filter is enough to
 * replace the checkout page and to add the new dashboard pages, and it keeps
 * working for the `[tutor_checkout]` shortcode because the shortcode itself calls
 * `tutor_load_template( 'ecommerce.checkout' )`.
 *
 * Themes can still win: drop a file at
 * `wp-content/themes/<theme>/tutor-offline-payment/<name>.php` to override any of
 * these views.
 */
class Templates {

	/**
	 * Theme subdirectory used for overrides.
	 *
	 * @var string
	 */
	const THEME_DIR = 'tutor-offline-payment';

	/**
	 * Template names this plugin provides, currently being resolved.
	 *
	 * @var string[]
	 */
	private $owned = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_get_template_path', array( $this, 'resolve' ), 999, 2 );
		add_action( 'tutor_load_template_before', array( $this, 'before_load' ), 10, 1 );
		add_action( 'tutor_load_template_after', array( $this, 'after_load' ), 10, 1 );
	}

	/**
	 * Map of Tutor template name to file inside this plugin's templates folder.
	 *
	 * @return array
	 */
	public function map() {
		$map = array(
			'dashboard/' . Dashboard::PAGE_SETUP   => 'dashboard/offline-payment-setup.php',
			'dashboard/' . Dashboard::PAGE_ORDERS  => 'dashboard/offline-payments.php',
			'dashboard/' . Dashboard::PAGE_STUDENT => 'dashboard/my-offline-payments.php',
		);

		if ( tioc_is_enabled() ) {
			$map['ecommerce/checkout'] = 'checkout.php';
		}

		/**
		 * Filter the template map.
		 *
		 * Keys are Tutor template names with `/` separators, values are paths
		 * relative to this plugin's `templates/` directory.
		 *
		 * @param array $map Template map.
		 */
		return apply_filters( 'tioc_template_map', $map );
	}

	/**
	 * Swap in our own file when Tutor asks for one of the names we own.
	 *
	 * @param string $path     Resolved path.
	 * @param string $template Template name, already separator-normalised by Tutor.
	 *
	 * @return string
	 */
	public function resolve( $path, $template ) {
		$name = self::normalise( $template );
		$map  = $this->map();

		if ( ! isset( $map[ $name ] ) ) {
			return $path;
		}

		// A theme override of the Tutor template itself still wins, so a site that
		// has already customised checkout keeps its own file.
		if ( 'ecommerce/checkout' === $name && self::theme_has_tutor_override( $template ) ) {
			return $path;
		}

		$theme_file = self::locate_theme_override( $map[ $name ] );
		if ( $theme_file ) {
			return $theme_file;
		}

		$plugin_file = TIOC_PATH . 'templates/' . $map[ $name ];

		return file_exists( $plugin_file ) ? $plugin_file : $path;
	}

	/**
	 * Suppress Tutor's "template does not exist" warning while it looks up a
	 * template we are about to provide.
	 *
	 * Tutor echoes that warning before the path filter runs, so the only way to
	 * keep the page clean is to silence it for exactly these template names.
	 *
	 * @param string $template Template name as passed to tutor_load_template().
	 *
	 * @return void
	 */
	public function before_load( $template ) {
		$name = self::normalise( $template );

		if ( ! isset( $this->map()[ $name ] ) ) {
			return;
		}

		$this->owned[] = $name;
		add_filter( 'tutor_not_found_template_warning_msg', '__return_empty_string', 999 );
	}

	/**
	 * Restore the warning.
	 *
	 * @param string $template Template name.
	 *
	 * @return void
	 */
	public function after_load( $template ) {
		$name = self::normalise( $template );

		if ( ! in_array( $name, $this->owned, true ) ) {
			return;
		}

		$this->owned = array_values( array_diff( $this->owned, array( $name ) ) );

		if ( empty( $this->owned ) ) {
			remove_filter( 'tutor_not_found_template_warning_msg', '__return_empty_string', 999 );
		}
	}

	/**
	 * Render one of this plugin's partials.
	 *
	 * @param string $relative Path below templates/, without extension.
	 * @param array  $vars     Variables extracted into the template scope.
	 *
	 * @return void
	 */
	public static function render( $relative, array $vars = array() ) {
		$relative = ltrim( str_replace( array( '..', '\\' ), array( '', '/' ), $relative ), '/' );
		$file     = self::locate_theme_override( $relative . '.php' );

		if ( ! $file ) {
			$file = TIOC_PATH . 'templates/' . $relative . '.php';
		}

		if ( ! file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars );

		include $file;
	}

	/**
	 * Normalise a Tutor template name to forward slashes.
	 *
	 * Tutor converts dots to DIRECTORY_SEPARATOR before firing the filter, so the
	 * incoming value differs between Windows and Linux hosts.
	 *
	 * @param string $template Template name.
	 *
	 * @return string
	 */
	private static function normalise( $template ) {
		return str_replace( array( '\\', '.' ), '/', (string) $template );
	}

	/**
	 * Find a theme override for one of this plugin's templates.
	 *
	 * @param string $relative File path below templates/.
	 *
	 * @return string|false
	 */
	private static function locate_theme_override( $relative ) {
		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . self::THEME_DIR . '/' . $relative,
			trailingslashit( get_template_directory() ) . self::THEME_DIR . '/' . $relative,
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return false;
	}

	/**
	 * Whether the active theme already overrides the stock Tutor template.
	 *
	 * @param string $template Template name as given to Tutor.
	 *
	 * @return bool
	 */
	private static function theme_has_tutor_override( $template ) {
		$relative = self::normalise( $template ) . '.php';

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . 'tutor/' . $relative,
			trailingslashit( get_template_directory() ) . 'tutor/' . $relative,
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return true;
			}
		}

		return false;
	}
}
