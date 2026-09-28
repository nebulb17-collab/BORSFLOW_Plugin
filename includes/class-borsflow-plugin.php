<?php
/**
 * Main plugin orchestrator.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component to WordPress hooks.
 */
final class BorsFlow_Plugin {

	/**
	 * Custom capability for everything except global settings.
	 */
	const CAP = 'borsflow_manage_forms';

	/**
	 * Singleton instance.
	 *
	 * @var BorsFlow_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once.
	 *
	 * @return BorsFlow_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'borsflow-forms', false, dirname( plugin_basename( BORSFLOW_FILE ) ) . '/languages' );

		BorsFlow_Installer::maybe_upgrade();

		add_action( 'init', array( 'BorsFlow_Post_Type', 'register' ) );
		add_action( 'init', array( 'BorsFlow_Renderer', 'register_assets' ) );
		add_action( 'init', array( 'BorsFlow_Block', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( 'BorsFlow_Renderer', 'maybe_enqueue_early' ) );
		add_shortcode( 'borsflow_form', array( 'BorsFlow_Renderer', 'shortcode' ) );

		add_action( 'rest_api_init', array( 'BorsFlow_Rest', 'register_routes' ) );

		add_action( 'admin_post_borsflow_submit', array( 'BorsFlow_Submission_Handler', 'handle_post_fallback' ) );
		add_action( 'admin_post_nopriv_borsflow_submit', array( 'BorsFlow_Submission_Handler', 'handle_post_fallback' ) );
		add_action( 'admin_post_borsflow_download', array( 'BorsFlow_Uploads', 'handle_download' ) );

		add_action( BorsFlow_Sync::HOOK, array( 'BorsFlow_Sync', 'run' ) );
		add_action( 'borsflow_daily_maintenance', array( 'BorsFlow_Maintenance', 'run' ) );

		BorsFlow_Elementor::init();

		if ( is_admin() ) {
			BorsFlow_Admin::init();
		}
	}

	/**
	 * Whether the current user may manage forms and submissions.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}
}
