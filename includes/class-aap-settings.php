<?php
/**
 * Site-wide settings and the "owner" check (who may manage this plugin).
 */

defined( 'ABSPATH' ) || exit;

class AAP_Settings {

	const OPTION = 'aap_settings';

	private static $settings = null;

	public static function defaults() {
		return array(
			'owner_ids'           => array(),
			'scripts_skip_admins' => 1,
			'log_retention'       => 90,
			'log_views'           => 1,
		);
	}

	public static function get() {
		if ( null === self::$settings ) {
			$saved          = get_option( self::OPTION, array() );
			self::$settings = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$settings;
	}

	public static function update( array $settings ) {
		update_option( self::OPTION, array_merge( self::defaults(), $settings ) );
		self::$settings = null;
	}

	/**
	 * Owners manage profiles: administrators without an access profile,
	 * optionally limited to the user IDs set on the Settings tab.
	 */
	public static function is_owner( $user = null ) {
		if ( null === $user ) {
			if ( ! function_exists( 'wp_get_current_user' ) ) {
				return false;
			}
			$user = wp_get_current_user();
		}
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return false;
		}
		if ( ! in_array( 'administrator', (array) $user->roles, true ) || AAP_Profiles::for_user( $user ) ) {
			return false;
		}
		$ids = self::get()['owner_ids'];
		return empty( $ids ) || in_array( (int) $user->ID, array_map( 'intval', $ids ), true );
	}
}
