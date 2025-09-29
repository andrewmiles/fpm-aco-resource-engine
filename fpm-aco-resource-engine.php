<?php
/**
 * Plugin Name:     1 - FPM - ACO Resource Engine
 * Description:     Core functionality for the ACO Resource Library, including failover, sync and content models.
 * Version:         1.16.1
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
add_action( 'plugins_loaded', function () {
    if ( function_exists( 'acf' ) || function_exists( 'acf_is_pro' ) ) {
        add_filter('acf/settings/remove_wp_meta_box', '__return_false');
    }
}, 5);

// Patched: Self-healing table check now runs ONLY in admin.
add_action( 'init', function () {
    if ( ! is_admin() ) {
        return;
    }
    if ( ! function_exists('aco_re_table_exists_strict') || ! function_exists('aco_re_create_sync_log_table') ) { return; }
    global $wpdb;
    $table_name = $wpdb->prefix . 'aco_sync_log';
    if ( ! aco_re_table_exists_strict($table_name) ) {
        aco_re_create_sync_log_table();
    }
}, 1);

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
    wp_clear_scheduled_hook( 'aco_re_daily_log_purge' );
    flush_rewrite_rules();
} );

register_uninstall_hook( __FILE__, 'aco_re_on_uninstall' );
function aco_re_on_uninstall() { /* ... function content ... */ }
function aco_re_create_sync_log_table() { /* ... function content ... */ }
function aco_re_ensure_admin_capability() { /* ... function content ... */ }
function aco_re_load_text_domain() { /* ... function content ... */ }
function aco_re_seed_terms_conditionally() { /* ... function content ... */ }
function aco_re_register_content_models() { /* ... function content ... */ }
function aco_re_register_settings_page() { /* ... function content ... */ }
function aco_re_render_settings_page() { /* ... function content ... */ }
function aco_re_register_settings() { /* ... function content ... */ }
function aco_re_render_origin_field() { /* ... function content ... */ }
function aco_re_sanitize_and_purge($input) { /* ... function content ... */ }
function aco_re_handle_manual_tag_refresh() { /* ... function content ... */ }
function aco_re_register_tag_governance_settings() { /* ... function content ... */ }
function aco_re_render_tag_governance_section_text() { /* ... function content ... */ }
function aco_re_render_tag_refresh_button() { /* ... function content ... */ }
function aco_re_refresh_tag_allowlist() { /* ... function content ... */ }
add_filter( 'wp_insert_post_data', function( $data, $postarr ) { /* ... function content ... */ }, 10, 2 );
add_action( 'admin_notices', function() { /* ... function content ... */ } );
function aco_re_validate_tags_on_rest_save($prepared_post, $request) { /* ... function content ... */ }
function aco_re_get_tag_violations($tag_names) { /* ... function content ... */ }
function aco_re_failover_url_swap($url) { /* ... function content ... */ }
function aco_re_failover_srcset_swap($sources) { /* ... function content ... */ }
function aco_re_failover_img_attr_swap($attr) { /* ... function content ... */ }
function aco_re_enqueue_linter_script() { /* ... function content ... */ }
function aco_re_table_exists_strict(string $table_name): bool { /* ... function content ... */ }
function aco_re_get_recent_offloaded_items($count = 10) { /* ... function content ... */ }
function aco_re_perform_health_probe($count = 10, $force_fresh = false) { /* ... function content ... */ }
function aco_re_register_dashboard_widget() { /* ... function content ... */ }
function aco_re_render_health_widget() { /* ... function content ... */ }
function aco_re_add_site_health_test($tests) { /* ... function content ... */ }
function aco_re_register_log_purge_cron() { /* ... function content ... */ }
add_action( 'aco_re_daily_log_purge', 'aco_re_purge_old_logs' );
function aco_re_purge_old_logs() { /* ... function content ... */ }
if ( defined( 'WP_CLI' ) && WP_CLI ) { /* ... CLI classes and commands ... */ }
function aco_re_register_webhook_endpoint() { /* ... function content ... */ }
function aco_re_get_header(WP_REST_Request $request, $base_key) { /* ... function content ... */ }
function aco_re_get_webhook_secrets() { /* ... function content ... */ }
function aco_re_decode_signature_to_binary($sig_raw) { /* ... function content ... */ }
function aco_re_prevent_replay($sig_bin, $ttl = 300) { /* ... function content ... */ }
function aco_re_reject_oversized_requests(WP_REST_Request $request) { /* ... function content ... */ }
function aco_re_webhook_permission_callback(WP_REST_Request $request) { /* ... function content ... */ }
function aco_re_enqueue_sync_job(array $payload): bool { /* ... function content ... */ }
function aco_re_handle_webhook_sync(WP_REST_Request $request) { /* ... function content ... */ }
function aco_re_acquire_lock(string $record_id, int $ttl = 120): bool { /* ... function content ... */ }
function aco_re_release_lock(string $record_id): void { /* ... function content ... */ }
function aco_re_parse_last_modified($raw): array { /* ... function content ... */ }
function aco_re_get_post_id_by_record_id(string $record_id): int { /* ... function content ... */ }
function aco_re_get_first_string(array $data, array $keys): string { /* ... function content ... */ }
function aco_re_log(string $level, string $message, array $context = []): void { /* ... function content ... */ }
function aco_re_process_resource_sync_action($payload) { /* ... function content ... */ }