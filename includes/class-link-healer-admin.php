<?php
/**
 * Admin dashboard and healing engine for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_Admin
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_Admin
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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		// Hook save_post to auto-mark resolved links
		add_action( 'save_post', array( $this, 'on_post_save' ), 10, 3 );

		// Register the AJAX Link Healing endpoint
		add_action( 'wp_ajax_link_healer_heal_link', array( $this, 'ajax_heal_link' ) );
	}

	/**
	 * Register the top-level admin menu page.
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'Link Healer Dashboard', 'link-healer' ),
			__( 'Link Healer', 'link-healer' ),
			'manage_options',
			'link-healer',
			array( $this, 'render_dashboard_page' ),
			'dashicons-admin-links',
			30
		);
	}

	/**
	 * Register the plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'link_healer_settings_group',
			'link_healer_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Enqueue styles and scripts on our specific admin page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load assets on our custom admin page.
		if ( 'toplevel_page_link-healer' !== $hook ) {
			return;
		}

		// Enqueue premium stylesheet
		wp_enqueue_style(
			'link-healer-admin-css',
			LINK_HEALER_URL . 'css/admin.css',
			array(),
			LINK_HEALER_VERSION
		);

		// Enqueue AJAX-driven controllers
		wp_enqueue_script(
			'link-healer-admin-js',
			LINK_HEALER_URL . 'js/admin.js',
			array( 'jquery' ),
			LINK_HEALER_VERSION,
			true
		);

		// Localize script to securely pass variables and nonces
		wp_localize_script(
			'link-healer-admin-js',
			'linkHealerAdmin',
			array(
				'nonce' => wp_create_nonce( 'link_healer_admin_action' ),
				'i18n'  => array(
					'empty_suggestion' => __( 'Please fill in a valid suggestion URL before healing.', 'link-healer' ),
					'heal'             => __( 'Heal Link', 'link-healer' ),
					'no_selection'     => __( 'No links selected. Please check one or more rows.', 'link-healer' ),
					'confirm_reset'    => __( 'Are you absolutely sure you want to clear all tracked links and sources? This cannot be undone.', 'link-healer' ),
				),
			)
		);
	}

	/**
	 * Enqueue Gutenberg block editor sidebar assets.
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'link-healer-sidebar-js',
			LINK_HEALER_URL . 'js/sidebar.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ),
			LINK_HEALER_VERSION,
			true
		);
	}

	/**
	 * Automatically mark broken links as fixed if they are no longer in the post content upon save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function on_post_save( $post_id, $post, $update ) {
		if ( ! $update || wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;
		$table_links   = $wpdb->prefix . 'link_healer_links';
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Find the source record for this post
		$source_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_sources WHERE post_id = %d LIMIT 1",
			$post_id
		) );

		if ( ! $source_id ) {
			return;
		}

		// Fetch all broken links for this source
		$broken_links = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_links WHERE source_id = %d AND status = 'broken'",
			$source_id
		) );

		if ( empty( $broken_links ) ) {
			return;
		}

		foreach ( $broken_links as $link ) {
			// Check if the old raw URL is no longer present in the post content
			if ( strpos( $post->post_content, $link->raw_url ) === false ) {
				// Also check that it's not present in its escaped form
				if ( strpos( $post->post_content, esc_url( $link->raw_url ) ) === false ) {
					// The link was swapped/removed! Mark as fixed.
					$wpdb->update(
						$table_links,
						array(
							'status'       => 'fixed',
							'last_checked' => current_time( 'mysql' ),
						),
						array( 'id' => $link->id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
				}
			}
		}
	}

	/**
	 * AJAX Handler: One-Click link healing engine.
	 */
	public function ajax_heal_link() {
		check_ajax_referer( 'link_healer_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'link-healer' ) ), 403 );
		}

		$link_id = isset( $_POST['link_id'] ) ? intval( $_POST['link_id'] ) : 0;
		$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( trim( $_POST['new_url'] ) ) : '';

		if ( ! $link_id || empty( $new_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters provided.', 'link-healer' ) ), 400 );
		}

		global $wpdb;
		$table_links   = $wpdb->prefix . 'link_healer_links';
		$table_sources = $wpdb->prefix . 'link_healer_sources';

		// Fetch link and source details
		$link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_links WHERE id = %d", $link_id ) );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Target link not found.', 'link-healer' ) ), 404 );
		}

		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_sources WHERE id = %d", $link->source_id ) );
		if ( ! $source ) {
			wp_send_json_error( array( 'message' => __( 'Parent source not found.', 'link-healer' ) ), 404 );
		}

		// Perform Healing inside WordPress DB content if a published post is referenced
		if ( $source->post_id > 0 ) {
			$post = get_post( $source->post_id );
			if ( ! $post ) {
				wp_send_json_error( array( 'message' => __( 'Parent post content could not be loaded.', 'link-healer' ) ), 404 );
			}

			$old_content = $post->post_content;

			// Replace both raw and esc_url occurrences of the link inside href tags
			$new_content = str_replace(
				array(
					'href="' . esc_url( $link->raw_url ) . '"',
					'href=\'' . esc_url( $link->raw_url ) . '\'',
					'href="' . $link->raw_url . '"',
					'href=\'' . $link->raw_url . '\'',
				),
				array(
					'href="' . esc_url( $new_url ) . '"',
					'href=\'' . esc_url( $new_url ) . '\'',
					'href="' . $new_url . '"',
					'href=\'' . $new_url . '\'',
				),
				$old_content
			);

			// Save updated content
			if ( $old_content !== $new_content ) {
				$update_result = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $new_content,
					),
					true // return WP_Error object on failure
				);

				if ( is_wp_error( $update_result ) ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'WordPress failed to update post: %s', 'link-healer' ), $update_result->get_error_message() ) ) );
				}
			} else {
				// Fallback string check: if it failed to match exact href quotes, attempt a raw find-and-replace
				$new_content = str_replace( $link->raw_url, $new_url, $old_content );
				if ( $old_content !== $new_content ) {
					wp_update_post(
						array(
							'ID'           => $post->ID,
							'post_content' => $new_content,
						)
					);
				} else {
					wp_send_json_error( array( 'message' => __( 'Link could not be located inside post content.', 'link-healer' ) ) );
				}
			}
		} else {
			// Homepage, Taxonomy Terms, or custom links
			wp_send_json_error( array( 'message' => __( 'This link exists on a non-post page (like a term archive or template) and must be fixed manually.', 'link-healer' ) ), 400 );
		}

		// Update tracking table row to mark as fixed
		$wpdb->update(
			$table_links,
			array(
				'status'        => 'fixed',
				'suggested_fix' => $new_url,
				'last_checked'  => current_time( 'mysql' ),
			),
			array( 'id' => $link_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$ajax_data = $this->get_ajax_dashboard_data();
		wp_send_json_success(
			array(
				'message'    => __( 'Link successfully healed and updated!', 'link-healer' ),
				'kpis'       => $ajax_data['kpis'],
				'table_html' => $ajax_data['table_html'],
			)
		);
	}

	/**
	 * Retrieve updated dashboard stats and table HTML.
	 *
	 * @return array Updated dashboard data.
	 */
	public function get_ajax_dashboard_data() {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Query KPI metrics with DISTINCT target URLs for mathematical accuracy
		$scanned_links   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status != 'unchecked'" );
		$broken_internal = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'broken' AND link_type = 'internal'" );
		$broken_external = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'broken' AND link_type = 'external'" );
		$healed_links    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'fixed'" );

		// Render the table body and pagination container into a buffer
		ob_start();
		$this->render_table_content();
		$table_html = ob_get_clean();

		return array(
			'kpis'       => array(
				'scanned'    => $scanned_links,
				'broken_int' => $broken_internal,
				'broken_ext' => $broken_external,
				'healed'     => $healed_links,
			),
			'table_html' => $table_html,
		);
	}

	/**
	 * Render the table rows and pagination HTML.
	 */
	public function render_table_content() {
		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Setup Table Pagination
		$per_page     = 10;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_links WHERE status = 'broken'" );
		$num_pages   = ceil( $total_items / $per_page );

		// Fetch broken links alongside their source metadata
		$query = $wpdb->prepare(
			"SELECT l.*, s.source_url, s.source_type, s.post_id 
			 FROM $table_links l 
			 JOIN $table_sources s ON l.source_id = s.id 
			 WHERE l.status = 'broken'
			 ORDER BY l.last_checked DESC 
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$broken_links_list = $wpdb->get_results( $query );
		?>
		<div class="link-healer-table-wrapper">
			<table class="link-healer-table">
				<thead>
					<tr>
						<th class="link-healer-col-cb"><input type="checkbox" id="link-healer-select-all" /></th>
						<th class="link-healer-col-source"><?php esc_html_e( 'Source Page', 'link-healer' ); ?></th>
						<th class="link-healer-col-target"><?php esc_html_e( 'Broken Target URL', 'link-healer' ); ?></th>
						<th class="link-healer-col-status"><?php esc_html_e( 'HTTP Status', 'link-healer' ); ?></th>
						<th class="link-healer-col-suggestion"><?php esc_html_e( 'Smart Suggestion', 'link-healer' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Actions', 'link-healer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $broken_links_list ) ) : ?>
						<tr>
							<td colspan="6">
								<div class="link-healer-empty-state">
									<div class="link-healer-empty-state-icon">🎉</div>
									<p><?php esc_html_e( 'Awesome! No broken links found on your site.', 'link-healer' ); ?></p>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $broken_links_list as $link ) : ?>
							<tr id="link-row-<?php echo esc_attr( $link->id ); ?>">
								<td class="link-healer-col-cb">
									<input type="checkbox" class="link-row-cb" value="<?php echo esc_attr( $link->id ); ?>" />
								</td>
								<td class="link-healer-col-source">
									<?php
									$edit_url = '';
									if ( $link->post_id > 0 ) {
										$edit_url = get_edit_post_link( $link->post_id );
									}
									if ( ! empty( $edit_url ) ) :
										?>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="link-healer-source-title" target="_blank">
											<?php echo esc_html( get_the_title( $link->post_id ) ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( $link->source_url ); ?>" class="link-healer-source-title" target="_blank">
											<?php
											if ( 'homepage' === $link->source_type ) {
												esc_html_e( 'Homepage', 'link-healer' );
											} else {
												echo esc_html( basename( $link->source_url ) );
											}
											?>
										</a>
									<?php endif; ?>
									<span class="link-healer-source-meta">
										<?php echo esc_html( str_replace( '_', ' ', $link->source_type ) ); ?>
									</span>
								</td>
								<td class="link-healer-col-target">
									<div class="link-healer-target-url"><?php echo esc_html( $link->raw_url ); ?></div>
									<span class="link-healer-source-meta" style="font-size:10px;">
										<?php echo esc_html( $link->link_type ); ?>
									</span>
								</td>
								<td class="link-healer-col-status">
									<span class="link-healer-badge status-broken">
										<?php echo esc_html( $link->http_status ? $link->http_status : '404' ); ?>
									</span>
								</td>
								<td class="link-healer-col-suggestion">
									<div class="link-healer-suggestion-wrapper">
										<input type="text" 
											   class="link-healer-suggestion-input" 
											   data-link-id="<?php echo esc_attr( $link->id ); ?>" 
											   value="<?php echo esc_attr( $link->suggested_fix ); ?>" 
											   placeholder="http://..." />
									</div>
								</td>
								<td style="text-align:right;">
									<button class="link-healer-btn link-healer-btn-primary link-healer-heal-btn" 
											data-link-id="<?php echo esc_attr( $link->id ); ?>" 
											style="font-size: 11px; padding: 6px 12px; border-radius:6px;">
										<?php esc_html_e( 'Heal Link', 'link-healer' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Table Pagination links -->
		<?php if ( $num_pages > 1 ) : ?>
			<div class="link-healer-pagination">
				<div>
					<?php
					printf(
						esc_html__( 'Showing page %1$d of %2$d', 'link-healer' ),
						esc_html( $current_page ),
						esc_html( $num_pages )
					);
					?>
				</div>
				<div class="link-healer-pagination-links">
					<?php
					for ( $i = 1; $i <= $num_pages; $i++ ) {
						$class = ( $i === $current_page ) ? 'active' : '';
						$page_url = add_query_arg( 'paged', $i );
						echo '<a href="' . esc_url( $page_url ) . '" class="link-healer-page-num ' . esc_attr( $class ) . '">' . esc_html( $i ) . '</a>';
					}
					?>
				</div>
			</div>
		<?php endif;
	}

	/**
	 * Render the unified Administration Dashboard page.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table_sources = $wpdb->prefix . 'link_healer_sources';
		$table_links   = $wpdb->prefix . 'link_healer_links';

		// Verify tables are ready
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_sources'" ) !== $table_sources ) {
			?>
			<div class="wrap link-healer-wrap">
				<h1><?php esc_html_e( 'Link Healer', 'link-healer' ); ?></h1>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Database tables are not installed. Please deactivate and reactivate the plugin to trigger the install script.', 'link-healer' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		// Query KPI metrics with DISTINCT target URLs for exact mathematical accuracy
		$scanned_links   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status != 'unchecked'" );
		$broken_internal = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'broken' AND link_type = 'internal'" );
		$broken_external = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'broken' AND link_type = 'external'" );
		$healed_links    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT raw_url) FROM $table_links WHERE status = 'fixed'" );

		// Settings redirect feedback
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			add_settings_error( 'link_healer_settings', 'settings_updated', __( 'Settings saved successfully.', 'link-healer' ), 'updated' );
		}
		?>
		<div class="wrap link-healer-wrap">
			
			<div class="link-healer-header">
				<h1>Link Healer <span class="version">v<?php echo esc_html( LINK_HEALER_VERSION ); ?></span></h1>
			</div>

			<?php settings_errors( 'link_healer_settings' ); ?>

			<!-- Tab Triggers -->
			<div class="link-healer-tabs">
				<button class="link-healer-tab-trigger active" data-tab="dashboard"><?php esc_html_e( 'Audit Dashboard', 'link-healer' ); ?></button>
				<button class="link-healer-tab-trigger" data-tab="settings"><?php esc_html_e( 'OpenAI Settings', 'link-healer' ); ?></button>
			</div>

			<!-- Tab 1: Dashboard Panel -->
			<div id="tab-dashboard" class="link-healer-tab-content active">
				
				<!-- KPI metrics row -->
				<div class="link-healer-kpi-grid">
					<div class="link-healer-kpi-card scanned">
						<div class="link-healer-kpi-title"><?php esc_html_e( 'Total Scanned Links', 'link-healer' ); ?></div>
						<div class="link-healer-kpi-value" id="link-healer-count-scanned"><?php echo esc_html( $scanned_links ); ?></div>
					</div>
					<div class="link-healer-kpi-card broken-int">
						<div class="link-healer-kpi-title"><?php esc_html_e( 'Broken Internal Links', 'link-healer' ); ?></div>
						<div class="link-healer-kpi-value" id="link-healer-count-broken-int"><?php echo esc_html( $broken_internal ); ?></div>
					</div>
					<div class="link-healer-kpi-card broken-ext">
						<div class="link-healer-kpi-title"><?php esc_html_e( 'Broken External Links', 'link-healer' ); ?></div>
						<div class="link-healer-kpi-value" id="link-healer-count-broken-ext"><?php echo esc_html( $broken_external ); ?></div>
					</div>
					<div class="link-healer-kpi-card healed">
						<div class="link-healer-kpi-title"><?php esc_html_e( 'Total Healed Links', 'link-healer' ); ?></div>
						<div class="link-healer-kpi-value" id="link-healer-count-healed"><?php echo esc_html( $healed_links ); ?></div>
					</div>
				</div>

				<!-- Console manual scan controls -->
				<div class="link-healer-console">
					<h3 class="link-healer-console-title"><?php esc_html_e( 'Crawl Console', 'link-healer' ); ?></h3>
					<div class="link-healer-console-actions">
						<button id="link-healer-trigger-discovery" class="link-healer-btn link-healer-btn-primary">
							<span class="dashicons dashicons-search" style="margin-top:2px;"></span> <?php esc_html_e( 'Scan Site Content', 'link-healer' ); ?>
						</button>
						<button id="link-healer-trigger-crawl" class="link-healer-btn link-healer-btn-secondary">
							<span class="dashicons dashicons-update" style="margin-top:2px;"></span> <?php esc_html_e( 'Crawl Pending Links', 'link-healer' ); ?>
						</button>
						<button id="link-healer-cancel-crawl" class="link-healer-btn link-healer-btn-danger" style="display:none;">
							<span class="dashicons dashicons-dismiss" style="margin-top:2px;"></span> <?php esc_html_e( 'Cancel Crawl', 'link-healer' ); ?>
						</button>
						<button id="link-healer-reset-data" class="link-healer-btn link-healer-btn-danger">
							<span class="dashicons dashicons-trash" style="margin-top:2px;"></span> <?php esc_html_e( 'Reset Database', 'link-healer' ); ?>
						</button>
					</div>
					<!-- Progress Tracker Container -->
					<div id="link-healer-progress-container" style="display:none; margin-top: 15px; padding: 15px; background: rgba(0,0,0,0.02); border-radius: 8px;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-weight: 500;">
							<span id="link-healer-progress-label">Crawling...</span>
							<span id="link-healer-progress-percentage">0%</span>
						</div>
						<div style="width: 100%; height: 10px; background: rgba(0,0,0,0.05); border-radius: 5px; overflow: hidden;">
							<div id="link-healer-progress-bar" style="width: 0%; height: 100%; background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); transition: width 0.3s ease;"></div>
						</div>
						<div id="link-healer-progress-details" style="margin-top: 5px; font-size: 11px; opacity: 0.7;">
							Remaining: 0 | Total: 0
						</div>
					</div>
				</div>

				<!-- Main Audit List Card -->
				<div class="link-healer-card" id="link-healer-audit-card">
					
					<div class="link-healer-table-header">
						<div class="link-healer-bulk-actions">
							<select id="link-healer-bulk-action" class="link-healer-select">
								<option value="-1"><?php esc_html_e( 'Bulk Actions', 'link-healer' ); ?></option>
								<option value="heal"><?php esc_html_e( 'Heal Selected Links', 'link-healer' ); ?></option>
							</select>
							<button id="link-healer-bulk-apply" class="link-healer-btn link-healer-btn-secondary">
								<?php esc_html_e( 'Apply', 'link-healer' ); ?>
							</button>
						</div>
					</div>

					<div id="link-healer-table-container">
						<?php $this->render_table_content(); ?>
					</div>

				</div>
			</div>

			<!-- Tab 2: Settings Panel -->
			<div id="tab-settings" class="link-healer-tab-content">
				<div class="link-healer-settings-form">
					<form method="post" action="options.php">
						<?php
						settings_fields( 'link_healer_settings_group' );
						?>
						<div class="link-healer-settings-card">
							<div class="link-healer-form-group">
								<label for="link_healer_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'link-healer' ); ?></label>
								<input type="password" 
									   id="link_healer_openai_api_key" 
									   name="link_healer_openai_api_key" 
									   value="<?php echo esc_attr( get_option( 'link_healer_openai_api_key', '' ) ); ?>" 
									   class="link-healer-form-input-pass" 
									   placeholder="sk-proj-..." />
								<p class="description">
									<?php esc_html_e( 'Provide your OpenAI API Key. The key is used as a Tier 3 fallback when standard slug parsing and fuzzy comparisons cannot locate highly confident URL matches.', 'link-healer' ); ?>
								</p>
							</div>
							
							<?php submit_button( __( 'Save Key Settings', 'link-healer' ), 'primary', 'submit', false, array( 'class' => 'link-healer-btn link-healer-btn-primary' ) ); ?>
						</div>
					</form>
				</div>
			</div>

			<!-- Toast Notification Alert Structure -->
			<div id="link-healer-toast" class="link-healer-toast">
				<span class="dashicons dashicons-info" style="margin-top:-2px;"></span>
				<span class="toast-message"></span>
			</div>

		</div>
		<?php
	}
}
