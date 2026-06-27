<?php
/**
 * Auto-Match Suggestion Engine for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_Matcher {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_Matcher
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_Matcher
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
	 * Primary match resolution method.
	 *
	 * Executes a 3-Tier fallback matching strategy to find the best suggested URL.
	 *
	 * @param string $broken_url The broken/404 URL.
	 * @return string Best match suggestion, or empty string if none found.
	 */
	public function get_best_match( $broken_url ) {
		// Only attempt matching for internal URLs.
		if ( ! $this->is_internal_url( $broken_url ) ) {
			return '';
		}

		// TIER 1: Slug Parsing (Exact Slug Match in DB posts/taxonomies)
		$tier1_match = $this->tier1_slug_matching( $broken_url );
		if ( ! empty( $tier1_match ) ) {
			return $tier1_match;
		}

		// TIER 2: Fuzzy String Matching (Levenshtein & similar_text percentage)
		$fuzzy_results = $this->tier2_fuzzy_matching( $broken_url );
		if ( ! empty( $fuzzy_results['best_match'] ) && $fuzzy_results['best_score'] > 85 ) {
			return $fuzzy_results['best_match'];
		}

		// TIER 3: LLM API Fallback (OpenAI Chat Completion)
		$tier3_match = $this->tier3_llm_fallback( $broken_url, $fuzzy_results['all_candidates'] );
		if ( ! empty( $tier3_match ) ) {
			return $tier3_match;
		}

		// Fallback: If Tier 3 fails or is not configured, return the best fuzzy candidate if available.
		if ( ! empty( $fuzzy_results['best_match'] ) ) {
			return $fuzzy_results['best_match'];
		}

		return '';
	}

	/**
	 * Verify if a given URL is internal to the site.
	 *
	 * @param string $url The target URL.
	 * @return bool True if internal, false otherwise.
	 */
	private function is_internal_url( $url ) {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $url, PHP_URL_HOST );

		// If no host is present, it's relative/root-relative (hence internal).
		if ( ! $target_host ) {
			return true;
		}

		return strcasecmp( $site_host, $target_host ) === 0;
	}

	/**
	 * Extract the path portion of a URL.
	 *
	 * @param string $url The target URL.
	 * @return string The path, or empty string.
	 */
	private function get_url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return $path ? $path : '';
	}

	/**
	 * Tier 1 Matching: Matches the terminal slug exactly against posts and terms.
	 *
	 * @param string $broken_url Broken URL.
	 * @return string Corrected URL, or empty string.
	 */
	private function tier1_slug_matching( $broken_url ) {
		$path = $this->get_url_path( $broken_url );
		if ( empty( $path ) ) {
			return '';
		}

		// Explode path segments and isolate the terminal segment.
		$segments = array_filter( explode( '/', trim( $path, '/' ) ) );
		if ( empty( $segments ) ) {
			return '';
		}

		$slug       = end( $segments );
		$clean_slug = sanitize_title( $slug );
		if ( empty( $clean_slug ) ) {
			return '';
		}

		global $wpdb;

		// 1. Search in published posts, pages, and custom post types (CPTs).
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish' LIMIT 1",
				$clean_slug
			)
		);

		if ( $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				return $permalink;
			}
		}

		// 2. Search in public taxonomy archives (Categories, Tags, CPT taxonomies).
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( ! empty( $taxonomies ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => array_values( $taxonomies ),
					'slug'       => $clean_slug,
					'hide_empty' => false,
					'number'     => 1,
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$term_link = get_term_link( $terms[0] );
				if ( ! is_wp_error( $term_link ) && $term_link ) {
					return $term_link;
				}
			}
		}

		return '';
	}

	/**
	 * Tier 2 Matching: Compares paths using Levenshtein distance and similar_text percent.
	 *
	 * @param string $broken_url Broken URL.
	 * @return array Multi-dimensional array with the best match and all scored candidate URLs.
	 */
	private function tier2_fuzzy_matching( $broken_url ) {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Retrieve all active, completed source URLs.
		$live_urls = $wpdb->get_col( "SELECT source_url FROM $table_sources WHERE status = 'completed'" );

		if ( empty( $live_urls ) ) {
			return array(
				'best_match'     => '',
				'best_score'     => 0,
				'all_candidates' => array(),
			);
		}

		$broken_path = strtolower( trim( rawurldecode( $this->get_url_path( $broken_url ) ), '/' ) );

		$candidates      = array();
		$all_live_scores = array();

		foreach ( $live_urls as $live_url ) {
			$live_path = strtolower( trim( rawurldecode( $this->get_url_path( $live_url ) ), '/' ) );

			// Skip homepage or root-only paths if we are matching a multi-segment broken path.
			if ( empty( $live_path ) && ! empty( $broken_path ) ) {
				continue;
			}

			// Calculate similarity percentage.
			similar_text( $broken_path, $live_path, $percent );

			// Calculate Levenshtein distance (ensuring we don't exceed PHP's 255-char limit).
			$b_len = strlen( $broken_path );
			$l_len = strlen( $live_path );
			if ( $b_len < 255 && $l_len < 255 ) {
				$lev = levenshtein( $broken_path, $live_path );
			} else {
				$lev = levenshtein( substr( $broken_path, 0, 254 ), substr( $live_path, 0, 254 ) );
			}

			$score_info = array(
				'url'     => $live_url,
				'percent' => $percent,
				'lev'     => $lev,
			);

			$all_live_scores[] = $score_info;

			// Store high-confidence options: Levenshtein distance <= 3 OR similar_text > 85%.
			if ( $lev <= 3 || $percent > 85 ) {
				$candidates[] = $score_info;
			}
		}

		// Sort high-confidence candidates by percentage descending, then Levenshtein distance ascending.
		if ( ! empty( $candidates ) ) {
			usort(
				$candidates,
				function ( $a, $b ) {
					if ( $a['percent'] === $b['percent'] ) {
						return $a['lev'] - $b['lev'];
					}
					return ( $b['percent'] > $a['percent'] ) ? 1 : -1;
				}
			);
		}

		// Sort all live scores by percentage descending for context chunking in Tier 3.
		usort(
			$all_live_scores,
			function ( $a, $b ) {
				if ( $a['percent'] === $b['percent'] ) {
					return $a['lev'] - $b['lev'];
				}
				return ( $b['percent'] > $a['percent'] ) ? 1 : -1;
			}
		);

		return array(
			'best_match'     => ! empty( $candidates ) ? $candidates[0]['url'] : '',
			'best_score'     => ! empty( $candidates ) ? $candidates[0]['percent'] : 0,
			'all_candidates' => array_column( $all_live_scores, 'url' ),
		);
	}

	/**
	 * Tier 3 Matching: LLM Fallback via OpenAI's API.
	 *
	 * @param string $broken_url     Broken URL.
	 * @param array  $all_candidates Ranked list of candidate URLs from Tier 2.
	 * @return string Corrected URL from OpenAI, or empty string.
	 */
	private function tier3_llm_fallback( $broken_url, $all_candidates ) {
		$api_key = get_option( 'link_healer_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			return '';
		}

		// Slice the top 50 most similar paths to act as the "nearby live site structures".
		$nearby_urls = array_slice( $all_candidates, 0, 50 );
		if ( empty( $nearby_urls ) ) {
			return '';
		}

		$api_url = 'https://api.openai.com/v1/chat/completions';

		$system_prompt = "You are a precise link redirection assistant. You are given a broken URL and a list of valid live URLs from the same website. Analyze the broken URL and select the best matching live URL from the provided list. Return ONLY the matched absolute URL from the list. Do not explain, do not add markdown (e.g. no code blocks, no quotes), do not return anything other than the exact URL string. If no match is suitable, return an empty string.";

		$user_content = "Broken URL: " . esc_url( $broken_url ) . "\n\nLive URLs:\n" . implode( "\n", array_map( 'esc_url', $nearby_urls ) );

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => 'gpt-4o-mini',
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => $system_prompt,
							),
							array(
								'role'    => 'user',
								'content' => $user_content,
							),
						),
						'temperature' => 0,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
			$suggested_url = trim( $data['choices'][0]['message']['content'] );

			// Strict safety guard: Validate that the returned URL matches one of our live site structures.
			if ( in_array( $suggested_url, $all_candidates, true ) ) {
				return $suggested_url;
			}
		}

		return '';
	}
}
