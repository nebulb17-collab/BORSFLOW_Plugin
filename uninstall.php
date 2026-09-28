<?php
/**
 * Uninstall BorsFlow Forms.
 *
 * Always removes options, capabilities, transients and scheduled events.
 * Forms, submissions, the sync log and uploaded files are only deleted when
 * "Delete all data on uninstall" was enabled in Settings.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Uninstall one site.
 */
function borsflow_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'borsflow_settings', array() );
	$wipe     = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );

	wp_unschedule_hook( 'borsflow_sync_submission' );
	wp_unschedule_hook( 'borsflow_send_notifications' );
	wp_unschedule_hook( 'borsflow_daily_maintenance' );

	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'borsflow_manage_forms' );
	}

	if ( $wipe ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping our own tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}borsflow_submissions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}borsflow_sync_log" );
		// phpcs:enable

		$form_ids = get_posts(
			array(
				'post_type'      => 'borsflow_form',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $form_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$uploads = wp_upload_dir( null, false );
		$dir     = apply_filters( 'borsflow_upload_dir', $uploads['basedir'] . '/borsflow-private' );
		borsflow_uninstall_rmdir( $dir );
	}

	delete_option( 'borsflow_settings' );
	delete_option( 'borsflow_db_version' );
	delete_transient( 'borsflow_unread_count' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_borsflow\\_%' OR option_name LIKE '\\_transient\\_timeout\\_borsflow\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Recursively delete the private uploads directory.
 *
 * @param string $dir Directory.
 */
function borsflow_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) || false === strpos( $dir, 'borsflow' ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $borsflow_site_id ) {
		switch_to_blog( $borsflow_site_id );
		borsflow_uninstall_site();
		restore_current_blog();
	}
} else {
	borsflow_uninstall_site();
}
