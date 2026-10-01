<?php
/**
 * Plugin Name:       Admin Access Profiles
 * Plugin URI:        https://github.com/mehdibouchami/admin-access-profiles
 * Description:       Create access profiles (roles) for agencies, freelancers and staff: lock whole plugins like LearnDash, WooCommerce or snippet plugins, open extra access on demand with timed switches, and review everything in an activity log.
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      7.2
 * Author:            Mehdi Bouchami
 * Author URI:        https://github.com/mehdibouchami
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-access-profiles
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AAP_VERSION', '1.0.4' );
define( 'AAP_FILE', __FILE__ );
define( 'AAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAP_URL', plugin_dir_url( __FILE__ ) );

require_once AAP_DIR . 'includes/class-aap-util.php';
require_once AAP_DIR . 'includes/class-aap-settings.php';
require_once AAP_DIR . 'includes/class-aap-packs.php';
require_once AAP_DIR . 'includes/class-aap-profiles.php';
require_once AAP_DIR . 'includes/class-aap-menu.php';
require_once AAP_DIR . 'includes/class-aap-activity-log.php';
require_once AAP_DIR . 'includes/class-aap-cap-guard.php';
require_once AAP_DIR . 'includes/class-aap-admin-gate.php';
require_once AAP_DIR . 'includes/class-aap-rest-guard.php';
require_once AAP_DIR . 'includes/class-aap-self-protect.php';
require_once AAP_DIR . 'includes/class-aap-scripts.php';
require_once AAP_DIR . 'includes/class-aap-admin-ui.php';

class AAP_Plugin {

	const SETTINGS_PAGE = 'aap-profiles';
	const SCRIPTS_PAGE  = 'aap-scripts';
	const AUTHOR        = 'Mehdi Bouchami';
	const GITHUB_URL    = 'https://github.com/mehdibouchami';
	const REPO_URL      = 'https://github.com/mehdibouchami/admin-access-profiles';
	const SUPPORT_URL   = 'https://ba9chich.com/fr/mehdibouchami';

	public static function boot() {
		if ( get_option( 'aap_db_version' ) !== AAP_Activity_Log::DB_VERSION ) {
			AAP_Activity_Log::install_table();
		}
		self::schedule_events();
		// Updating by uploading a zip does not run the activation hook: re-sync roles on version change.
		if ( get_option( 'aap_version' ) !== AAP_VERSION ) {
			add_action( 'init', array( __CLASS__, 'upgrade' ), 5 );
		}

		AAP_Cap_Guard::init();
		AAP_Menu::init();
		AAP_Admin_Gate::init();
		AAP_Rest_Guard::init();
		AAP_Self_Protect::init();
		AAP_Scripts::init();
		AAP_Activity_Log::init();
		AAP_Admin_UI::init();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_action( 'aap_hourly', array( 'AAP_Profiles', 'expire_toggles' ) );
		add_action( 'aap_daily', array( 'AAP_Activity_Log', 'purge' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'admin-access-profiles', false, dirname( plugin_basename( AAP_FILE ) ) . '/languages' );
	}

	public static function upgrade() {
		AAP_Profiles::sync_all();
		AAP_Profiles::$syncing = true;
		update_option( 'aap_version', AAP_VERSION );
		AAP_Profiles::$syncing = false;
	}

	public static function activate() {
		AAP_Activity_Log::install_table();
		AAP_Profiles::sync_all();
		self::schedule_events();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'aap_hourly' );
		wp_clear_scheduled_hook( 'aap_daily' );
		// Without the guards running, extra caps would be unrestricted: drop profile roles to read-only.
		AAP_Profiles::reset_roles_to_minimum();
	}

	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'aap_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'aap_hourly' );
		}
		if ( ! wp_next_scheduled( 'aap_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'aap_daily' );
		}
	}
}

register_activation_hook( __FILE__, array( 'AAP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AAP_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'AAP_Plugin', 'boot' ), 1 );
