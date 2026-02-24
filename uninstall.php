<?php
/**
 * Fired when the plugin is uninstalled via "Delete" in the WordPress plugins list.
 *
 * Removes EVERY database row and option that F! Insights creates so the site
 * is left in a perfectly clean state. This file must be kept in sync with:
 *   - class-fi-activator.php  (options set on activation)
 *   - class-fi-admin.php      (options saved from settings forms)
 *   - class-fi-analytics.php  (options written at runtime)
 *
 * IMPORTANT: fi_leads contains visitor PII (emails, business contact info).
 * Dropping it on uninstall is a GDPR compliance requirement.
 *
 * NOTE: User-uploaded files and WordPress core data are never touched.
 */

// Safety gate: WordPress sets this constant before calling uninstall.php.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Freemius uninstall ────────────────────────────────────────────────────────
// Must run before our own cleanup so Freemius can deregister the site with
// the licensing server and purge its own option rows cleanly.
if ( ! function_exists( 'fi_fs' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'f-insights.php';
}
fi_fs()->_uninstall_plugin_event();

global $wpdb;

// ── 1. Plugin options ─────────────────────────────────────────────────────────
// Keep this list in sync with set_default_options() in class-fi-activator.php
// and every update_option() call in class-fi-admin.php.

$options = array(

    // API credentials (stored encrypted; deleting removes both plaintext fallback
    // and the encryption-key-derived value)
    'fi_google_api_key',
    'fi_claude_api_key',
    'fi_claude_model',

    // Cache & search (v1.0+)
    'fi_cache_duration',
    'fi_competitor_radius_miles',
    'fi_autocomplete_radius_miles',

    // Rate limiting (v1.0+)
    'fi_rate_limit_enabled',
    'fi_rate_limit_per_ip',
    'fi_rate_limit_window',

    // Lead capture flags — deprecated in v1.3.0, kept for back-compat (v1.0+)
    'fi_lead_capture_enabled',
    'fi_lead_capture_required',

    // White-label — email settings (v1.3+)
    'fi_wl_sender_name',
    'fi_wl_reply_to',
    'fi_wl_logo_url',
    'fi_wl_footer_cta',
    'fi_wl_report_title',

    // White-label — CTA button (v1.3+)
    'fi_wl_cta_button_enabled',
    'fi_wl_cta_button_text',
    'fi_wl_cta_button_url',
    'fi_wl_hide_branding',
    'fi_report_retention_days',
    'fi_shared_reports_table_created',

    // White-label — semantic brand colors (v1.9+)
    'fi_brand_button_bg',
    'fi_brand_button_text',
    'fi_brand_link_color',
    'fi_brand_card_header_bg',
    'fi_brand_card_header_text',
    'fi_brand_score_badge',
    'fi_brand_background_tint',

    // White-label — status badge color overrides (v1.9+)
    'fi_brand_use_custom_status',
    'fi_brand_status_excellent',
    'fi_brand_status_good',
    'fi_brand_status_alert',
    'fi_brand_status_critical',

    // White-label — typography (v1.8+)
    'fi_brand_font',
    'fi_brand_body_font',
    'fi_brand_base_size',
    'fi_brand_scale_ratio',

    // White-label — legacy colors kept for back-compat (v1.0–v1.8)
    'fi_brand_primary_color',
    'fi_brand_secondary_color',
    'fi_brand_accent_color',

    // Lead notifications (v1.6+)
    'fi_lead_notifications_enabled',
    'fi_lead_notification_email',
    'fi_lead_notification_threshold',

    // Analytics (v1.6+)
    'fi_analytics_ip_blacklist',
    'fi_free_scan_count',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── 2. Transients ─────────────────────────────────────────────────────────────
// delete_transient() removes both the value row and its _timeout_ companion.

$transients = array(
    // Settings page notices
    'fi_settings_saved',
    'fi_cache_cleared',
    'fi_ip_blacklist_saved',

    // Analytics page notices (v1.6+)
    'fi_test_leads_cleared',
    'fi_analytics_reset',
);

foreach ( $transients as $transient ) {
    delete_transient( $transient );
}

// ── 3. Custom database tables ─────────────────────────────────────────────────
// IMPORTANT: fi_leads contains PII — must be dropped for GDPR compliance.

$tables = array(
    $wpdb->prefix . 'fi_analytics',   // scan event log (v1.0+)
    $wpdb->prefix . 'fi_leads',       // lead records with PII (v1.6+) — GDPR: must drop
    $wpdb->prefix . 'fi_cache',       // cached Google Places / website results (v1.0+)
    $wpdb->prefix . 'fi_rate_limits',       // per-IP rate-limit counters (v1.0+)
    $wpdb->prefix . 'fi_shared_reports',   // shareable report store (v2.1.0)
);

foreach ( $tables as $table ) {
    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
}

// ── 4. Object cache ───────────────────────────────────────────────────────────
// Unschedule plugin cron events
$cron_events = array( 'fi_rate_limit_cleanup', 'fi_shared_report_cleanup' );
foreach ( $cron_events as $event ) {
    $timestamp = wp_next_scheduled( $event );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $event );
    }
}

// Flush so stale in-memory references to our data do not persist.
wp_cache_flush();