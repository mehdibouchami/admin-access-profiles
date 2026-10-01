<?php
/**
 * "Tracking Scripts": header / body / footer code boxes for pixels and tag managers.
 * A safe alternative to snippet plugins: the code is printed as HTML/JS, never run as PHP.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Scripts {

	const OPTION  = 'aap_scripts';
	const HISTORY = 'aap_scripts_history';
	const MAX_HISTORY = 10;

	/** True while this class saves its own options (lets the option guard through). */
	public static $saving = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_aap_save_scripts', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_aap_restore_scripts', array( __CLASS__, 'handle_restore' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 20 );
		add_action( 'wp_body_open', array( __CLASS__, 'print_body' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'print_footer' ), 99 );
	}

	public static function current() {
		$scripts = get_option( self::OPTION, array() );
		return array_merge( array( 'head' => '', 'body' => '', 'footer' => '', 'time' => 0, 'user_id' => 0 ), is_array( $scripts ) ? $scripts : array() );
	}

	private static function can_edit() {
		return AAP_Settings::is_owner() || ( AAP_Profiles::current() && current_user_can( 'aap_edit_scripts' ) );
	}

	public static function register_page() {
		if ( ! self::can_edit() ) {
			return;
		}
		add_menu_page(
			__( 'Tracking Scripts', 'admin-access-profiles' ),
			__( 'Tracking Scripts', 'admin-access-profiles' ),
			AAP_Settings::is_owner() ? 'manage_options' : 'aap_edit_scripts',
			AAP_Plugin::SCRIPTS_PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-editor-code',
			81
		);
	}

	/* ------------------------------------------------------------------
	 * Front-end output
	 * ---------------------------------------------------------------- */

	private static function should_print() {
		if ( is_admin() || is_feed() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() && ! AAP_Profiles::current() ) ) {
			return false;
		}
		if ( ! empty( AAP_Settings::get()['scripts_skip_admins'] ) && is_user_logged_in() ) {
			$roles = (array) wp_get_current_user()->roles;
			if ( in_array( 'administrator', $roles, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function output( $slot ) {
		if ( ! self::should_print() ) {
			return;
		}
		$code = self::current()[ $slot ];
		if ( '' !== trim( $code ) ) {
			echo "\n<!-- Tracking Scripts ({$slot}) -->\n" . $code . "\n<!-- /Tracking Scripts -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- intentionally raw tracking code.
		}
	}

	public static function print_head() {
		self::output( 'head' );
	}

	public static function print_body() {
		self::output( 'body' );
	}

	public static function print_footer() {
		self::output( 'footer' );
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------- */

	public static function handle_save() {
		if ( ! self::can_edit() ) {
			wp_die( esc_html__( 'Not allowed.', 'admin-access-profiles' ), 403 );
		}
		check_admin_referer( 'aap_save_scripts' );

		$old = self::current();
		$new = array(
			'head'    => isset( $_POST['aap_head'] ) ? self::clean( wp_unslash( $_POST['aap_head'] ) ) : '',
			'body'    => isset( $_POST['aap_body'] ) ? self::clean( wp_unslash( $_POST['aap_body'] ) ) : '',
			'footer'  => isset( $_POST['aap_footer'] ) ? self::clean( wp_unslash( $_POST['aap_footer'] ) ) : '',
			'time'    => time(),
			'user_id' => get_current_user_id(),
		);

		$changed = array();
		foreach ( array( 'head', 'body', 'footer' ) as $slot ) {
			if ( $old[ $slot ] !== $new[ $slot ] ) {
				$changed[ $slot ] = array( 'old' => $old[ $slot ], 'new' => $new[ $slot ] );
			}
		}

		if ( $changed ) {
			self::store( $new, $old );
			AAP_Activity_Log::log( 'scripts_saved', implode( ', ', array_keys( $changed ) ), $changed );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => AAP_Plugin::SCRIPTS_PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_restore() {
		if ( ! AAP_Settings::is_owner() ) {
			wp_die( esc_html__( 'Not allowed.', 'admin-access-profiles' ), 403 );
		}
		check_admin_referer( 'aap_restore_scripts' );
		$index   = isset( $_POST['version'] ) ? (int) $_POST['version'] : -1;
		$history = self::history();
		if ( isset( $history[ $index ] ) ) {
			$version            = $history[ $index ];
			$version['time']    = time();
			$version['user_id'] = get_current_user_id();
			self::store( $version, self::current() );
			AAP_Activity_Log::log( 'scripts_restored', 'version from ' . gmdate( 'Y-m-d H:i', (int) $history[ $index ]['time'] ) . ' UTC' );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => AAP_Plugin::SCRIPTS_PAGE, 'restored' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function clean( $code ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $code );
	}

	private static function store( array $new, array $old ) {
		self::$saving = true;
		$history = self::history();
		if ( $old['time'] || '' !== $old['head'] . $old['body'] . $old['footer'] ) {
			array_unshift( $history, $old );
		}
		update_option( self::HISTORY, array_slice( $history, 0, self::MAX_HISTORY ), false );
		update_option( self::OPTION, $new );
		self::$saving = false;
	}

	public static function history() {
		$history = get_option( self::HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! self::can_edit() ) {
			return;
		}
		$scripts = self::current();
		$slots   = array(
			'head'   => array( __( 'Inside <head>', 'admin-access-profiles' ), __( 'Meta Pixel base code, Google tag (gtag.js), Google Tag Manager part 1, site verification meta tags.', 'admin-access-profiles' ) ),
			'body'   => array( __( 'Right after <body>', 'admin-access-profiles' ), __( 'Google Tag Manager part 2 (noscript). Needs a theme that calls wp_body_open() — most themes since 2019 do.', 'admin-access-profiles' ) ),
			'footer' => array( __( 'Before </body>', 'admin-access-profiles' ), __( 'Scripts that can load last (chat widgets, extra tracking).', 'admin-access-profiles' ) ),
		);
		?>
		<div class="wrap aap-wrap">
			<h1><?php esc_html_e( 'Tracking Scripts', 'admin-access-profiles' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scripts saved.', 'admin-access-profiles' ); ?></p></div>
			<?php elseif ( isset( $_GET['restored'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Previous version restored.', 'admin-access-profiles' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Paste tracking code (HTML / JavaScript) here. It is added to every front-end page. PHP is not executed.', 'admin-access-profiles' ); ?>
				<?php if ( ! empty( AAP_Settings::get()['scripts_skip_admins'] ) ) : ?>
					<?php esc_html_e( 'The code is not loaded for logged-in administrators, so test it in a private window.', 'admin-access-profiles' ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aap_save_scripts">
				<?php wp_nonce_field( 'aap_save_scripts' ); ?>
				<?php foreach ( $slots as $slot => $text ) : ?>
					<h2><?php echo esc_html( $text[0] ); ?></h2>
					<p class="description"><?php echo esc_html( $text[1] ); ?></p>
					<textarea name="aap_<?php echo esc_attr( $slot ); ?>" rows="10" class="large-text code" spellcheck="false"><?php echo esc_textarea( $scripts[ $slot ] ); ?></textarea>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save scripts', 'admin-access-profiles' ) ); ?>
			</form>

			<?php if ( $scripts['time'] ) : ?>
				<p class="description">
					<?php
					$author = get_userdata( (int) $scripts['user_id'] );
					printf(
						/* translators: 1: date, 2: user name */
						esc_html__( 'Last saved %1$s by %2$s.', 'admin-access-profiles' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $scripts['time'] ) ),
						esc_html( $author ? $author->display_name : '#' . (int) $scripts['user_id'] )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( AAP_Settings::is_owner() ) : ?>
				<?php self::render_history( $scripts ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_history( array $current ) {
		$history = self::history();
		if ( ! $history ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Previous versions', 'admin-access-profiles' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Only site owners see this. Each entry shows what changed compared with the current code.', 'admin-access-profiles' ); ?></p>
		<?php foreach ( $history as $i => $version ) : ?>
			<?php $author = get_userdata( (int) $version['user_id'] ); ?>
			<details class="aap-version">
				<summary>
					<?php echo esc_html( $version['time'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $version['time'] ) : __( 'Initial', 'admin-access-profiles' ) ); ?>
					— <?php echo esc_html( $author ? $author->display_name : '#' . (int) $version['user_id'] ); ?>
				</summary>
				<?php
				foreach ( array( 'head', 'body', 'footer' ) as $slot ) {
					$diff = wp_text_diff( $version[ $slot ], $current[ $slot ], array( 'title_left' => __( 'This version', 'admin-access-profiles' ), 'title_right' => __( 'Current', 'admin-access-profiles' ) ) );
					if ( $diff ) {
						echo '<h4>' . esc_html( $slot ) . '</h4>' . $diff; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_text_diff escapes its content.
					}
				}
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aap_restore_scripts">
					<input type="hidden" name="version" value="<?php echo (int) $i; ?>">
					<?php wp_nonce_field( 'aap_restore_scripts' ); ?>
					<?php submit_button( __( 'Restore this version', 'admin-access-profiles' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>
		<?php endforeach; ?>
		<?php
	}
}
