<?php
/**
 * Plugin Name:     1 - FPM - ACO Resource Engine
 * Description:     Core functionality for the ACO Resource Library, including failover, sync and content models.
 * Version:         1.19.6
 * Author:          FPM, AM
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
if ( ! defined( 'ACO_AT_RECORD_ID_META' ) ) {
    define( 'ACO_AT_RECORD_ID_META', '_aco_at_record_id' );
}
if ( ! defined( 'ACO_AT_LAST_MODIFIED_META' ) ) {
    define( 'ACO_AT_LAST_MODIFIED_META', '_aco_at_last_modified' ); // ISO-8601 UTC string
}
if ( ! defined( 'ACO_AT_LAST_MODIFIED_MS_META' ) ) {
    define( 'ACO_AT_LAST_MODIFIED_MS_META', '_aco_at_last_modified_ms' ); // integer milliseconds since epoch
}


function aco_re_on_uninstall() {
    delete_option( 'aco_re_seed_terms_pending' );
    delete_option( 'aco_re_active_media_origin' );
    delete_transient( 'aco_mirror_health_probe_results' );
    delete_option( 'aco_re_tag_allowlist_etag' );
    delete_option( 'aco_re_tag_allowlist_last_sync' );
    delete_transient( 'aco_re_tag_allowlist' );
    if ( $admin_role = get_role( 'administrator' ) ) {
        $admin_role->remove_cap( 'aco_manage_failover' );
    }
}
/**
 * Creates or updates the custom sync log table in the database.
 */

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
    if ( is_admin() && current_user_can( 'manage_options' ) ) {
        if ( $role = get_role( 'administrator' ) ) {
            if ( ! $role->has_cap( 'aco_manage_failover' ) ) {
                $role->add_cap( 'aco_manage_failover' );
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
    $cpt_labels = [ 'name' => _x( 'Resources', 'Post type general name', 'fpm-aco-resource-engine' ), 'singular_name' => _x( 'Resource', 'Post type singular name', 'fpm-aco-resource-engine' ), 'menu_name' => _x( 'Resources', 'Admin Menu text', 'fpm-aco-resource-engine' ), 'add_new_item' => __( 'Add New Resource', 'fpm-aco-resource-engine' ), 'add_new' => __( 'Add New', 'fpm-aco-resource-engine' ), 'edit_item' => __( 'Edit Resource', 'fpm-aco-resource-engine' ), 'update_item' => __( 'Update Resource', 'fpm-aco-resource-engine' ), 'view_item' => __( 'View Resource', 'fpm-aco-resource-engine' ), 'search_items' => __( 'Search Resources', 'fpm-aco-resource-engine' ) ];
    $cpt_args   = [ 'labels' => $cpt_labels, 'public' => true, 'has_archive' => true, 'rewrite' => [ 'slug' => 'resources' ], 'menu_icon' => 'dashicons-media-document', 'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ], 'show_in_rest' => true ];
    register_post_type( 'resource', $cpt_args );

    $tag_labels = [ 'name' => _x( 'Universal Tags', 'taxonomy general name', 'fpm-aco-resource-engine' ), 'singular_name' => _x( 'Universal Tag', 'taxonomy singular name', 'fpm-aco-resource-engine' ), 'menu_name' => __( 'Universal Tags', 'fpm-aco-resource-engine' ), 'all_items' => __( 'All Universal Tags', 'fpm-aco-resource-engine' ), 'edit_item' => __( 'Edit Universal Tag', 'fpm-aco-resource-engine' ), 'update_item' => __( 'Update Universal Tag', 'fpm-aco-resource-engine' ), 'add_new_item' => __( 'Add New Universal Tag', 'fpm-aco-resource-engine' ), 'new_item_name' => __( 'New Universal Tag Name', 'fpm-aco-resource-engine' ) ];
    $tag_args   = [ 'hierarchical' => false, 'labels' => $tag_labels, 'show_ui' => true, 'show_admin_column' => true, 'query_var' => true, 'rewrite' => [ 'slug' => 'universal-tag' ], 'show_in_rest' => true ];
    register_taxonomy( 'universal_tag', [ 'resource', 'post' ], $tag_args );

    $type_labels = [ 'name' => _x( 'Resource Types', 'taxonomy general name', 'fpm-aco-resource-engine' ), 'singular_name' => _x( 'Resource Type', 'taxonomy singular name', 'fpm-aco-resource-engine' ), 'menu_name' => __( 'Resource Types', 'fpm-aco-resource-engine' ), 'all_items' => __( 'All Resource Types', 'fpm-aco-resource-engine' ), 'edit_item' => __( 'Edit Resource Type', 'fpm-aco-resource-engine' ), 'update_item' => __( 'Update Resource Type', 'fpm-aco-resource-engine' ), 'add_new_item' => __( 'Add New Resource Type', 'fpm-aco-resource-engine' ), 'new_item_name' => __( 'New Resource Type Name', 'fpm-aco-resource-engine' ) ];
    $type_args   = [ 'hierarchical' => true, 'labels' => $type_labels, 'show_ui' => true, 'show_admin_column' => true, 'query_var' => true, 'rewrite' => [ 'slug' => 'resource-type' ], 'show_in_rest' => true ];
    register_taxonomy( 'resource_type', [ 'resource' ], $type_args );
}

// --- Settings Page & Logic ---
function aco_re_register_settings_page() {
    add_options_page( __( 'ACO Resilience', 'fpm-aco-resource-engine' ), __( 'ACO Resilience', 'fpm-aco-resource-engine' ), 'aco_manage_failover', 'aco-resilience-settings', 'aco_re_render_settings_page' );
}

function aco_re_render_settings_page() {
    $setting_slug = 'aco-resilience-settings';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'ACO Resilience Settings', 'fpm-aco-resource-engine' ); ?></h1>
        <p><?php esc_html_e( 'This page contains the settings for managing media failover and tag governance.', 'fpm-aco-resource-engine' ); ?></p>
        <?php settings_errors( $setting_slug ); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'aco_resilience_options' );
            do_settings_sections( $setting_slug );
            submit_button( __( 'Save Settings', 'fpm-aco-resource-engine' ) );
            ?>
        </form>
    </div>
    <?php
}

function aco_re_register_settings() {
    register_setting(
        'aco_resilience_options',
        'aco_re_active_media_origin',
        [
            'type'              => 'string',
            'default'           => 's3',
            'sanitize_callback' => 'aco_re_sanitize_and_purge',
            'capability'        => 'aco_manage_failover',
            'show_in_rest'      => false,
        ]
    );
    add_settings_section( 'aco_re_failover_section', __( 'Media Origin', 'fpm-aco-resource-engine' ), '__return_false', 'aco-resilience-settings' );
    add_settings_field( 'aco_re_active_media_origin_field', __( 'Active Media Origin', 'fpm-aco-resource-engine' ), 'aco_re_render_origin_field', 'aco-resilience-settings', 'aco_re_failover_section' );
}

function aco_re_render_origin_field() {
    $option = get_option( 'aco_re_active_media_origin', 's3' );
    ?>
    <fieldset>
        <legend class="screen-reader-text"><span><?php esc_html_e( 'Active Media Origin', 'fpm-aco-resource-engine' ); ?></span></legend>
        <label><input type="radio" name="aco_re_active_media_origin" value="s3" <?php checked( $option, 's3' ); ?> /> <span><?php esc_html_e( 'S3 (Primary)', 'fpm-aco-resource-engine' ); ?></span></label><br>
        <label><input type="radio" name="aco_re_active_media_origin" value="wasabi" <?php checked( $option, 'wasabi' ); ?> /> <span><?php esc_html_e( 'Wasabi (Mirror)', 'fpm-aco-resource-engine' ); ?></span></label>
    </fieldset>
    <?php
}

function aco_re_sanitize_and_purge( $input ) {
    $new_value = ( $input === 'wasabi' ) ? 'wasabi' : 's3';
    $old_value = get_option( 'aco_re_active_media_origin', 's3' );
    if ( $new_value === 'wasabi' && $old_value === 's3' ) {
        $health = aco_re_perform_health_probe( 10, true );
        if ( ! $health['all_found'] ) {
            add_settings_error( 'aco-resilience-settings', 'wasabi_mirror_lagging', __( 'Failover to Wasabi blocked. The mirror is missing one or more recent files. Please check rclone sync status and try again.', 'fpm-aco-resource-engine' ), 'error' );
            return $old_value;
        }
    }
    if ( $new_value !== $old_value ) {
        if ( function_exists( 'litespeed_purge_all' ) ) {
            litespeed_purge_all();
        }
        delete_transient( 'aco_mirror_health_probe_results' );
    }
    return $new_value;
}

// --- Tag Governance & Airtable Allow-list ---

function aco_re_handle_manual_tag_refresh() {
    if ( ! isset( $_GET['action'] ) || 'aco_re_force_tag_refresh' !== $_GET['action'] ) {
        return;
    }
    if ( ! current_user_can( 'aco_manage_failover' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'fpm-aco-resource-engine' ), esc_html__( 'Access denied', 'fpm-aco-resource-engine' ), [ 'response' => 403, 'back_link' => true ] );
    }
    check_admin_referer( 'aco_re_force_tag_refresh_nonce' );

    $result       = aco_re_refresh_tag_allowlist();
    $setting_slug = 'aco-resilience-settings';
    $type         = is_wp_error( $result ) ? 'error' : 'updated';
    $text         = is_wp_error( $result ) ? $result->get_error_message() : __( 'Tag allow-list has been successfully refreshed from Airtable.', 'fpm-aco-resource-engine' );

    add_settings_error( $setting_slug, 'tag_refresh_status', $text, $type );
    set_transient( 'settings_errors', get_settings_errors(), 30 );

    $redirect_url = add_query_arg( [ 'page' => $setting_slug, 'settings-updated' => 'true' ], admin_url( 'options-general.php' ) );
    wp_safe_redirect( $redirect_url );
    exit;
}

function aco_re_register_tag_governance_settings() {
    add_settings_section( 'aco_re_tag_governance_section', __( 'Tag Governance', 'fpm-aco-resource-engine' ), 'aco_re_render_tag_governance_section_text', 'aco-resilience-settings' );
    add_settings_field( 'aco_re_tag_governance_refresh_field', __( 'Refresh Allow-list', 'fpm-aco-resource-engine' ), 'aco_re_render_tag_refresh_button', 'aco-resilience-settings', 'aco_re_tag_governance_section' );
}

function aco_re_render_tag_governance_section_text() {
    echo '<p>' . esc_html__( 'Manage the synchronization of the Universal Tag allow-list from Airtable. This list is used to enforce tagging rules.', 'fpm-aco-resource-engine' ) . '</p>';
    $last_sync_time = (int) get_option( 'aco_re_tag_allowlist_last_sync', 0 );
    if ( $last_sync_time ) {
        $formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync_time );
        echo '<p><strong>' . esc_html__( 'Last successful sync:', 'fpm-aco-resource-engine' ) . '</strong> ' . esc_html( $formatted ) . '</p>';
    } else {
        echo '<p>' . esc_html__( 'The allow-list has not been synced yet.', 'fpm-aco-resource-engine' ) . '</p>';
    }
}

function aco_re_render_tag_refresh_button() {
    $refresh_url = wp_nonce_url( add_query_arg( 'action', 'aco_re_force_tag_refresh' ), 'aco_re_force_tag_refresh_nonce' );
    ?>
    <p class="description"><?php esc_html_e( 'The allow-list is refreshed automatically every hour. Click this link to force an immediate refresh.', 'fpm-aco-resource-engine' ); ?></p>
    <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Refresh Now', 'fpm-aco-resource-engine' ); ?></a>
    <?php
}

function aco_re_refresh_tag_allowlist() {
    $api_key  = _aco_re_get_config( 'ACO_AT_API_KEY' );
    $base_id  = _aco_re_get_config( 'ACO_AT_BASE_ID' );
    $table_id = _aco_re_get_config( 'ACO_AT_TABLE_TAGS' );

    if ( ! $api_key || ! $base_id || ! $table_id ) {
        return new WP_Error( 'aco_re_missing_creds', __( 'Airtable API credentials are not configured in environment variables.', 'fpm-aco-resource-engine' ) );
    }

    $endpoint   = "https://api.airtable.com/v0/{$base_id}/{$table_id}";
    $headers    = [ 'Authorization' => 'Bearer ' . $api_key ];
    $have_cache = ( false !== get_transient( 'aco_re_tag_allowlist' ) );

    if ( $stored_etag = get_option( 'aco_re_tag_allowlist_etag', '' ) ) {
        $headers['If-None-Match'] = $stored_etag;
    }

    $all_records = [];
    $offset      = null;
    $attempt     = 0;

    do {
        $attempt++;
        $args       = [ 'headers' => $headers, 'timeout' => 15, 'redirection' => 0 ];
        $query_args = [ 'pageSize' => 100 ];

        if ( $offset ) {
            $query_args['offset'] = $offset;
            unset( $args['headers']['If-None-Match'] );
        }

        $response = wp_remote_get( add_query_arg( $query_args, $endpoint ), $args );
        if ( is_wp_error( $response ) ) { return $response; }

        $code = (int) wp_remote_retrieve_response_code( $response );

        // Treat 304 as success only if we already have a cache to serve.
        if ( 304 === $code ) {
            if ( $have_cache ) {
                update_option( 'aco_re_tag_allowlist_last_sync', time() );
                return true;
            }
            // No cache but 304 → drop ETag and refetch to repopulate.
            unset( $headers['If-None-Match'] );
            $have_cache = true; // prevent infinite loop; next fetch aims for 200
            continue;
        }

        if ( 200 !== $code ) {
            return new WP_Error(
                'aco_re_api_error',
                sprintf( __( 'Airtable API returned an error. Code: %d', 'fpm-aco-resource-engine' ), $code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['records'] ) ) {
            return new WP_Error( 'aco_re_invalid_response', __( 'Invalid response format from Airtable API.', 'fpm-aco-resource-engine' ) );
        }

        $all_records = array_merge( $all_records, $data['records'] );
        $offset      = $data['offset'] ?? null;

        if ( ! isset( $query_args['offset'] ) ) { // first page of the fetch
            if ( $new_etag = wp_remote_retrieve_header( $response, 'etag' ) ) {
                update_option( 'aco_re_tag_allowlist_etag', $new_etag );
            }
        }
    } while ( $offset && $attempt < 50 );

    $new_allowlist = [];
    foreach ( $all_records as $record ) {
        $name   = $record['fields']['Name']   ?? '';
        $status = $record['fields']['Status'] ?? 'Pending';
        if ( '' !== $name ) { $new_allowlist[ $name ] = $status; }
    }

    // Keep the existing filter name for full backward compatibility.
    set_transient(
        'aco_re_tag_allowlist',
        $new_allowlist,
        (int) apply_filters( 'aco_tag_allowlist_ttl', HOUR_IN_SECONDS )
    );
    update_option( 'aco_re_tag_allowlist_last_sync', time() );
    return true;
}

/**
 * Gently validates tags on save for classic editor and Quick Edit flows.
 * Instead of wp_die(), it forces the post to 'draft' and displays an admin notice.
 * This version handles both standard POST and AJAX (Quick Edit) saves.
 */
add_filter( 'wp_insert_post_data', function( $data, $postarr ) {
    if ( 'resource' !== ( $data['post_type'] ?? '' ) || 'auto-draft' === ( $data['post_status'] ?? '' ) ) {
        return $data;
    }
    if ( empty( $postarr['tax_input']['universal_tag'] ) ) {
        return $data;
    }

    $submitted_tags = (array) $postarr['tax_input']['universal_tag'];
    if ( count( $submitted_tags ) === 1 && is_string( $submitted_tags[0] ) ) {
        $submitted_tags = array_map( 'trim', explode( ',', $submitted_tags[0] ) );
    }

    $tag_names_to_check = [];
    foreach ( $submitted_tags as $tag ) {
        if ( is_numeric( $tag ) ) {
            $term = get_term( (int) $tag, 'universal_tag' );
            if ( $term && ! is_wp_error( $term ) ) { $tag_names_to_check[] = $term->name; }
        } elseif ( is_string( $tag ) && $tag !== '' ) {
            $tag_names_to_check[] = $tag;
        }
    }
    
    $tag_names_to_check = array_unique( array_filter( $tag_names_to_check ) );
    if ( empty( $tag_names_to_check ) ) { return $data; }

    $violations = aco_re_get_tag_violations( $tag_names_to_check );
    if ( empty( $violations ) ) { return $data; }

    $error_message = sprintf(
        esc_html__( 'This resource was not published because the following tag(s) are not Approved: %s. The post has been saved as a Draft.', 'fpm-aco-resource-engine' ),
        '<strong>' . esc_html( implode( ', ', $violations ) ) . '</strong>'
    );
    
    // Use the appropriate method to pass the error message.
    if ( wp_doing_ajax() ) {
        // For AJAX saves (like Quick Edit), store the message in a short-lived user transient.
        set_transient( 'aco_re_tag_error_' . get_current_user_id(), $error_message, 30 );
    } else {
        // For regular saves, use the redirect URL method.
        add_filter( 'redirect_post_location', function( $location ) use ( $error_message ) {
            return add_query_arg( [ 'aco_re_tag_error' => rawurlencode( $error_message ) ], $location );
        } );
    }

    $data['post_status'] = 'draft';

    return $data;
}, 10, 2 );

/**
 * Renders the admin notice passed from our gentle validation filter.
 * This version checks for both a transient (for AJAX saves) and a URL parameter (for regular saves).
 */
add_action( 'admin_notices', function() {
    $error_message = '';
    $transient_key = 'aco_re_tag_error_' . get_current_user_id();
    
    // Check for a transient first.
    if ( $transient_message = get_transient( $transient_key ) ) {
        $error_message = $transient_message;
        delete_transient( $transient_key ); // Important: clear the transient after reading it.
    } 
    // If no transient, check for a URL parameter.
    elseif ( isset( $_GET['aco_re_tag_error'] ) ) {
        $error_message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['aco_re_tag_error'] ) ) );
    }

    if ( $error_message ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $error_message ) . '</p></div>';
    }
} );

function aco_re_validate_tags_on_rest_save( $prepared_post, $request ) {
    if ( ! isset( $request['universal_tag'] ) ) { return $prepared_post; }
    $term_ids = array_filter( array_map( 'absint', (array) $request['universal_tag'] ) );
    if ( empty( $term_ids ) ) { return $prepared_post; }

    $tags_to_check = [];
    foreach ( $term_ids as $term_id ) {
        if ( $term = get_term( $term_id, 'universal_tag' ) ) {
            if ( ! is_wp_error( $term ) ) { $tags_to_check[] = $term->name; }
        }
    }

    $violations = aco_re_get_tag_violations( $tags_to_check );
    if ( ! empty( $violations ) ) {
        $error_message = sprintf( esc_html__( 'This cannot be saved because the following tag(s) are not Approved in Airtable: %s. Only tags with Status Approved may be used. Please update or remove these tag(s). If you think this tag has the incorrect status please check with colleagues who curate the tags list in AirTable.', 'fpm-aco-resource-engine' ), esc_html( implode( ', ', $violations ) ) );
        return new WP_Error( 'rest_invalid_tags', $error_message, [ 'status' => 400 ] );
    }
    return $prepared_post;
}

function aco_re_get_tag_violations( $tag_names ) {
    $allowlist = get_transient('aco_re_tag_allowlist');
    if ( false === $allowlist ) {
        aco_re_refresh_tag_allowlist();
        $allowlist = get_transient('aco_re_tag_allowlist');
    }
    if ( ! is_array( $allowlist ) ) {
        $strict = (bool) apply_filters( 'aco_re_strict_when_allowlist_unavailable', false );
        if ( $strict ) { return array_values( array_unique( array_filter( (array) $tag_names ) ) ); }
        if ( function_exists( 'error_log' ) ) { error_log('ACO Resource Engine: Tag allow-list unavailable. Bypassing validation.'); }
        return [];
    }
    $violations = [];
    foreach ( (array) $tag_names as $tag_name ) {
        if ( ! isset( $allowlist[ $tag_name ] ) || $allowlist[ $tag_name ] !== 'Approved' ) {
            $violations[] = $tag_name;
        }
    }
    return $violations;
}

// --- URL Filtering for Failover ---
function aco_re_failover_url_swap( $url ) {
    if ( get_option( 'aco_re_active_media_origin', 's3' ) !== 'wasabi' ) { return $url; }
    $primary_domain = apply_filters( 'aco_re_primary_media_host', 'media.acomain.site' );
    $failover_host  = apply_filters( 'aco_re_failover_media_host', 'aco-media-production-mirror.s3.eu-west-1.wasabisys.com' );
    $parts = wp_parse_url( $url );
    if ( empty( $parts['host'] ) || strcasecmp( $parts['host'], $primary_domain ) !== 0 ) { return $url; }
    $parts['host']   = $failover_host;
    $parts['scheme'] = 'https';
    $rebuilt_url = $parts['scheme'] . '://' . $parts['host'];
    if ( ! empty( $parts['port'] ) ) { $rebuilt_url .= ':' . $parts['port']; }
    if ( ! empty( $parts['path'] ) ) { $rebuilt_url .= $parts['path']; }
    if ( ! empty( $parts['query'] ) ) { $rebuilt_url .= '?' . $parts['query']; }
    if ( ! empty( $parts['fragment'] ) ) { $rebuilt_url .= '#' . $parts['fragment']; }
    return $rebuilt_url;
}

function aco_re_failover_srcset_swap( $sources ) {
    if ( ! is_array( $sources ) || get_option( 'aco_re_active_media_origin', 's3' ) !== 'wasabi' ) { return $sources; }
    foreach ( $sources as &$source ) {
        if ( isset( $source['url'] ) ) { $source['url'] = aco_re_failover_url_swap( $source['url'] ); }
    }
    unset( $source );
    return $sources;
}

function aco_re_failover_img_attr_swap( $attr ) {
    if ( get_option( 'aco_re_active_media_origin', 's3' ) !== 'wasabi' ) { return $attr; }
    if ( isset( $attr['src'] ) ) { $attr['src'] = aco_re_failover_url_swap( $attr['src'] ); }
    if ( isset( $attr['srcset'] ) ) {
        $srcset_parts = array_map( 'trim', explode( ',', $attr['srcset'] ) );
        foreach ( $srcset_parts as &$part ) {
            $url_and_width = preg_split( '/\s+/', $part, 2 );
            if ( ! empty( $url_and_width[0] ) ) {
                $url_and_width[0] = aco_re_failover_url_swap( $url_and_width[0] );
                $part = implode( ' ', $url_and_width );
            }
        }
        unset($part);
        $attr['srcset'] = implode( ', ', $srcset_parts );
    }
    return $attr;
}

// --- Pre-Publish Linter ---
function aco_re_enqueue_linter_script() {
    $screen = get_current_screen();
    if ( ! $screen || ! $screen->is_block_editor() ) { return; }
    $script_path = plugin_dir_path( __FILE__ ) . 'admin/linter.js';
    if ( ! file_exists( $script_path ) ) { return; }
    $script_version = filemtime( $script_path );
    $script_url = plugins_url( 'admin/linter.js', __FILE__ );
    wp_enqueue_script( 'aco-linter', $script_url, [ 'wp-data', 'wp-dom-ready', 'wp-notices' ], $script_version, true );
}

// --- Health Probe & Dashboard Widget ---

function aco_re_table_exists_strict( string $table_name ): bool {
    global $wpdb;
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = %s AND table_name = %s LIMIT 1";
    $db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;
    $found   = $wpdb->get_var( $wpdb->prepare( $sql, $db_name, $table_name ) );
    if ( '1' === (string) $found ) { return true; }
    $tables = (array) $wpdb->get_col( 'SHOW TABLES' );
    foreach ( $tables as $tbl ) {
        if ( is_string( $tbl ) && strcasecmp( $tbl, $table_name ) === 0 ) { return true; }
    }
    return false;
}

function aco_re_get_recent_offloaded_items( $count = 10 ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'as3cf_items';
    if ( ! aco_re_table_exists_strict( $table_name ) ) { return []; }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.source_id AS attachment_id, NULLIF(TRIM(i.path), '') AS s3_key FROM {$table_name} i WHERE i.source_type IN ('media', 'media-library') AND NULLIF(TRIM(i.path), '') IS NOT NULL ORDER BY i.id DESC LIMIT %d", absint( $count ) ), ARRAY_A );
    $items = [];
    if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) {
            $items[] = [ 'attachment_id' => (int) $row['attachment_id'], 's3_key' => ltrim( (string) $row['s3_key'], '/' ) ];
        }
    }
    return $items;
}

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
        $response = wp_remote_head( $file_url, [ 'timeout' => 10 ] );
        $status_code = null;
        if ( is_wp_error( $response ) ) {
            $file_status = 'error';
            $status_code = $response->get_error_message();
            $results['all_found'] = false;
        } else {
            $status_code = wp_remote_retrieve_response_code( $response );
            if ( 200 === (int) $status_code ) { $file_status = 'found'; }
            else { $file_status = 'missing'; $results['all_found'] = false; }
        }
        $results['files'][] = [ 'key' => $file_key, 'status' => $file_status, 'code' => $status_code ?: 'N/A' ];
    }
    set_transient( $transient_key, $results, 2 * MINUTE_IN_SECONDS );
    return $results;
}

function aco_re_register_dashboard_widget() {
    wp_add_dashboard_widget( 'aco_re_health_widget', __( 'ACO Media Health', 'fpm-aco-resource-engine' ), 'aco_re_render_health_widget' );
}

function aco_re_render_health_widget() {
    echo '<p>' . esc_html__( 'Checks the status of the S3 primary and Wasabi mirror.', 'fpm-aco-resource-engine' ) . '</p>';
    $probe = aco_re_perform_health_probe( 5 );
    $active_origin = get_option( 'aco_re_active_media_origin', 's3' );
    if ( 's3' === $active_origin ) { echo '<p><strong>' . esc_html__( 'Active Origin:', 'fpm-aco-resource-engine' ) . '</strong> <span style="color:green;">✅ ' . esc_html__( 'S3 (Primary)', 'fpm-aco-resource-engine' ) . '</span></p>'; } else { echo '<p><strong>' . esc_html__( 'Active Origin:', 'fpm-aco-resource-engine' ) . '</strong> <span style="color:orange;">⚠️ ' . esc_html__( 'Wasabi (Mirror)', 'fpm-aco-resource-engine' ) . '</span></p>'; }
    if ( $probe['checked'] === 0 ) { echo '<p><strong>' . esc_html__( 'Wasabi Mirror Sync:', 'fpm-aco-resource-engine' ) . '</strong> <span style="color:grey;">❔ ' . esc_html__( 'Unknown', 'fpm-aco-resource-engine' ) . '</span> (' . esc_html__( 'No offloaded uploads found to check', 'fpm-aco-resource-engine' ) . ')</p>'; } elseif ( $probe['all_found'] ) { echo '<p><strong>' . esc_html__( 'Wasabi Mirror Sync:', 'fpm-aco-resource-engine' ) . '</strong> <span style="color:green;">✅ ' . esc_html__( 'Healthy', 'fpm-aco-resource-engine' ) . '</span></p>'; } else { echo '<p><strong>' . esc_html__( 'Wasabi Mirror Sync:', 'fpm-aco-resource-engine' ) . '</strong> <span style="color:red;">❌ ' . esc_html__( 'Warning', 'fpm-aco-resource-engine' ) . '</span></p>'; }
    echo '<h4>' . esc_html__( 'Recent Uploads Check:', 'fpm-aco-resource-engine' ) . '</h4>';
    if ( empty( $probe['files'] ) ) {
        echo '<p>' . esc_html__( 'No recent offloaded files to display.', 'fpm-aco-resource-engine' ) . '</p>';
    } else {
        echo '<ul style="font-size:12px; margin-left: 10px;">';
        foreach ( $probe['files'] as $file ) {
            $status_icon = ( 'found' === $file['status'] ) ? '✅' : '❌';
            $status_color = ( 'found' === $file['status'] ) ? 'green' : 'red';
            printf( '<li style="color:%s;">%s %s</li>', esc_attr( $status_color ), esc_html( $status_icon ), esc_html( basename( $file['key'] ) ) );
        }
        echo '</ul>';
    }
}

// --- Site Health Integration ---
function aco_re_add_site_health_test( $tests ) {
    $tests['direct']['aco_re_mirror_health'] = [
        'label' => __( 'ACO Wasabi Mirror Health', 'fpm-aco-resource-engine' ),
        'test'  => function() {
            $result = [ 'badge' => [ 'label' => __( 'ACO Engine', 'fpm-aco-resource-engine' ), 'color' => 'blue' ], 'status' => 'good', 'label' => __( 'Wasabi mirror is ready', 'fpm-aco-resource-engine' ), 'description' => '<p>' . __( 'Recent offloaded uploads are present on the mirror.', 'fpm-aco-resource-engine' ) . '</p>' ];
            $probe = aco_re_perform_health_probe( 10, true );
            if ( $probe['checked'] === 0 ) {
                $result['status'] = 'recommended';
                $result['label'] = __( 'Wasabi mirror status is unknown', 'fpm-aco-resource-engine' );
                $result['description'] = '<p>' . __( 'No offloaded uploads were found to test against the mirror.', 'fpm-aco-resource-engine' ) . '</p>';
            } elseif ( ! $probe['all_found'] ) {
                $result['status'] = 'critical';
                $result['label'] = __( 'Wasabi mirror is lagging', 'fpm-aco-resource-engine' );
                $result['description'] = '<p>' . __( 'One or more recent files are missing on the mirror. Check rclone sync status.', 'fpm-aco-resource-engine' ) . '</p>';
            }
            return $result;
        },
    ];
    return $tests;
}

// --- Logging & Maintenance ---

/**
 * Register a daily cron to purge old sync logs if not already scheduled.
 */
function aco_re_register_log_purge_cron() {
    if ( ! wp_next_scheduled( 'aco_re_daily_log_purge' ) ) {
        // Schedule to run in 1 hour, then daily thereafter.
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aco_re_daily_log_purge' );
    }
}

/**
 * Action hook to handle the log purging.
 */

/**
 * Purges sync logs older than N days (default 30).
 * The retention period is configurable via the 'aco_re_log_retention_days' filter.
 */
function aco_re_purge_old_logs() {
    global $wpdb;
    
    // Set retention period in days. Default is 30, with a minimum of 7.
    $days = max( 7, (int) apply_filters( 'aco_re_log_retention_days', 30 ) );
    
    $table_name = $wpdb->prefix . 'aco_sync_log';
    
    // Defensive check to ensure the table exists before querying.
    if ( ! function_exists( 'aco_re_table_exists_strict' ) || ! aco_re_table_exists_strict( $table_name ) ) {
        return;
    }
    
    // Calculate the threshold date in UTC, consistent with how logs are stored.
    $threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
    
    // Prepare and execute the DELETE query.
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM {$table_name} WHERE ts < %s", $threshold_date )
    );
}

// --- WP-CLI Commands ---
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class ACO_RE_CLI_Media {
        public function flip( $args, $assoc_args ) {
            $destination = strtolower( $assoc_args['to'] ?? '' );
            if ( ! in_array( $destination, [ 's3', 'wasabi' ], true ) ) { WP_CLI::error( 'Invalid destination. Please use --to=s3 or --to=wasabi' ); return; }
            $current_origin = get_option( 'aco_re_active_media_origin', 's3' );
            if ( $current_origin === $destination ) { WP_CLI::warning( "Media origin is already set to '$destination'. No change made." ); return; }
            if ( 'wasabi' === $destination ) {
                WP_CLI::log( 'Running pre-flip safety probe...' );
                $health = aco_re_perform_health_probe( 10, true );
                if ( ! $health['all_found'] ) { WP_CLI::error( 'Failover to Wasabi blocked. The mirror is missing recent files. Check rclone sync log.' ); return; }
                WP_CLI::log( 'Probe passed. Mirror is healthy.' );
            }
            update_option( 'aco_re_active_media_origin', $destination );
            if ( function_exists('litespeed_purge_all') ) { litespeed_purge_all(); WP_CLI::log( 'LiteSpeed cache purged.' ); }
            delete_transient( 'aco_mirror_health_probe_results' );
            WP_CLI::success( "Flipped active media origin to '$destination'." );
        }
    }
    class ACO_RE_CLI_Health {
        public function probe( $args, $assoc_args ) {
            WP_CLI::log( 'Probing Wasabi mirror health...' );
            $results = aco_re_perform_health_probe( 10, true );
            if ( $results['checked'] === 0 ) { WP_CLI::warning( 'No recent offloaded media uploads found to check.' ); return; }
            $table_data = array_map( function($file) { return [ 'File' => basename( $file['key'] ), 'Status' => strtoupper( $file['status'] ), 'Code' => $file['code'] ?: 'N/A' ]; }, $results['files']);
            WP_CLI\Utils\format_items( 'table', $table_data, [ 'File', 'Status', 'Code' ] );
            if ( $results['all_found'] ) { WP_CLI::success( 'Mirror is healthy. All recent uploads were found.' ); } else { WP_CLI::error( 'Mirror health warning. One or more recent uploads are missing or not public.' ); }
        }
    }
    WP_CLI::add_command( 'aco media', 'ACO_RE_CLI_Media' );
    WP_CLI::add_command( 'aco health', 'ACO_RE_CLI_Health' );
}

// --- Webhook & Sync Processing ---

function aco_re_register_webhook_endpoint() {
    register_rest_route( 'aco/v1', '/sync', [ 'methods' => 'POST', 'callback' => 'aco_re_handle_webhook_sync', 'permission_callback' => 'aco_re_webhook_permission_callback', 'args' => [] ] );
}

function aco_re_get_header( WP_REST_Request $request, $base_key ) {
    $val = $request->get_header( strtolower( $base_key ) );
    if ( '' !== $val && null !== $val ) { return $val; }
    return $request->get_header( strtolower( str_replace( '-', '_', $base_key ) ) );
}

function aco_re_get_webhook_secrets() {
    $primary   = _aco_re_get_config( 'ACO_WEBHOOK_SECRET_PRIMARY' );
    $secondary = _aco_re_get_config( 'ACO_WEBHOOK_SECRET_SECONDARY' );
    return [ $primary, $secondary ];
}

function aco_re_decode_signature_to_binary( $sig_raw ) {
    if ( ! is_string( $sig_raw ) ) { return null; }
    $sig = trim( $sig_raw );
    if ( stripos( $sig, 'sha256=' ) === 0 ) { $sig = substr( $sig, 7 ); }
    if ( ctype_xdigit( $sig ) && ( strlen( $sig ) % 2 === 0 ) ) {
        $bin = @pack( 'H*', strtolower( $sig ) );
        return $bin !== false ? $bin : null;
    }
    $b64 = base64_decode( $sig, true );
    if ( $b64 !== false ) { return $b64; }
    return null;
}

function aco_re_prevent_replay( $sig_bin, $ttl = 300 ) {
    if ( ! is_string( $sig_bin ) || $sig_bin === '' ) { return false; }
    $key      = 'aco_replay_' . hash( 'sha256', $sig_bin );
    $existing = get_transient( $key );
    if ( $existing ) { return false; }
    set_transient( $key, 1, $ttl );
    return true;
}

/**
 * Rejects webhook requests with a payload larger than a configured limit.
 *
 * @param WP_REST_Request $request The request object.
 * @return true|WP_Error True if the payload size is acceptable, otherwise a WP_Error.
 */
function aco_re_reject_oversized_requests( WP_REST_Request $request ) {
    // Set a max payload size of 256 KiB by default, configurable via a filter.
    $max_bytes = (int) apply_filters( 'aco_re_webhook_max_bytes', 262144 );
    if ( $max_bytes <= 0 ) {
        return true; // Size check is disabled.
    }

    // First, try the efficient Content-Length header.
    $content_length = (int) $request->get_header( 'content-length' );
    if ( $content_length > 0 && $content_length > $max_bytes ) {
        return new WP_Error(
            'payload_too_large',
            __( 'Payload exceeds maximum size limit.', 'fpm-aco-resource-engine' ),
            [ 'status' => 413 ] // 413 Payload Too Large
        );
    }

    // As a fallback, check the actual body length.
    $body = $request->get_body();
    if ( strlen( $body ) > $max_bytes ) {
        return new WP_Error(
            'payload_too_large',
            __( 'Payload exceeds maximum size limit.', 'fpm-aco-resource-engine' ),
            [ 'status' => 413 ]
        );
    }

    return true;
}

function aco_re_webhook_permission_callback( WP_REST_Request $request ) {
    // 1. First, reject any requests that are excessively large.
    $size_check = aco_re_reject_oversized_requests( $request );
    if ( is_wp_error( $size_check ) ) {
        return $size_check;
    }

    // 2. Proceed with the original validation logic.
    $content_type = aco_re_get_header( $request, 'content-type' );
    if ( $content_type && stripos( $content_type, 'application/json' ) === false ) {
        return new WP_Error( 'invalid_content_type', 'Content-Type must be application/json.', [ 'status' => 415 ] );
    }

    $timestamp_raw = aco_re_get_header( $request, 'x-aco-timestamp' );
    $signature_raw = aco_re_get_header( $request, 'x-aco-signature' );
    $body          = $request->get_body();

    if ( ! $timestamp_raw || ! $signature_raw ) {
        return new WP_Error( 'missing_headers', 'Missing required security headers.', [ 'status' => 400 ] );
    }

    $timestamp_raw = trim( (string) $timestamp_raw );
    if ( ! ctype_digit( $timestamp_raw ) ) {
        return new WP_Error( 'invalid_timestamp', 'Timestamp header must be numeric.', [ 'status' => 400 ] );
    }

    $ts_num = (float) $timestamp_raw;
    $ts_sec = ( $ts_num >= 1000000000000 ) ? ( $ts_num / 1000.0 ) : $ts_num;
    $now    = (float) current_time( 'timestamp', true );

    if ( ( $now - $ts_sec ) > 300.0 || ( $ts_sec - $now ) > 60.0 ) {
        return new WP_Error( 'stale_request', 'The webhook timestamp is outside the allowed window.', [ 'status' => 400 ] );
    }

    $payload = $timestamp_raw . '.' . $body;
    $sig_bin = aco_re_decode_signature_to_binary( $signature_raw );
    if ( null === $sig_bin ) {
        return new WP_Error( 'bad_signature_format', 'The webhook signature format is invalid.', [ 'status' => 400 ] );
    }

    list( $primary_secret, $secondary_secret ) = aco_re_get_webhook_secrets();
    if ( ! $primary_secret && ! $secondary_secret ) {
        return new WP_Error( 'server_misconfig', 'Webhook secrets are not configured.', [ 'status' => 500 ] );
    }

    $expected_primary   = $primary_secret   ? hash_hmac( 'sha256', $payload, $primary_secret, true )   : null;
    $expected_secondary = $secondary_secret ? hash_hmac( 'sha256', $payload, $secondary_secret, true ) : null;

    $is_valid = false;
    if ( $expected_primary && hash_equals( $expected_primary, $sig_bin ) ) {
        $is_valid = true;
    } elseif ( $expected_secondary && hash_equals( $expected_secondary, $sig_bin ) ) {
        $is_valid = true;
    }

    if ( ! $is_valid ) {
        return new WP_Error( 'bad_signature', 'The webhook signature is invalid.', [ 'status' => 403 ] );
    }

    if ( ! aco_re_prevent_replay( $sig_bin, 300 ) ) {
        return new WP_Error( 'replay_detected', 'This webhook has already been processed.', [ 'status' => 409 ] );
    }

    return true;
}

function aco_re_enqueue_sync_job( array $payload ): bool {
    if ( function_exists( 'as_enqueue_async_action' ) ) {
        as_enqueue_async_action( 'aco_re_process_resource_sync', [ 'payload' => $payload ], 'aco_resource_sync' );
        return true;
    }
    return (bool) wp_schedule_single_event( time(), 'aco_re_process_resource_sync', [ [ 'payload' => $payload ] ] );
}

function aco_re_handle_webhook_sync( WP_REST_Request $request ) {
    $payload = $request->get_json_params();
    if ( empty( $payload ) || ! isset( $payload['record_id'] ) ) {
        return new WP_REST_Response( [ 'status' => 'bad_request', 'message' => __( 'Missing or invalid payload.', 'fpm-aco-resource-engine' ) ], 400 );
    }
    $enqueued = aco_re_enqueue_sync_job( $payload );
    if ( ! $enqueued ) {
        return new WP_REST_Response( [ 'status' => 'error', 'message' => __( 'Could not queue the sync job.', 'fpm-aco-resource-engine' ) ], 500 );
    }
    return new WP_REST_Response( [ 'status' => 'accepted' ], 202 );
}

/**
 * Lightweight, best-effort lock using transients to avoid concurrent processing
 * of the same Airtable record_id. Not perfectly atomic but mitigates most races.
 */
function aco_re_acquire_lock( string $record_id, int $ttl = 120 ): bool {
    $key = 'aco_sync_lock_' . md5( $record_id );
    if ( get_transient( $key ) ) {
        return false;
    }
    return (bool) set_transient( $key, 1, $ttl );
}
function aco_re_release_lock( string $record_id ): void {
    $key = 'aco_sync_lock_' . md5( $record_id );
    delete_transient( $key );
}

/**
 * Robustly parse an ISO-8601 date (possibly with milliseconds + Z) to:
 * - integer epoch milliseconds
 * - normalized ISO-8601 (UTC) string
 */
function aco_re_parse_last_modified( $raw ): array {
    if ( ! is_string( $raw ) || $raw === '' ) {
        return [ 0, '' ];
    }
    try {
        $dt = new DateTimeImmutable( $raw );
    } catch ( Throwable $e ) {
        return [ 0, '' ];
    }
    $dt_utc = $dt->setTimezone( new DateTimeZone( 'UTC' ) );
    $sec  = (int) $dt_utc->format( 'U' );
    $usec = (int) $dt_utc->format( 'u' );
    $ms   = ( $sec * 1000 ) + (int) floor( $usec / 1000 );
    $iso  = $dt_utc->format( 'Y-m-d\TH:i:s.v\Z' );
    return [ $ms, $iso ];
}

/**
 * Fetch an existing Resource post ID by Airtable record_id.
 */
function aco_re_get_post_id_by_record_id( string $record_id ): int {
    $posts = get_posts( [
        'post_type'              => 'resource',
        'post_status'            => 'any',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'meta_key'               => ACO_AT_RECORD_ID_META,
        'meta_value'             => $record_id,
        'no_found_rows'          => true,
        'suppress_filters'       => true,
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ] );
    return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Safe string getter for common field name variants.
 */
function aco_re_get_first_string( array $data, array $keys ): string {
    foreach ( $keys as $k ) {
        if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && $data[ $k ] !== '' ) {
            return $data[ $k ];
        }
    }
    return '';
}
/**
 * Structured logger that writes to the wp_aco_sync_log table.
 * This hardened version includes a table existence check and specifies data formats.
 * MODIFIED: Now serializes the full context into the 'message' column for replayability.
 */
function aco_re_log( string $level, string $message, array $context = [] ): void {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aco_sync_log';

    if ( ! function_exists( 'aco_re_table_exists_strict' ) || ! aco_re_table_exists_strict( $table_name ) ) {
        return;
    }

    $status = 'unknown';
    switch ( strtolower( $level ) ) {
        case 'info':    $status = 'success'; break;
        case 'warning': $status = 'warning'; break;
        case 'error':   $status = 'failed';  break;
    }

    // --- START MODIFICATION ---
    // We still extract specific fields for dedicated columns to allow for filtering and sorting.
    $data = [
        'ts'            => gmdate( 'Y-m-d H:i:s' ),
        'source'        => isset( $context['source'] ) ? (string) $context['source'] : 'webhook',
        'record_id'     => isset( $context['record_id'] ) ? (string) $context['record_id'] : null,
        'last_modified' => isset( $context['raw_last_modified'] ) ? (string) $context['raw_last_modified'] : null,
        'action'        => isset( $context['action'] ) ? (string) $context['action'] : null,
        'resource_id'   => isset( $context['resource_id'] ) ? (int) $context['resource_id']
                    : ( isset( $context['post_id'] ) ? (int) $context['post_id'] : null ),
        'attachment_id' => isset( $context['attachment_id'] ) ? (int) $context['attachment_id'] : null,
        'fingerprint'   => isset( $context['fingerprint'] ) ? substr( (string) $context['fingerprint'], 0, 255 ) : null,
        'status'        => $status,
        'attempts'      => isset( $context['attempts'] ) ? (int) $context['attempts'] : 0,
        'duration_ms'   => isset( $context['duration_ms'] ) ? (int) $context['duration_ms'] : 0,
        'error_code'    => isset( $context['error'] ) ? (string) $context['error'] : ( isset( $context['reason'] ) ? (string) $context['reason'] : null ),
    ];
    
    // The 'message' column will now store the full context for replayability,
    // along with the human-readable message.
    $data['message'] = serialize( [
        'message' => (string) $message . ( isset( $context['exception'] ) ? ' | Exception: ' . (string) $context['exception'] : '' ),
        'context' => $context,
    ] );
    // --- END MODIFICATION ---

    $formats = [
        '%s', // ts
        '%s', // source
        '%s', // record_id
        '%s', // last_modified
        '%s', // action
        '%d', // resource_id
        '%d', // attachment_id
        '%s', // fingerprint
        '%s', // status
        '%d', // attempts
        '%d', // duration_ms
        '%s', // error_code
        '%s', // message (now contains serialized data)
    ];

    $suppress = $wpdb->suppress_errors();
    $wpdb->insert( $table_name, $data, $formats );
    $wpdb->suppress_errors( $suppress );
}

/**
 * Validate a file source and perform a HEAD pre-flight.
 * Enforces https scheme, allowed host(s), allowed MIME(s), and a max size.
 */
function aco_re_preflight_remote_file( string $file_url ) {
    $url = esc_url_raw( $file_url );
    $parts = wp_parse_url( $url );
    if ( empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ) {
        return new WP_Error( 'bad_scheme', 'Only HTTPS sources are allowed.' );
    }
    $host = strtolower( $parts['host'] ?? '' );
    // Allow-list: default empty means allow any host; override via filter.
    $allowed_hosts = (array) apply_filters( 'aco_re_allowed_file_hosts', [] );
    if ( $allowed_hosts && ! in_array( $host, array_map( 'strtolower', $allowed_hosts ), true ) ) {
        return new WP_Error( 'host_not_allowed', 'Source host is not allow-listed.' );
    }

    $head = wp_remote_head( $url, [ 'timeout' => 10, 'redirection' => 2 ] );
    if ( is_wp_error( $head ) ) {
        return $head;
    }
    $code = (int) wp_remote_retrieve_response_code( $head );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'head_failed', 'HEAD request failed with HTTP ' . $code );
    }

    $ctype = strtolower( (string) wp_remote_retrieve_header( $head, 'content-type' ) );
    $allowed_mimes = (array) apply_filters( 'aco_re_allowed_mime_types', [ 'application/pdf' ] );
    $ok_mime = false;
    foreach ( $allowed_mimes as $mime ) {
        if ( $ctype && strpos( $ctype, strtolower( $mime ) ) !== false ) { $ok_mime = true; break; }
    }
    if ( ! $ok_mime ) {
        return new WP_Error( 'bad_mime', 'Remote file type is not permitted.' );
    }

    $len = (int) wp_remote_retrieve_header( $head, 'content-length' );
    $max_bytes = (int) apply_filters( 'aco_re_max_sideload_bytes', 50 * 1024 * 1024 ); // 50 MB default
    if ( $len > 0 && $len > $max_bytes ) {
        return new WP_Error( 'too_large', 'Remote file exceeds the permitted size.' );
    }

    return [
        'content_type'   => $ctype,
        'content_length' => $len,
        'url'            => $url,
        'host'           => $host,
        'parts'          => $parts,
    ];
}

/**
 * Try to resolve an existing attachment for our own media URLs (primary or mirror),
 * avoiding re-uploading duplicates.
 */
function aco_re_try_resolve_existing_attachment_from_url( string $file_url ) {
    $parts = wp_parse_url( $file_url );
    if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
        return 0;
    }

    $primary_host = apply_filters( 'aco_re_primary_media_host', 'media.acomain.site' );
    $mirror_host  = apply_filters( 'aco_re_failover_media_host', 'aco-media-production-mirror.s3.eu-west-1.wasabisys.com' );
    $host = strtolower( $parts['host'] );

    if ( strtolower( $primary_host ) !== $host && strtolower( $mirror_host ) !== $host ) {
        return 0; // Not our infra → cannot resolve by key.
    }

    $key = ltrim( (string) $parts['path'], '/' );

    global $wpdb;
    $table = $wpdb->prefix . 'as3cf_items';
    if ( ! function_exists( 'aco_re_table_exists_strict' ) || ! aco_re_table_exists_strict( $table ) ) {
        return 0;
    }

    // Look up the attachment by the exact offload key (path).
    $attachment_id = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT source_id FROM {$table} WHERE path = %s LIMIT 1", $key )
    );

    return $attachment_id > 0 ? $attachment_id : 0;
}

/**
 * Wait briefly for Offload Media to record bucket/path for a new attachment.
 */
function aco_re_get_offload_info_with_retry( int $attachment_id, int $attempts = 5, int $sleep_ms = 500 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'as3cf_items';
    if ( ! function_exists( 'aco_re_table_exists_strict' ) || ! aco_re_table_exists_strict( $table ) ) {
        return null;
    }
    for ( $i = 0; $i < max( 1, $attempts ); $i++ ) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT bucket, path FROM {$table} WHERE source_id = %d AND source_type IN ('media','media-library') LIMIT 1",
                $attachment_id
            ),
            ARRAY_A
        );
        if ( $row && ! empty( $row['bucket'] ) && ! empty( $row['path'] ) ) {
            return $row;
        }
        // Sleep between attempts.
        if ( $i < $attempts - 1 ) {
            usleep( max( 0, $sleep_ms ) * 1000 );
        }
    }
    return null;
}

/**
 * Handle a cleared FileURL (remove association).
 */
function aco_re_clear_primary_attachment( int $post_id ) {
    delete_post_meta( $post_id, '_aco_primary_attachment_id' );
    delete_post_meta( $post_id, '_aco_file_fingerprint' );
}


/**
 * Enhanced helper: validates, de-dupes, sideloads (only when needed), and fingerprints.
 */
function _aco_re_sideload_and_attach_file( int $post_id, string $file_url ) {
    // 1) Pre-flight validation (scheme/host/MIME/size)
    $pre = aco_re_preflight_remote_file( $file_url );
    if ( is_wp_error( $pre ) ) {
        return $pre;
    }
    $pre = is_array( $pre ) ? $pre : [];

    // 2) Shortcut: if this URL is already under our media domain(s), reuse existing attachment.
    $existing_id = aco_re_try_resolve_existing_attachment_from_url( $file_url );
    if ( $existing_id > 0 ) {
        update_post_meta( $post_id, '_aco_primary_attachment_id', $existing_id );

        // Compute fingerprint from Offload table.
        $off = aco_re_get_offload_info_with_retry( $existing_id );
        if ( $off ) {
            $filesize = 0;
            $meta = wp_get_attachment_metadata( $existing_id );
            if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
                $filesize = (int) $meta['filesize'];
            }
            if ( ! $filesize ) {
                $filepath = get_attached_file( $existing_id );
                if ( $filepath && file_exists( $filepath ) ) {
                    $filesize = (int) filesize( $filepath );
                } elseif ( ! empty( $pre['content_length'] ) ) {
                    $filesize = (int) $pre['content_length'];
                }
            }
            if ( $filesize ) {
                $fingerprint = sprintf( 's3:%s:%s|%d', $off['bucket'], ltrim( (string) $off['path'], '/' ), $filesize );
                update_post_meta( $post_id, '_aco_file_fingerprint', $fingerprint );
            }
        }

        aco_re_log( 'info', 'Reused existing attachment for media-domain URL.', [
            'post_id'       => $post_id,
            'attachment_id' => $existing_id,
            'action'        => 'file_reuse',
            'fingerprint'   => get_post_meta( $post_id, '_aco_file_fingerprint', true ),
            'payload'       => [ 'FileURL' => $file_url ],
        ] );
        return true;
    }

    // 3) Sideload (external source)
    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $tmp = download_url( $file_url, 30 ); // honour WP HTTP settings; 30s safeguard
    if ( is_wp_error( $tmp ) ) {
        return $tmp;
    }

    $file_array = [
        'name'     => basename( wp_parse_url( $file_url, PHP_URL_PATH ) ) ?: 'document.pdf',
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload( $file_array, $post_id );
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $file_array['tmp_name'] );
        return $attachment_id;
    }

    update_post_meta( $post_id, '_aco_primary_attachment_id', $attachment_id );

    // START: Patch to parent the attachment and trigger re-index
    $parent_result = wp_update_post(
        [ 'ID' => (int) $attachment_id, 'post_parent' => (int) $post_id ],
        true
    );
    if ( is_wp_error( $parent_result ) ) {
        aco_re_log('warning', 'Could not parent attachment to Resource.', [
            'post_id'       => (int) $post_id,
            'attachment_id' => (int) $attachment_id,
            'action'        => 'file_parent',
            'error'         => $parent_result->get_error_message(),
        ]);
    } else {
        // If SearchWP is present, enqueue a reindex of the Resource so PDF content attribution updates.
        if ( has_action( 'searchwp\\index\\enqueue' ) ) {
            do_action( 'searchwp\\index\\enqueue', 'post', (int) $post_id );
        }
    }
    // END: Patch

    // 4) Compute fingerprint (with small retry for Offload row)
    $off = aco_re_get_offload_info_with_retry( $attachment_id );
    if ( ! $off ) {
        return new WP_Error( 'fingerprint_failed', 'Could not retrieve offload info to generate fingerprint.' );
    }

    $filesize = 0;
    $filepath = get_attached_file( $attachment_id );
    if ( $filepath && file_exists( $filepath ) ) {
        $filesize = (int) filesize( $filepath );
    }
    if ( ! $filesize ) {
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
            $filesize = (int) $meta['filesize'];
        }
    }
    if ( ! $filesize && ! empty( $pre['content_length'] ) ) {
        $filesize = (int) $pre['content_length'];
    }

    if ( ! $filesize ) {
        return new WP_Error( 'fingerprint_failed', 'Could not determine attachment size for fingerprint.' );
    }

    $fingerprint = sprintf( 's3:%s:%s|%d', $off['bucket'], ltrim( (string) $off['path'], '/' ), $filesize );
    update_post_meta( $post_id, '_aco_file_fingerprint', $fingerprint );

    aco_re_log( 'info', 'Sideloaded and fingerprinted file.', [
        'post_id'       => $post_id,
        'attachment_id' => $attachment_id,
        'fingerprint'   => $fingerprint,
        'action'        => 'file_sideload',
        'payload'       => [ 'FileURL' => $file_url ],
    ] );

    return true;
}

/**
 * Deterministic term setter:
 * - Resolves each term by name with get_term_by() to avoid get_terms() filters.
 * - Enforces allow-list (when requested).
 * - Replaces terms atomically by clearing first, then setting exactly the resolved IDs.
 */
function _aco_re_set_post_terms_from_names( int $post_id, string $taxonomy, array $names, array $log_ctx, bool $enforce_allowlist = false ): void {
    // Defensive
    if ( ! taxonomy_exists( $taxonomy ) ) {
        $ctx = $log_ctx; $ctx['taxonomy'] = $taxonomy;
        aco_re_log( 'error', sprintf( 'Taxonomy "%s" does not exist; cannot set terms.', $taxonomy ), $ctx );
        return;
    }

    // Normalise
    $names = array_values( array_unique( array_filter( array_map(
        static function( $n ) { return trim( (string) $n ); },
        (array) $names
    ) ) ) );

    // Allow-list enforcement for universal_tag
    if ( $enforce_allowlist && ! empty( $names ) ) {
        $violations = aco_re_get_tag_violations( $names ); // uses names
        if ( ! empty( $violations ) ) {
            $ctx = $log_ctx; $ctx['taxonomy'] = $taxonomy; $ctx['violations'] = $violations;
            aco_re_log( 'warning', sprintf( 'Rejected non-approved term(s) for "%s": %s', $taxonomy, implode( ', ', $violations ) ), $ctx );
            $names = array_values( array_diff( $names, $violations ) );
        }
    }

    // If empty after validation → clear all and finish
    if ( empty( $names ) ) {
        wp_set_object_terms( $post_id, [], $taxonomy );
        return;
    }

    // Resolve by name (not via get_terms) to avoid filter-driven widening
    $term_ids  = [];
    $not_found = [];
    foreach ( $names as $name ) {
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $term_ids[] = (int) $term->term_id;
        } else {
            $not_found[] = $name;
        }
    }

    if ( $not_found ) {
        $ctx = $log_ctx; $ctx['taxonomy'] = $taxonomy; $ctx['not_found'] = $not_found;
        aco_re_log( 'warning', sprintf( 'The following term name(s) were not found for "%s": %s', $taxonomy, implode( ', ', $not_found ) ), $ctx );
    }

    // Hard replace: clear first, then set exactly the resolved IDs (if any)
    wp_set_object_terms( $post_id, [], $taxonomy );
    if ( $term_ids ) {
        wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
    }
}

/**
 * Processes a single resource sync job from the Action Scheduler queue.
 *
 * @param array|string $payload The data received from the Airtable webhook (array or JSON string).
 * @return array Outcome summary for observability: ['status' => 'created|updated|skipped|error', 'post_id' => int|null, 'reason' => string|null]
 */
function aco_re_process_resource_sync_action( $payload ) {
    if ( is_string( $payload ) ) {
        $decoded = json_decode( $payload, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $payload = $decoded;
        }
    }
    $data = ( is_array( $payload ) && isset( $payload['payload'] ) && is_array( $payload['payload'] ) ) ? $payload['payload'] : ( is_array( $payload ) ? $payload : [] );
    if ( ! post_type_exists( 'resource' ) ) {
        aco_re_log( 'error', 'Post type "resource" is not registered.' );
        return [ 'status' => 'error', 'post_id' => null, 'reason' => 'post_type_missing' ];
    }
    $raw_record_id = $data['record_id'] ?? '';
    $raw_last_modified = $data['LastModified'] ?? '';
    $record_id = sanitize_text_field( (string) $raw_record_id );
    list( $incoming_ms, $incoming_iso ) = aco_re_parse_last_modified( $raw_last_modified );

    if ( $record_id === '' || $incoming_ms === 0 ) {
        aco_re_log( 'error', 'Payload missing valid record_id or LastModified.', [ 'has_record_id' => $record_id !== '', 'raw_last_modified' => is_string( $raw_last_modified ) ? $raw_last_modified : null, 'action' => 'validation' ] );
        return [ 'status' => 'error', 'post_id' => null, 'reason' => 'invalid_payload' ];
    }
    if ( ! aco_re_acquire_lock( $record_id, 120 ) ) {
        aco_re_log( 'warning', 'Lock present, skipping concurrent job.', [ 'record_id' => $record_id, 'action' => 'lock' ] );
        return [ 'status' => 'skipped', 'post_id' => null, 'reason' => 'locked' ];
    }
    try {
        $post_id = aco_re_get_post_id_by_record_id( $record_id );
        $action = 'update';

        if ( $post_id ) { // --- UPDATE PATH ---
            $stored_ms = (int) get_post_meta( $post_id, ACO_AT_LAST_MODIFIED_MS_META, true );
            if ( ! $stored_ms ) {
                $stored_iso_legacy = (string) get_post_meta( $post_id, ACO_AT_LAST_MODIFIED_META, true );
                if ( $stored_iso_legacy ) { list( $stored_ms_from_iso ) = aco_re_parse_last_modified( $stored_iso_legacy ); $stored_ms = (int) $stored_ms_from_iso; }
            }
            if ( $incoming_ms <= $stored_ms ) {
                aco_re_log( 'info', 'SKIP stale update.', [ 'record_id' => $record_id, 'post_id' => $post_id, 'action' => 'skip' ] );
                return [ 'status' => 'skipped', 'post_id' => $post_id, 'reason' => 'stale' ];
            }
            $update = [ 'ID' => $post_id ];
            if ( isset( $data['Title'] ) ) { $update['post_title'] = wp_strip_all_tags( $data['Title'] ); }
            if ( count( $update ) > 1 ) { wp_update_post( wp_slash( $update ), true, false ); }
            
        } else { // --- CREATE PATH ---
            $action = 'create';
            $title = $data['Title'] ?? 'Resource ' . $record_id;
            $content = $data['Content'] ?? '';
            $insert = [ 'post_type' => 'resource', 'post_status' => 'publish', 'post_title' => wp_strip_all_tags( $title ), 'post_content' => wp_kses_post( $content ) ];
            $post_id = wp_insert_post( wp_slash( $insert ), true );
            if ( is_wp_error( $post_id ) ) {
                aco_re_log( 'error', 'Create failed.', [ 'record_id' => $record_id, 'error' => $post_id->get_error_message(), 'action' => 'create' ] );
                return [ 'status' => 'error', 'post_id' => null, 'reason' => 'create_failed' ];
            }
        }

        if ( is_wp_error( $post_id ) || ! ( $post_id > 0 ) ) {
             // This case should be caught by the create path error, but as a safeguard:
            aco_re_log( 'error', 'Invalid post ID after create/update.', [ 'record_id' => $record_id, 'action' => $action ] );
            return [ 'status' => 'error', 'post_id' => null, 'reason' => 'invalid_post_id' ];
        }

        // --- COMMON LOGIC FOR BOTH CREATE AND UPDATE ---
        // Meta fields
        update_post_meta( $post_id, ACO_AT_RECORD_ID_META, $record_id ); // Ensure this is always set
        update_post_meta( $post_id, '_aco_summary', isset( $data['Summary'] ) ? sanitize_textarea_field( $data['Summary'] ) : '' );
        update_post_meta( $post_id, '_aco_document_date', ( isset( $data['DocumentDate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['DocumentDate'] ) ) ? $data['DocumentDate'] : '' );
        update_post_meta( $post_id, ACO_AT_LAST_MODIFIED_META, $incoming_iso );
        update_post_meta( $post_id, ACO_AT_LAST_MODIFIED_MS_META, $incoming_ms );

        // Taxonomy fields
        $log_ctx = [ 'record_id' => $record_id, 'post_id' => (int) $post_id, 'action' => $action ];
        $type_names = ( ! empty( $data['Type'] ) && is_string( $data['Type'] ) ) ? [ $data['Type'] ] : [];
        _aco_re_set_post_terms_from_names( (int) $post_id, 'resource_type', $type_names, $log_ctx, false );
        $tag_names = ( ! empty( $data['Tags'] ) && is_array( $data['Tags'] ) ) ? $data['Tags'] : [];
        _aco_re_set_post_terms_from_names( (int) $post_id, 'universal_tag', $tag_names, $log_ctx, true );

        // File handling
        if ( array_key_exists( 'FileURL', $data ) ) {
            $file_url = is_string( $data['FileURL'] ) ? trim( $data['FileURL'] ) : '';
            if ( $file_url !== '' ) {
                $file_result = _aco_re_sideload_and_attach_file( (int) $post_id, $file_url );
                if ( is_wp_error( $file_result ) ) {
                    $log_ctx['error']   = $file_result->get_error_message();
                    $log_ctx['payload'] = [ 'FileURL' => $file_url ];
                    aco_re_log( 'warning', 'File handling failed.', $log_ctx );
                }
            } else {
                aco_re_clear_primary_attachment( (int) $post_id );
                aco_re_log( 'info', 'Cleared primary attachment due to empty FileURL.', [ 'record_id' => $record_id, 'post_id'   => (int) $post_id, 'action' => 'file_clear' ] );
            }
        }
        
        // --- Enqueue SearchWP reindex on successful upsert ---
        if ( has_action( 'searchwp\\index\\enqueue' ) && $post_id ) {
            do_action( 'searchwp\\index\\enqueue', 'post', (int) $post_id );
        }

        // --- LOG FINAL STATUS ---
        if ( $action === 'create' ) {
            aco_re_log( 'info', 'ACCEPTED CREATE.', [ 'record_id' => $record_id, 'post_id' => (int) $post_id, 'action' => 'create' ] );
            return [ 'status' => 'created', 'post_id' => (int) $post_id, 'reason' => null ];
        } else {
            aco_re_log( 'info', 'ACCEPTED UPDATE.', [ 'record_id' => $record_id, 'post_id' => $post_id, 'action' => 'update' ] );
            return [ 'status' => 'updated', 'post_id' => $post_id, 'reason' => null ];
        }

    } catch ( Throwable $e ) {
        aco_re_log( 'error', 'Unhandled exception.', [ 'record_id' => $record_id, 'exception' => $e->getMessage(), 'action' => 'exception' ] );
        return [ 'status' => 'error', 'post_id' => null, 'reason' => 'exception' ];
    } finally {
        aco_re_release_lock( $record_id );
    }
}


// --- Nightly Delta Sync (Spec-aligned, safe) ---
// Requires env vars per Implementation Spec v1 §3: ACO_AT_API_KEY, ACO_AT_BASE_ID, ACO_AT_TABLE_RESOURCES.

/**
 * Central helper to retrieve configuration values.
 * It checks for a defined constant first, then falls back to an environment variable.
 */
function _aco_re_get_config( string $key ) {
    // Prefer constants defined in wp-config.php
    if ( defined( $key ) ) {
        $val = constant( $key );
        if ( $val !== null && $val !== '' ) {
            return $val;
        }
    }
    // Fallbacks for environments where getenv() may be empty under WP-CLI wrappers
    foreach ( [ $_ENV, $_SERVER ] as $src ) {
        if ( isset( $src[ $key ] ) && $src[ $key ] !== '' ) {
            return $src[ $key ];
        }
    }
    $env = getenv( $key );
    return ( $env !== false && $env !== '' ) ? $env : null;
}

/**
 * Small Airtable helper for making GET requests (Patched to use _aco_re_get_config).
 */
function _aco_re_airtable_get( string $table_id, array $query = [] ) {
    $api_key = _aco_re_get_config( 'ACO_AT_API_KEY' );
    $base_id = _aco_re_get_config( 'ACO_AT_BASE_ID' );
    if ( ! $api_key || ! $base_id || ! $table_id ) {
        return new WP_Error( 'aco_re_missing_creds', 'Airtable API credentials are not configured.' );
    }

    $endpoint = "https://api.airtable.com/v0/{$base_id}/{$table_id}";
    $url      = add_query_arg( $query, $endpoint );

    $args = [
        'headers'     => [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/json',
        ],
        'timeout'     => 20,
        'redirection' => 3,
    ];

    $res = wp_remote_get( $url, $args );
    if ( is_wp_error( $res ) ) {
        return $res;
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = (string) wp_remote_retrieve_body( $res );

    if ( 200 !== $code ) {
        $snippet = mb_substr( trim( $body ), 0, 300 );
        return new WP_Error( 'aco_re_api_error', sprintf( 'Airtable API error %d: %s', $code, $snippet ) );
    }

    $data = json_decode( $body, true );
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'aco_re_bad_json', 'Invalid JSON from Airtable.' );
    }

    return $data;
}

/**
 * Main orchestrator for the nightly sync cron job.
 */
function aco_re_run_nightly_delta_sync() {
    $start_ts = time();
    $sync_id  = uniqid('sync_', true);
    $summary  = [
        'enqueued_upserts' => 0,
        'deleted'          => 0,
        'errors'           => [],
        'start_time'       => gmdate('c', $start_ts),
        'end_time'         => '',
        'duration_s'       => 0,
    ];

    aco_re_log( 'info', 'Nightly sync started.', [ 'source' => 'nightly', 'sync_id' => $sync_id ] );

    try {
        $upserts = _aco_re_nightly_sync_upserts( $sync_id );
        if ( is_wp_error( $upserts ) ) { throw new Exception( $upserts->get_error_message() ); }
        $summary['enqueued_upserts'] = (int) $upserts['enqueued'];

        $deletes = _aco_re_nightly_sync_deletes( $sync_id );
        if ( is_wp_error( $deletes ) ) { throw new Exception( $deletes->get_error_message() ); }
        $summary['deleted'] = (int) $deletes['deleted'];

        update_option( 'aco_re_nightly_sync_cursor', $summary['start_time'] );
        aco_re_log( 'info', 'Nightly sync completed.', [ 'source' => 'nightly', 'sync_id' => $sync_id, 'summary' => $summary ] );

    } catch ( Throwable $e ) {
        $summary['errors'][] = $e->getMessage();
        aco_re_log( 'error', 'Nightly sync failed: ' . $e->getMessage(), [ 'source' => 'nightly', 'sync_id' => $sync_id, 'exception' => $e->getMessage() ] );
    }

    $summary['end_time']   = gmdate( 'c' );
    $summary['duration_s'] = max( 0, time() - $start_ts );
    _aco_re_nightly_sync_send_summary_email( $summary );
}

/**
 * Stage 1: Fetches new/updated records and enqueues them for processing.
 */
function _aco_re_nightly_sync_upserts( string $sync_id ) {
    $table_id = _aco_re_get_config( 'ACO_AT_TABLE_RESOURCES' );
    if ( ! $table_id ) {
        return new WP_Error( 'aco_re_missing_table', 'ACO_AT_TABLE_RESOURCES is not configured.' );
    }

    $cursor_iso = get_option( 'aco_re_nightly_sync_cursor', '' );
    if ( empty( $cursor_iso ) ) {
        $cursor_iso = gmdate( 'c', time() - DAY_IN_SECONDS );
    }

    // Schema-agnostic: filter by computed LAST_MODIFIED_TIME() and do NOT restrict fields.
    $query  = [
        'pageSize'        => 100,
        'filterByFormula' => sprintf( 'IS_AFTER(LAST_MODIFIED_TIME(), "%s")', $cursor_iso ),
        // Intentionally no 'fields' param to avoid 422 when names differ.
    ];

    $enqueued  = 0;
    $guard_max = (int) apply_filters( 'aco_re_nightly_sync_max_items', 5000 );
    $seen      = 0;
    $offset    = null;

    do {
        if ( $offset ) {
            $query['offset'] = $offset;
        } else {
            unset( $query['offset'] );
        }

        $data = _aco_re_airtable_get( $table_id, $query );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $records = ( isset( $data['records'] ) && is_array( $data['records'] ) ) ? $data['records'] : [];
        foreach ( $records as $rec ) {
            if ( empty( $rec['id'] ) || empty( $rec['fields'] ) || ! is_array( $rec['fields'] ) ) {
                continue;
            }

            $f = is_array( $rec['fields'] ?? null ) ? $rec['fields'] : [];

            // Robust getters with common fallbacks
            $title        = aco_re_get_first_string( $f, [ 'Title', 'Name' ] );
            $summary      = aco_re_get_first_string( $f, [ 'Summary', 'Description', 'Notes' ] );
            $file_url     = aco_re_get_first_string( $f, [ 'FileURL', 'File Url', 'File URL', 'URL' ] );
            $type_value   = aco_re_get_first_string( $f, [ 'Type', 'Resource Type', 'Types' ] );
            $documentDate = aco_re_get_first_string( $f, [ 'DocumentDate', 'Document Date', 'Date' ] );
            $lastModified = aco_re_get_first_string( $f, [ 'LastModified', 'Last Modified', 'Last modified time' ] );

            // Tags can be either an array or a comma-separated string
            $tags = [];
            if ( isset( $f['Tags'] ) ) {
                if ( is_array( $f['Tags'] ) ) {
                    $tags = array_values( array_filter( array_map( 'strval', $f['Tags'] ) ) );
                } elseif ( is_string( $f['Tags'] ) ) {
                    $tags = array_values( array_filter( array_map( 'trim', explode( ',', $f['Tags'] ) ) ) );
                }
            }

            // Fallback for last modified (we filtered by LAST_MODIFIED_TIME() already)
            if ( $lastModified === '' ) {
                $lastModified = gmdate( 'c' );
            }

            $payload = [
                'record_id'    => (string) $rec['id'],
                'Title'        => (string) $title,
                'Summary'      => (string) $summary,
                'FileURL'      => (string) $file_url,
                'Type'         => (string) $type_value,
                'DocumentDate' => (string) $documentDate,
                'LastModified' => (string) $lastModified,
                'Tags'         => $tags,
            ];

            if ( aco_re_enqueue_sync_job( $payload ) ) {
                $enqueued++;
            }
        }

        $seen   += count( $records );
        $offset  = $data['offset'] ?? null;

        if ( $seen >= $guard_max ) {
            aco_re_log(
                'warning',
                'Nightly upserts capped by guard_max.',
                [ 'source' => 'nightly', 'sync_id' => $sync_id, 'guard_max' => $guard_max ]
            );
            break;
        }
    } while ( $offset );

    aco_re_log(
        'info',
        'Stage 1 (Upserts) completed.',
        [ 'source' => 'nightly', 'sync_id' => $sync_id, 'enqueued' => $enqueued, 'cursor' => $cursor_iso ]
    );
    return [ 'enqueued' => $enqueued ];
}

/**
 * Stage 2: Finds and soft-deletes WordPress records that are no longer in Airtable.
 */
function _aco_re_nightly_sync_deletes( string $sync_id ) {
    global $wpdb;
    $table_id = _aco_re_get_config( 'ACO_AT_TABLE_RESOURCES' );
    if ( ! $table_id ) return new WP_Error( 'aco_re_missing_table', 'ACO_AT_TABLE_RESOURCES is not configured.' );

    $airtable_ids = [];
    // No fields restriction; we only need IDs and want to avoid 422 on unknown field names.
    $query = [ 'pageSize' => 100 ];
    $offset = null;

    do {
        if ( $offset ) { $query['offset'] = $offset; } else { unset( $query['offset'] ); }
        $data = _aco_re_airtable_get( $table_id, $query );
        if ( is_wp_error( $data ) ) { return $data; }

        $records = isset( $data['records'] ) && is_array( $data['records'] ) ? $data['records'] : [];
        foreach ( $records as $rec ) {
            if ( isset( $rec['id'] ) ) { $airtable_ids[$rec['id']] = true; }
        }
        $offset = isset( $data['offset'] ) ? $data['offset'] : null;
    } while ( $offset );

    if ( empty( $airtable_ids ) ) {
        aco_re_log( 'warning', 'Stage 2 aborted: Airtable returned zero IDs. No records will be deleted.', [ 'source' => 'nightly', 'sync_id' => $sync_id ] );
        return [ 'deleted' => 0 ];
    }

    $wp_map = [];
    $batch  = 2000;
    $last_id = 0;

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value AS record_id
                 FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = %s AND pm.post_id > %d
                 ORDER BY pm.post_id ASC
                 LIMIT %d",
                ACO_AT_RECORD_ID_META,
                $last_id,
                $batch
            )
        );
        if ( empty( $rows ) ) { break; }
        foreach ( $rows as $r ) {
            $wp_map[ (string) $r->record_id ] = (int) $r->post_id;
            $last_id = (int) $r->post_id;
        }
    } while ( count( $rows ) === $batch );

    $to_delete = array_diff_key( $wp_map, $airtable_ids );
    $deleted = 0;

    foreach ( $to_delete as $record_id => $post_id ) {
        $p = get_post( $post_id );
        if ( ! $p || $p->post_type !== 'resource' || $p->post_status !== 'publish' ) { continue; }

        $ok = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'private' ], true );
        if ( ! is_wp_error( $ok ) ) {
            update_post_meta( $post_id, '_aco_sync_deleted_on', gmdate( 'c' ) );
            $deleted++;
            aco_re_log( 'info', 'Soft-deleted resource.', [ 'source' => 'nightly', 'sync_id' => $sync_id, 'post_id' => $post_id, 'record_id' => $record_id ] );
        } else {
            aco_re_log( 'warning', 'Soft-delete failed.', [ 'source' => 'nightly', 'sync_id' => $sync_id, 'post_id' => $post_id, 'record_id' => $record_id, 'error' => $ok->get_error_message() ] );
        }
    }

    aco_re_log( 'info', 'Stage 2 (Deletes) completed.', [ 'source' => 'nightly', 'sync_id' => $sync_id, 'deleted_count' => $deleted ] );
    return [ 'deleted' => $deleted ];
}

/**
 * Sends the nightly sync summary email.
 */
function _aco_re_nightly_sync_send_summary_email( array $summary ) {
    $to = apply_filters( 'aco_re_nightly_sync_email_to', get_option( 'admin_email' ) );
    $subject = sprintf(
        '[%s] Airtable Nightly Sync: %s',
        wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        empty( $summary['errors'] ) ? 'Success' : 'FAILED'
    );

    $body  = "Airtable to WordPress nightly sync completed.

";
    $body .= "Start:    {$summary['start_time']} UTC
";
    $body .= "End:      {$summary['end_time']} UTC
";
    $body .= "Duration: {$summary['duration_s']} seconds
";
    $body .= "-----------------------------------------
";
    $body .= "Upserts enqueued: {$summary['enqueued_upserts']}
";
    $body .= "Soft-deleted:     {$summary['deleted']}
";
    if ( ! empty( $summary['errors'] ) ) {
        $body .= "-----------------------------------------
Errors:
";
        foreach ( (array) $summary['errors'] as $e ) { $body .= "- {$e}
"; }
    }

    wp_mail( $to, $subject, $body );
}

/**
 * Schedules the nightly sync to run at 02:30 UTC.
 */
function aco_re_schedule_nightly_sync() {
    if ( ! wp_next_scheduled( 'aco_re_nightly_delta_sync_hook' ) ) {
        $ts = strtotime( 'tomorrow 02:30:00 UTC' );
        wp_schedule_event( $ts, 'daily', 'aco_re_nightly_delta_sync_hook' );
    }
}

/**
 * Clears the nightly sync cron job on deactivation.
 */
function aco_re_clear_nightly_sync() {
    wp_clear_scheduled_hook( 'aco_re_nightly_delta_sync_hook' );
}


// --- Hook Registrations ---

// --- Activation / Deactivation / Uninstall Hooks ---

register_activation_hook( __FILE__, function () {
    aco_re_create_sync_log_table();
    if ( $admin_role = get_role( 'administrator' ) ) {
        $admin_role->add_cap( 'aco_manage_failover' );
    }
    add_option( 'aco_re_seed_terms_pending', true );
    if ( ! wp_next_scheduled( 'aco_re_hourly_tag_sync_hook' ) ) {
        wp_schedule_event( time(), 'hourly', 'aco_re_hourly_tag_sync_hook' );
    }
    aco_re_schedule_nightly_sync(); // Add this line
    aco_re_register_content_models();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'aco_re_hourly_tag_sync_hook' );
    aco_re_clear_nightly_sync(); // Add this line
    flush_rewrite_rules();
} );

register_uninstall_hook( __FILE__, 'aco_re_on_uninstall' );


// --- Main Plugin Hooks ---

add_action( 'init', 'aco_re_register_content_models' );
add_action( 'init', 'aco_re_seed_terms_conditionally', 20 );
add_action( 'init', 'aco_re_ensure_admin_capability', 1 );
add_action( 'init', 'aco_re_register_log_purge_cron' );
add_action( 'aco_re_daily_log_purge', 'aco_re_purge_old_logs' );
add_action( 'aco_re_nightly_delta_sync_hook', 'aco_re_run_nightly_delta_sync' ); // Add this line

add_action( 'admin_init', 'aco_re_register_settings' );
add_action( 'admin_init', 'aco_re_register_tag_governance_settings' );
add_action( 'admin_init', 'aco_re_handle_manual_tag_refresh' );

add_action( 'plugins_loaded', 'aco_re_load_text_domain' );
add_action( 'admin_menu', 'aco_re_register_settings_page' );
add_action( 'admin_enqueue_scripts', 'aco_re_enqueue_linter_script' );
add_action( 'wp_dashboard_setup', 'aco_re_register_dashboard_widget' );
add_action( 'aco_re_hourly_tag_sync_hook', 'aco_re_refresh_tag_allowlist' );
add_action( 'rest_api_init', 'aco_re_register_webhook_endpoint' );
add_action( 'aco_re_process_resource_sync', 'aco_re_process_resource_sync_action' );

add_filter( 'rest_pre_insert_resource', 'aco_re_validate_tags_on_rest_save', 10, 2 );
add_filter( 'wp_get_attachment_url', 'aco_re_failover_url_swap', 99 );
add_filter( 'wp_calculate_image_srcset', 'aco_re_failover_srcset_swap', 99 );
add_filter( 'wp_get_attachment_image_attributes', 'aco_re_failover_img_attr_swap', 99 );
add_filter( 'site_status_tests', 'aco_re_add_site_health_test' );
add_action('plugins_loaded', function() {
    if (function_exists('acf_is_pro') && acf_is_pro()) {
        add_filter('acf/settings/remove_wp_meta_box', '__return_false');
    }
});

// --- START: ACO Sync Log Admin Viewer ---

// 3. Add Screen Options
function aco_re_log_viewer_screen_options() {
    $option = 'per_page';
    $args   = [
        'label'   => __( 'Log entries per page', 'fpm-aco-resource-engine' ),
        'default' => 20,
        'option'  => 'aco_sync_log_per_page'
    ];
    add_screen_option( $option, $args );
    // We instantiate the table here so the screen options are registered.
    new ACO_Sync_Log_List_Table();
}


// 4. Ensure WP_List_Table is available.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Consolidated list table with Replay support.
 */
class ACO_Sync_Log_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Log Entry', 'fpm-aco-resource-engine' ),
            'plural'   => __( 'Log Entries', 'fpm-aco-resource-engine' ),
            'ajax'     => false
        ] );
    }
    
    public function no_items() {
        esc_html_e( 'No log entries found.', 'fpm-aco-resource-engine' );
    }

    public function get_columns() {
        return [
            'ts'          => __( 'Timestamp', 'fpm-aco-resource-engine' ),
            'source'      => __( 'Source', 'fpm-aco-resource-engine' ),
            'record_id'   => __( 'Airtable Record ID', 'fpm-aco-resource-engine' ),
            'status'      => __( 'Status', 'fpm-aco-resource-engine' ),
            'action'      => __( 'Action', 'fpm-aco-resource-engine' ),
            'message'     => __( 'Message', 'fpm-aco-resource-engine' ),
            'resource_id' => __( 'Resource ID', 'fpm-aco-resource-engine' ),
        ];
    }

    public function get_sortable_columns() {
        return [
            'ts'     => [ 'ts', true ],
            'source' => [ 'source', false ],
            'status' => [ 'status', false ],
            'action' => [ 'action', false ],
        ];
    }
    
    protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $wpdb;
		$table_name = $wpdb->prefix . 'aco_sync_log';

		$statuses       = $wpdb->get_col( "SELECT DISTINCT status FROM {$table_name} ORDER BY status ASC" );
		$current_status = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';

		$sources        = $wpdb->get_col( "SELECT DISTINCT source FROM {$table_name} ORDER BY source ASC" );
		$current_source = isset( $_GET['source_filter'] ) ? sanitize_text_field( $_GET['source_filter'] ) : '';

		echo '<div class="alignleft actions">';

		echo '<select name="status_filter">';
		echo '<option value="">' . esc_html__( 'All Statuses', 'fpm-aco-resource-engine' ) . '</option>';
		foreach ( $statuses as $status ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $status ), selected( $current_status, $status, false ), esc_html( ucfirst( $status ) ) );
		}
		echo '</select>';

		echo '<select name="source_filter">';
		echo '<option value="">' . esc_html__( 'All Sources', 'fpm-aco-resource-engine' ) . '</option>';
		foreach ( $sources as $source ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $source ), selected( $current_source, $source, false ), esc_html( ucfirst( $source ) ) );
		}
		echo '</select>';

		submit_button( __( 'Filter', 'fpm-aco-resource-engine' ), 'secondary', 'filter_action', false, [ 'id' => 'post-query-submit' ] );

		// Add the nonce for security
		wp_nonce_field( 'aco_export_sync_log_nonce', 'aco_export_nonce' );
		// Add the export button
		submit_button( __( 'Export to CSV', 'fpm-aco-resource-engine' ), 'secondary', 'export_csv', false );

		echo '</div>';
	}

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aco_sync_log';

        $per_page = $this->get_items_per_page( 'aco_sync_log_per_page', 20 );
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

        $where_clauses = [];
        // Filters
        if ( ! empty( $_GET['status_filter'] ) ) {
            $where_clauses[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $_GET['status_filter'] ) );
        }
        if ( ! empty( $_GET['source_filter'] ) ) {
            $where_clauses[] = $wpdb->prepare( 'source = %s', sanitize_text_field( $_GET['source_filter'] ) );
        }
        // Search
        if ( ! empty( $_GET['s'] ) ) {
             $search_term = '%' . $wpdb->esc_like( sanitize_text_field( $_GET['s'] ) ) . '%';
             $where_clauses[] = $wpdb->prepare( '(record_id LIKE %s OR message LIKE %s)', $search_term, $search_term );
        }

        $where_sql = count( $where_clauses ) > 0 ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

        // Harden ORDER BY with a strict whitelist.
        $allowed_orderby = [ 'ts', 'source', 'status', 'action' ];
        $orderby_param = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'ts';
        $orderby = in_array( $orderby_param, $allowed_orderby, true ) ? $orderby_param : 'ts';

        // Harden order direction.
        $order_param = isset( $_GET['order'] ) ? strtolower( $_GET['order'] ) : 'desc';
        $order = in_array( $order_param, [ 'asc', 'desc' ], true ) ? $order_param : 'desc';

        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} {$where_sql}" );
        
        $this->items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );
        
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ] );
    }

    public function column_default( $item, $column_name ) {
        return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
    }
    
    public function column_ts( $item ) {
        $timestamp = strtotime( $item['ts'] );
        if ( ! $timestamp ) return '';
        // Use wp_date for correct timezone and localization.
        return esc_html( wp_date( 'Y-m-d H:i:s', $timestamp ) );
    }

    public function column_status( $item ) {
        $status = isset( $item['status'] ) ? $item['status'] : '';
        $color = '#646970'; // Default grey
        if ( $status === 'success' ) $color = '#00a32a';
        if ( $status === 'warning' ) $color = '#dba617';
        if ( $status === 'failed' || $status === 'error' ) $color = '#d63638';
        
        return sprintf( '<span style="color:%s; font-weight:bold;">%s</span>', esc_attr( $color ), esc_html( ucfirst( $status ) ) );
    }

    public function column_resource_id( $item ) {
        $id = isset( $item['resource_id'] ) ? absint( $item['resource_id'] ) : 0;
        if ( $id > 0 && get_post_status( $id ) ) {
            $url = get_edit_post_link( $id );
            return sprintf( '<a href="%s">%d</a>', esc_url( $url ), $id );
        }
        return 'N/A';
    }

    public function column_message( $item ) {
        $raw_data = $item['message'] ?? '';
        $data = aco_re_safe_parse_message( $raw_data );
        $message = isset( $data['message'] ) && is_string( $data['message'] ) 
                   ? $data['message'] 
                   : __( 'Could not read message.', 'fpm-aco-resource-engine' );

        return esc_html( $message );
    }
    
    public function column_record_id( $item ) {
        $record_id = isset( $item['record_id'] ) ? esc_html( $item['record_id'] ) : '';
        $actions = [];

        $status = $item['status'] ?? '';
        if ( in_array( $status, [ 'failed', 'error' ], true ) && ! empty( $item['id'] ) ) {
            $replay_url = wp_nonce_url( add_query_arg( [
                'page'    => 'aco-sync-log',
                'action'  => 'replay',
                'log_id'  => $item['id'],
            ], admin_url( 'tools.php' ) ), 'aco_replay_log_' . $item['id'] );
            
            $actions['replay'] = sprintf( '<a href="%s">%s</a>', esc_url( $replay_url ), __( 'Replay', 'fpm-aco-resource-engine' ) );
        }

        return $record_id . $this->row_actions( $actions );
    }
}

// 1. Register the admin page under the "Tools" menu. (Consolidated)
function aco_re_register_log_viewer_page() {
    $hook_suffix = add_management_page(
        __( 'ACO Sync Log', 'fpm-aco-resource-engine' ),
        __( 'ACO Sync Log', 'fpm-aco-resource-engine' ),
        'manage_options',
        'aco-sync-log',
        'aco_re_render_log_viewer_page'
    );
    // Action to add screen options
    add_action( "load-{$hook_suffix}", 'aco_re_log_viewer_screen_options' );
}
add_action( 'admin_menu', 'aco_re_register_log_viewer_page' );

// 2. Render the admin page. (Consolidated)
function aco_re_render_log_viewer_page() {
    // Re-check capability as a defense-in-depth measure.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fpm-aco-resource-engine' ) );
    }

    $log_list_table = new ACO_Sync_Log_List_Table();
    $log_list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Airtable to WP Sync Log', 'fpm-aco-resource-engine' ); ?></h1>
        <p><?php esc_html_e( 'This log shows the history of operations triggered by Airtable.', 'fpm-aco-resource-engine' ); ?></p>
        <form method="get">
            <input type="hidden" name="page" value="aco-sync-log" />
            <?php
            $log_list_table->search_box( __( 'Search Logs', 'fpm-aco-resource-engine' ), 'aco-sync-log-search' );
            $log_list_table->display();
            ?>
        </form>
    </div>
    <?php
}
// --- END: ACO Sync Log Admin Viewer ---

// --- START: Replay Failed Jobs (Hardened Version) ---

/**
 * Make arbitrary values safe for CSV (mitigate Excel/LibreOffice formula injection).
 * - Coerce to string; normalise newlines to \n (fputcsv handles quoting).
 * - Prefix apostrophe if the value begins with =, +, -, @
 */
function aco_re_csv_safe_cell( $value ) {
	if ( is_null( $value ) ) {
		$value = '';
	} elseif ( is_bool( $value ) ) {
		$value = $value ? '1' : '0';
	} elseif ( is_array( $value ) || is_object( $value ) ) {
		$value = wp_json_encode( $value );
	} else {
		$value = (string) $value;
	}

	$value = str_replace( [ "\r\n", "\r" ], "\n", $value );

	if ( $value !== '' ) {
		$first = substr( $value, 0, 1 );
		if ( in_array( $first, [ '=', '+', '-', '@' ], true ) ) {
			$value = "'" . $value; // Leading apostrophe is respected by Excel.
		}
	}
	return $value;
}

/**
 * Handles the trigger for exporting the sync log to a CSV file.
 * This function listens for a specific GET parameter, verifies security,
 * and then calls the logic to generate and stream the file.
 */
function aco_re_handle_csv_export() {
	// Gate: only run on our log page & when requested.
	if ( ! isset( $_GET['page'], $_GET['export_csv'] ) || 'aco-sync-log' !== $_GET['page'] ) {
		return;
	}

	// Capability + CSRF.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'fpm-aco-resource-engine' ), esc_html__( 'Access denied', 'fpm-aco-resource-engine' ), [ 'response' => 403, 'back_link' => true ] );
	}
	check_admin_referer( 'aco_export_sync_log_nonce', 'aco_export_nonce' );

	// Fail fast if headers already sent.
	if ( headers_sent() ) {
		wp_die( esc_html__( 'Cannot stream CSV: headers already sent by another output.', 'fpm-aco-resource-engine' ) );
	}

	// Performance safety nets for larger exports.
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
	ignore_user_abort( true );
	@set_time_limit( 0 );

	global $wpdb;
	$table_name = $wpdb->prefix . 'aco_sync_log';

	// Build WHERE (mirrors prepare_items()).
	$where_clauses = [];
	if ( ! empty( $_GET['status_filter'] ) ) {
		$where_clauses[] = $wpdb->prepare( 'status = %s', sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) );
	}
	if ( ! empty( $_GET['source_filter'] ) ) {
		$where_clauses[] = $wpdb->prepare( 'source = %s', sanitize_text_field( wp_unslash( $_GET['source_filter'] ) ) );
	}
	if ( ! empty( $_GET['s'] ) ) {
		$search_term   = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
		$where_clauses[] = $wpdb->prepare( '(record_id LIKE %s OR message LIKE %s)', $search_term, $search_term );
	}
	$where_sql = $where_clauses ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

	// ORDER BY (whitelist).
	$allowed_orderby = [ 'ts', 'source', 'status', 'action' ];
	$orderby_param   = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'ts';
	$orderby         = in_array( $orderby_param, $allowed_orderby, true ) ? $orderby_param : 'ts';
	$order_param     = isset( $_GET['order'] ) ? strtolower( (string) $_GET['order'] ) : 'desc';
	$order           = in_array( $order_param, [ 'asc', 'desc' ], true ) ? $order_param : 'desc';

	// Headers.
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	$filename = 'aco-sync-log-' . gmdate( 'Y-m-d-His' ) . '.csv';
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	// Open output stream.
	$out = fopen( 'php://output', 'w' );

	// Column set: include all key columns (spec parity).
	$column_keys = [
		'id', 'ts', 'source', 'record_id', 'last_modified', 'action',
		'resource_id', 'attachment_id', 'fingerprint', 'status',
		'attempts', 'duration_ms', 'error_code', 'message'
	];
	fputcsv( $out, $column_keys );

	// Chunked stream to keep memory bounded.
	$limit  = 1000;
	$offset = 0;

	do {
		$sql  = $wpdb->prepare(
			"SELECT * FROM {$table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			break;
		}

		foreach ( $rows as $row ) {
			// Extract a human-readable message, safely.
			$parsed        = aco_re_safe_parse_message( $row['message'] ?? '' );
			$row['message'] = ( isset( $parsed['message'] ) && is_string( $parsed['message'] ) ) ? $parsed['message'] : '';

			// Build row in the defined column order with CSV-safe cells.
			$csv = [];
			foreach ( $column_keys as $key ) {
				$csv[] = aco_re_csv_safe_cell( $row[ $key ] ?? '' );
			}
			fputcsv( $out, $csv );
		}

		// Advance window.
		$offset += $limit;
		// Flush to the client to avoid buffering.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
		if ( function_exists( 'ob_flush' ) ) {
			@ob_flush();
		}
	} while ( count( $rows ) === $limit );

	fclose( $out );
	exit;
}

function aco_re_safe_parse_message( $raw_data ): array {
	if ( is_array( $raw_data ) ) {
		return $raw_data;
	}
	if ( ! is_string( $raw_data ) || $raw_data === '' ) {
		return [];
	}

	// Try JSON first (docstring intent).
	$trim = ltrim( $raw_data );
	if ( $trim !== '' && ( $trim[0] === '{' || $trim[0] === '[' ) ) {
		$json = json_decode( $raw_data, true );
		if ( is_array( $json ) ) {
			return $json;
		}
	}

	// Fallback: PHP serialised array, forbid objects.
	if ( strpos( $raw_data, 'a:' ) === 0 && strpos( $raw_data, '{' ) !== false ) {
		$data = @unserialize( $raw_data, [ 'allowed_classes' => false ] );
		return is_array( $data ) ? $data : [];
	}

	return [];
}

/**
 * Handles the replay action, now with security and sanitization checks.
 */
function aco_re_handle_log_actions() {
    $page   = isset( $_GET['page'] )   ? sanitize_key( $_GET['page'] )   : '';
    $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
    $log_id = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] )       : 0;

    if ( $page !== 'aco-sync-log' || $action !== 'replay' ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'fpm-aco-resource-engine' ) );
    }
    
    if ( $log_id <= 0 ) {
        // Safely redirect for invalid ID.
        wp_safe_redirect( admin_url( 'tools.php?page=aco-sync-log' ) );
        exit;
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'aco_replay_log_' . $log_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'fpm-aco-resource-engine' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'aco_sync_log';
    $log_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $log_id ), ARRAY_A );

    if ( ! $log_entry ) {
        set_transient( 'aco_replay_notice_' . get_current_user_id(), [ 'type' => 'error', 'message' => __( 'Log entry not found.', 'fpm-aco-resource-engine' ) ] );
    } else {
        $data    = aco_re_safe_parse_message( $log_entry['message'] );
        $context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
        $payload = $context['payload'] ?? null;
        
        if ( $payload && function_exists( 'as_enqueue_async_action' ) ) {
            // Enqueue the correct action hook name used by the worker.
            as_enqueue_async_action( 'aco_re_process_resource_sync', [ 'payload' => $payload ], 'aco_resource_sync' );
            $message = sprintf( 
                __( 'Job for Airtable Record ID %s has been re-queued.', 'fpm-aco-resource-engine' ), 
                '<strong>' . esc_html( $log_entry['record_id'] ) . '</strong>'
            );
            set_transient( 'aco_replay_notice_' . get_current_user_id(), [ 'type' => 'success', 'message' => $message ] );
        } else {
            set_transient( 'aco_replay_notice_' . get_current_user_id(), [ 'type' => 'error', 'message' => __( 'Could not re-queue job. The payload was missing or Action Scheduler is inactive.', 'fpm-aco-resource-engine' ) ] );
        }
    }
    
    wp_safe_redirect( admin_url( 'tools.php?page=aco-sync-log' ) );
    exit;
}
add_action( 'admin_init', 'aco_re_handle_log_actions' );
add_action( 'admin_init', 'aco_re_handle_csv_export' );

/**
 * Displays the admin notice after a replay action.
 */
function aco_re_show_replay_notices() {
    $uid = get_current_user_id();
    $key = 'aco_replay_notice_' . ( $uid ?: 0 ); // fallback to 0 if ever needed
    if ( $notice = get_transient( $key ) ) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $notice['type'] ),
            wp_kses_post( $notice['message'] )
        );
        delete_transient( $key );
    }
}
add_action( 'admin_notices', 'aco_re_show_replay_notices' );

// --- END: Replay Failed Jobs (Hardened Version) ---

add_action('enqueue_block_editor_assets', function () {
    $path = plugin_dir_path(__FILE__) . 'admin/promote.js';
    if ( file_exists( $path ) ) {
        wp_enqueue_script(
            'aco-re-promote',
            plugin_dir_url(__FILE__) . 'admin/promote.js',
            [ 'wp-data', 'wp-dom-ready', 'wp-api-fetch', 'wp-notices' ],
            filemtime( $path ),
            true
        );
    }
});

// -- BEGIN: ACO Promote-to-Resource (v1) --

/**
 * Feature kill-switch (filterable).
 */
function aco_re_promote_enabled(): bool {
    return (bool) apply_filters('aco_re_promote_enabled', true);
}

/**
 * Capability: ensure on activation and defensively on init.
 */
register_activation_hook(__FILE__, function () {
    if ($r = get_role('administrator')) { $r->add_cap('aco_promote_resource'); }
    if ($r = get_role('editor'))        { $r->add_cap('aco_promote_resource'); }
});

// Ensure roles always have the promote capability (idempotent, cheap).
add_action('init', function () {
    if ($r = get_role('administrator')) { $r->add_cap('aco_promote_resource'); }
    if ($r = get_role('editor'))        { $r->add_cap('aco_promote_resource'); }
}, 1);

function aco_re_current_user_can_promote(): bool {
    $can = current_user_can('aco_promote_resource');
    return (bool) apply_filters('aco_can_promote', $can);
}

/**
 * Allowed source types (filterable). Default to posts only in v1.
 * Add Events later via the 'aco_re_promote_allowed_source_types' filter.
 */
function aco_re_get_promote_allowed_source_types(): array {
    $default = [ 'post' ];
    return (array) apply_filters('aco_re_promote_allowed_source_types', $default);
}

/**
 * Compute or retrieve the attachment fingerprint.
 * Prefers saved meta, otherwise constructs s3:<bucket>:<path>|<filesize>.
 */
function _aco_re_compute_attachment_fingerprint(int $attachment_id): string {
    $existing = (string) get_post_meta($attachment_id, '_aco_file_fingerprint', true);
    if ($existing !== '') {
        return $existing;
    }

    $off = function_exists('aco_re_get_offload_info_with_retry')
        ? aco_re_get_offload_info_with_retry($attachment_id)
        : null;

    $bucket = is_array($off) ? ($off['bucket'] ?? '') : '';
    $path   = is_array($off) ? ($off['path'] ?? '')   : '';

    $filesize = 0;
    $meta = wp_get_attachment_metadata($attachment_id);
    if (is_array($meta) && !empty($meta['filesize'])) {
        $filesize = (int) $meta['filesize'];
    }
    if (!$filesize) {
        $filepath = get_attached_file($attachment_id);
        if ($filepath && file_exists($filepath)) {
            $filesize = (int) filesize($filepath);
        }
    }

    if ($bucket && $path && $filesize > 0) {
        $fp = sprintf('s3:%s:%s|%d', $bucket, ltrim((string) $path, '/'), $filesize);
        update_post_meta($attachment_id, '_aco_file_fingerprint', $fp);
        return $fp;
    }
    return '';
}

/**
 * Airtable outbound (FileURL only), using project config and filterable table.
 */
if (!function_exists('_aco_re_airtable_post_fileurl')) :
function _aco_re_airtable_post_fileurl(string $file_url) {
    if (!function_exists('_aco_re_get_config')) { return; }

    $api_key = (string) _aco_re_get_config('ACO_AT_API_KEY');
    $base_id = (string) _aco_re_get_config('ACO_AT_BASE_ID');
    $table   = (string) _aco_re_get_config('ACO_AT_TABLE_RESOURCES_INBOX');
    if (!$table) {
        $table = (string) _aco_re_get_config('ACO_AT_TABLE_RESOURCES');
    }
    $table = (string) apply_filters('aco_re_airtable_outbound_table', $table);

    if (!$api_key || !$base_id || !$table) {
        if (function_exists('aco_re_log')) {
            aco_re_log('warning', 'Airtable outbound skipped: config missing.', [
                'source' => 'promote', 'action' => 'airtable_outbound'
            ]);
        }
        return;
    }

    $endpoint = "https://api.airtable.com/v0/{$base_id}/{$table}";
    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
        'body'    => wp_json_encode([ 'records' => [ [ 'fields' => [ 'FileURL' => $file_url ] ] ] ]),
    ];
    $res = wp_remote_post($endpoint, $args);

    if (function_exists('aco_re_log')) {
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $level = (is_wp_error($res) || $code < 200 || $code >= 300) ? 'warning' : 'info';
        $msg = $level === 'info' ? 'Airtable outbound ok.' : 'Airtable outbound failed.';
        aco_re_log($level, $msg, [ 'source' => 'promote', 'action' => 'airtable_outbound', 'http_code' => $code ?: 'WP_Error' ]);
    }
}
endif;

/**
 * REST: POST /aco/v1/promote
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'aco/v1', '/promote', [
        'methods'  => 'POST',

        // Use a Closure so the handler is always callable.
        'callback' => function ( WP_REST_Request $request ) {
            // Delegate to our controller if available.
            if ( function_exists( 'aco_re_promote_controller' ) ) {
                return aco_re_promote_controller( $request );
            }

            // Absolute fallback: clear, actionable 500 instead of "invalid handler".
            return new WP_Error(
                'aco_promote_handler_missing',
                __( 'Promote controller is not loaded. Check plugin load order and includes.', 'fpm-aco-resource-engine' ),
                [ 'status' => 500 ]
            );
        },

        // Permission check as a Closure; delegates if project helper exists, else safe fallback.
        'permission_callback' => function ( WP_REST_Request $request ) {
            if ( function_exists( 'aco_re_promote_permission_callback' ) ) {
                return aco_re_promote_permission_callback( $request );
            }

            // Fallback: honour feature flag and core caps (Admins/Editors with edit access on this post).
            $enabled = apply_filters( 'aco_re_promote_enabled', true );
            if ( ! $enabled ) {
                return new WP_Error( 'rest_forbidden', __( 'Promote is disabled.', 'fpm-aco-resource-engine' ), [ 'status' => 403 ] );
            }

            $post_id = (int) $request->get_param( 'postId' );
            if ( $post_id <= 0 ) {
                return new WP_Error( 'rest_invalid_param', __( 'postId must be a positive integer.', 'fpm-aco-resource-engine' ), [ 'status' => 400 ] );
            }

            if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'aco_promote_resource' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'You do not have permission to promote this resource.', 'fpm-aco-resource-engine' ), [ 'status' => 403 ] );
            }

            return true;
        },

        'args' => [
            'postId'       => [ 'type' => 'integer', 'required' => true ],
            'attachmentId' => [ 'type' => 'integer', 'required' => true ],
            'tags'         => [ 'type' => 'array',   'required' => false, 'items' => [ 'type' => 'string' ] ],
            'resourceType' => [ 'type' => 'string',  'required' => false ],
        ],
    ] );
} );

function aco_re_promote_permission_callback(WP_REST_Request $request) {
    if (!aco_re_promote_enabled()) {
        return new WP_Error('rest_forbidden', __('This feature is disabled.', 'fpm-aco-resource-engine'), [ 'status' => 403 ]);
    }

    if (function_exists('aco_re_reject_oversized_requests')) {
        $size_check = aco_re_reject_oversized_requests($request);
        if (is_wp_error($size_check)) { return $size_check; }
    }

    $nonce = (string) ($request->get_header('X-WP-Nonce') ?: '');
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('rest_forbidden', __('Bad or missing nonce.', 'fpm-aco-resource-engine'), [ 'status' => 403 ]);
    }
    if (!aco_re_current_user_can_promote()) {
        return new WP_Error('rest_forbidden', __('Insufficient permissions.', 'fpm-aco-resource-engine'), [ 'status' => 403 ]);
    }

    $post_id = (int) $request['postId'];
    if ($post_id && ! current_user_can('edit_post', $post_id)) {
        return new WP_Error('rest_forbidden', __('You cannot edit this post.', 'fpm-aco-resource-engine'), [ 'status' => 403 ]);
    }
    return true;
}

/**
 * Does the post contain a core/file block with this attachment ID?
 * 
 * Filter: aco_re_promote_block_walk_max_depth
 * Adjust the maximum recursion depth when scanning blocks for a core/file link.
 * Default 10. Return an integer >= 1.
 *
 * - Recurses innerBlocks
 * - Resolves reusable blocks (core/block → wp_block post via attrs.ref)
 * - Depth limit (filterable) + visited-set to avoid cycles
 * - Compares URL *paths* scheme/host-agnostically (now with rawurldecode())
 * - Accepts ?attachment_id=<id> form and basename suffix as last resort
 *
 * @param WP_Post $post
 * @param int     $attachment_id
 * @return bool
 */
function aco_re_post_has_file_block_with_id( WP_Post $post, int $attachment_id ): bool {
    $attachment_id = (int) $attachment_id;
    if ( $attachment_id <= 0 ) { return false; }

    $content = (string) $post->post_content;
    if ( $content === '' ) { return false; }

    // Candidates + canonical filename (host/CDN agnostic)
    $file_url = wp_get_attachment_url( $attachment_id );
    $page_url = get_attachment_link( $attachment_id );

    $basename = '';
    if ( $file_url ) {
        $p = wp_parse_url( $file_url, PHP_URL_PATH );
        if ( is_string( $p ) ) { $basename = basename( $p ); }
    }
    if ( $basename === '' ) {
        $local = get_attached_file( $attachment_id );
        if ( $local && file_exists( $local ) ) { $basename = basename( $local ); }
    }

    // Helpers: scheme/host agnostic comparison + attachment_id detector
    $same_path = static function( string $a, string $b ): bool {
        if ( $a === '' || $b === '' ) { return false; }
        $pa = wp_parse_url( $a ); $pb = wp_parse_url( $b );
        $path_a = isset( $pa['path'] ) ? rtrim( rawurldecode( $pa['path'] ), '/' ) : '';
        $path_b = isset( $pb['path'] ) ? rtrim( rawurldecode( $pb['path'] ), '/' ) : '';
        return $path_a !== '' && $path_a === $path_b;
    };
    $href_points_to_attachment = static function( string $href ) use ( $attachment_id ): bool {
        if ( $href === '' ) { return false; }
        $parts = wp_parse_url( $href );
        if ( empty( $parts['query'] ) ) { return false; }
        parse_str( $parts['query'], $q );
        return isset( $q['attachment_id'] ) && (int) $q['attachment_id'] === $attachment_id;
    };

    $blocks = parse_blocks( $content );
    if ( is_array( $blocks ) ) {
        /** Allow integrators to reduce/increase traversal depth (default 10). */
        $max_depth = (int) apply_filters( 'aco_re_promote_block_walk_max_depth', 10 );
        $visited_reusable = [];

        $walker = static function ( array $list, int $depth ) use (
            &$walker, $attachment_id, $file_url, $page_url, $basename, $max_depth, &$visited_reusable,
            $same_path, $href_points_to_attachment
        ): bool {
            if ( $depth > $max_depth ) { return false; }
            foreach ( $list as $b ) {
                $name = isset( $b['blockName'] ) ? (string) $b['blockName'] : '';

                if ( $name === 'core/file' ) {
                    $attrs = ( isset( $b['attrs'] ) && is_array( $b['attrs'] ) ) ? $b['attrs'] : [];
                    $id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
                    if ( $id === $attachment_id ) { return true; }

                    $href = isset( $attrs['href'] ) ? (string) $attrs['href'] : '';
                    if ( $href !== '' ) {
                        // 1) Exact path equality with file URL or attachment page URL (scheme/host agnostic)
                        if ( $file_url && $same_path( $href, $file_url ) ) { return true; }
                        if ( $page_url && $same_path( $href, $page_url ) ) { return true; }

                        // 2) attachment_id query param (e.g. /?attachment_id=123)
                        if ( $href_points_to_attachment( $href ) ) { return true; }

                        // 3) Last-resort: filename suffix for CDN/host swaps
                        if ( $basename !== '' ) {
                            $path = (string) wp_parse_url( $href, PHP_URL_PATH );
                            if ( $path && substr( $path, -strlen( $basename ) ) === $basename ) { return true; }
                        }
                    }
                }

                if ( $name === 'core/block' ) {
                    $attrs = ( isset( $b['attrs'] ) && is_array( $b['attrs'] ) ) ? $b['attrs'] : [];
                    $ref   = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
                    if ( $ref > 0 && empty( $visited_reusable[ $ref ] ) ) {
                        $visited_reusable[ $ref ] = true;
                        $ref_post = get_post( $ref );
                        if ( $ref_post instanceof WP_Post && $ref_post->post_type === 'wp_block' ) {
                            $ref_blocks = parse_blocks( (string) $ref_post->post_content );
                            if ( is_array( $ref_blocks ) && $walker( $ref_blocks, $depth + 1 ) ) { return true; }
                        }
                    }
                }

                if ( ! empty( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) {
                    if ( $walker( $b['innerBlocks'], $depth + 1 ) ) { return true; }
                }
            }
            return false;
        };

        if ( $walker( $blocks, 0 ) ) { return true; }
    }

    // Raw-content fallbacks (exact URL, attachment page URL, or filename / attachment_id param)
    if ( $file_url && strpos( $content, $file_url ) !== false ) { return true; }
    if ( $page_url && strpos( $content, $page_url ) !== false ) { return true; }
    if ( $attachment_id && preg_match( '#(?:\?|&)(?:attachment_id)=' . preg_quote( (string) $attachment_id, '#' ) . '\b#', $content ) ) { return true; }
    if ( $basename !== '' && preg_match( '#href=["\'][^"\']*' . preg_quote( $basename, '#' ) . '["\']#i', $content ) ) { return true; }

    return false;
}



/**
 * Controller: POST /aco/v1/promote
 *
 * Expects JSON body: { postId: int, attachmentId: int, tags?: string[], resourceType?: string }
 */
function aco_re_promote_controller( WP_REST_Request $request ) {
    $t_start = microtime( true );

    // ---- Params ----
    $post_id       = (int) $request->get_param( 'postId' );
    $attachment_id = (int) $request->get_param( 'attachmentId' );
    $tags          = (array) ( $request->get_param( 'tags' ) ?: [] );
    $resource_type = (string) ( $request->get_param( 'resourceType' ) ?: '' );

    if ( $post_id <= 0 || $attachment_id <= 0 ) {
        return new WP_Error( 'rest_invalid_param', __( 'postId and attachmentId are required.', 'fpm-aco-resource-engine' ), [ 'status' => 400 ] );
    }

    // ---- Load posts ----
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'post_not_found', __( 'The source post was not found.', 'fpm-aco-resource-engine' ), [ 'status' => 404 ] );
    }

    $allowed_types = aco_re_get_promote_allowed_source_types(); // defaults to ['post']
    if ( ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
        return new WP_Error( 'bad_source_type', __( 'Promote is not allowed for this post type.', 'fpm-aco-resource-engine' ), [ 'status' => 400, 'postType' => $post->post_type ] );
    }

    $att = get_post( $attachment_id );
    if ( ! $att || $att->post_type !== 'attachment' ) {
        return new WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'fpm-aco-resource-engine' ), [ 'status' => 404 ] );
    }

    $mime = (string) get_post_mime_type( $attachment_id );
    if ( stripos( $mime, 'pdf' ) === false ) {
        return new WP_Error( 'bad_mime', __( 'Only PDF files can be promoted.', 'fpm-aco-resource-engine' ), [ 'status' => 400, 'mime' => $mime ] );
    }

    $file_url = wp_get_attachment_url( $attachment_id );
    if ( ! $file_url ) {
        return new WP_Error( 'file_url_missing', __( 'Could not resolve the attachment URL.', 'fpm-aco-resource-engine' ), [ 'status' => 500 ] );
    }

// ---- Verify link presence (block-aware with broader fallbacks) ----
$content   = (string) $post->post_content;
$has_block = aco_re_post_has_file_block_with_id( $post, $attachment_id );
$has_url   = ( $file_url && strpos( $content, $file_url ) !== false );

// Attachment page URL present?
$page_url  = get_attachment_link( $attachment_id );
$has_page  = ( $page_url && strpos( $content, $page_url ) !== false );

// Any link using ?attachment_id=<ID> ?
$has_qs    = (bool) preg_match(
    '#href=["\'][^"\']*(?:\?|&)attachment_id=' . preg_quote( (string) $attachment_id, '#' ) . '\b[^"\']*["\']#i',
    $content
);

// Any link whose href ends with the file's basename (plain or URL-encoded)?
$basename      = '';
$basename_enc  = '';
$path_from_url = wp_parse_url( (string) $file_url, PHP_URL_PATH );
if ( is_string( $path_from_url ) && $path_from_url !== '' ) {
    $basename     = basename( rawurldecode( $path_from_url ) );
    $basename_enc = rawurlencode( $basename );
}
if ( $basename === '' ) {
    $local = get_attached_file( $attachment_id );
    if ( $local && file_exists( $local ) ) {
        $basename     = basename( $local );
        $basename_enc = rawurlencode( $basename );
    }
}
$has_basename = false;
if ( $basename !== '' ) {
    $has_basename = (bool) preg_match(
        '#href=["\'][^"\']*(?:' . preg_quote( $basename, '#' ) . '|' . preg_quote( $basename_enc, '#' ) . ')[^"\']*["\']#i',
        $content
    );
}

if ( ! ( $has_block || $has_url || $has_page || $has_qs || $has_basename ) ) {
    if ( function_exists( 'aco_re_log' ) ) {
        aco_re_log( 'warning', 'Promote failed: file link not found in post content', [
            'source'        => 'promote',
            'action'        => 'link_check',
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
        ] );
    }
    return new WP_Error(
        'link_not_found',
        __( 'The selected PDF is not present as a File block in this post.', 'fpm-aco-resource-engine' ),
        [ 'status' => 400, 'postId' => $post_id, 'attachmentId' => $attachment_id ]
    );
}

    // ---- Create or reuse Resource by fingerprint ----
    $fingerprint = (string) _aco_re_compute_attachment_fingerprint( $attachment_id );
    if ( $fingerprint === '' ) {
        // Fallback: dedupe by attachment id + (best-effort) size. Keeps behaviour deterministic if Offload info is unavailable.
        $size = 0;
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
            $size = (int) $meta['filesize'];
        }
        $fingerprint = sprintf( 'att:%d|%d', $attachment_id, $size );
    }

    $existing = get_posts( [
        'post_type'              => 'resource',
        'post_status'            => 'any',
        'meta_key'               => '_aco_file_fingerprint',
        'meta_value'             => $fingerprint,
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'suppress_filters'       => true,
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ] );
    $resource_id = $existing ? (int) $existing[0] : 0;

    if ( ! $resource_id ) {
        $title_guess = get_the_title( $attachment_id );
        if ( ! is_string( $title_guess ) || $title_guess === '' ) {
            $title_guess = basename( wp_parse_url( $file_url, PHP_URL_PATH ) ?: 'document.pdf' );
        }
        $resource_id = wp_insert_post( [
            'post_type'    => 'resource',
            'post_status'  => 'publish',
            'post_title'   => wp_strip_all_tags( $title_guess ),
            'post_content' => '',
        ], true );

        if ( is_wp_error( $resource_id ) ) {
            return new WP_Error( 'create_failed', __( 'Could not create the Resource.', 'fpm-aco-resource-engine' ), [ 'status' => 500, 'error' => $resource_id->get_error_message() ] );
        }
    }

    // BEFORE re-parenting, capture whether the attachment belonged to the source post
    $att = $att instanceof WP_Post ? $att : get_post( $attachment_id );
    $was_parented_to_source = ( $att instanceof WP_Post ) ? ( (int) $att->post_parent === $post_id ) : false;

    // ---- Parent the attachment to the resource ----
    $parented = wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $resource_id ], true );
    if ( is_wp_error( $parented ) ) {
        return new WP_Error( 'parent_failed', __( 'Could not parent the attachment to the Resource.', 'fpm-aco-resource-engine' ), [ 'status' => 500, 'error' => $parented->get_error_message() ] );
    }

    // ---- Meta & taxonomy on the Resource ----
    update_post_meta( $resource_id, '_aco_primary_attachment_id', $attachment_id );
    update_post_meta( $resource_id, '_aco_file_fingerprint', $fingerprint );

    if ( is_string( $resource_type ) && $resource_type !== '' ) {
        if ( function_exists( '_aco_re_set_post_terms_from_names' ) ) {
            _aco_re_set_post_terms_from_names( $resource_id, 'resource_type', [ $resource_type ], [ 'source' => 'promote', 'action' => 'set_type', 'post_id' => $resource_id ], false );
        }
    }
    if ( ! empty( $tags ) ) {
        $tags = array_values( array_filter( array_map( 'strval', $tags ) ) );
        if ( function_exists( '_aco_re_set_post_terms_from_names' ) ) {
            _aco_re_set_post_terms_from_names( $resource_id, 'universal_tag', $tags, [ 'source' => 'promote', 'action' => 'set_tags', 'post_id' => $resource_id ], true );
        }
    }

    // ---- Rewrite the post content link (best-effort; broadened) ----
    $resource_link = get_permalink( $resource_id );
    if ( is_string( $resource_link ) && $resource_link !== '' ) {
        $updated_content = $content;

        // Known direct targets we can replace 1:1
        $targets = array_filter( [
            $file_url,
            get_attachment_link( $attachment_id ),
        ] );

        // Also capture any hrefs with ?attachment_id=<ID>
        if ( preg_match_all(
            '#href=["\']([^"\']*?(?:\?|&)attachment_id=' . preg_quote( (string) $attachment_id, '#' ) . '\b[^"\']*)["\']#i',
            $content,
            $m
        ) ) {
            foreach ( array_unique( (array) $m[1] ) as $href ) {
                $targets[] = $href;
            }
        }

        // 1) Exact known URLs → resource permalink
        $targets = array_values( array_unique( array_filter( $targets ) ) );
        foreach ( $targets as $t ) {
            $updated_content = str_replace( $t, $resource_link, $updated_content );
        }

        // Compute path + filename for path/filename based rewrites
        $file_path = (string) wp_parse_url( $file_url, PHP_URL_PATH );
        $basename  = $file_path ? basename( $file_path ) : '';

        // Build an encoded variant of the path (to match blocks that URL-encode the href)
        $encoded_path = '';
        if ( $file_path ) {
            $segments     = array_map( 'rawurlencode', array_filter( explode( '/', ltrim( $file_path, '/' ) ) ) );
            $encoded_path = '/' . implode( '/', $segments );
        }

        // 2) Path-based replace (host-agnostic, keeps existing quotes)
        if ( $file_path ) {
            $pattern_by_path = '#href=(["\'])([^"\']*' . preg_quote( $file_path, '#' ) . '(?:[?#][^"\']*)?)\1#i';
            $updated_content = preg_replace( $pattern_by_path, 'href=$1' . esc_url( $resource_link ) . '$1', $updated_content ) ?? $updated_content;
        }
        if ( $encoded_path && $encoded_path !== $file_path ) {
            $pattern_by_enc_path = '#href=(["\'])([^"\']*' . preg_quote( $encoded_path, '#' ) . '(?:[?#][^"\']*)?)\1#i';
            $updated_content     = preg_replace( $pattern_by_enc_path, 'href=$1' . esc_url( $resource_link ) . '$1', $updated_content ) ?? $updated_content;
        }

        // 3) Filename fallback (handles CDN domain swaps & transformed prefixes)
        if ( $basename ) {
            $pattern_by_name = '#href=(["\'])([^"\']*/' . preg_quote( $basename, '#' ) . '(?:[?#][^"\']*)?)\1#i';
            $updated_content = preg_replace( $pattern_by_name, 'href=$1' . esc_url( $resource_link ) . '$1', $updated_content ) ?? $updated_content;
        }

        if ( $updated_content !== $content ) {
            $u = wp_update_post( [ 'ID' => $post_id, 'post_content' => $updated_content ], true );
            if ( is_wp_error( $u ) && function_exists( 'aco_re_log' ) ) {
                aco_re_log( 'warning', 'Content rewrite failed.', [
                    'source'        => 'promote',
                    'action'        => 'rewrite',
                    'post_id'       => $post_id,
                    'attachment_id' => $attachment_id,
                    'error'         => $u->get_error_message(),
                ] );
            }
        }
    }

    // ---- Best-effort: post the FileURL to Airtable ----
    if ( function_exists( '_aco_re_airtable_post_fileurl' ) ) {
        try {
            _aco_re_airtable_post_fileurl( $file_url );
        } catch ( Throwable $e ) {
            // Swallow errors in this ancillary step.
        }
    }

    /**
     * Re-index behaviour (SearchWP 4.x queue API)
     *
     * Filter: aco_re_reindex_source_on_promote
     * Decide whether the source post should be re-indexed after a successful promote.
     * Default: true ONLY when the source previously owned the attachment or its content linked the file.
     * Signature: (bool $default, int $source_post_id, int $resource_post_id, int $attachment_id): bool
     *
     * Filter: aco_re_reindex_attachment_on_promote
     * Decide whether the attachment itself should be re-indexed.
     * Default: false. Enable if your SearchWP engines index attachments directly.
     * Signature: (bool $default, int $source_post_id, int $resource_post_id, int $attachment_id): bool
     */
    if ( has_action( 'searchwp\\index\\enqueue' ) ) {
        // Always enqueue the canonical Resource so its PDF content is indexed there.
        do_action( 'searchwp\\index\\enqueue', 'post', $resource_id );

        // ALSO re-index the source post when warranted (previously owned the PDF or content linked it).
        $link_present = isset( $has_block, $has_url ) ? ( $has_block || $has_url ) : false;
        $should_reindex_source = (bool) apply_filters(
            'aco_re_reindex_source_on_promote',
            ( $was_parented_to_source || $link_present ),
            $post_id,
            $resource_id,
            $attachment_id
        );
        if ( $should_reindex_source ) {
            do_action( 'searchwp\\index\\enqueue', 'post', $post_id );
        }

        // Optional: refresh the attachment’s own index entry (disabled by default)
        if ( (bool) apply_filters( 'aco_re_reindex_attachment_on_promote', false, $post_id, $resource_id, $attachment_id ) ) {
            do_action( 'searchwp\\index\\enqueue', 'post', $attachment_id );
        }
    }

    // ---- Log & response ----
    if ( function_exists( 'aco_re_log' ) ) {
        aco_re_log( 'info', 'Promote completed.', [
            'source'        => 'promote',
            'action'        => 'promote',
            'post_id'       => $post_id,
            'resource_id'   => $resource_id,
            'attachment_id' => $attachment_id,
            'fingerprint'   => $fingerprint,
            'duration_ms'   => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
        ] );
    }

    return new WP_REST_Response( [
        'status'       => 'ok',
        'resourceId'   => (int) $resource_id,
        'attachmentId' => (int) $attachment_id,
        'postId'       => (int) $post_id,
        'link'         => (string) get_permalink( $resource_id ),
    ], 200 );
}

// -- END: ACO Promote-to-Resource (v1) --

if ( ! function_exists( 'aco_re_single_resource_template' ) ) {
    /**
     * Provide a default single template for the Resource post type when the theme does not supply one.
     *
     * @param string $single The path to the template WordPress intends to use.
     * @return string
     */
    function aco_re_single_resource_template( $single ) {
        if ( is_singular( 'resource' ) ) {
            $template = plugin_dir_path( __FILE__ ) . 'templates/single-resource.php';
            if ( file_exists( $template ) ) {
                return $template;
            }
        }
        return $single;
    }
}
add_filter( 'single_template', 'aco_re_single_resource_template' );

// --- SearchWP Integration ---

/**
 * Hardened helper to get the current search scope from the request.
 *
 * @return string The validated scope, defaulting to 'all'.
 */
function aco_re_get_search_scope_from_request(): string {
    $scope   = isset( $_GET['scope'] ) ? sanitize_key( (string) $_GET['scope'] ) : '';
    $allowed = (array) apply_filters( 'aco_re_allowed_search_scopes', [ 'all', 'resources', 'news' ] );
    return in_array( $scope, $allowed, true ) ? $scope : 'all';
}

/**
 * Modifies the main WordPress query based on the 'scope' GET parameter for the global search.
 * This version uses an allowlist and a filterable map for post types.
 *
 * @param WP_Query $query The main WP_Query object.
 */
function aco_re_modify_query_for_scope_selector( WP_Query $query ): void {
    if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
        return;
    }

    $scope = aco_re_get_search_scope_from_request();

    // Map each scope to the post types we want to include. Filterable for future extension.
    $map = (array) apply_filters( 'aco_re_scope_to_post_types', [
        'all'       => [ 'post', 'resource' ],
        'resources' => [ 'resource' ],
        'news'      => [ 'post' ],
    ] );

    if ( isset( $map[ $scope ] ) && is_array( $map[ $scope ] ) ) {
        $query->set( 'post_type', array_values( array_unique( array_map( 'sanitize_key', $map[ $scope ] ) ) ) );
    }
}
add_action( 'pre_get_posts', 'aco_re_modify_query_for_scope_selector' );

/**
 * Feature-flagged stub for the programmatic SearchWP engine registration.
 * DO NOT enable this until the programmatic API for SearchWP v4 has been fully validated.
 *
 * @param array $engines The existing array of SearchWP engines.
 * @return array The modified array of engines.
 */
function aco_re_register_searchwp_engine_placeholder( $engines ) {
    if ( ! apply_filters( 'aco_re_enable_searchwp_resources_engine', false ) ) {
        return $engines;
    }
    // Intentionally left blank pending API validation.
    // When ready, the code to define the 'resources' engine will go here.
    return $engines;
}
add_filter( 'searchwp\engines', 'aco_re_register_searchwp_engine_placeholder', 0 );
