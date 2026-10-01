<?php
/**
 * REST API enforcement for profile users (the block editor, WooCommerce Analytics and many
 * plugin screens talk to wp-json, so wp-admin blocking alone is not enough).
 */

defined( 'ABSPATH' ) || exit;

class AAP_Rest_Guard {

	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'dispatch' ), 9999, 3 );
	}

	public static function dispatch( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return $result;
		}
		$route  = '/' . trim( (string) $request->get_route(), '/' );
		$method = strtoupper( $request->get_method() );
		if ( ! self::is_blocked( $profile, $route, $method, $request ) ) {
			return $result;
		}
		AAP_Activity_Log::log( 'blocked', 'rest: ' . $route, array( 'method' => $method ) );
		return new WP_Error(
			'aap_forbidden',
			__( 'Your account does not have access to this. Ask the site owner if you need it.', 'admin-access-profiles' ),
			array( 'status' => 403 )
		);
	}

	private static function is_blocked( array $profile, $route, $method, $request ) {
		$rules = AAP_Packs::rules_for( $profile );

		// WooCommerce Store API powers the front-end cart and checkout; it exposes no admin data.
		if ( preg_match( '#^/wc/store(/|$)#', $route ) ) {
			return false;
		}
		// Read-only analytics when the "Sales reports" switch is on: aggregates only.
		if ( preg_match( '#^/(wc-analytics|wc-admin)(/|$)#', $route ) && AAP_Menu::reports_open( $profile ) && 'GET' === $method ) {
			$private = '#/(customers|coupons|stock|downloads|leaderboards|export)|/reports/orders(?!/stats)|^/wc-analytics/orders#';
			return (bool) preg_match( $private, $route );
		}

		// Users: own account always; the author list for the block editor when the user can edit posts.
		if ( preg_match( '#^/wp/v2/users(/(.*))?$#', $route, $m ) ) {
			$rest = isset( $m[2] ) ? $m[2] : '';
			if ( 'me' === $rest || 0 === strpos( $rest, 'me/' ) ) {
				return false;
			}
			$author_list = '' === $rest && 'GET' === $method && ( $request->get_param( 'who' ) || $request->get_param( 'capabilities' ) );
			return ! ( $author_list && AAP_Profiles::allows_cap( $profile, 'edit_posts' ) );
		}

		if ( preg_match( '#^/wp/v2/(plugins|block-directory)#', $route ) && ! AAP_Profiles::allows_cap( $profile, 'activate_plugins' ) ) {
			return true;
		}

		if ( AAP_Util::matches_regex( $route, $rules['rest'] ) ) {
			return true;
		}

		foreach ( self::protected_rest_bases( $profile ) as $base ) {
			if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(/|$)#', $route ) ) {
				return true;
			}
		}
		return false;
	}

	/** REST routes of protected post types and taxonomies, discovered at runtime. */
	private static function protected_rest_bases( array $profile ) {
		$bases = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( AAP_Menu::is_protected_post_type( $profile, $type->name ) ) {
				$bases[] = self::rest_path( $type );
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
			if ( AAP_Menu::is_protected_taxonomy( $profile, $tax->name ) ) {
				$bases[] = self::rest_path( $tax );
			}
		}
		return array_unique( $bases );
	}

	private static function rest_path( $object ) {
		$namespace = ! empty( $object->rest_namespace ) ? $object->rest_namespace : 'wp/v2';
		$base      = ! empty( $object->rest_base ) ? $object->rest_base : $object->name;
		return '/' . trim( $namespace, '/' ) . '/' . $base;
	}
}
