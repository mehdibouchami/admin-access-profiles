<?php
/**
 * Access profiles. Each profile is backed by its own WordPress role ("aap_{id}").
 * A user who has such a role is governed by that profile.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Profiles {

	const OPTION      = 'aap_profiles';
	const ROLE_PREFIX = 'aap_';

	/** True while this class writes the roles option (lets the option guard through). */
	public static $syncing = false;

	private static $profiles   = null;
	private static $user_cache = array();
	private static $caps_cache = array();

	/* ------------------------------------------------------------------
	 * Switches
	 * ---------------------------------------------------------------- */

	/** Capabilities each switch grants. No translated strings: safe for early capability checks. */
	public static function toggle_caps() {
		return array(
			'content'    => array(
				'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
				'delete_posts', 'delete_others_posts', 'delete_published_posts', 'read_private_posts',
				'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages',
				'delete_pages', 'delete_others_pages', 'delete_published_pages', 'read_private_pages',
				'manage_categories', 'upload_files', 'moderate_comments',
			),
			'appearance' => array( 'edit_theme_options' ),
			'themes'     => array( 'switch_themes', 'install_themes', 'update_themes' ),
			'settings'   => array( 'manage_options' ),
			'plugins'    => array( 'install_plugins', 'activate_plugins', 'update_plugins' ),
			'reports'    => array( 'view_woocommerce_reports' ),
		);
	}

	public static function toggle_labels() {
		return array(
			'content'    => array(
				'label'       => __( 'Posts, pages & media', 'admin-access-profiles' ),
				'description' => __( 'Create and edit posts and pages, upload media, moderate comments. Content of locked packs (courses, products…) stays blocked.', 'admin-access-profiles' ),
			),
			'appearance' => array(
				'label'       => __( 'Menus, widgets & Customizer', 'admin-access-profiles' ),
				'description' => __( 'Edit navigation menus, widgets, the Customizer and the Site Editor.', 'admin-access-profiles' ),
			),
			'themes'     => array(
				'label'       => __( 'Install & switch themes', 'admin-access-profiles' ),
				'description' => __( 'Change, install or update themes.', 'admin-access-profiles' ),
				'warning'     => __( 'A theme can run PHP code, so it could get around these restrictions.', 'admin-access-profiles' ),
			),
			'settings'   => array(
				'label'       => __( 'Plugin settings pages', 'admin-access-profiles' ),
				'description' => __( 'Gives manage_options, which most plugins require for their settings screens (pixels, tag managers, SEO, analytics). Each screen still has to be allowed on the Menu access list.', 'admin-access-profiles' ),
			),
			'plugins'    => array(
				'label'       => __( 'Install & activate plugins', 'admin-access-profiles' ),
				'description' => __( 'Install, activate and update plugins. Plugins protected by a locked pack (and this plugin) can never be deactivated or deleted.', 'admin-access-profiles' ),
				'warning'     => __( 'A plugin they install runs with full power and could get around these restrictions. Turn this on for a short time, then check the Activity log.', 'admin-access-profiles' ),
			),
			'reports'    => array(
				'label'       => __( 'Sales reports (read-only)', 'admin-access-profiles' ),
				'description' => __( 'WooCommerce Analytics: Overview, Revenue, Products, Categories, Variations and Taxes. Orders, customers, coupons and stock stay blocked.', 'admin-access-profiles' ),
			),
		);
	}

	/** Switches that make sense on this site (Sales reports needs WooCommerce). */
	public static function available_toggles() {
		$labels = self::toggle_labels();
		if ( ! AAP_Packs::is_detected( 'woocommerce' ) ) {
			unset( $labels['reports'] );
		}
		return $labels;
	}

	public static function toggle_on( array $profile, $key ) {
		if ( empty( $profile['toggles'][ $key ]['on'] ) ) {
			return false;
		}
		$expires = (int) $profile['toggles'][ $key ]['expires'];
		return 0 === $expires || $expires > time();
	}

	/* ------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		$toggles = array();
		foreach ( array_keys( self::toggle_caps() ) as $key ) {
			$toggles[ $key ] = array( 'on' => 0, 'expires' => 0 );
		}
		$packs = array();
		foreach ( array_keys( AAP_Packs::all() ) as $pack ) {
			$packs[ $pack ] = 1;
		}
		return array(
			'id'            => '',
			'name'          => '',
			'description'   => '',
			'base_role'     => '',
			'packs'         => $packs,
			'toggles'       => $toggles,
			'menu_mode'     => 'allowlist',
			'allowed_menus' => array(),
			'extra_pages'   => array(),
			'scripts'       => 0,
			'extra_caps'    => array(),
			'custom'        => array( 'pages' => array(), 'post_types' => array(), 'rest' => array(), 'plugins' => array() ),
			'created'       => 0,
		);
	}

	private static function normalize( array $profile ) {
		$defaults           = self::defaults();
		$profile            = array_merge( $defaults, $profile );
		$profile['toggles'] = array_merge( $defaults['toggles'], (array) $profile['toggles'] );
		$profile['custom']  = array_merge( $defaults['custom'], (array) $profile['custom'] );
		$profile['packs']   = (array) $profile['packs'];
		return $profile;
	}

	public static function all() {
		if ( null === self::$profiles ) {
			$saved          = get_option( self::OPTION, array() );
			self::$profiles = array();
			foreach ( (array) $saved as $id => $profile ) {
				if ( is_array( $profile ) ) {
					self::$profiles[ $id ] = self::normalize( $profile );
				}
			}
		}
		return self::$profiles;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function save( array $profile ) {
		$all                   = self::all();
		$profile               = self::normalize( $profile );
		$all[ $profile['id'] ] = $profile;
		self::write( $all );
		self::sync_role( $profile );
		return $profile;
	}

	public static function delete( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$role = self::role_name( $id );
		foreach ( get_users( array( 'role' => $role ) ) as $user ) {
			$user->remove_role( $role );
			if ( ! $user->roles ) {
				$user->set_role( 'subscriber' );
			}
		}
		self::$syncing = true;
		remove_role( $role );
		self::$syncing = false;
		unset( $all[ $id ] );
		self::write( $all );
	}

	private static function write( array $all ) {
		self::$syncing = true;
		update_option( self::OPTION, $all, true );
		self::$syncing = false;
		self::$profiles   = null;
		self::$caps_cache = array();
		AAP_Packs::flush_cache();
	}

	/** Unique id from a name ("Marketing agency" → "marketing_agency"). */
	public static function unique_id( $name ) {
		$base = substr( trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( remove_accents( $name ) ) ), '_' ), 0, 40 );
		$base = '' === $base ? 'profile' : $base;
		$id   = $base;
		$n    = 2;
		while ( self::get( $id ) || get_role( self::role_name( $id ) ) ) {
			$id = $base . '_' . $n++;
		}
		return $id;
	}

	public static function role_name( $id ) {
		return self::ROLE_PREFIX . $id;
	}

	/* ------------------------------------------------------------------
	 * Presets for the "New profile" screen
	 * ---------------------------------------------------------------- */

	public static function presets() {
		$presets = array(
			'blank'     => array(
				'label'       => __( 'Blank — dashboard and profile only', 'admin-access-profiles' ),
				'profile'     => array(),
			),
			'agency'    => array(
				'label'       => __( 'Marketing / ads agency — tracking scripts, open more on request', 'admin-access-profiles' ),
				'profile'     => array( 'scripts' => 1, 'description' => __( 'Ads and tracking agency.', 'admin-access-profiles' ) ),
			),
			'seo'       => array(
				'label'       => __( 'SEO specialist — posts & pages, plugin settings pages, scripts', 'admin-access-profiles' ),
				'profile'     => array(
					'scripts' => 1,
					'toggles' => array( 'content' => array( 'on' => 1, 'expires' => 0 ), 'settings' => array( 'on' => 1, 'expires' => 0 ) ),
				),
			),
			'writer'    => array(
				'label'       => __( 'Content writer — based on Editor, every menu it can use', 'admin-access-profiles' ),
				'profile'     => array(
					'base_role' => 'editor',
					'menu_mode' => 'all',
					'toggles'   => array( 'content' => array( 'on' => 1, 'expires' => 0 ) ),
				),
			),
		);
		if ( get_role( 'shop_manager' ) ) {
			$presets['shop'] = array(
				'label'   => __( 'Shop manager without LMS — WooCommerce open, LearnDash / GrassBlade / snippets locked', 'admin-access-profiles' ),
				'profile' => array(
					'base_role' => 'shop_manager',
					'menu_mode' => 'all',
					'toggles'   => array(
						'content' => array( 'on' => 1, 'expires' => 0 ),
						'reports' => array( 'on' => 1, 'expires' => 0 ),
					),
					'packs'     => array( 'learndash' => 1, 'grassblade' => 1, 'woocommerce' => 0, 'snippets' => 1 ),
				),
			);
		}
		return $presets;
	}

	/* ------------------------------------------------------------------
	 * Who is governed by what
	 * ---------------------------------------------------------------- */

	/** Profile governing a user, or null. */
	public static function for_user( $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return null;
		}
		if ( ! array_key_exists( $user->ID, self::$user_cache ) ) {
			$found = null;
			foreach ( (array) $user->roles as $role ) {
				if ( 0 === strpos( $role, self::ROLE_PREFIX ) ) {
					$found = self::get( substr( $role, strlen( self::ROLE_PREFIX ) ) );
					if ( $found ) {
						break;
					}
				}
			}
			self::$user_cache[ $user->ID ] = $found;
		}
		return self::$user_cache[ $user->ID ];
	}

	public static function for_user_id( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$user_cache ) ) {
			self::for_user( get_userdata( $user_id ) );
			if ( ! array_key_exists( $user_id, self::$user_cache ) ) {
				self::$user_cache[ $user_id ] = null;
			}
		}
		return self::$user_cache[ $user_id ];
	}

	/** Profile of the current user, or null. */
	public static function current() {
		if ( ! function_exists( 'get_current_user_id' ) || ! did_action( 'plugins_loaded' ) ) {
			return null;
		}
		return self::for_user_id( get_current_user_id() );
	}

	/* ------------------------------------------------------------------
	 * Capabilities
	 * ---------------------------------------------------------------- */

	/** Every capability the profile is meant to have (before pack/core denials). */
	public static function granted_caps( array $profile ) {
		$id = $profile['id'];
		if ( isset( self::$caps_cache[ $id ] ) ) {
			return self::$caps_cache[ $id ];
		}
		$caps = array( 'read' => true, 'view_admin_dashboard' => true );
		if ( $profile['scripts'] ) {
			$caps['aap_edit_scripts'] = true;
		}
		if ( $profile['base_role'] && 0 !== strpos( $profile['base_role'], self::ROLE_PREFIX ) ) {
			$base = get_role( $profile['base_role'] );
			if ( $base ) {
				foreach ( (array) $base->capabilities as $cap => $grant ) {
					if ( $grant ) {
						$caps[ $cap ] = true;
					}
				}
			}
		}
		// Switches have the last word on their capabilities: Off removes them even when the base role has them.
		foreach ( self::toggle_caps() as $toggle => $list ) {
			$on = self::toggle_on( $profile, $toggle );
			foreach ( $list as $cap ) {
				if ( $on ) {
					$caps[ $cap ] = true;
				} else {
					unset( $caps[ $cap ] );
				}
			}
		}
		// Extra capabilities are typed in explicitly by the owner, so they come last.
		foreach ( $profile['extra_caps'] as $cap ) {
			$caps[ $cap ] = true;
		}
		self::$caps_cache[ $id ] = $caps;
		return $caps;
	}

	/** True when a locked pack or the core rules forbid a capability. */
	public static function is_cap_denied( array $profile, $cap ) {
		$rules = AAP_Packs::rules_for( $profile );
		if ( 'view_woocommerce_reports' === $cap && $rules['reports_pack'] ) {
			return ! self::toggle_on( $profile, 'reports' );
		}
		return AAP_Util::matches( $cap, $rules['cap_names'] ) || AAP_Util::matches_regex( $cap, $rules['caps'] );
	}

	/** Final: may a profile user have this primitive capability? */
	public static function allows_cap( array $profile, $cap ) {
		$granted = self::granted_caps( $profile );
		return ! empty( $granted[ $cap ] ) && ! self::is_cap_denied( $profile, $cap );
	}

	/* ------------------------------------------------------------------
	 * Roles
	 * ---------------------------------------------------------------- */

	public static function sync_role( array $profile ) {
		unset( self::$caps_cache[ $profile['id'] ] );
		$desired = array();
		foreach ( array_keys( self::granted_caps( $profile ) ) as $cap ) {
			if ( ! self::is_cap_denied( $profile, $cap ) ) {
				$desired[ $cap ] = true;
			}
		}

		self::$syncing = true;
		$name = self::role_name( $profile['id'] );
		$role = get_role( $name );
		if ( ! $role ) {
			add_role( $name, $profile['name'], $desired );
		} else {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( ! isset( $desired[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
			foreach ( $desired as $cap => $grant ) {
				if ( empty( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap, $grant );
				}
			}
			self::rename_role( $name, $profile['name'] );
		}
		self::$syncing = false;
	}

	private static function rename_role( $name, $label ) {
		$roles = wp_roles();
		if ( isset( $roles->roles[ $name ] ) && $roles->roles[ $name ]['name'] !== $label ) {
			$roles->roles[ $name ]['name'] = $label;
			$roles->role_names[ $name ]    = $label;
			update_option( $roles->role_key, $roles->roles );
		}
	}

	public static function sync_all() {
		foreach ( self::all() as $profile ) {
			self::sync_role( $profile );
		}
	}

	/** Deactivation: without the guards, profile roles keep only "read". */
	public static function reset_roles_to_minimum() {
		self::$syncing = true;
		foreach ( array_keys( self::all() ) as $id ) {
			$role = get_role( self::role_name( $id ) );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( 'read' !== $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
		self::$syncing = false;
	}

	/** Hourly cron: switch off switches whose time is up. */
	public static function expire_toggles() {
		foreach ( self::all() as $profile ) {
			$expired = array();
			foreach ( $profile['toggles'] as $key => $toggle ) {
				if ( ! empty( $toggle['on'] ) && ! empty( $toggle['expires'] ) && (int) $toggle['expires'] <= time() ) {
					$profile['toggles'][ $key ] = array( 'on' => 0, 'expires' => 0 );
					$expired[] = $key;
				}
			}
			if ( $expired ) {
				self::save( $profile );
				AAP_Activity_Log::log( 'switch_expired', implode( ', ', $expired ), array(), 0, $profile['id'] );
			}
		}
	}
}
