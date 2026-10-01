<?php
/**
 * Protection packs: groups of blocking rules for a plugin or area.
 *
 * Rules are wildcard patterns / regexes rather than exact names, so they keep working
 * when a plugin renames menu slugs, post types, capabilities or REST routes between versions.
 *
 * Rule keys:
 *   pages      ?page= slugs            files      core admin files
 *   post_types / taxonomies            caps       capability-name regexes
 *   cap_names  exact capability names  ajax       admin-ajax / admin-post actions
 *   rest       REST route regexes      options    option names that cannot be changed
 *   plugins    plugin folders that cannot be (de)activated or deleted
 *   hidden     plugin folders removed from the Plugins list
 *   ui         admin bar node / dashboard widget ids
 */

defined( 'ABSPATH' ) || exit;

class AAP_Packs {

	private static $rules_cache = array();

	/** Rules that apply to every profile and cannot be switched off. */
	public static function core() {
		global $wpdb;
		$own = AAP_Util::plugin_folder( plugin_basename( AAP_FILE ) );
		return array(
			'pages'      => array( AAP_Plugin::SETTINGS_PAGE . '*' ),
			'files'      => array(
				'users.php', 'user-new.php', 'user-edit.php', 'plugin-editor.php', 'theme-editor.php',
				'export.php', 'import.php', 'site-health.php', 'export-personal-data.php', 'erase-personal-data.php',
				'update-core.php', 'network.php', 'ms-*.php', 'privacy-policy-guide.php', 'authorize-application.php',
			),
			'cap_names'  => array(
				'list_users', 'edit_users', 'create_users', 'delete_users', 'promote_users', 'remove_users', 'add_users',
				'edit_plugins', 'edit_themes', 'edit_files', 'delete_plugins', 'delete_themes', 'update_core',
				'update_languages', 'install_languages', 'unfiltered_upload', 'unfiltered_html', 'export', 'import',
				'manage_network', 'manage_network_*', 'manage_sites', 'setup_network', 'upgrade_network',
				'manage_privacy_options', 'erase_others_personal_data', 'export_others_personal_data',
				'view_site_health_checks', 'resume_plugins', 'resume_themes', 'aap_manage',
			),
			'ajax'       => array( 'add-user', 'delete-plugin', 'health-check-*', 'aap_owner_*', 'aap_restore_scripts' ),
			'rest'       => array( '#^/wp-site-health#', '#^/aap(/|$)#' ),
			'options'    => array(
				'default_role', 'users_can_register', 'admin_email', 'new_admin_email', 'siteurl', 'home',
				$wpdb->prefix . 'user_roles', 'aap_*',
			),
			'plugins'    => array( $own ),
			'hidden'     => array( $own ),
			'ui'         => array( 'new-user', 'user-new', 'site-health*', 'dashboard_site_health' ),
			'post_types' => array(),
			'taxonomies' => array(),
			'caps'       => array(),
		);
	}

	/** Pack names and descriptions, kept apart from the rules because rules are read before translations load. */
	public static function labels() {
		return array(
			'learndash'   => array(
				'label'       => __( 'LearnDash', 'admin-access-profiles' ),
				'description' => __( 'Courses, lessons, topics, quizzes, questions, groups, certificates, assignments, notifications, reports, settings and add-ons. Also stops LearnDash treating the user as an admin on the front end.', 'admin-access-profiles' ),
			),
			'grassblade'  => array(
				'label'       => __( 'GrassBlade xAPI', 'admin-access-profiles' ),
				'description' => __( 'GrassBlade xAPI Companion / LRS: xAPI content, settings, reports and enrollments.', 'admin-access-profiles' ),
			),
			'woocommerce' => array(
				'label'       => __( 'WooCommerce', 'admin-access-profiles' ),
				'description' => __( 'Orders (classic and HPOS), customers, products, coupons, analytics, marketing, settings, status and extensions in the WooCommerce menu. Sales reports can still be opened read-only with the "Sales reports" switch.', 'admin-access-profiles' ),
			),
			'snippets'    => array(
				'label'       => __( 'Code snippet plugins', 'admin-access-profiles' ),
				'description' => __( 'Code Snippets, WPCode / Insert Headers and Footers, Woody snippets and similar. These can run PHP, so opening them gives full control of the site. The plugins are also hidden from the Plugins list.', 'admin-access-profiles' ),
			),
		);
	}

	/** Optional packs. "detect" = plugin folders that show the pack as installed. */
	public static function all() {
		return array(
			'learndash'   => array(
				'detect'      => array( 'sfwd-lms', 'learndash*' ),
				'rules'       => array(
					'pages'      => array(
						'learndash*', '*learndash*', 'ld-*', 'ld_*', 'ldadvquiz*', 'sfwd*', '*sfwd*', 'group_admin_page',
						'courses-*', 'lessons-*', 'topics-*', 'quizzes-*', 'questions-*', 'groups-*', 'certificates-*',
						'assignments-*', 'essays-*', 'exams-*', 'propanel*', 'ld_propanel*',
					),
					'post_types' => array( 'sfwd-*', 'groups', 'ld-*', 'ld_*' ),
					'taxonomies' => array( 'ld_*', 'ld-*' ),
					'caps'       => array( '/(course|lesson|topic|quiz|question|group|certificate|assignment|essay|learndash|sfwd|(^|_)ld_)/i' ),
					'ajax'       => array( 'learndash*', '*learndash*', 'ld_*', 'ld-*', 'ldadvquiz*', 'sfwd*', '*sfwd*' ),
					'rest'       => array( '#^/ldlms(/|$)#', '#^/learndash#', '#^/ld[-_/]#', '#^/sfwd#' ),
					'options'    => array( 'learndash*', 'sfwd*' ),
					'plugins'    => array( 'sfwd-lms', 'learndash*', '*learndash*' ),
					'ui'         => array( '*learndash*', 'ld-*', 'ld_*', '*sfwd*' ),
					'front_manage_options' => true,
				),
			),
			'grassblade'  => array(
				'detect'      => array( 'grassblade*', 'gb-*' ),
				'rules'       => array(
					'pages'      => array( 'grassblade*', '*grassblade*', 'gb_*', 'gb-*' ),
					'post_types' => array( 'gb_*', 'gb-*', 'grassblade*' ),
					'taxonomies' => array( 'gb_*' ),
					'caps'       => array( '/(grassblade|(^|_)gb_)/i' ),
					'ajax'       => array( 'grassblade*', '*grassblade*', 'gb_*', 'gb-*' ),
					'rest'       => array( '#^/grassblade#', '#^/gb[-_/]#' ),
					'options'    => array( 'grassblade*', 'gb_*' ),
					'plugins'    => array( 'grassblade*', 'gb-*' ),
					'ui'         => array( '*grassblade*', 'gb_*' ),
				),
			),
			'woocommerce' => array(
				'detect'      => array( 'woocommerce' ),
				'rules'       => array(
					'pages'      => array( 'woocommerce*', '*woocommerce*', 'wc-*', 'wc_*', 'product_*', 'product-*', 'shop_*', 'checkout_*', 'wcpay*' ),
					'post_types' => array( 'shop_*', 'product', 'product_variation', 'wc_*', 'wc-*' ),
					'taxonomies' => array( 'product_*', 'pa_*', 'shop_*', 'wc_*', 'wc-*' ),
					'caps'       => array( '/(shop_order|shop_coupon|shop_webhook|product|woocommerce)/i' ),
					'cap_names'  => array( 'manage_woocommerce' ),
					'ajax'       => array( 'woocommerce_*', 'wc_*', 'wc-*' ),
					'rest'       => array( '#^/wc(/|-|$)#', '#^/woocommerce#' ),
					'plugins'    => array( 'woocommerce*' ),
					'ui'         => array( '*woocommerce*', 'wc-*', 'wc_*' ),
					'reports'    => true,
				),
			),
			'snippets'    => array(
				'detect'      => array( 'code-snippets*', 'insert-headers-and-footers*', 'wpcode*', 'insert-php*', 'woody-*' ),
				'rules'       => array(
					'pages'      => array( 'snippets*', '*snippet*', 'wpcode*', 'ihaf*', 'insert-headers-and-footers*', 'wbcr_*', 'woody*' ),
					'post_types' => array( 'wpcode*', 'snippet*', 'code-snippet*', 'wbcr-*' ),
					'taxonomies' => array( 'wpcode*' ),
					'caps'       => array( '/(snippet|wpcode)/i' ),
					'ajax'       => array( '*snippet*', 'wpcode*', 'ihaf*', 'wbcr_*' ),
					'rest'       => array( '#^/code-snippets#', '#^/wpcode#', '#^/ihaf#' ),
					'options'    => array( 'wpcode*', 'code_snippets*', 'ihaf_*' ),
					'plugins'    => array( 'code-snippets*', 'insert-headers-and-footers*', 'wpcode*', 'insert-php*', 'woody-*' ),
					'hidden'     => array( 'code-snippets*', 'insert-headers-and-footers*', 'wpcode*', 'insert-php*', 'woody-*' ),
					'ui'         => array( '*snippet*', '*wpcode*' ),
				),
			),
		);
	}

	public static function is_detected( $pack_id ) {
		$packs = self::all();
		if ( empty( $packs[ $pack_id ] ) ) {
			return false;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( AAP_Util::matches( AAP_Util::plugin_folder( $plugin ), $packs[ $pack_id ]['detect'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Core rules + the rules of every pack the profile locks, merged.
	 *
	 * @param array $profile Profile array.
	 */
	public static function rules_for( array $profile ) {
		$id = $profile['id'];
		if ( isset( self::$rules_cache[ $id ] ) ) {
			return self::$rules_cache[ $id ];
		}
		$rules = self::core();
		$rules['front_manage_options'] = false;
		$rules['reports_pack']         = false;
		$rules['packs']                = array();

		foreach ( self::all() as $pack_id => $pack ) {
			if ( empty( $profile['packs'][ $pack_id ] ) ) {
				continue;
			}
			$rules['packs'][] = $pack_id;
			foreach ( $pack['rules'] as $key => $value ) {
				if ( 'front_manage_options' === $key ) {
					$rules['front_manage_options'] = true;
				} elseif ( 'reports' === $key ) {
					$rules['reports_pack'] = true;
				} else {
					$rules[ $key ] = array_merge( isset( $rules[ $key ] ) ? $rules[ $key ] : array(), $value );
				}
			}
		}

		// Custom rules added by the site owner on the profile screen.
		foreach ( array( 'pages', 'post_types', 'rest' ) as $key ) {
			if ( ! empty( $profile['custom'][ $key ] ) ) {
				$extra = $profile['custom'][ $key ];
				if ( 'rest' === $key ) {
					$extra = array_map(
						function ( $prefix ) {
							return '#^' . preg_quote( '/' . ltrim( $prefix, '/' ), '#' ) . '#';
						},
						$extra
					);
				}
				$rules[ $key ] = array_merge( $rules[ $key ], $extra );
			}
		}
		if ( ! empty( $profile['custom']['plugins'] ) ) {
			$rules['plugins'] = array_merge( $rules['plugins'], $profile['custom']['plugins'] );
		}

		self::$rules_cache[ $id ] = $rules;
		return $rules;
	}

	public static function flush_cache() {
		self::$rules_cache = array();
	}
}
