<?php
/**
 * wp-admin gate for profile users: menu hiding, direct URL blocking,
 * admin-ajax / admin-post blocking, admin bar and dashboard cleanup.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Admin_Gate {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'hide_menus' ), PHP_INT_MAX );
		// "init" runs before most plugins process ?page= requests on admin_init.
		add_action( 'init', array( __CLASS__, 'gate_request' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'clean_admin_bar' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'clean_dashboard' ), PHP_INT_MAX );
	}

	/* ------------------------------------------------------------------
	 * Menus
	 * ---------------------------------------------------------------- */

	public static function hide_menus() {
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return;
		}
		global $menu, $submenu;

		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $i => $item ) {
				if ( ! AAP_Menu::is_allowed( $profile, $parent . '>' . $item[2], $item[2], $parent ) ) {
					unset( $submenu[ $parent ][ $i ] );
				}
			}
			if ( empty( $submenu[ $parent ] ) ) {
				unset( $submenu[ $parent ] );
			} else {
				$submenu[ $parent ] = array_values( $submenu[ $parent ] );
			}
		}

		foreach ( (array) $menu as $i => $item ) {
			if ( AAP_Menu::is_separator( $item ) ) {
				continue;
			}
			// A top item stays when it is allowed itself or still has an allowed submenu.
			if ( empty( $submenu[ $item[2] ] ) && ! AAP_Menu::is_allowed( $profile, $item[2], $item[2] ) ) {
				unset( $menu[ $i ] );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Requests
	 * ---------------------------------------------------------------- */

	public static function gate_request() {
		if ( ! is_admin() ) {
			return;
		}
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return;
		}
		global $pagenow;
		$rules = AAP_Packs::rules_for( $profile );

		if ( wp_doing_ajax() || 'admin-post.php' === $pagenow ) {
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( '' !== $action && AAP_Util::matches( $action, $rules['ajax'] ) ) {
				self::deny( $profile, 'action: ' . $action, wp_doing_ajax() );
			}
			return;
		}

		$args = wp_unslash( $_GET );
		if ( 'post.php' === $pagenow && isset( $_POST['post_ID'] ) ) {
			$args['post'] = (int) $_POST['post_ID'];
		}
		if ( isset( $_POST['post_type'] ) && is_string( $_POST['post_type'] ) && ! isset( $args['post_type'] ) ) {
			$args['post_type'] = sanitize_key( wp_unslash( $_POST['post_type'] ) );
		}

		$reason = 'options.php' === $pagenow
			? self::options_block_reason( $profile )
			: self::screen_block_reason( $profile, $pagenow, $args );
		if ( '' !== $reason ) {
			self::deny( $profile, $reason );
		}
	}

	/**
	 * Why a profile user may not open a screen ('' when allowed).
	 *
	 * @param array  $profile Profile.
	 * @param string $file    Admin file, e.g. "edit.php" or "admin.php".
	 * @param array  $args    Query args.
	 */
	public static function screen_block_reason( array $profile, $file, array $args ) {
		$page = isset( $args['page'] ) && is_string( $args['page'] ) ? sanitize_text_field( $args['page'] ) : '';
		$type = self::request_post_type( $file, $args );
		$tax  = self::request_taxonomy( $file, $args );

		if ( '' !== $type && AAP_Menu::is_protected_post_type( $profile, $type ) ) {
			return 'post type: ' . $type;
		}
		if ( '' !== $tax && AAP_Menu::is_protected_taxonomy( $profile, $tax ) ) {
			return 'taxonomy: ' . $tax;
		}

		if ( '' !== $page ) {
			if ( AAP_Plugin::SCRIPTS_PAGE === $page ) {
				return '';
			}
			if ( AAP_Menu::reports_open( $profile ) && AAP_Menu::is_reports_screen( $page, $args ) ) {
				return '';
			}
			if ( AAP_Menu::is_blocked_page( $profile, $page ) ) {
				return 'page: ' . $page;
			}
			if ( 'all' === $profile['menu_mode'] || in_array( $page, AAP_Menu::allowed_page_ids( $profile ), true ) ) {
				return '';
			}
			return 'page not allowed: ' . $page;
		}

		if ( in_array( $file, array( 'index.php', 'profile.php', 'about.php', 'credits.php', 'freedoms.php', 'contribute.php' ), true ) ) {
			return '';
		}
		$rules = AAP_Packs::rules_for( $profile );
		if ( AAP_Util::matches( $file, $rules['files'] ) ) {
			return 'screen: ' . $file;
		}
		$cap_files = AAP_Menu::cap_files();
		if ( isset( $cap_files[ $file ] ) ) {
			return AAP_Menu::profile_has_any_cap( $profile, $cap_files[ $file ] ) ? '' : 'screen: ' . $file;
		}
		if ( 'all' === $profile['menu_mode'] ) {
			return '';
		}

		// Core screens listed in the menu registry must be allowed on the Menu access list.
		$matches = self::registry_matches( $file, $type, $tax );
		if ( ! $matches ) {
			return ''; // Not a menu screen: normal capability checks apply.
		}
		foreach ( $matches as $key => $entry ) {
			if ( AAP_Menu::is_allowed( $profile, $key, $entry['slug'], $entry['parent'] ) ) {
				return '';
			}
		}
		return 'screen not allowed: ' . $file . ( $type ? ' (' . $type . ')' : '' ) . ( $tax ? ' (' . $tax . ')' : '' );
	}

	private static function request_post_type( $file, array $args ) {
		if ( 'post.php' === $file && ! empty( $args['post'] ) && is_scalar( $args['post'] ) ) {
			$post = get_post( (int) $args['post'] );
			return $post ? $post->post_type : '';
		}
		if ( ! empty( $args['post_type'] ) && is_string( $args['post_type'] ) ) {
			return sanitize_key( $args['post_type'] );
		}
		return in_array( $file, array( 'edit.php', 'post-new.php' ), true ) ? 'post' : '';
	}

	private static function request_taxonomy( $file, array $args ) {
		if ( 'term.php' === $file && ! empty( $args['tag_ID'] ) && is_scalar( $args['tag_ID'] ) ) {
			$term = get_term( (int) $args['tag_ID'] );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->taxonomy;
			}
		}
		if ( ! empty( $args['taxonomy'] ) && is_string( $args['taxonomy'] ) ) {
			return sanitize_key( $args['taxonomy'] );
		}
		return in_array( $file, array( 'edit-tags.php', 'term.php' ), true ) ? 'post_tag' : '';
	}

	/** Registry entries that point at the same core screen as the request. */
	private static function registry_matches( $file, $type, $tax ) {
		$map = array(
			'post.php'     => 'edit.php',
			'post-new.php' => 'edit.php',
			'term.php'     => 'edit-tags.php',
		);
		if ( 'attachment' === $type ) {
			$file = 'upload.php';
		} elseif ( isset( $map[ $file ] ) ) {
			$file = $map[ $file ];
		}

		$found = array();
		foreach ( AAP_Menu::registry() as $key => $entry ) {
			if ( '' !== AAP_Util::page_id_from_slug( $entry['slug'] ) ) {
				continue;
			}
			$entry_file = AAP_Util::slug_file( $entry['slug'] );
			if ( 'post-new.php' === $entry_file ) {
				$entry_file = 'edit.php';
			}
			if ( $entry_file !== $file ) {
				continue;
			}
			$entry_args = AAP_Util::slug_args( $entry['slug'] );
			if ( 'edit.php' === $file && ( isset( $entry_args['post_type'] ) ? $entry_args['post_type'] : 'post' ) !== $type ) {
				continue;
			}
			if ( 'edit-tags.php' === $file && ( isset( $entry_args['taxonomy'] ) ? $entry_args['taxonomy'] : 'post_tag' ) !== $tax ) {
				continue;
			}
			$found[ $key ] = $entry;
		}
		return $found;
	}

	/**
	 * options.php is the generic "save settings" endpoint: only accept a save coming
	 * from an allowed screen, and never for locked plugins' option groups.
	 */
	private static function options_block_reason( array $profile ) {
		if ( 'POST' !== strtoupper( AAP_Util::request_value( 'REQUEST_METHOD' ) ) ) {
			return 'screen: options.php';
		}
		$group = isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] ) ? sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) : '';
		$rules = AAP_Packs::rules_for( $profile );
		if ( '' === $group || AAP_Menu::is_blocked_page( $profile, $group ) || AAP_Util::matches( $group, $rules['options'] ) ) {
			return 'option group: ' . $group;
		}

		$core = array(
			'general'    => 'options-general.php',
			'writing'    => 'options-writing.php',
			'reading'    => 'options-reading.php',
			'discussion' => 'options-discussion.php',
			'media'      => 'options-media.php',
			'privacy'    => 'options-privacy.php',
			'permalink'  => 'options-permalink.php',
		);
		if ( isset( $core[ $group ] ) ) {
			return '' === self::screen_block_reason( $profile, $core[ $group ], array() ) ? '' : 'option group: ' . $group;
		}

		$referer = isset( $_POST['_wp_http_referer'] ) && is_string( $_POST['_wp_http_referer'] ) ? wp_unslash( $_POST['_wp_http_referer'] ) : wp_get_referer();
		$file    = $referer ? basename( (string) wp_parse_url( $referer, PHP_URL_PATH ) ) : '';
		if ( '' === $file || 'options.php' === $file ) {
			return 'option group without allowed screen: ' . $group;
		}
		$args = array();
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $args );
		$reason = self::screen_block_reason( $profile, $file, $args );
		return '' === $reason ? '' : 'option group: ' . $group . ' (' . $reason . ')';
	}

	private static function deny( array $profile, $reason, $json = false ) {
		AAP_Activity_Log::log(
			'blocked',
			$reason,
			array(
				'uri'    => esc_url_raw( AAP_Util::request_value( 'REQUEST_URI' ) ),
				'method' => sanitize_key( AAP_Util::request_value( 'REQUEST_METHOD' ) ),
			)
		);
		$message = __( 'Your account does not have access to this. Ask the site owner if you need it.', 'admin-access-profiles' );
		if ( $json ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		wp_die( esc_html( $message ), esc_html__( 'Access restricted', 'admin-access-profiles' ), array( 'response' => 403, 'back_link' => true ) );
	}

	/* ------------------------------------------------------------------
	 * Admin bar and dashboard
	 * ---------------------------------------------------------------- */

	public static function clean_admin_bar( $bar ) {
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return;
		}
		$patterns = AAP_Packs::rules_for( $profile )['ui'];
		if ( ! AAP_Profiles::allows_cap( $profile, 'update_plugins' ) ) {
			$patterns[] = 'updates';
		}
		foreach ( (array) $bar->get_nodes() as $node ) {
			$id = $node->id;
			if ( AAP_Util::matches( $id, $patterns )
				|| ( 0 === strpos( $id, 'new-' ) && AAP_Menu::is_protected_post_type( $profile, substr( $id, 4 ) ) ) ) {
				$bar->remove_node( $id );
			}
		}
	}

	public static function clean_dashboard() {
		$profile = AAP_Profiles::current();
		global $wp_meta_boxes;
		if ( ! $profile || empty( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}
		$patterns = AAP_Packs::rules_for( $profile )['ui'];
		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( AAP_Util::matches( $id, $patterns ) ) {
						unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $id ] );
					}
				}
			}
		}
	}
}
