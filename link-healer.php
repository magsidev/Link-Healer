<?php
/**
 * Plugin Name:       Link Healer
 * Plugin URI:        https://github.com/google/link-healer
 * Description:       Finds and automatically fixes broken internal and external links across posts, pages, CPTs, taxonomies, and page builders.
 * Version:           1.0.0
 * Author:            WordPress Core and Database Engineer
 * Author URI:        https://github.com/google/link-healer
 * License:           GPL2
 * Text Domain:       link-healer
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'LINK_HEALER_VERSION', '1.0.0' );
define( 'LINK_HEALER_FILE', __FILE__ );
define( 'LINK_HEALER_PATH', plugin_dir_path( __FILE__ ) );
define( 'LINK_HEALER_URL', plugin_dir_url( __FILE__ ) );
define( 'LINK_HEALER_INC', LINK_HEALER_PATH . 'includes/' );

// Register the PSR-4/WordPress custom autoloader.
spl_autoload_register( function ( $class_name ) {
	// Only load classes starting with Link_Healer_
	if ( strpos( $class_name, 'Link_Healer_' ) !== 0 ) {
		return;
	}

	// Convert Link_Healer_Class_Name to class-link-healer-class-name.php
	$relative_class = substr( $class_name, 12 );
	$file_name      = 'class-link-healer-' . str_replace( '_', '-', strtolower( $relative_class ) ) . '.php';
	$file_path      = LINK_HEALER_INC . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

/**
 * Main plugin class.
 */
class Link_Healer {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Initialize the plugin logic when plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
	}

	/**
	 * Initialize components.
	 */
	public function init_plugin() {
		// Instantiate DB hooks and background cron managers.
		Link_Healer_DB::get_instance();
		Link_Healer_Cron::get_instance();
		Link_Healer_Discovery::get_instance();
		Link_Healer_Crawler::get_instance();
		Link_Healer_REST::get_instance();

		// Initialize admin interface if in admin dashboard.
		if ( is_admin() ) {
			Link_Healer_Admin::get_instance();
		}

		// Add Admin AJAX hooks for manual trigger/status checks.
		add_action( 'wp_ajax_link_healer_trigger_discovery', array( $this, 'ajax_trigger_discovery' ) );
		add_action( 'wp_ajax_link_healer_trigger_crawl', array( $this, 'ajax_trigger_crawl' ) );
		add_action( 'wp_ajax_link_healer_get_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_link_healer_reset_data', array( $this, 'ajax_reset_data' ) );
	}

	/**
	 * Handle manual discovery trigger via secure AJAX request.
	 */
	public function ajax_trigger_discovery() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		// Prevent duplicate discovery tasks
		if ( get_transient( 'link_healer_discovery_lock' ) ) {
			wp_send_json_error( array( 'message' => __( 'Discovery scan is already running. Please wait.', 'link-healer' ) ) );
		}

		set_transient( 'link_healer_discovery_lock', 'locked', 5 * MINUTE_IN_SECONDS );

		$discovery = Link_Healer_Discovery::get_instance();
		$count     = $discovery->discover_all_urls();

		delete_transient( 'link_healer_discovery_lock' );

		$admin_data = Link_Healer_Admin::get_instance()->get_ajax_dashboard_data();

		wp_send_json_success( array(
			'message'    => sprintf( __( 'Discovery completed. Found and indexed %d source URLs.', 'link-healer' ), $count ),
			'count'      => $count,
			'kpis'       => $admin_data['kpis'],
			'table_html' => $admin_data['table_html'],
		) );
	}

	/**
	 * Handle manual execution of next crawl batch.
	 */
	public function ajax_trigger_crawl() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Get counts before running the batch
		$completed_sources_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'completed'" );
		$checked_links_before     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status != 'unchecked'" );

		$cron      = Link_Healer_Cron::get_instance();
		$processed = $cron->process_batch();

		if ( $processed === false ) {
			wp_send_json_error( array( 'message' => __( 'Queue is locked or database error. Please wait.', 'link-healer' ) ) );
		}

		// Get counts after running the batch
		$pending_sources   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'pending'" );
		$completed_sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'completed'" );
		$total_sources     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources" );

		$unchecked_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'unchecked'" );
		$checked_links   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status != 'unchecked'" );
		$total_links     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links" );

		$sources_diff = $completed_sources - $completed_sources_before;
		$links_diff   = $checked_links - $checked_links_before;
		
		$processed_in_batch   = $sources_diff + $links_diff;
		$remaining_pending    = $pending_sources + $unchecked_links;
		
		// Set total pending at start as the current remaining plus what we processed in this batch, if not tracked on frontend
		$total_pending_at_start = $remaining_pending + $processed_in_batch;

		$admin_data = Link_Healer_Admin::get_instance()->get_ajax_dashboard_data();

		wp_send_json_success( array(
			'message'                => sprintf( __( 'Processed batch: %d sources, %d links.', 'link-healer' ), $sources_diff, $links_diff ),
			'processed_in_batch'     => $processed_in_batch,
			'remaining_pending'      => $remaining_pending,
			'total_pending_at_start' => $total_pending_at_start,
			'pending_sources'        => $pending_sources,
			'completed_sources'      => $completed_sources,
			'total_sources'          => $total_sources,
			'unchecked_links'        => $unchecked_links,
			'total_links'            => $total_links,
			'kpis'                   => $admin_data['kpis'],
			'table_html'             => $admin_data['table_html'],
		) );
	}

	/**
	 * Retrieve current scan progress statistics.
	 */
	public function ajax_get_status() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Verify tables exist.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_sources'" ) !== $table_sources ) {
			wp_send_json_error( array( 'message' => __( 'Database tables not installed.', 'link-healer' ) ) );
		}

		$total_sources     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources" );
		$pending_sources   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'pending'" );
		$completed_sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'completed'" );
		$failed_sources    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'failed'" );

		$total_links   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links" );
		$broken_links  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'broken'" );
		$healthy_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'healthy'" );

		wp_send_json_success( array(
			'sources' => array(
				'total'     => $total_sources,
				'pending'   => $pending_sources,
				'completed' => $completed_sources,
				'failed'    => $failed_sources,
			),
			'links'   => array(
				'total'   => $total_links,
				'broken'  => $broken_links,
				'healthy' => $healthy_links,
			),
		) );
	}

	/**
	 * Reset all database tracking tables.
	 */
	public function ajax_reset_data() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		$wpdb->query( "TRUNCATE TABLE $table_sources" );
		$wpdb->query( "TRUNCATE TABLE $table_links" );

		$admin_data = Link_Healer_Admin::get_instance()->get_ajax_dashboard_data();

		wp_send_json_success( array(
			'message'    => __( 'All source URLs and scanned links reset successfully.', 'link-healer' ),
			'kpis'       => $admin_data['kpis'],
			'table_html' => $admin_data['table_html'],
		) );
	}
}

// Hook registration on plugin activation.
register_activation_hook( LINK_HEALER_FILE, array( 'Link_Healer_DB', 'install' ) );
register_activation_hook( LINK_HEALER_FILE, array( 'Link_Healer_Cron', 'activate' ) );

// Hook registration on plugin deactivation.
register_deactivation_hook( LINK_HEALER_FILE, array( 'Link_Healer_Cron', 'deactivate' ) );

// Bootstrap the plugin.
Link_Healer::get_instance();
