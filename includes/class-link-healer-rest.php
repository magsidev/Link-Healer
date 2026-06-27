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
			"SELECT l.id, l.raw_url, l.anchor_text, l.suggested_fix, l.link_type, l.status, l.http_status 
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
