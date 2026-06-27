<?php
/**
 * Universal content discovery engine for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_Discovery {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_Discovery
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_Discovery
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
	private function __construct() {}

	/**
	 * Discover all public URLs on the WordPress site and index them as sources.
	 *
	 * This queries public posts, pages, CPTs (like WooCommerce products),
	 * taxonomy archives (categories/tags), and the homepage.
	 *
	 * @return int Total discovered source URLs count.
	 */
	public function discover_all_urls() {
		$db = Link_Healer_DB::get_instance();
		$count = 0;

		// 1. Homepage.
		$homepage_url = home_url( '/' );
		$db->add_source( 0, 'homepage', $homepage_url );
		$count++;

		// 2. Discover Public Post Types (including posts, pages, CPTs, products, templates if public).
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		// Expose custom hook to filter target post types for audit.
		$post_types = apply_filters( 'link_healer_audit_post_types', $post_types );

		if ( ! empty( $post_types ) ) {
			$batch_size = 500;
			$paged      = 1;

			do {
				// Query in lightweight ID-only batches to prevent memory leaks on huge databases.
				$query_args = array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => $batch_size,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'no_found_rows'          => true, // Optimizes query performance.
				);

				$posts = get_posts( $query_args );

				if ( empty( $posts ) ) {
					break;
				}

				foreach ( $posts as $post_id ) {
					$url = get_permalink( $post_id );
					if ( $url ) {
						$type = get_post_type( $post_id );
						$db->add_source( $post_id, 'post_' . $type, $url );
						$count++;
					}
				}

				$paged++;
				// Flush object cache between batches to avoid memory bloat.
				if ( function_exists( 'wp_cache_flush_group' ) ) {
					wp_cache_flush_group( 'posts' );
				}
			} while ( count( $posts ) === $batch_size );
		}

		// 3. Discover Public Taxonomies (Categories, Tags, WooCommerce Product Categories, etc.).
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$taxonomies = apply_filters( 'link_healer_audit_taxonomies', $taxonomies );

		if ( ! empty( $taxonomies ) ) {
			// Query only terms attached to posts/objects.
			$terms = get_terms( array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => 5000, // Safe limit for typical sites.
			) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$url = get_term_link( $term );
					if ( ! is_wp_error( $url ) && $url ) {
						$db->add_source( 0, 'term_' . $term->taxonomy, $url );
						$count++;
					}
				}
			}
		}

		// Custom filter hook to append additional paths or integration URLs.
		$extra_urls = apply_filters( 'link_healer_extra_audit_urls', array() );
		if ( is_array( $extra_urls ) && ! empty( $extra_urls ) ) {
			foreach ( $extra_urls as $url ) {
				$db->add_source( 0, 'custom_url', $url );
				$count++;
			}
		}

		return $count;
	}
}
