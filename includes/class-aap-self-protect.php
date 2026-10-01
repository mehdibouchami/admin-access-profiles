<?php
/**
 * Keeps profile users away from this plugin, protected plugins and critical options.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Self_Protect {

	public static function init() {
		add_filter( 'all_plugins', array( __CLASS__, 'hide_plugins' ), 9999 );
		add_filter( 'plugin_action_links', array( __CLASS__, 'strip_actions' ), 9999, 2 );
		add_filter( 'pre_update_option', array( __CLASS__, 'guard_option' ), 9999, 3 );
	}

	public static function hide_plugins( $plugins ) {
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return $plugins;
		}
		$hidden = AAP_Packs::rules_for( $profile )['hidden'];
		foreach ( array_keys( $plugins ) as $file ) {
			if ( AAP_Util::matches( AAP_Util::plugin_folder( $file ), $hidden ) ) {
				unset( $plugins[ $file ] );
			}
		}
		return $plugins;
	}

	public static function strip_actions( $actions, $file ) {
		$profile = AAP_Profiles::current();
		if ( $profile && AAP_Util::matches( AAP_Util::plugin_folder( $file ), AAP_Packs::rules_for( $profile )['plugins'] ) ) {
			unset( $actions['deactivate'], $actions['delete'], $actions['activate'], $actions['edit'] );
		}
		return $actions;
	}

	public static function guard_option( $value, $option, $old_value ) {
		if ( AAP_Profiles::$syncing || AAP_Scripts::$saving || $value === $old_value ) {
			return $value;
		}
		$profile = AAP_Profiles::current();
		if ( ! $profile ) {
			return $value;
		}
		$patterns = AAP_Packs::rules_for( $profile )['options'];
		if ( ! AAP_Profiles::allows_cap( $profile, 'activate_plugins' ) ) {
			$patterns[] = 'active_plugins';
		}
		if ( ! AAP_Profiles::allows_cap( $profile, 'switch_themes' ) ) {
			$patterns[] = 'template';
			$patterns[] = 'stylesheet';
		}
		if ( AAP_Util::matches( $option, $patterns ) ) {
			AAP_Activity_Log::log( 'blocked', 'option: ' . $option );
			return $old_value;
		}
		return $value;
	}
}
