<?php
/**
 * Admin menu registry (recorded while an owner browses wp-admin) and per-profile menu rules.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Menu {

	const REGISTRY = 'aap_menu_registry';

	private static $pages_cache = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'capture_registry' ), PHP_INT_MAX );
	}

	public static function registry() {
		$registry = get_option( self::REGISTRY, array() );
		return is_array( $registry ) ? $registry : array();
	}

	public static function capture_registry() {
		if ( ! AAP_Settings::is_owner() ) {
			return;
		}
		global $menu, $submenu;
		$old      = self::registry();
		$first    = empty( $old );
		$now      = time();
		$registry = array();
		$order    = 0;

		foreach ( (array) $menu as $item ) {
			if ( self::is_separator( $item ) ) {
				continue;
			}
			$slug              = $item[2];
			$registry[ $slug ] = self::entry( $item, '', $order++, $old, $slug, $first, $now );
			foreach ( isset( $submenu[ $slug ] ) ? (array) $submenu[ $slug ] : array() as $sub ) {
				if ( ! empty( $sub[2] ) ) {
					$key              = $slug . '>' . $sub[2];
					$registry[ $key ] = self::entry( $sub, $slug, $order++, $old, $key, $first, $now );
				}
			}
		}

		if ( $registry && serialize( $registry ) !== serialize( $old ) ) {
			update_option( self::REGISTRY, $registry, false );
		}
	}

	private static function entry( array $item, $parent, $order, array $old, $key, $first, $now ) {
		$title = trim( wp_strip_all_tags( preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) $item[0] ) ) );
		return array(
			'slug'       => $item[2],
			'title'      => '' === $title ? $item[2] : $title,
			'cap'        => isset( $item[1] ) && is_string( $item[1] ) ? $item[1] : '',
			'parent'     => $parent,
			'order'      => $order,
			'first_seen' => isset( $old[ $key ] ) ? $old[ $key ]['first_seen'] : ( $first ? 0 : $now ),
		);
	}

	public static function is_separator( $item ) {
		return empty( $item[2] ) || ( isset( $item[4] ) && false !== strpos( $item[4], 'wp-menu-separator' ) );
	}

	/* ------------------------------------------------------------------
	 * Rules
	 * ---------------------------------------------------------------- */

	public static function is_blocked_page( array $profile, $page ) {
		return AAP_Util::matches( $page, AAP_Packs::rules_for( $profile )['pages'] );
	}

	public static function is_protected_post_type( array $profile, $post_type ) {
		return '' !== (string) $post_type && AAP_Util::matches( $post_type, AAP_Packs::rules_for( $profile )['post_types'] );
	}

	public static function is_protected_taxonomy( array $profile, $taxonomy ) {
		return '' !== (string) $taxonomy && AAP_Util::matches( $taxonomy, AAP_Packs::rules_for( $profile )['taxonomies'] );
	}

	/** True when a menu slug leads to an area locked for the profile. */
	public static function is_slug_locked( array $profile, $slug ) {
		$page = AAP_Util::page_id_from_slug( $slug );
		if ( AAP_Plugin::SCRIPTS_PAGE === $page ) {
			return false;
		}
		if ( '' !== $page && self::is_blocked_page( $profile, $page ) ) {
			return true;
		}
		$file = AAP_Util::slug_file( $slug );
		if ( '' !== $file && AAP_Util::matches( $file, AAP_Packs::rules_for( $profile )['files'] ) ) {
			return true;
		}
		$args = AAP_Util::slug_args( $slug );
		if ( ! empty( $args['post_type'] ) && self::is_protected_post_type( $profile, $args['post_type'] ) ) {
			return true;
		}
		return ! empty( $args['taxonomy'] ) && self::is_protected_taxonomy( $profile, $args['taxonomy'] );
	}

	/** Core screens opened by a capability instead of the allowlist: file => caps (any). */
	public static function cap_files() {
		return array(
			'plugins.php'        => array( 'activate_plugins' ),
			'plugin-install.php' => array( 'install_plugins' ),
			'theme-install.php'  => array( 'install_themes' ),
			'update.php'         => array( 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' ),
		);
	}

	/** Caps controlling a menu slug, or empty array. */
	public static function caps_for_slug( $slug ) {
		$file  = AAP_Util::slug_file( $slug );
		$files = self::cap_files();
		return ( '' !== $file && '' === AAP_Util::page_id_from_slug( $slug ) && isset( $files[ $file ] ) ) ? $files[ $file ] : array();
	}

	public static function profile_has_any_cap( array $profile, array $caps ) {
		foreach ( $caps as $cap ) {
			if ( AAP_Profiles::allows_cap( $profile, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Capabilities the profile lacks to open a menu (WordPress hides a menu whose capability is missing,
	 * whatever the Menu access setting). Empty array when the menu can be opened.
	 */
	public static function missing_caps( array $profile, $cap ) {
		static $sample = array();
		if ( '' === (string) $cap ) {
			return array();
		}
		if ( ! array_key_exists( $profile['id'], $sample ) ) {
			$users                    = get_users( array( 'role' => AAP_Profiles::role_name( $profile['id'] ), 'number' => 1, 'fields' => 'ID' ) );
			$sample[ $profile['id'] ] = $users ? (int) $users[0] : 0;
		}
		$user_id = $sample[ $profile['id'] ];
		if ( $user_id && user_can( $user_id, $cap ) ) {
			return array();
		}
		$missing = array();
		foreach ( map_meta_cap( $cap, $user_id ) as $primitive ) {
			if ( 'exist' === $primitive ) {
				continue;
			}
			// With a real user, ask WordPress: it includes capabilities other plugins grant on the fly.
			$has = ( $user_id && 'do_not_allow' !== $primitive )
				? user_can( $user_id, $primitive )
				: AAP_Profiles::allows_cap( $profile, $primitive );
			if ( ! $has ) {
				$missing[] = $primitive;
			}
		}
		if ( ! $missing && $user_id ) {
			$missing[] = $cap; // The plugin itself decides (its own capability or setup state).
		}
		return array_unique( $missing );
	}

	/** WooCommerce Analytics / legacy Reports screens opened by the "Sales reports" switch. */
	public static function is_reports_screen( $page, array $args ) {
		if ( 'wc-admin' === $page ) {
			$path = isset( $args['path'] ) && is_string( $args['path'] ) ? $args['path'] : '';
			return (bool) preg_match( '#^/analytics(/(overview|revenue|products|categories|variations|taxes))?/?$#', $path );
		}
		if ( 'wc-reports' === $page ) {
			$tab = isset( $args['tab'] ) && is_string( $args['tab'] ) ? $args['tab'] : 'orders';
			return 'orders' === $tab;
		}
		return false;
	}

	public static function reports_open( array $profile ) {
		return AAP_Packs::rules_for( $profile )['reports_pack'] && AAP_Profiles::toggle_on( $profile, 'reports' );
	}

	public static function always_allowed_keys() {
		return array(
			'index.php', 'index.php>index.php', 'profile.php', 'profile.php>profile.php',
			AAP_Plugin::SCRIPTS_PAGE, AAP_Plugin::SCRIPTS_PAGE . '>' . AAP_Plugin::SCRIPTS_PAGE,
		);
	}

	/**
	 * How a menu entry behaves for a profile: always | locked | reports | cap | normal.
	 */
	public static function entry_state( array $profile, $key, $slug, $parent ) {
		if ( in_array( $key, self::always_allowed_keys(), true ) ) {
			return 'always';
		}
		if ( self::reports_open( $profile ) && self::is_reports_slug( $slug ) ) {
			return 'reports';
		}
		if ( ( '' !== $parent && self::is_slug_locked( $profile, $parent ) ) || self::is_slug_locked( $profile, $slug ) ) {
			return 'locked';
		}
		if ( self::caps_for_slug( $slug ) ) {
			return 'cap';
		}
		return 'normal';
	}

	public static function is_reports_slug( $slug ) {
		return self::is_reports_screen( AAP_Util::page_id_from_slug( $slug ), AAP_Util::slug_args( $slug ) );
	}

	/** Whether a profile user may see a menu entry ("parent>slug" keys for submenus). */
	public static function is_allowed( array $profile, $key, $slug, $parent = '' ) {
		switch ( self::entry_state( $profile, $key, $slug, $parent ) ) {
			case 'always':
			case 'reports':
				return true;
			case 'locked':
				return false;
			case 'cap':
				return self::profile_has_any_cap( $profile, self::caps_for_slug( $slug ) );
		}
		return 'all' === $profile['menu_mode'] || in_array( $key, $profile['allowed_menus'], true );
	}

	/** Page ids (?page=...) a profile may open. */
	public static function allowed_page_ids( array $profile ) {
		if ( isset( self::$pages_cache[ $profile['id'] ] ) ) {
			return self::$pages_cache[ $profile['id'] ];
		}
		$pages = array( AAP_Plugin::SCRIPTS_PAGE );
		foreach ( self::registry() as $key => $entry ) {
			$page = AAP_Util::page_id_from_slug( $entry['slug'] );
			if ( '' !== $page && self::is_allowed( $profile, $key, $entry['slug'], $entry['parent'] ) ) {
				$pages[] = $page;
			}
		}
		foreach ( $profile['extra_pages'] as $page ) {
			if ( ! self::is_blocked_page( $profile, $page ) ) {
				$pages[] = $page;
			}
		}
		self::$pages_cache[ $profile['id'] ] = array_unique( $pages );
		return self::$pages_cache[ $profile['id'] ];
	}
}
