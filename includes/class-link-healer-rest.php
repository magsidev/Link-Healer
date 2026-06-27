<?php
/**
 * REST API Endpoints for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_REST {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_REST
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_REST
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
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register Link Healer REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'link-healer/v1',
			'/post-links/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_post_links' ),
				'permission_callback' => array( $this, 'get_post_links_permissions_check' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			'link-healer/v1',
			'/crawl',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_trigger_crawl' ),
				'permission_callback' => array( $this, 'rest_crawl_permissions_check' ),
			)
		);
	}

	/**
	 * Verify permissions for crawl REST trigger.
	 *
	 * @return bool True if authorized, false otherwise.
	 */
	public function rest_crawl_permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Trigger crawling batch via REST API.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response containing the status of the crawl.
	 */
	public function rest_trigger_crawl( $request ) {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Get counts before running the batch
		$completed_sources_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_sources WHERE status = 'completed'" );
		$checked_links_before     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status != 'unchecked'" );

		$cron      = Link_Healer_Cron::get_instance();
		$processed = $cron->process_batch();

		if ( $processed === false ) {
			return new WP_Error( 'link_healer_lock_error', __( 'Queue is locked or database error.', 'link-healer' ), array( 'status' => 500 ) );
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

		return rest_ensure_response( array(
			'success'                => true,
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
	 * Verify if current user is authorized to query links for the specific post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return bool True if authorized, false otherwise.
	 */
	public function get_post_links_permissions_check( $request ) {
		$post_id = (int) $request['id'];
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Fetch broken links for a specific post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response containing the list of links.
	 */
	public function get_post_links( $request ) {
		$post_id = (int) $request['id'];

		global $wpdb;
		$table_links   = $wpdb->prefix . 'link_healer_links';
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Verify tables exist before query.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_sources'" ) !== $table_sources ) {
			return new WP_Error( 'link_healer_db_error', __( 'Database tables not found.', 'link-healer' ), array( 'status' => 500 ) );
		}

		// Retrieve broken links associated with the post's source ID.
		$query = $wpdb->prepare(
			"SELECT l.id, l.target_url, l.anchor_text, l.suggested_fix_url, l.is_internal, l.status, l.http_status 
			 FROM $table_links l 
			 JOIN $table_sources s ON l.source_id = s.id 
			 WHERE s.post_id = %d AND l.status = 'broken'
			 ORDER BY l.id ASC",
			$post_id
		);

		$broken_links = $wpdb->get_results( $query );

		return rest_ensure_response( $broken_links );
	}
}
