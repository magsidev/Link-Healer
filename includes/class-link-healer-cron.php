<?php
/**
 * Cron scheduling and batch queue runner for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_Cron {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_Cron
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_Cron
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
		// Filter to register a custom 5-minute interval for cron runs.
		add_filter( 'cron_schedules', array( $this, 'register_cron_intervals' ) );
		// Hook the background processing runner.
		add_action( 'link_healer_cron_tick', array( $this, 'run_background_batch' ) );
	}

	/**
	 * Activate scheduled tasks.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( 'link_healer_cron_tick' ) ) {
			wp_schedule_event( time(), 'link_healer_five_minutes', 'link_healer_cron_tick' );
		}
	}

	/**
	 * Deactivate and clear scheduled tasks.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'link_healer_cron_tick' );
	}

	/**
	 * Register custom cron intervals.
	 *
	 * Adds a "five_minutes" interval to standard WordPress cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function register_cron_intervals( $schedules ) {
		$schedules['link_healer_five_minutes'] = array(
			'interval' => 300,
			'display'  => esc_html__( 'Every 5 Minutes', 'link-healer' ),
		);
		return $schedules;
	}

	/**
	 * Run the background crawling batch under safe locks.
	 */
	public function run_background_batch() {
		$this->process_batch();
	}

	/**
	 * Process a batch of pending source URLs and links.
	 *
	 * Uses transients as lock parameters to avoid overlapping concurrent requests.
	 *
	 * @return int|bool Number of processed source URLs, or false on lock constraint.
	 */
	public function process_batch() {
		// Implement mutual exclusion locks via transient to prevent race conditions.
		if ( get_transient( 'link_healer_batch_lock' ) ) {
			return false;
		}

		// Set lock for 10 minutes.
		set_transient( 'link_healer_batch_lock', 'locked', 10 * MINUTE_IN_SECONDS );

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Configurable batch parameters.
		$source_batch_size = (int) apply_filters( 'link_healer_source_batch_size', 20 );
		$link_batch_size   = (int) apply_filters( 'link_healer_link_test_batch_size', 50 );

		// Query pending source URLs.
		$pending_sources = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $table_sources WHERE status = 'pending' LIMIT %d",
			$source_batch_size
		) );

		$crawler           = Link_Healer_Crawler::get_instance();
		$processed_sources = 0;

		if ( ! empty( $pending_sources ) ) {
			foreach ( $pending_sources as $source_id ) {
				// Process crawl for source URL.
				$crawler->crawl_source( $source_id );
				$processed_sources++;
			}
		}

		// If we did not process many new sources, check/validate unchecked link targets in the DB.
		if ( $processed_sources < $source_batch_size ) {
			$crawler->check_pending_links( $link_batch_size );
		}

		// Release the transient execution lock.
		delete_transient( 'link_healer_batch_lock' );

		return $processed_sources;
	}
}
