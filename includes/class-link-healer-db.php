<?php
/**
 * Database schema and connection wrapper for Link Healer.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Healer_DB {

	/**
	 * Singleton instance.
	 *
	 * @var Link_Healer_DB
	 */
	private static $instance = null;

	/**
	 * Get active instance.
	 *
	 * @return Link_Healer_DB
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
	 * Install custom tables.
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_sources   = $wpdb->prefix . 'link_healer_sources';
		$table_links     = $wpdb->prefix . 'link_healer_links';

		// wp_link_healer_sources table SQL.
		$sql_sources = "CREATE TABLE $table_sources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned DEFAULT NULL,
			source_type varchar(50) NOT NULL,
			source_url varchar(2083) NOT NULL,
			status varchar(20) DEFAULT 'pending' NOT NULL,
			last_scanned datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY source_type (source_type),
			KEY source_url (source_url(191))
		) $charset_collate;";

		// wp_link_healer_links table SQL using new column names.
		$sql_links = "CREATE TABLE $table_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			target_url varchar(2083) NOT NULL,
			url_hash char(32) NOT NULL,
			is_internal tinyint(1) NOT NULL DEFAULT 0,
			http_status int(5) DEFAULT NULL,
			status varchar(20) DEFAULT 'unchecked' NOT NULL,
			anchor_text text DEFAULT NULL,
			suggested_fix_url varchar(2083) DEFAULT NULL,
			last_checked datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_link (source_id, target_url(191)),
			KEY source_id (source_id),
			KEY status (status),
			KEY url_hash (url_hash),
			KEY http_status (http_status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sources );
		dbDelta( $sql_links );
	}

	/**
	 * Insert or get existing source URL.
	 *
	 * @param int|null $post_id
	 * @param string   $source_type
	 * @param string   $source_url
	 * @return int Source ID
	 */
	public function add_source( $post_id, $source_type, $source_url ) {
		global $wpdb;
		$table = $wpdb->prefix . 'link_healer_sources';

		$source_url = esc_url_raw( trim( $source_url ) );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE source_url = %s",
			$source_url
		) );

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		$wpdb->insert(
			$table,
			array(
				'post_id'     => $post_id,
				'source_type' => sanitize_key( $source_type ),
				'source_url'  => $source_url,
				'status'      => 'pending',
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update source scan status and timestamp.
	 *
	 * @param int    $source_id
	 * @param string $status
	 */
	public function update_source_status( $source_id, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'link_healer_sources';

		$wpdb->update(
			$table,
			array(
				'status'       => sanitize_key( $status ),
				'last_scanned' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $source_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Insert/update link details under a specific source.
	 *
	 * @param int    $source_id
	 * @param string $target_url
	 * @param int    $is_internal (1 for internal, 0 for external)
	 * @param string $anchor_text
	 * @return int Link ID
	 */
	public function save_link( $source_id, $target_url, $is_internal, $anchor_text ) {
		global $wpdb;
		$table = $wpdb->prefix . 'link_healer_links';

		$target_url = esc_url_raw( trim( $target_url ) );
		$url_hash   = md5( $target_url );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE source_id = %d AND url_hash = %s",
			(int) $source_id,
			$url_hash
		) );

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array( 'anchor_text' => wp_kses_post( $anchor_text ) ),
				array( 'id' => (int) $existing_id ),
				array( '%s' ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$wpdb->insert(
			$table,
			array(
				'source_id'   => (int) $source_id,
				'target_url'  => $target_url,
				'url_hash'    => $url_hash,
				'is_internal' => (int) $is_internal,
				'status'      => 'unchecked',
				'anchor_text' => wp_kses_post( $anchor_text ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update validation results for a discovered link.
	 *
	 * @param int    $link_id
	 * @param int    $http_status
	 * @param string $status
	 * @param string $suggested_fix_url
	 */
	public function update_link_status( $link_id, $http_status, $status, $suggested_fix_url = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'link_healer_links';

		$wpdb->update(
			$table,
			array(
				'http_status'       => (int) $http_status,
				'status'            => sanitize_key( $status ),
				'suggested_fix_url' => esc_url_raw( $suggested_fix_url ),
				'last_checked'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $link_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove links for a specific source that were not found in the latest scan.
	 *
	 * @param int   $source_id
	 * @param array $current_link_hashes Hashes of the links that were found in the current scan.
	 */
	public function purge_removed_links( $source_id, $current_link_hashes ) {
		global $wpdb;
		$table = $wpdb->prefix . 'link_healer_links';

		if ( empty( $current_link_hashes ) ) {
			$wpdb->delete( $table, array( 'source_id' => (int) $source_id ), array( '%d' ) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $current_link_hashes ), '%s' ) );
		$query        = $wpdb->prepare(
			"DELETE FROM $table WHERE source_id = %d AND url_hash NOT IN ($placeholders)",
			array_merge( array( (int) $source_id ), $current_link_hashes )
		);

		$wpdb->query( $query );
	}
}
