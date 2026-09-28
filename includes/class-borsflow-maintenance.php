<?php
/**
 * Daily housekeeping: data retention and recovery of lost retry events.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron daily job.
 */
class BorsFlow_Maintenance {

	/**
	 * Cron callback.
	 */
	public static function run() {
		self::apply_retention();
		self::reschedule_overdue();
	}

	/**
	 * Delete submissions older than the configured number of days, in batches.
	 */
	public static function apply_retention() {
		$days = (int) BorsFlow_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		for ( $i = 0; $i < 20; $i++ ) {
			$ids = BorsFlow_Submissions::ids_older_than( $days, 500 );
			if ( ! $ids ) {
				break;
			}
			BorsFlow_Submissions::delete( $ids );
		}
	}

	/**
	 * Re-queue submissions whose sync event disappeared (e.g. cron cleared on deactivate/reactivate).
	 */
	public static function reschedule_overdue() {
		if ( ! BorsFlow_Crm_Client::is_configured() ) {
			return;
		}
		foreach ( BorsFlow_Submissions::overdue_ids( (int) BorsFlow_Settings::get( 'crm_max_attempts' ) ) as $id ) {
			if ( ! wp_next_scheduled( BorsFlow_Sync::HOOK, array( $id ) ) ) {
				BorsFlow_Sync::schedule( $id, 0 );
			}
		}
	}
}
