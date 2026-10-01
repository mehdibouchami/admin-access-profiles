<?php
/**
 * Activity log: what profile users did or tried to do, plus owner changes to profiles.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Activity_Log {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aap_log';
	}

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				profile varchar(60) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				event varchar(40) NOT NULL DEFAULT '',
				object varchar(255) NOT NULL DEFAULT '',
				details longtext NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY user_id (user_id),
				KEY profile (profile),
				KEY event (event)
			) {$charset};"
		);
		update_option( 'aap_db_version', self::DB_VERSION );
	}

	public static function init() {
		add_action( 'current_screen', array( __CLASS__, 'on_screen' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_updated_option' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'on_added_option' ), 10, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_on' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_off' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'on_customize' ) );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/**
	 * @param string      $event      Short event name.
	 * @param string      $object     What it is about.
	 * @param array       $details    Extra data (stored as JSON).
	 * @param int|null    $user_id    Defaults to the current user.
	 * @param string|null $profile_id Defaults to the current user's profile.
	 */
	public static function log( $event, $object = '', array $details = array(), $user_id = null, $profile_id = null ) {
		global $wpdb;
		if ( get_option( 'aap_db_version' ) !== self::DB_VERSION ) {
			return;
		}
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( null === $profile_id ) {
			$profile    = AAP_Profiles::for_user_id( $user_id );
			$profile_id = $profile ? $profile['id'] : '';
		}
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => (int) $user_id,
				'profile'    => substr( (string) $profile_id, 0, 60 ),
				'ip'         => substr( sanitize_text_field( AAP_Util::request_value( 'REMOTE_ADDR' ) ), 0, 45 ),
				'event'      => substr( $event, 0, 40 ),
				'object'     => substr( (string) $object, 0, 255 ),
				'details'    => $details ? wp_json_encode( $details ) : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function purge() {
		global $wpdb;
		$days = max( 7, (int) AAP_Settings::get()['log_retention'] );
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) )
		);
	}

	private static function short( $value ) {
		$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
	}

	/* ------------------------------------------------------------------
	 * Event hooks (profile users only)
	 * ---------------------------------------------------------------- */

	public static function on_screen( $screen ) {
		if ( AAP_Profiles::current() && ! empty( AAP_Settings::get()['log_views'] ) ) {
			self::log( 'view', $screen->id, array( 'uri' => esc_url_raw( AAP_Util::request_value( 'REQUEST_URI' ) ) ) );
		}
	}

	public static function on_save_post( $post_id, $post ) {
		if ( ! AAP_Profiles::current() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::log( 'save_post', $post->post_type . ' #' . $post_id, array( 'title' => $post->post_title, 'status' => $post->post_status ) );
	}

	public static function on_delete_post( $post_id ) {
		if ( AAP_Profiles::current() ) {
			self::log( 'delete_post', get_post_type( $post_id ) . ' #' . $post_id, array( 'title' => get_the_title( $post_id ) ) );
		}
	}

	public static function on_trash_post( $post_id ) {
		if ( AAP_Profiles::current() ) {
			self::log( 'trash_post', get_post_type( $post_id ) . ' #' . $post_id, array( 'title' => get_the_title( $post_id ) ) );
		}
	}

	private static function skip_option( $option ) {
		return AAP_Util::matches(
			$option,
			array( '_transient_*', '_site_transient_*', 'cron', 'rewrite_rules', '*_session*', 'aap_*', 'recently_edited', 'can_compress_scripts', '*_last_run*', '*_lock' )
		);
	}

	public static function on_updated_option( $option, $old, $new ) {
		if ( AAP_Profiles::current() && ! self::skip_option( $option ) ) {
			self::log( 'option', $option, array( 'old' => self::short( $old ), 'new' => self::short( $new ) ) );
		}
	}

	public static function on_added_option( $option, $value ) {
		if ( AAP_Profiles::current() && ! self::skip_option( $option ) ) {
			self::log( 'option', $option, array( 'new' => self::short( $value ) ) );
		}
	}

	public static function on_plugin_on( $plugin ) {
		if ( AAP_Profiles::current() ) {
			self::log( 'plugin_activated', $plugin );
		}
	}

	public static function on_plugin_off( $plugin ) {
		if ( AAP_Profiles::current() ) {
			self::log( 'plugin_deactivated', $plugin );
		}
	}

	public static function on_upgrader( $upgrader, $data ) {
		if ( ! AAP_Profiles::current() ) {
			return;
		}
		$type   = isset( $data['type'] ) ? $data['type'] : '';
		$action = isset( $data['action'] ) ? $data['action'] : '';
		$items  = isset( $data['plugins'] ) ? $data['plugins'] : ( isset( $data['themes'] ) ? $data['themes'] : array() );
		if ( ! $items && 'install' === $action && isset( $upgrader->new_plugin_data['Name'] ) ) {
			$items = array( $upgrader->new_plugin_data['Name'] );
		}
		self::log( $type . '_' . $action, implode( ', ', (array) $items ) );
	}

	public static function on_switch_theme( $name ) {
		if ( AAP_Profiles::current() ) {
			self::log( 'theme_switched', $name );
		}
	}

	public static function on_customize() {
		if ( AAP_Profiles::current() ) {
			self::log( 'customizer_saved', get_stylesheet() );
		}
	}

	public static function on_login( $login, $user ) {
		$profile = AAP_Profiles::for_user( $user );
		if ( $profile ) {
			self::log( 'login', $login, array(), $user->ID, $profile['id'] );
		}
	}

	/* ------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------- */

	/**
	 * @return array{rows: array, total: int}
	 */
	public static function query( $page = 1, $per_page = 50, array $filters = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = $filters['event'];
		}
		if ( ! empty( $filters['profile'] ) ) {
			$where[]  = 'profile = %s';
			$params[] = $filters['profile'];
		}
		if ( ! empty( $filters['user'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user'];
		}
		$table     = self::table();
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) ) ) );

		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	public static function events() {
		global $wpdb;
		return (array) $wpdb->get_col( 'SELECT DISTINCT event FROM ' . self::table() . ' ORDER BY event' );
	}
}
