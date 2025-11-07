<?php
/**
 * Plugin Name:     1 - FPM - ACO Resource Engine
 * Description:     Core functionality for the ACO Resource Library, including failover, sync and content models.
 * Version:         1.17.2
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
    $api_key  = getenv( 'ACO_AT_API_KEY' );
    $base_id  = getenv( 'ACO_AT_BASE_ID' );
    $table_id = getenv( 'ACO_AT_TABLE_TAGS' );

    if ( ! $api_key || ! $base_id || ! $table_id ) { return new WP_Error( 'aco_re_missing_creds', __( 'Airtable API credentials are not configured in environment variables.', 'fpm-aco-resource-engine' ) ); }

    $endpoint = "https://api.airtable.com/v0/{$base_id}/{$table_id}";
    $headers = [ 'Authorization' => 'Bearer ' . $api_key ];
    if ( $stored_etag = get_option( 'aco_re_tag_allowlist_etag', '' ) ) { $headers['If-None-Match'] = $stored_etag; }

    $all_records = [];
    $offset      = null;
    $attempt     = 0;
    do {
        $attempt++;
        $args = [ 'headers' => $headers, 'timeout' => 15, 'redirection' => 0 ];
        $query_args = [ 'pageSize' => 100 ];
        if ( $offset ) {
            $query_args['offset'] = $offset;
            unset( $args['headers']['If-None-Match'] );
        }
        $response = wp_remote_get( add_query_arg( $query_args, $endpoint ), $args );
        if ( is_wp_error( $response ) ) { return $response; }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 304 === (int) $response_code ) {
            if ( false !== get_transient( 'aco_re_tag_allowlist' ) ) {
                update_option( 'aco_re_tag_allowlist_last_sync', time() );
                return true;
            }
        }
        if ( 200 !== (int) $response_code ) { return new WP_Error( 'aco_re_api_error', sprintf( __( 'Airtable API returned an error. Code: %d', 'fpm-aco-resource-engine' ), (int) $response_code ) ); }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['records'] ) ) { return new WP_Error( 'aco_re_invalid_response', __( 'Invalid response format from Airtable API.', 'fpm-aco-resource-engine' ) ); }

        $all_records = array_merge( $all_records, $data['records'] );
        $offset      = $data['offset'] ?? null;

        if ( $attempt === 1 && $new_etag = wp_remote_retrieve_header( $response, 'etag' ) ) {
            update_option( 'aco_re_tag_allowlist_etag', $new_etag );
        }
    } while ( $offset && $attempt < 50 );

    $new_allowlist = [];
    foreach ( $all_records as $record ) {
        $name   = $record['fields']['Name'] ?? '';
        $status = $record['fields']['Status'] ?? 'Pending';
        if ( '' !== $name ) { $new_allowlist[ $name ] = $status; }
    }

    set_transient( 'aco_re_tag_allowlist', $new_allowlist, (int) apply_filters( 'aco_tag_allowlist_ttl', HOUR_IN_SECONDS ) );
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
    $primary   = getenv( 'ACO_WEBHOOK_SECRET_PRIMARY' );
    $secondary = getenv( 'ACO_WEBHOOK_SECRET_SECONDARY' );
    if ( ! $primary && defined( 'ACO_WEBHOOK_SECRET_PRIMARY' ) ) { $primary = ACO_WEBHOOK_SECRET_PRIMARY; }
    if ( ! $secondary && defined( 'ACO_WEBHOOK_SECRET_SECONDARY' ) ) { $secondary = ACO_WEBHOOK_SECRET_SECONDARY; }
    if ( ! $primary ) {
        $opt = get_option( 'aco_webhook_secret_primary' );
        if ( is_string( $opt ) && $opt !== '' ) { $primary = $opt; }
    }
    if ( ! $secondary ) {
        $opt = get_option( 'aco_webhook_secret_secondary' );
        if ( is_string( $opt ) && $opt !== '' ) { $secondary = $opt; }
    }
    return [ $primary ?: null, $secondary ?: null ];
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
        'resource_id'   => isset( $context['post_id'] ) ? (int) $context['post_id'] : null,
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
 * Processes a single resource sync job from the Action Scheduler queue.
 *
 * @param array|string $payload The data received from the Airtable webhook (array or JSON string).
 * @return array Outcome summary for observability: ['status' => 'created|updated|skipped|error', 'post_id' => int|null, 'reason' => string|null]
 */
function aco_re_process_resource_sync_action( $payload ) {
    // 0. Decode JSON if a string payload is provided.
    if ( is_string( $payload ) ) {
        $decoded = json_decode( $payload, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $payload = $decoded;
        }
    }

    // 1. Normalize the incoming data.
    $data = ( is_array( $payload ) && isset( $payload['payload'] ) && is_array( $payload['payload'] ) )
        ? $payload['payload']
        : ( is_array( $payload ) ? $payload : [] );

    // 1a. Validate post type availability early.
    if ( ! post_type_exists( 'resource' ) ) {
        aco_re_log( 'error', 'Post type "resource" is not registered.' );
        return [ 'status' => 'error', 'post_id' => null, 'reason' => 'post_type_missing' ];
    }

    // 2. Validate required fields.
    $raw_record_id      = $data['record_id']    ?? '';
    $raw_last_modified  = $data['LastModified'] ?? '';
    $record_id = sanitize_text_field( (string) $raw_record_id );
    list( $incoming_ms, $incoming_iso ) = aco_re_parse_last_modified( $raw_last_modified );

    if ( $record_id === '' || $incoming_ms === 0 ) {
        aco_re_log( 'error', 'Payload missing valid record_id or LastModified.', [ 'has_record_id' => $record_id !== '', 'raw_last_modified' => is_string( $raw_last_modified ) ? $raw_last_modified : null, 'action' => 'validation' ] );
        return [ 'status' => 'error', 'post_id' => null, 'reason' => 'invalid_payload' ];
    }

    // 3. Concurrency lock per record.
    if ( ! aco_re_acquire_lock( $record_id, 120 ) ) {
        aco_re_log( 'warning', 'Lock present, skipping concurrent job.', [ 'record_id' => $record_id, 'action' => 'lock' ] );
        return [ 'status' => 'skipped', 'post_id' => null, 'reason' => 'locked' ];
    }

    try {
        // 4. Lookup post by Airtable record_id.
        $post_id = aco_re_get_post_id_by_record_id( $record_id );
        $action = 'update'; // Default action for logging

        if ( $post_id ) {
            // UPDATE path
            $stored_ms = (int) get_post_meta( $post_id, ACO_AT_LAST_MODIFIED_MS_META, true );
            if ( ! $stored_ms ) {
                $stored_iso_legacy = (string) get_post_meta( $post_id, ACO_AT_LAST_MODIFIED_META, true );
                if ( $stored_iso_legacy ) {
                    list( $stored_ms_from_iso ) = aco_re_parse_last_modified( $stored_iso_legacy );
                    $stored_ms = (int) $stored_ms_from_iso;
                }
            }

            if ( $incoming_ms <= $stored_ms ) {
                aco_re_log( 'info', 'SKIP stale update.', [ 'record_id' => $record_id, 'post_id' => $post_id, 'action' => 'skip' ] );
                return [ 'status' => 'skipped', 'post_id' => $post_id, 'reason' => 'stale' ];
            }

            $update = [ 'ID' => $post_id ];
            if ( isset($data['Title']) ) { $update['post_title'] = wp_strip_all_tags( $data['Title'] ); }

            if ( count( $update ) > 1 ) {
                wp_update_post( wp_slash( $update ), true, false );
            }
            
            update_post_meta( $post_id, '_aco_summary', isset( $data['Summary'] ) ? sanitize_textarea_field( $data['Summary'] ) : '' );
            update_post_meta( $post_id, '_aco_document_date', ( isset( $data['DocumentDate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['DocumentDate'] ) ) ? $data['DocumentDate'] : '' );

            update_post_meta( $post_id, ACO_AT_LAST_MODIFIED_META, $incoming_iso );
            update_post_meta( $post_id, ACO_AT_LAST_MODIFIED_MS_META, $incoming_ms );

        } else {
            // CREATE path
            $action = 'create';
            $title   = $data['Title'] ?? 'Resource ' . $record_id;
            $content = $data['Content'] ?? '';
            
            $meta_input = [
                ACO_AT_RECORD_ID_META        => $record_id,
                ACO_AT_LAST_MODIFIED_META    => $incoming_iso,
                ACO_AT_LAST_MODIFIED_MS_META => $incoming_ms,
                '_aco_summary'               => isset( $data['Summary'] ) ? sanitize_textarea_field( $data['Summary'] ) : '',
                '_aco_document_date'         => ( isset( $data['DocumentDate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['DocumentDate'] ) ) ? $data['DocumentDate'] : '',
            ];

            $insert = [
                'post_type'    => 'resource',
                'post_status'  => 'publish',
                'post_title'   => wp_strip_all_tags( $title ),
                'post_content' => wp_kses_post( $content ),
                'meta_input'   => $meta_input,
            ];

            $post_id = wp_insert_post( wp_slash( $insert ), true );
            if ( is_wp_error( $post_id ) ) {
                aco_re_log( 'error', 'Create failed.', [ 'record_id' => $record_id, 'error' => $post_id->get_error_message(), 'action' => 'create' ] );
                return [ 'status' => 'error', 'post_id' => null, 'reason' => 'create_failed' ];
            }
        }
        
        // --- START: HANDLE TAXONOMIES ---
        if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
            // Handle Type Taxonomy
            if ( ! empty( $data['Type'] ) ) {
                $type_term = get_term_by( 'name', $data['Type'], 'resource_type' );
                if ( $type_term ) {
                    wp_set_object_terms( $post_id, $type_term->term_id, 'resource_type' );
                } else {
                    aco_re_log( 'warning', "Resource Type '{$data['Type']}' not found.", [ 'record_id' => $record_id, 'post_id' => $post_id, 'action' => $action ] );
                }
            } else {
                wp_set_object_terms( $post_id, [], 'resource_type' ); // Clear if empty
            }

            // Handle Tags Taxonomy
            if ( ! empty( $data['Tags'] ) && is_array( $data['Tags'] ) ) {
                $tag_ids = [];
                foreach ( $data['Tags'] as $tag_name ) {
                    $tag_term = get_term_by( 'name', $tag_name, 'universal_tag' );
                    if ( $tag_term ) {
                        $tag_ids[] = $tag_term->term_id;
                    } else {
                        aco_re_log( 'warning', "Universal Tag '{$tag_name}' not found.", [ 'record_id' => $record_id, 'post_id' => $post_id, 'action' => $action ] );
                    }
                }
                wp_set_object_terms( $post_id, $tag_ids, 'universal_tag' );
            } else {
                wp_set_object_terms( $post_id, [], 'universal_tag' ); // Clear if empty
            }
        }
        // --- END: HANDLE TAXONOMIES ---

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
// --- Hook Registrations ---

// --- Activation / Deactivation / Uninstall Hooks ---

register_activation_hook( __FILE__, function () {
    update_option('aco_re_activation_test', time()); // <-- ADD THIS LINE

    // Call the function to create the table
    aco_re_create_sync_log_table(); 

    // Original activation logic
    if ( $admin_role = get_role( 'administrator' ) ) {
        $admin_role->add_cap( 'aco_manage_failover' );
    }
    add_option( 'aco_re_seed_terms_pending', true );
    if ( ! wp_next_scheduled( 'aco_re_hourly_tag_sync_hook' ) ) {
        wp_schedule_event( time(), 'hourly', 'aco_re_hourly_tag_sync_hook' );
    }
    aco_re_register_content_models();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'aco_re_hourly_tag_sync_hook' );
    flush_rewrite_rules();
} );

register_uninstall_hook( __FILE__, 'aco_re_on_uninstall' );




add_action( 'init', 'aco_re_register_log_purge_cron' );
add_action( 'aco_re_daily_log_purge', 'aco_re_purge_old_logs' );



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

add_filter( 'rest_pre_insert_resource', 'aco_re_validate_tags_on_rest_save', 10, 2 );
add_filter( 'wp_get_attachment_url', 'aco_re_failover_url_swap', 99 );
add_filter( 'wp_calculate_image_srcset', 'aco_re_failover_srcset_swap', 99 );
add_filter( 'wp_get_attachment_image_attributes', 'aco_re_failover_img_attr_swap', 99 );
add_filter( 'site_status_tests', 'aco_re_add_site_health_test' );
add_action('plugins_loaded', function() {
        // First, check if ACF Pro is active to prevent errors if it's disabled.
    if (function_exists('acf_is_pro') && acf_is_pro()) {
        // Add the filter, now that we know it's safe to do so.
        add_filter('acf/settings/remove_wp_meta_box', '__return_false');
    }
});

<?php
// --- START: ACO Sync Log Admin Viewer ---

// 1. Register the admin page under the "Tools" menu.
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

// 2. Render the admin page.
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
            <input type="hidden" name="page" value="<?php echo isset( $_REQUEST['page'] ) ? esc_attr( $_REQUEST['page'] ) : ''; ?>" />
            <?php
            $log_list_table->search_box( __( 'Search Logs', 'fpm-aco-resource-engine' ), 'aco-sync-log-search' );
            $log_list_table->display();
            ?>
        </form>
    </div>
    <?php
}

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


// 4. Create the WP_List_Table class for our log.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

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
        
        $statuses = $wpdb->get_col( "SELECT DISTINCT status FROM {$table_name} ORDER BY status ASC" );
        $current_status = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
        
        $sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$table_name} ORDER BY source ASC" );
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

        submit_button( __( 'Filter', 'fpm-aco-resource-engine' ), 'secondary', 'filter_action', false );
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
}

// --- END: ACO Sync Log Admin Viewer ---

// --- START: Replay Failed Jobs (Hardened Version) ---

/**
 * Safely parses a string that might be serialized or JSON.
 * It prioritizes JSON, forbids object unserialization, and always returns an array.
 *
 * @param mixed $raw_data The raw data from the database.
 * @return array The parsed data, or an empty array on failure.
 */
function aco_re_safe_parse_message( $raw_data ): array {
    if ( is_array( $raw_data ) ) {
        return $raw_data;
    }
    if ( ! is_string( $raw_data ) || empty( $raw_data ) ) {
        return [];
    }
    // PHP's is_serialized() is unreliable. We check for the typical structure.
    if ( strpos( $raw_data, 'a:' ) === 0 && strpos( $raw_data, '{' ) > 0 ) {
        // Only unserialize if it looks like a serialized array.
        // Forbidding classes is the critical security measure.
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
        set_transient( 'aco_replay_notice', [ 'type' => 'error', 'message' => __( 'Log entry not found.', 'fpm-aco-resource-engine' ) ] );
    } else {
        $data    = aco_re_safe_parse_message( $log_entry['message'] );
        $context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
        $payload = $context['payload'] ?? null;
        
        if ( $payload && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aco_re_process_resource_sync_action', [ 'payload' => $payload ], 'aco_resource_sync' );
            $message = sprintf( 
                __( 'Job for Airtable Record ID %s has been re-queued.', 'fpm-aco-resource-engine' ), 
                '<strong>' . esc_html( $log_entry['record_id'] ) . '</strong>'
            );
            set_transient( 'aco_replay_notice', [ 'type' => 'success', 'message' => $message ] );
        } else {
            set_transient( 'aco_replay_notice', [ 'type' => 'error', 'message' => __( 'Could not re-queue job. The payload was missing or Action Scheduler is inactive.', 'fpm-aco-resource-engine' ) ] );
        }
    }
    
    wp_safe_redirect( admin_url( 'tools.php?page=aco-sync-log' ) );
    exit;
}
add_action( 'admin_init', 'aco_re_handle_log_actions' );

/**
 * Displays the admin notice after a replay action.
 */
function aco_re_show_replay_notices() {
    if ( $notice = get_transient( 'aco_replay_notice' ) ) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $notice['type'] ),
            wp_kses_post( $notice['message'] ) // Use wp_kses_post to allow for <strong> tag.
        );
        delete_transient( 'aco_replay_notice' );
    }
}
add_action( 'admin_notices', 'aco_re_show_replay_notices' );

/**
 * We need to unhook the old page registration and renderer
 * before we can hook in the new ones that use our final List Table class.
 */
function aco_re_remove_original_log_viewer() {
    remove_action( 'admin_menu', 'aco_re_register_log_viewer_page' );
}
add_action( 'admin_init', 'aco_re_remove_original_log_viewer', 9 ); // Run early.

class ACO_Sync_Log_List_Table_Final extends ACO_Sync_Log_List_Table {
    
    function column_message( $item ) {
        $raw_data = $item['message'] ?? '';
        $data = aco_re_safe_parse_message( $raw_data );
        $message = isset( $data['message'] ) && is_string( $data['message'] ) 
                   ? $data['message'] 
                   : __( 'Could not read message.', 'fpm-aco-resource-engine' );

        return esc_html( $message );
    }
    
    function column_record_id( $item ) {
        $record_id = isset( $item['record_id'] ) ? esc_html( $item['record_id'] ) : '';
        $actions = [];

        $status = $item['status'] ?? '';
        if ( in_array( $status, [ 'failed', 'error' ], true ) && ! empty( $item['id'] ) ) {
            $replay_url = wp_nonce_url( add_query_arg( [
                'page'     => 'aco-sync-log',
                'action'   => 'replay',
                'log_id'   => $item['id'],
            ], admin_url( 'tools.php' ) ), 'aco_replay_log_' . $item['id'] );
            
            $actions['replay'] = sprintf( '<a href="%s">%s</a>', esc_url( $replay_url ), __( 'Replay', 'fpm-aco-resource-engine' ) );
        }

        return $record_id . $this->row_actions( $actions );
    }
}

function aco_re_register_log_viewer_page_final() {
    $hook_suffix = add_management_page(
        __( 'ACO Sync Log', 'fpm-aco-resource-engine' ),
        __( 'ACO Sync Log', 'fpm-aco-resource-engine' ),
        'manage_options',
        'aco-sync-log',
        'aco_re_render_log_viewer_page_final'
    );
    add_action( "load-{$hook_suffix}", 'aco_re_log_viewer_screen_options' );
}
add_action( 'admin_menu', 'aco_re_register_log_viewer_page_final' );

function aco_re_render_log_viewer_page_final() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fpm-aco-resource-engine' ) );
    }

    $log_list_table = new ACO_Sync_Log_List_Table_Final(); // Use the final class name
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
// --- END: Replay Failed Jobs (Hardened Version) ---
?>