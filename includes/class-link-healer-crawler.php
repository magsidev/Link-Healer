<?php
/**
 * HTML Crawler and Link Validator for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_Crawler {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_Crawler
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_Crawler
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
	 * Crawl a single source URL, extract links, save them, and clean up orphaned links.
	 *
	 * @param int $source_id DB ID from wp_link_healer_sources.
	 * @return bool True on success, false on failure.
	 */
	public function crawl_source( $source_id ) {
		global $wpdb;
		$db            = Link_Healer_DB::get_instance();
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Fetch source URL.
		$source = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_sources WHERE id = %d",
			(int) $source_id
		) );

		if ( ! $source ) {
			return false;
		}

		$db->update_source_status( $source_id, 'scanning' );

		// Fetch the URL content via HTTP request.
		$response = wp_remote_get( $source->source_url, array(
			'timeout'    => 20,
			'user-agent' => 'LinkHealerBot/1.0; ' . home_url(),
			'sslverify'  => false, // Avoid SSL verification errors on local staging environments.
		) );

		if ( is_wp_error( $response ) ) {
			$db->update_source_status( $source_id, 'failed' );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			$db->update_source_status( $source_id, 'failed' );
			return false;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			$db->update_source_status( $source_id, 'completed' );
			$db->purge_removed_links( $source_id, array() );
			return true;
		}

		// Extract all valid hyperlinks.
		$extracted_links = $this->parse_links_from_html( $html, $source->source_url );

		$current_link_hashes = array();

		foreach ( $extracted_links as $link ) {
			$raw_url     = $link['url'];
			$anchor_text = $link['anchor'];
			$link_type   = $this->classify_link_type( $raw_url );

			$link_id = $db->save_link( $source_id, $raw_url, $link_type, $anchor_text );
			if ( $link_id ) {
				$current_link_hashes[] = md5( esc_url_raw( trim( $raw_url ) ) );
			}
		}

		// Garbage Collection: Purge links that are no longer present on this page.
		$db->purge_removed_links( $source_id, $current_link_hashes );

		$db->update_source_status( $source_id, 'completed' );
		return true;
	}

	/**
	 * Extract link URLs and anchor text from HTML string.
	 *
	 * @param string $html Content of the page.
	 * @param string $base_url URL of the page (to resolve relative links).
	 * @return array Multi-dimensional array of links.
	 */
	public function parse_links_from_html( $html, $base_url ) {
		$links     = array();
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			// Suppress parsing errors for invalid HTML5 markup.
			libxml_use_internal_errors( true );
			// Load with UTF-8 encoding support.
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();

			$tags = $dom->getElementsByTagName( 'a' );
			foreach ( $tags as $tag ) {
				$href   = $tag->getAttribute( 'href' );
				$anchor = $tag->nodeValue;

				if ( $this->is_valid_href( $href ) ) {
					$resolved_url = $this->resolve_relative_url( $href, $base_url );
					if ( $resolved_url ) {
						$links[] = array(
							'url'    => $resolved_url,
							'anchor' => trim( $anchor ),
						);
					}
				}
			}
		} else {
			// Fallback regex parsing if DOMDocument is not available.
			preg_match_all( '/<a\s+[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$href   = $match[1];
				$anchor = wp_strip_all_tags( $match[2] );

				if ( $this->is_valid_href( $href ) ) {
					$resolved_url = $this->resolve_relative_url( $href, $base_url );
					if ( $resolved_url ) {
						$links[] = array(
							'url'    => $resolved_url,
							'anchor' => trim( $anchor ),
						);
					}
				}
			}
		}

		return $links;
	}

	/**
	 * Verify check-pending links in the database.
	 *
	 * @param int $limit Max number of links to check in this run.
	 * @return int Total checked links.
	 */
	public function check_pending_links( $limit = 50 ) {
		global $wpdb;
		$db          = Link_Healer_DB::get_instance();
		$table_links = $wpdb->prefix . 'link_healer_links';

		// Get unchecked links, prioritizing those that have not been checked longest or ever.
		$links = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_links WHERE status = 'unchecked' ORDER BY last_checked ASC LIMIT %d",
			(int) $limit
		) );

		if ( empty( $links ) ) {
			return 0;
		}

		$checked = 0;

		foreach ( $links as $link ) {
			$status_code = $this->ping_url( $link->raw_url );

			$status        = 'healthy';
			$suggested_fix = '';

			if ( 403 === $status_code || 503 === $status_code ) {
				$status = 'blocked';
			} elseif ( $status_code === 0 || $status_code >= 400 ) {
				$status = 'broken';
				if ( 404 === $status_code ) {
					$suggested_fix = Link_Healer_Matcher::get_instance()->get_best_match( $link->raw_url );
				}
			} elseif ( $status_code >= 200 && $status_code < 400 ) {
				$status = 'healthy';
			}

			$db->update_link_status( $link->id, $status_code, $status, $suggested_fix );
			$checked++;
		}

		return $checked;
	}

	/**
	 * Ping a URL to verify its HTTP response status.
	 *
	 * @param string $url Target URL to check.
	 * @return int HTTP Status Code, or 0 on error.
	 */
	private function ping_url( $url ) {
		$args = array(
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'timeout'     => 15,
			'redirection' => 5,
			'sslverify'   => false,
		);

		// First try a HEAD request (as it's faster and lower bandwidth, using our spoofed UA)
		$head_args           = $args;
		$head_args['method'] = 'HEAD';

		$response = wp_remote_request( $url, $head_args );
		$code     = wp_remote_retrieve_response_code( $response );

		// Fallback to GET request if HEAD fails or is blocked
		if ( is_wp_error( $response ) || $code === 405 || $code === 403 || $code === 501 ) {
			// Add Range header to keep payload lightweight (only download first KB)
			$get_args            = $args;
			$get_args['headers'] = array( 'Range' => 'bytes=0-1024' );
			$response            = wp_remote_get( $url, $get_args );
			$code                = wp_remote_retrieve_response_code( $response );
		}

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		return (int) $code;
	}

	/**
	 * Determine if the URL href is a standard crawlable page link.
	 *
	 * Filters out mailto, tel, JS handlers, anchor hops, and empty values.
	 *
	 * @param string $href Link href value.
	 * @return bool True if valid crawl target.
	 */
	private function is_valid_href( $href ) {
		$href = trim( $href );
		if ( empty( $href ) ) {
			return false;
		}

		// Filter out JavaScript, anchors, phone numbers, and mail links.
		if ( preg_match( '/^(javascript:|#|mailto:|tel:|sms:|whatsapp:|skype:|callto:)/i', $href ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Classify URL as internal or external.
	 *
	 * @param string $url Link target.
	 * @return string 'internal' or 'external'.
	 */
	private function classify_link_type( $url ) {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $target_host || strcasecmp( $site_host, $target_host ) === 0 ) {
			return 'internal';
		}

		return 'external';
	}

	/**
	 * Resolve relative paths and root-relative paths to absolute URLs.
	 *
	 * @param string $url Link href target.
	 * @param string $base_url Parent Page URL.
	 * @return string Absolute URL.
	 */
	private function resolve_relative_url( $url, $base_url ) {
		// If already absolute URL.
		if ( preg_match( '/^https?:\/\//i', $url ) ) {
			return $url;
		}

		// Protocol-relative URL.
		if ( strpos( $url, '//' ) === 0 ) {
			$protocol = wp_parse_url( $base_url, PHP_URL_SCHEME );
			return $protocol ? $protocol . ':' . $url : 'http:' . $url;
		}

		$base_parts = wp_parse_url( $base_url );
		$host_base  = $base_parts['scheme'] . '://' . $base_parts['host'];
		if ( isset( $base_parts['port'] ) ) {
			$host_base .= ':' . $base_parts['port'];
		}

		// Root-relative path.
		if ( strpos( $url, '/' ) === 0 ) {
			return $host_base . $url;
		}

		// Relative path or query query string.
		if ( strpos( $url, '?' ) === 0 || strpos( $url, '#' ) === 0 ) {
			$path = isset( $base_parts['path'] ) ? $base_parts['path'] : '';
			return $host_base . $path . $url;
		}

		// Simple relative file path resolution.
		$path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$dir  = dirname( $path );
		if ( $dir === '.' || $dir === '\\' ) {
			$dir = '/';
		} else {
			$dir = rtrim( $dir, '/' ) . '/';
		}

		return $host_base . $dir . $url;
	}

	/**
	 * Find an internal published post slug fallback for a 404 URL.
	 *
	 * @param string $url The broken internal URL.
	 * @return string Suggested correct URL, or empty string.
	 */
	private function find_internal_fallback_suggestion( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}

		$slug = basename( $path );
		if ( empty( $slug ) ) {
			return '';
		}

		// Try matching slug in database.
		global $wpdb;
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish' LIMIT 1",
			sanitize_title( $slug )
		) );

		if ( $post_id ) {
			return get_permalink( $post_id );
		}

		return '';
	}
}
