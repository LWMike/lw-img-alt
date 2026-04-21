<?php
/**
 * Plugin uninstall — runs when the plugin is deleted from the WordPress admin.
 *
 * Drops the log table unless the admin opted to keep it.
 * Plugin options are always removed.
 *
 * @package LW_Image_Alt
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop the log table unless the site admin chose to keep it.
$keep_log = get_option( 'lwia_uninstall_keep_log', false );

if ( ! $keep_log ) {
	$table = $wpdb->prefix . 'lwia_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

// Remove all plugin options.
foreach ( array( 'lwia_db_version', 'lwia_activated', 'lwia_uninstall_keep_log' ) as $option ) {
	delete_option( $option );
}
