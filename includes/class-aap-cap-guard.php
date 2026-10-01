<?php
/**
 * Capability-level enforcement for profile users.
 *
 * A profile user only keeps capabilities the profile grants (allowlist), minus anything a
 * locked pack forbids. Works on meta capabilities and name patterns, not on LearnDash /
 * WooCommerce capability lists, because those changed between plugin versions.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Cap_Guard {

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_caps' ), 9999, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'filter_meta_caps' ), 9999, 4 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'block_role_meta' ), 9999, 3 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_wc_admin' ), 9999 );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'allow_wc_admin' ), 9999 );
	}

	public static function filter_user_caps( $allcaps, $caps, $args, $user ) {
		$profile = AAP_Profiles::for_user( $user );
		if ( ! $profile ) {
			return $allcaps;
		}
		foreach ( array_unique( array_merge( array_keys( $allcaps ), (array) $caps ) ) as $cap ) {
			if ( self::is_core_cap( $cap ) ) {
				// WordPress core capabilities: only what the profile grants.
				$allcaps[ $cap ] = AAP_Profiles::allows_cap( $profile, $cap );
			} elseif ( ! empty( $allcaps[ $cap ] ) && AAP_Profiles::is_cap_denied( $profile, $cap ) ) {
				// Plugin capabilities (often granted on the fly, e.g. Site Kit): kept unless a locked pack forbids them.
				$allcaps[ $cap ] = false;
			} elseif ( empty( $allcaps[ $cap ] ) && in_array( $cap, (array) $caps, true ) ) {
				$allcaps[ $cap ] = AAP_Profiles::allows_cap( $profile, $cap );
			}
		}
		// LearnDash treats manage_options as "admin" and bypasses course access on the front end.
		if ( ! empty( $allcaps['manage_options'] ) && AAP_Packs::rules_for( $profile )['front_manage_options'] && self::is_front_end() ) {
			$allcaps['manage_options'] = false;
		}
		return $allcaps;
	}

	/** WordPress core primitive capabilities (plus switch and safety caps) are strictly controlled. */
	private static function is_core_cap( $cap ) {
		static $core = null;
		if ( null === $core ) {
			$list = array(
				'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files',
				'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import',
				'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
				'edit_pages', 'read', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages',
				'delete_others_pages', 'delete_published_pages', 'delete_posts', 'delete_others_posts',
				'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts',
				'delete_private_pages', 'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users',
				'unfiltered_upload', 'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins',
				'update_themes', 'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users',
				'edit_theme_options', 'delete_themes', 'export', 'add_users', 'install_languages', 'update_languages',
				'resume_plugins', 'resume_themes', 'view_site_health_checks', 'manage_privacy_options',
				'erase_others_personal_data', 'export_others_personal_data', 'manage_network', 'manage_sites',
				'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options',
				'upgrade_network', 'setup_network', 'create_sites', 'delete_sites', 'delete_site',
				'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8', 'level_9', 'level_10',
			);
			foreach ( AAP_Profiles::toggle_caps() as $toggle_caps ) {
				$list = array_merge( $list, $toggle_caps );
			}
			$core = array_flip( $list );
		}
		return isset( $core[ $cap ] ) || 'do_not_allow' === $cap;
	}

	private static function is_front_end() {
		return ! is_admin()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron();
	}

	public static function filter_meta_caps( $caps, $cap, $user_id, $args ) {
		$profile = $user_id ? AAP_Profiles::for_user_id( $user_id ) : null;
		if ( ! $profile ) {
			return $caps;
		}
		$deny = array( 'do_not_allow' );
		$arg0 = isset( $args[0] ) ? $args[0] : null;

		switch ( $cap ) {
			case 'edit_post':
			case 'read_post':
			case 'delete_post':
			case 'publish_post':
			case 'edit_post_meta':
			case 'add_post_meta':
			case 'delete_post_meta':
				$post = $arg0 ? get_post( $arg0 ) : null;
				if ( $post && AAP_Menu::is_protected_post_type( $profile, $post->post_type ) ) {
					return $deny;
				}
				break;

			case 'edit_term':
			case 'delete_term':
			case 'assign_term':
				$term = $arg0 ? get_term( (int) $arg0 ) : null;
				if ( $term && ! is_wp_error( $term ) && AAP_Menu::is_protected_taxonomy( $profile, $term->taxonomy ) ) {
					return $deny;
				}
				break;

			case 'edit_comment':
				$comment = $arg0 ? get_comment( $arg0 ) : null;
				$post    = $comment ? get_post( $comment->comment_post_ID ) : null;
				if ( $post && AAP_Menu::is_protected_post_type( $profile, $post->post_type ) ) {
					return $deny;
				}
				break;

			case 'activate_plugin':
			case 'deactivate_plugin':
			case 'resume_plugin':
				if ( $arg0 && AAP_Util::matches( AAP_Util::plugin_folder( $arg0 ), AAP_Packs::rules_for( $profile )['plugins'] ) ) {
					return $deny;
				}
				break;

			case 'edit_user':
				if ( (int) $arg0 !== (int) $user_id ) {
					return $deny;
				}
				break;

			case 'promote_user':
			case 'delete_user':
			case 'remove_user':
			case 'unfiltered_html':
			case 'manage_privacy_options':
			case 'export_others_personal_data':
			case 'erase_others_personal_data':
			case 'view_site_health_checks':
				return $deny;

			case 'upload_plugins':
				if ( ! AAP_Profiles::allows_cap( $profile, 'install_plugins' ) ) {
					return $deny;
				}
				break;

			case 'upload_themes':
				if ( ! AAP_Profiles::allows_cap( $profile, 'install_themes' ) ) {
					return $deny;
				}
				break;
		}
		return $caps;
	}

	/** A profile user can never write role or level meta for anyone, including themselves. */
	public static function block_role_meta( $check, $object_id, $meta_key ) {
		global $wpdb;
		if ( AAP_Profiles::current() && in_array( $meta_key, array( $wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level' ), true ) ) {
			AAP_Activity_Log::log( 'blocked', 'user meta: ' . $meta_key, array( 'user' => (int) $object_id ) );
			return false;
		}
		return $check;
	}

	public static function allow_wc_admin( $prevent ) {
		return AAP_Profiles::current() ? false : $prevent;
	}
}
