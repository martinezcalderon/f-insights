<?php
/**
 * Plugin Name: Fricking Local Business Insights
 * Plugin URI:  https://fricking.website/f-insights
 * Description: AI-powered Google Business Profile scanner. Drop a shortcode, scan any local business, capture leads, deliver branded reports. At least make your cold outreach with honest intel.
 * Version:     1.0.0
 * Requires at least: 6.2
 * Requires PHP:       8.0
 * Author:      Saïd Martínez Calderón
 * Author URI:  https://saidmartinezcalderon.com/project/frickingwebsite/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: f-insights
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Require PHP 8.0+ for str_contains/str_starts_with and named arguments
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Fricking Local Business Insights</strong> requires PHP 8.0 or higher. You are running PHP ' . PHP_VERSION . '. Please upgrade your PHP version.</p></div>';
    } );
    return;
}

define( 'FI_VERSION',       '1.0.0' );
define( 'FI_ASSET_VERSION', '1.0.3' );  // bump this whenever CSS/JS changes; keeps plugin version stable
define( 'FI_FILE',       __FILE__ );
define( 'FI_DIR',        plugin_dir_path( __FILE__ ) );
define( 'FI_URL',        plugin_dir_url( __FILE__ ) );
define( 'FI_INCLUDES',   FI_DIR . 'includes/' );
define( 'FI_ASSETS_URL', FI_URL . 'assets/' );
define( 'FI_LOG_DIR',    WP_CONTENT_DIR . '/fi-insights-logs/' );
define( 'FI_DB_VERSION', '4.5' );

/**
 * PREMIUM FEATURE TOGGLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Set to true  → premium features always on (dev / solo use, no license check).
 * Set to false → premium is gated by a valid license (production).
 * ─────────────────────────────────────────────────────────────────────────────
 */
define( 'FI_DEV_MODE', true ); // ← false = production (Polar license enforcement active)

// Load order matters — dependencies first
$fi_includes = [
    // ── Shared utilities (no inter-class deps, loaded first) ──────────────
    'class-fi-utils.php',

    // ── Core utilities (no inter-class deps) ──────────────────────────────
    'class-fi-logger.php',
    'class-fi-db.php',
    'class-fi-cache.php',
    'class-fi-rate-limiter.php',
    'class-fi-polar.php',              // Polar.sh MoR integration — must load before FI_Premium
    'class-fi-premium.php',

    // ── Lifecycle ─────────────────────────────────────────────────────────
    'class-fi-activator.php',
    'class-fi-deactivator.php',

    // ── External API wrappers ─────────────────────────────────────────────
    'class-fi-google.php',
    'class-fi-claude.php',             // central Claude API wrapper (used by grader, analytics, ajax)
    'class-fi-grader.php',
    'class-fi-taxonomy.php',          // industry/category mapping for competitor search

    // ── Business logic ────────────────────────────────────────────────────
    'class-fi-share.php',
    'class-fi-leads.php',
    'class-fi-reviews.php',
    'class-fi-email.php',
    'class-fi-scan-runner.php',     // needs all of the above

    // ── HTTP layer ────────────────────────────────────────────────────────
    'class-fi-ajax.php',

    // ── Frontend ──────────────────────────────────────────────────────────
    'class-fi-shortcode.php',

    // ── Admin tab classes (loaded before FI_Admin) ────────────────────────
    'admin/class-fi-admin-tab-shortcode.php',
    'admin/class-fi-admin-tab-api.php',
    'admin/class-fi-admin-tab-cache.php',
    'admin/class-fi-admin-tab-rate-limiting.php',
    'admin/class-fi-admin-tab-ip-exclusions.php',
    'admin/class-fi-admin-tab-notifications.php',

    // ── Follow-up reminder (cron digest) ──────────────────────────────────
    'class-fi-followup-reminder.php',

    'class-fi-admin.php',
];

foreach ( $fi_includes as $file ) {
    $path = FI_INCLUDES . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    } else {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FI_Logger not yet loaded
        error_log( '[Fricking Local Business Insights] Missing include: ' . $file );
    }
}

// Premium-only files
$fi_premium_includes = [
    'class-fi-analytics.php',
    'class-fi-pitch.php',
    'class-fi-analytics-page.php',
    'class-fi-bulk-scan.php',
    'admin/class-fi-admin-tab-white-label.php',
    'admin/class-fi-admin-tab-lead-form.php',
    'admin/class-fi-admin-tab-reviews.php',
];

foreach ( $fi_premium_includes as $file ) {
    $path = FI_INCLUDES . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

register_activation_hook(   __FILE__, [ 'FI_Activator',   'activate'   ] );

// Multisite: provision tables for new subsites when they are created.
// wp_insert_site fires on WP 5.1+; wpmu_new_blog is the pre-5.1 equivalent.
// Both call the same lightweight provisioner so we cover all versions.
add_action( 'wp_insert_site', function( $new_site ) {
    if ( ! is_plugin_active_for_network( plugin_basename( FI_FILE ) ) ) return;
    switch_to_blog( $new_site->blog_id );
    FI_DB::create_tables();
    update_option( 'fi_db_version', FI_DB_VERSION );
    restore_current_blog();
} );
add_action( 'wpmu_new_blog', function( $blog_id ) {
    if ( ! is_plugin_active_for_network( plugin_basename( FI_FILE ) ) ) return;
    switch_to_blog( $blog_id );
    FI_DB::create_tables();
    update_option( 'fi_db_version', FI_DB_VERSION );
    restore_current_blog();
} );
register_deactivation_hook( __FILE__, [ 'FI_Deactivator', 'deactivate' ] );

function fi_init() {
    // Boot Polar integration first so the webhook REST route is registered
    // before any other plugin code runs.
    FI_Polar::init();
    // Register the daily cron callback on every page load so it is always
    // available when WP cron fires (not just at activation time).
    add_action( 'wp_ajax_fi_refresh_nonce', function() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        wp_send_json_success( [ 'nonce' => wp_create_nonce( 'fi_admin_nonce' ) ] );
    } );
    add_action( 'fi_daily_cleanup', [ 'FI_Logger', 'rotate' ] );
    // Auto-kill items stuck in 'scanning' before each tick processes the next item
    add_action( 'fi_bulk_scan_tick', [ 'FI_Bulk_Scan', 'auto_kill_stuck' ], 5 );

    // Runtime DB upgrade — runs create_tables() whenever the stored version
    // doesn't match the current constant. dbDelta() is idempotent: it only
    // adds missing columns/tables and never removes or alters existing data.
    if ( get_option( 'fi_db_version' ) !== FI_DB_VERSION ) {
        FI_DB::create_tables();
        update_option( 'fi_db_version', FI_DB_VERSION );
    }

    // Warn admins when dev mode is on — all premium features are unlocked for everyone.
    if ( defined( 'FI_DEV_MODE' ) && FI_DEV_MODE && is_admin() ) {
        add_action( 'admin_notices', function() {
            if ( ! current_user_can( 'manage_options' ) ) return;
            echo '<div class="notice notice-warning"><p>'
               . '<strong>Fricking Local Business Insights: Dev Mode is ON.</strong> '
               . 'Godddamnyoubernice. All premium features are unlocked for every visitor. '
               . 'Set <code>FI_DEV_MODE</code> to <code>false</code> in <code>f-insights.php</code> before distributing.'
               . '</p></div>';
        } );
    }

    if ( is_admin() ) {
        FI_Admin::init();
    }
    FI_Ajax::init();
    FI_Shortcode::init();

    // Cron callbacks must be registered on every page load so WP Cron can
    // find them when the scheduled event fires.
    if ( class_exists( 'FI_Followup_Reminder' ) ) {
        FI_Followup_Reminder::init();
    }

    // Bulk scan — cron processor and AJAX endpoints
    if ( class_exists( 'FI_Bulk_Scan' ) ) {
        FI_Bulk_Scan::init();
        add_action( 'wp_ajax_fi_bulk_estimate',   [ 'FI_Bulk_Scan', 'ajax_estimate'   ] );
        add_action( 'wp_ajax_fi_bulk_validate',   [ 'FI_Bulk_Scan', 'ajax_validate'   ] );
        add_action( 'wp_ajax_fi_bulk_start',      [ 'FI_Bulk_Scan', 'ajax_start'      ] );
        add_action( 'wp_ajax_fi_bulk_pause',      [ 'FI_Bulk_Scan', 'ajax_pause'      ] );
        add_action( 'wp_ajax_fi_bulk_resume',     [ 'FI_Bulk_Scan', 'ajax_resume'     ] );
        add_action( 'wp_ajax_fi_bulk_cancel',     [ 'FI_Bulk_Scan', 'ajax_cancel'     ] );
        add_action( 'wp_ajax_fi_bulk_retry_item', [ 'FI_Bulk_Scan', 'ajax_retry_item' ] );
        add_action( 'wp_ajax_fi_bulk_poll',       [ 'FI_Bulk_Scan', 'ajax_poll'       ] );
        add_action( 'wp_ajax_fi_bulk_kill_item',  [ 'FI_Bulk_Scan', 'ajax_kill_item'  ] );
        add_action( 'wp_ajax_fi_bulk_kill_stuck', [ 'FI_Bulk_Scan', 'ajax_kill_stuck' ] );
        add_action( 'wp_ajax_fi_bulk_export_csv',   [ 'FI_Bulk_Scan', 'ajax_export_csv'   ] );
        add_action( 'wp_ajax_fi_bulk_respawn_cron', [ 'FI_Bulk_Scan', 'ajax_respawn_cron' ] );
    }
}add_action( 'plugins_loaded', 'fi_init' );
