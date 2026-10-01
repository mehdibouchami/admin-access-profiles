<?php
/**
 * Removes everything the plugin stored. Users of access profiles become Subscribers.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array_keys( (array) get_option( 'aap_profiles', array() ) ) as $id ) {
	$role = 'aap_' . $id;
	foreach ( get_users( array( 'role' => $role ) ) as $user ) {
		$user->remove_role( $role );
		if ( ! $user->roles ) {
			$user->set_role( 'subscriber' );
		}
	}
	remove_role( $role );
}

foreach ( array( 'aap_profiles', 'aap_settings', 'aap_menu_registry', 'aap_scripts', 'aap_scripts_history', 'aap_db_version' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'aap_hourly' );
wp_clear_scheduled_hook( 'aap_daily' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aap_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
