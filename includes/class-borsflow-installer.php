<?php
/**
 * Activation, deactivation and version-aware migrations.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin schema.
 */
class BorsFlow_Installer {

	/**
	 * Versioned migration callbacks, run in order when upgrading past each version.
	 * dbDelta() in create_tables() handles additive column/index changes; put
	 * data transforms (renames, backfills) here.
	 *
	 * @var array<string,string>
	 */
	private static $migrations = array(
		'1.0.0' => 'migrate_1_0_0',
		'1.1.0' => 'migrate_1_1_0',
	);

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation on multisite.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Install for the current site.
	 */
	public static function install() {
		self::create_tables();
		add_option( BorsFlow_Settings::OPTION, BorsFlow_Settings::defaults() );
		self::add_caps();
		BorsFlow_Post_Type::register();
		BorsFlow_Uploads::ensure_protected_dir();
		self::schedule_events();
		update_option( 'borsflow_db_version', BORSFLOW_DB_VERSION );
	}

	/**
	 * Deactivation hook: clear scheduled events.
	 */
	public static function deactivate() {
		wp_unschedule_hook( BorsFlow_Sync::HOOK );
		wp_unschedule_hook( BorsFlow_Mailer::HOOK );
		wp_unschedule_hook( 'borsflow_daily_maintenance' );
	}

	/**
	 * Run migrations when the stored DB version is behind the code.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'borsflow_db_version', '0' );
		if ( version_compare( $installed, BORSFLOW_DB_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		foreach ( self::$migrations as $version => $method ) {
			if ( version_compare( $installed, $version, '<' ) ) {
				call_user_func( array( __CLASS__, $method ) );
			}
		}
		self::add_caps();
		self::schedule_events();
		update_option( 'borsflow_db_version', BORSFLOW_DB_VERSION );
	}

	/**
	 * Initial release: nothing to transform, just make sure options exist.
	 */
	private static function migrate_1_0_0() {
		$current = get_option( BorsFlow_Settings::OPTION, array() );
		update_option( BorsFlow_Settings::OPTION, wp_parse_args( is_array( $current ) ? $current : array(), BorsFlow_Settings::defaults() ) );
	}

	/**
	 * 1.1.0 moved emails to WP-Cron and added `emails_sent`. Rows that existed
	 * before were emailed synchronously, so mark them sent to avoid re-sending.
	 */
	private static function migrate_1_1_0() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . BorsFlow_Submissions::table() . ' SET emails_sent = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$submissions = BorsFlow_Submissions::table();
		$log         = BorsFlow_Submissions::log_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$submissions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  referrer text NULL,
  page_url text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  is_read tinyint(1) unsigned NOT NULL DEFAULT 0,
  emails_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
  sync_status varchar(20) NOT NULL DEFAULT 'pending',
  sync_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  crm_lead_id varchar(191) NOT NULL DEFAULT '',
  synced_at datetime NULL DEFAULT NULL,
  next_retry_at datetime NULL DEFAULT NULL,
  locked_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY form_id (form_id),
  KEY sync_status (sync_status),
  KEY created_at (created_at),
  KEY is_read (is_read)
) {$charset};

CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempt smallint(5) unsigned NOT NULL DEFAULT 0,
  status_code smallint(5) unsigned NOT NULL DEFAULT 0,
  success tinyint(1) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  trigger_source varchar(20) NOT NULL DEFAULT 'cron',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY submission_id (submission_id),
  KEY created_at (created_at)
) {$charset};"
		);
	}

	/**
	 * Grant the custom capability to administrators.
	 */
	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( BorsFlow_Plugin::CAP ) ) {
			$role->add_cap( BorsFlow_Plugin::CAP );
		}
	}

	/**
	 * Remove the custom capability from every role.
	 */
	public static function remove_caps() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( BorsFlow_Plugin::CAP ) ) {
				$role->remove_cap( BorsFlow_Plugin::CAP );
			}
		}
	}

	/**
	 * Ensure the recurring maintenance event exists.
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'borsflow_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'borsflow_daily_maintenance' );
		}
	}
}
