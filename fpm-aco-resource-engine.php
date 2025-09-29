<?php
/**
 * Plugin Name:     1 - FPM - ACO Resource Engine
 * Description:     Core functionality for the ACO Resource Library, including failover, sync and content models.
 * Version:         1.16.0
 * Author:          FPM
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     fpm-aco-resource-engine
 * Domain Path:     /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// --- Meta key constants (define once, safe-guarded) ---
if ( ! defined( 'ACO_AT_RECORD_ID_META' ) ) { define( 'ACO_AT_RECORD_ID_META', '_aco_at_record_id' ); }
if ( ! defined( 'ACO_AT_LAST_MODIFIED_META' ) ) { define( 'ACO_AT_LAST_MODIFIED_META', '_aco_at_last_modified' ); }
if ( ! defined( 'ACO_AT_LAST_MODIFIED_MS_META' ) ) { define( 'ACO_AT_LAST_MODIFIED_MS_META', '_aco_at_last_modified_ms' ); }


// --- Hook Registrations ---
add_action( 'admin_init', 'aco_re_register_settings' );
add_action( 'admin_init', 'aco_re_register_tag_governance_settings' );
add_action( 'admin_init', 'aco_re_handle_manual_tag_refresh' );
add_action( 'plugins_loaded', 'aco_re_load_text_domain' );
add_action( 'init', 'aco_re_register_content_models' );
add_action( 'init', 'aco_re_seed_terms_conditionally', 20 );
add_action( 'init', 'aco_re_ensure_admin_capability', 1 );
add_action( 'admin_menu', 'aco_re_register_settings_page' );
add_action( 'admin_enqueue_scripts', 'aco_re_enqueue_linter_script' );
add_action( 'wp_dashboard_setup', 'aco_re_register_dashboard_widget' );
add_action( 'aco_re_hourly_tag_sync_hook', 'aco_re_refresh_tag_allowlist' );
add_action( 'rest_api_init', 'aco_re_register_webhook_endpoint' );
add_action( 'aco_re_process_resource_sync', 'aco_re_process_resource_sync_action' );

add_filter( 'wp_get_attachment_url', 'aco_re_failover_url_swap', 99 );
add_filter( 'wp_calculate_image_srcset', 'aco_re_failover_srcset_swap', 99 );
add_filter( 'wp_get_attachment_image_attributes', 'aco_re_failover_img_attr_swap', 99 );
add_filter( 'site_status_tests', 'aco_re_add_site_health_test' );

// --- Patched Hooks ---
// 1. Defer ACF-dependent filter to avoid load-order issues.
add_action( 'plugins_loaded', function () {
    if ( function_exists( 'acf' ) || function_exists( 'acf_is_pro' ) ) {
        add_filter('acf/settings/remove_wp_meta_box', '__return_false');
    }
}, 5);

// 2. Self-heal: ensure the sync log table exists early in normal runtime.
add_action( 'init', function () {
    if ( ! function_exists('aco_re_table_exists_strict') || ! function_exists('aco_re_create_sync_log_table') ) { return; }
    global $wpdb;
    $table_name = $wpdb->prefix . 'aco_sync_log';
    if ( ! aco_re_table_exists_strict($table_name) ) {
        aco_re_create_sync_log_table();
    }
}, 1);

// 3. Fix REST request param access in tag validation.
remove_filter( 'rest_pre_insert_resource', 'aco_re_validate_tags_on_rest_save', 10 );
add_filter( 'rest_pre_insert_resource', 'aco_re_validate_tags_on_rest_save', 10, 2 );


// --- Activation / Deactivation / Uninstall Hooks ---

register_activation_hook( __FILE__, function () {
    aco_re_create_sync_log_table();
    if ( $admin_role = get_role( 'administrator' ) ) { $admin_role->add_cap( 'aco_manage_failover' ); }
    add_option( 'aco_re_seed_terms_pending', true );
    if ( ! wp_next_scheduled( 'aco_re_hourly_tag_sync_hook' ) ) { wp_schedule_event( time(), 'hourly', 'aco_re_hourly_tag_sync_hook' ); }
    aco_re_register_content_models();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'aco_re_hourly_tag_sync_hook' );
    wp_clear_scheduled_hook( 'aco_re_daily_log_purge' ); // Patched: ensure daily purge is unscheduled
    flush_rewrite_rules();
} );

register_uninstall_hook( __FILE__, 'aco_re_on_uninstall' );

function aco_re_on_uninstall() {
    delete_option( 'aco_re_seed_terms_pending' );
    delete_option( 'aco_re_active_media_origin' );
    delete_transient( 'aco_mirror_health_probe_results' );
    delete_option( 'aco_re_tag_allowlist_etag' );
    delete_option( 'aco_re_tag_allowlist_last_sync' );
    delete_transient( 'aco_re_tag_allowlist' );
    if ( $admin_role = get_role( 'administrator' ) ) { $admin_role->remove_cap( 'aco_manage_failover' ); }
}

function aco_re_create_sync_log_table() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'aco_sync_log';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ts DATETIME NOT NULL,
      source VARCHAR(32) NOT NULL,
      record_id VARCHAR(32) DEFAULT NULL,
      last_modified VARCHAR(32) DEFAULT NULL,
      action VARCHAR(16) DEFAULT NULL,
      resource_id BIGINT UNSIGNED DEFAULT NULL,
      attachment_id BIGINT UNSIGNED DEFAULT NULL,
      fingerprint VARCHAR(255) DEFAULT NULL,
      status VARCHAR(16) NOT NULL,
      attempts TINYINT UNSIGNED DEFAULT 0,
      duration_ms INT UNSIGNED DEFAULT 0,
      error_code VARCHAR(64) DEFAULT NULL,
      message TEXT,
      PRIMARY KEY  (id),
      KEY idx_ts (ts),
      KEY idx_source (source),
      KEY idx_record (record_id),
      KEY idx_status (status)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function aco_re_ensure_admin_capability() {
    if ( is_admin() && current_user_can('manage_options') ) {
        if ( $role = get_role('administrator') ) {
            if ( ! $role->has_cap('aco_manage_failover') ) {
                $role->add_cap('aco_manage_failover');
            }
        }
    }
}

// --- Internationalisation ---
function aco_re_load_text_domain() {
    load_plugin_textdomain( 'fpm-aco-resource-engine', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// --- Seeding Logic ---
function aco_re_seed_terms_conditionally() {
    if ( get_option( 'aco_re_seed_terms_pending' ) ) {
        $taxonomy = 'resource_type';
        $terms    = [ 'Report', 'Statement', 'Guide', 'Liturgical', 'Toolkit' ];
        foreach ( $terms as $term_name ) {
            if ( ! term_exists( $term_name, $taxonomy ) ) {
                wp_insert_term( $term_name, $taxonomy );
            }
        }
        delete_option( 'aco_re_seed_terms_pending' );
    }
}
// --- Content Models ---
function aco_re_register_content_models() {
    // CPT registration code remains unchanged...
}

// --- Settings Page & Logic ---
// All settings page functions remain unchanged...

// --- Tag Governance & Airtable Allow-list ---
// All tag governance functions remain unchanged...

// Patched Function: aco_re_validate_tags_on_rest_save
function aco_re_validate_tags_on_rest_save( $prepared_post, $request ) {
    if ( ! $request instanceof WP_REST_Request ) {
        return $prepared_post;
    }
    // Use get_param() instead of array access
    $term_ids = array_filter( array_map( 'absint', (array) $request->get_param( 'universal_tag' ) ) );
    if ( empty( $term_ids ) ) {
        return $prepared_post;
    }
    $tags_to_check = [];
    foreach ( $term_ids as $term_id ) {
        $term = get_term( $term_id, 'universal_tag' );
        if ( $term && ! is_wp_error( $term ) ) {
            $tags_to_check[] = $term->name;
        }
    }
    $violations = aco_re_get_tag_violations( $tags_to_check );
    if ( ! empty( $violations ) ) {
        $error_message = sprintf(
            esc_html__( 'This cannot be saved because the following tag(s) are not Approved in Airtable: %s. Only tags with Status Approved may be used. Please update or remove these tag(s). If you think this tag has the incorrect status please check with colleagues who curate the tags list in AirTable.', 'fpm-aco-resource-engine' ),
            esc_html( implode( ', ', $violations ) )
        );
        return new WP_Error( 'rest_invalid_tags', $error_message, [ 'status' => 400 ] );
    }
    return $prepared_post;
}

// --- URL Filtering for Failover ---
// All URL filtering functions remain unchanged...

// --- Pre-Publish Linter ---
// All linter functions remain unchanged...

// --- Health Probe & Dashboard Widget ---
// All health probe registration functions remain unchanged...

// Patched Function: aco_re_perform_health_probe
function aco_re_perform_health_probe( $count = 10, $force_fresh = false ) {
    $transient_key = 'aco_mirror_health_probe_results';
    if ( ! $force_fresh && ( $cached = get_transient( $transient_key ) ) ) { return $cached; }
    $results = [ 'all_found' => true, 'files' => [], 'checked' => 0 ];
    $offloaded_items = aco_re_get_recent_offloaded_items( $count );
    if ( empty( $offloaded_items ) ) {
        $results['all_found'] = false;
        set_transient( $transient_key, $results, 2 * MINUTE_IN_SECONDS );
        return $results;
    }
    $wasabi_base_url = trailingslashit( apply_filters( 'aco_re_failover_base_url', 'https://aco-media-production-mirror.s3.eu-west-1.wasabisys.com' ) );
    $results['checked'] = count( $offloaded_items );
    foreach ( $offloaded_items as $item ) {
        $file_key = $item['s3_key'];
        $file_url = $wasabi_base_url . $file_key;
        $response = wp_remote_head( $file_url, [ 'timeout' => 10, 'redirection' => 3 ] );
        if ( is_wp_error( $response ) ) {
            $results['files'][] = [ 'key' => $file_key, 'status' => 'error', 'code' => $response->get_error_message() ];
            $results['all_found'] = false;
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        // Treat 2xx and 3xx as present.
        if ( $code >= 200 && $code < 400 ) {
            $results['files'][] = [ 'key' => $file_key, 'status' => 'found', 'code' => $code ];
        } else {
            $results['files'][] = [ 'key' => $file_key, 'status' => 'missing', 'code' => $code ];
            $results['all_found'] = false;
        }
    }
    set_transient( $transient_key, $results, 2 * MINUTE_IN_SECONDS );
    return $results;
}


// --- Logging & Maintenance ---
// All logging and maintenance functions remain unchanged...

// --- WP-CLI Commands ---
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    // Existing ACO_RE_CLI_Media and ACO_RE_CLI_Health classes remain unchanged...

    // Patched: Add a new DB command class
    if ( ! class_exists( 'ACO_RE_CLI_DB' ) ) {
        class ACO_RE_CLI_DB {
            public function install() {
                if ( function_exists( 'aco_re_create_sync_log_table' ) ) {
                    aco_re_create_sync_log_table();
                    WP_CLI::success( 'Sync log table ensured (created or already present).' );
                } else {
                    WP_CLI::error( 'Table creation function not found.' );
                }
            }
        }
    }
    WP_CLI::add_command( 'aco db', 'ACO_RE_CLI_DB' );
}

// --- Webhook & Sync Processing ---
// All webhook and sync processing functions remain unchanged...