<?php
/**
 * Plugin settings storage and admin screen.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Settings are kept in one autoloaded option so a page render costs a single
 * cache lookup rather than a dozen.
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'tioc_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'tioc-settings';

	/**
	 * Runtime cache of the decoded option.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'               => 1,
			'allow_online_gateways' => 0,
			'require_proof'         => 0,
			'collect_reference'     => 1,
			'max_upload_mb'         => 2,
			'allowed_extensions'    => 'jpg,jpeg,png,webp,pdf',
			'admin_can_approve'     => 1,
			'notify_instructor'     => 1,
			'notify_student'        => 1,
			'earnings_mode'         => 'default',
			'checkout_notice'       => '',
			'block_unconfigured'    => 1,
		);
	}

	/**
	 * Write defaults for any key that has never been set.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::OPTION, array_merge( self::defaults(), $stored ) );
		self::$cache = null;
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}

		return $all[ $key ];
	}

	/**
	 * Allowed proof file extensions, normalised.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$raw = (string) self::get( 'allowed_extensions', 'jpg,jpeg,png,webp,pdf' );
		$out = array();

		foreach ( explode( ',', $raw ) as $ext ) {
			$ext = strtolower( trim( $ext, " \t\n\r\0\x0B." ) );
			if ( preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ) {
				$out[] = $ext;
			}
		}

		return $out ? array_unique( $out ) : array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
	}

	/**
	 * Maximum proof size in bytes.
	 *
	 * @return int
	 */
	public static function max_upload_bytes() {
		$mb = (float) self::get( 'max_upload_mb', 2 );
		$mb = $mb > 0 ? min( $mb, 20 ) : 2;

		return (int) round( $mb * MB_IN_BYTES );
	}

	/**
	 * Add the settings screen under the Tutor LMS menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'tutor',
			__( 'Instructor Offline Payment', 'tutor-instructor-offline-payment' ),
			__( 'Offline Payment', 'tutor-instructor-offline-payment' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist the submitted form.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['tioc_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'tutor-instructor-offline-payment' ) );
		}

		check_admin_referer( 'tioc_save_settings' );

		$posted = isset( $_POST['tioc'] ) && is_array( $_POST['tioc'] ) ? wp_unslash( $_POST['tioc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.

		$checkboxes = array(
			'enabled',
			'allow_online_gateways',
			'require_proof',
			'collect_reference',
			'admin_can_approve',
			'notify_instructor',
			'notify_student',
			'block_unconfigured',
		);

		$clean = array();
		foreach ( $checkboxes as $key ) {
			$clean[ $key ] = empty( $posted[ $key ] ) ? 0 : 1;
		}

		$clean['max_upload_mb']      = isset( $posted['max_upload_mb'] ) ? max( 0.1, min( 20, (float) $posted['max_upload_mb'] ) ) : 2;
		$clean['allowed_extensions'] = isset( $posted['allowed_extensions'] ) ? sanitize_text_field( $posted['allowed_extensions'] ) : 'jpg,jpeg,png,webp,pdf';
		$clean['earnings_mode']      = isset( $posted['earnings_mode'] ) && 'instructor_full' === $posted['earnings_mode'] ? 'instructor_full' : 'default';
		$clean['checkout_notice']    = isset( $posted['checkout_notice'] ) ? wp_kses_post( $posted['checkout_notice'] ) : '';

		update_option( self::OPTION, array_merge( self::all(), $clean ) );
		self::$cache = null;

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tioc-updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		$s = self::all();
		?>
		<div class="wrap tioc-admin-wrap">
			<h1><?php esc_html_e( 'Instructor Offline Payment', 'tutor-instructor-offline-payment' ); ?></h1>

			<?php if ( isset( $_GET['tioc-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tutor-instructor-offline-payment' ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:52em">
				<?php esc_html_e( 'Students pay each course author directly using the payment details that author publishes on their own dashboard. Orders are created as unpaid and only the course author (or an administrator) can mark them paid, which is what enrols the student.', 'tutor-instructor-offline-payment' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'tioc_save_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Checkout', 'tutor-instructor-offline-payment' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Replace default checkout', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
								<?php esc_html_e( 'Use the per-instructor offline checkout', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off to restore the stock Tutor LMS checkout without deactivating the plugin. Existing offline orders stay manageable.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Online gateways', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[allow_online_gateways]" value="1" <?php checked( $s['allow_online_gateways'] ); ?>>
								<?php esc_html_e( 'Also offer the site\'s active online gateways', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, students may pay online instead. Online payments go to the site owner\'s gateway account and use the stock Tutor flow (one combined order), not the per-instructor split.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Courses without payment details', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[block_unconfigured]" value="1" <?php checked( $s['block_unconfigured'] ); ?>>
								<?php esc_html_e( 'Block checkout when an author has published no active payment method', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommended. Otherwise students can submit an order that nobody has told them how to pay.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tioc-checkout-notice"><?php esc_html_e( 'Checkout notice', 'tutor-instructor-offline-payment' ); ?></label></th>
						<td>
							<textarea id="tioc-checkout-notice" name="tioc[checkout_notice]" rows="3" class="large-text"><?php echo esc_textarea( $s['checkout_notice'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown at the top of the checkout page. Basic HTML allowed.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Proof of payment', 'tutor-instructor-offline-payment' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment reference', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[collect_reference]" value="1" <?php checked( $s['collect_reference'] ); ?>>
								<?php esc_html_e( 'Ask the student for a transaction reference', 'tutor-instructor-offline-payment' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Receipt upload', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[require_proof]" value="1" <?php checked( $s['require_proof'] ); ?>>
								<?php esc_html_e( 'Require a receipt file before the order can be submitted', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The upload field is always available; this only controls whether it is mandatory.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tioc-max-upload"><?php esc_html_e( 'Maximum file size', 'tutor-instructor-offline-payment' ); ?></label></th>
						<td>
							<input id="tioc-max-upload" type="number" step="0.1" min="0.1" max="20" name="tioc[max_upload_mb]" value="<?php echo esc_attr( $s['max_upload_mb'] ); ?>" class="small-text">
							<?php esc_html_e( 'MB', 'tutor-instructor-offline-payment' ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: server upload limit */
									esc_html__( 'Your server currently accepts up to %s per upload.', 'tutor-instructor-offline-payment' ),
									esc_html( size_format( wp_max_upload_size() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tioc-extensions"><?php esc_html_e( 'Allowed file types', 'tutor-instructor-offline-payment' ); ?></label></th>
						<td>
							<input id="tioc-extensions" type="text" name="tioc[allowed_extensions]" value="<?php echo esc_attr( $s['allowed_extensions'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Comma separated extensions. Receipts are stored outside the media library and are only served to the student, the course author and administrators.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Approval and notifications', 'tutor-instructor-offline-payment' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Administrator approval', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[admin_can_approve]" value="1" <?php checked( $s['admin_can_approve'] ); ?>>
								<?php esc_html_e( 'Administrators may approve or reject any offline order', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Course authors can always act on their own orders. Uncheck this to make approval strictly the author\'s job.', 'tutor-instructor-offline-payment' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emails', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tioc[notify_instructor]" value="1" <?php checked( $s['notify_instructor'] ); ?>>
								<?php esc_html_e( 'Email the course author when a payment is submitted', 'tutor-instructor-offline-payment' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="tioc[notify_student]" value="1" <?php checked( $s['notify_student'] ); ?>>
								<?php esc_html_e( 'Email the student when their payment is approved or rejected', 'tutor-instructor-offline-payment' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Earnings ledger', 'tutor-instructor-offline-payment' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'How to record earnings', 'tutor-instructor-offline-payment' ); ?></th>
						<td>
							<label>
								<input type="radio" name="tioc[earnings_mode]" value="default" <?php checked( 'default' === $s['earnings_mode'] ); ?>>
								<?php esc_html_e( 'Use the normal Tutor commission split', 'tutor-instructor-offline-payment' ); ?>
							</label><br>
							<label>
								<input type="radio" name="tioc[earnings_mode]" value="instructor_full" <?php checked( 'instructor_full' === $s['earnings_mode'] ); ?>>
								<?php esc_html_e( 'Credit the full amount to the course author (0% admin)', 'tutor-instructor-offline-payment' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The author physically holds the money, so the default commission split will show the site as owing them their share. Choosing the second option keeps the ledger consistent with reality, but stops recording an admin commission you would have to collect from the author separately.', 'tutor-instructor-offline-payment' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'tutor-instructor-offline-payment' ), 'primary', 'tioc_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}
}
