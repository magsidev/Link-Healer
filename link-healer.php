<?php
/**
 * Plugin Name:       Link Healer
 * Description:       Automatically finds, validates, and heals broken links on page and Gutenberg canvas.
 * Version:           1.0.0
 * Author:            Antigravity AI
 * Text Domain:       link-healer
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core plugin constants.
define( 'LINK_HEALER_VERSION', '1.0.0' );
define( 'LINK_HEALER_FILE', __FILE__ );
define( 'LINK_HEALER_PATH', plugin_dir_path( __FILE__ ) );
define( 'LINK_HEALER_URL', plugin_dir_url( __FILE__ ) );

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
		$this->autoload_classes();
		add_action( 'init', array( $this, 'init_plugin' ) );
	}

	/**
	 * Simple PSR-4 Autoloader implementation matching file prefix convention.
	 */
	private function autoload_classes() {
		spl_autoload_register( function ( $class_name ) {
			// Only autoload Link Healer classes
			if ( strpos( $class_name, 'Link_Healer_' ) !== 0 ) {
				return;
			}

			// Convert CamelCase/Underscores to lowercase hyphens matching prefix
			$file_part = strtolower( str_replace( '_', '-', substr( $class_name, 12 ) ) );
			$file_path = LINK_HEALER_PATH . 'includes/class-link-healer-' . $file_part . '.php';

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		} );
	}

	/**
	 * Initialize components and AJAX hooks.
	 */
	public function init_plugin() {
		// Initialize databases
		Link_Healer_DB::get_instance();

		// Trigger background systems
		Link_Healer_Cron::get_instance();
		Link_Healer_Discovery::get_instance();
		Link_Healer_Crawler::get_instance();
		Link_Healer_REST::get_instance();

		// Initialize admin interface if in admin dashboard.
		if ( is_admin() ) {
			Link_Healer_Admin::get_instance();
		}

		// Add Admin AJAX hooks for manual trigger/status checks (lh_ prefixed)
		add_action( 'wp_ajax_lh_discover_urls', array( $this, 'ajax_trigger_discovery' ) );
		add_action( 'wp_ajax_lh_crawl_batch', array( $this, 'ajax_trigger_crawl' ) );
		add_action( 'wp_ajax_lh_get_crawl_stats', array( $this, 'ajax_get_crawl_stats' ) );
		add_action( 'wp_ajax_lh_reset_database', array( $this, 'ajax_reset_data' ) );
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

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		$pending_sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'pending'" );
		$unchecked_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'unchecked'" );
		$remaining       = $pending_sources + $unchecked_links;

		wp_send_json_success( array(
			'total_sources'     => $count,
			'remaining_pending' => $remaining,
			'message'           => sprintf( __( 'Discovery completed. Found and indexed %d source URLs.', 'link-healer' ), $count ),
		) );
	}

	/**
	 * Retrieve remaining crawl statistics for queue initialization.
	 */
	public function ajax_get_crawl_stats() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		$pending_sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'pending'" );
		$unchecked_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'unchecked'" );
		
		wp_send_json_success( array(
			'total_pending' => $pending_sources + $unchecked_links,
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

		$unchecked_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'unchecked'" );
		$checked_links   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status != 'unchecked'" );

		$sources_diff = $completed_sources - $completed_sources_before;
		$links_diff   = $checked_links - $checked_links_before;
		
		$processed_in_batch = $sources_diff + $links_diff;
		$remaining_pending  = $pending_sources + $unchecked_links;
		
		$admin_data = Link_Healer_Admin::get_instance()->get_ajax_dashboard_data();

		wp_send_json_success( array(
			'processed_in_batch' => $processed_in_batch,
			'remaining_pending'  => $remaining_pending,
			'metrics'            => array(
				'total_scanned'   => $admin_data['kpis']['scanned'],
				'broken_internal' => $admin_data['kpis']['broken_int'],
				'broken_external' => $admin_data['kpis']['broken_ext'],
				'total_healed'    => $admin_data['kpis']['healed'],
			)
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

		wp_send_json_success( array( 'message' => __( 'All source URLs and scanned links reset successfully.', 'link-healer' ) ) );
	}
}

// Hook registration on plugin activation.
register_activation_hook( LINK_HEALER_FILE, array( 'Link_Healer_DB', 'install' ) );
register_activation_hook( LINK_HEALER_FILE, array( 'Link_Healer_Cron', 'activate' ) );

// Hook registration on plugin deactivation.
register_deactivation_hook( LINK_HEALER_FILE, array( 'Link_Healer_Cron', 'deactivate' ) );

// Bootstrap the plugin.
Link_Healer::get_instance();
