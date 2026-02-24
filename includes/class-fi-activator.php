<?php
/**
 * Fired during plugin activation
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Activator {

    /**
     * Minimum requirements. Checked on every activation so customers
     * get a clear error instead of a fatal crash.
     *
     * PHP 7.4+ is declared in the plugin header.  We enforce it here
     * programmatically because WordPress does not block activation on
     * PHP version alone — it only shows a warning.
     *
     * Why 7.4?  All syntax in this plugin is compatible with PHP 7.4:
     *   - Typed method signatures/return types  → PHP 7.0 / 7.1
     *   - Nullsafe operator (?->)               → PHP 8.0 (NOT used)
     *   - str_starts_with()                     → PHP 8.0 (NOT used; we use strpos)
     *   - Short array destructuring [ $a, $b ]  → PHP 7.1
     *   - Arrow functions (fn =>)               → PHP 7.4
     */
    const MIN_PHP = '7.4';
    const MIN_WP  = '5.8';

    public static function activate() {
        self::check_requirements();
        self::create_tables();
        self::set_default_options();
        self::run_migrations();

        // Schedule daily rate-limit table pruning. Without this the fi_rate_limits
        // table grows forever — every unique IP that has ever scanned keeps a row
        // even after its window expires. Daily cleanup keeps the table lean.
        if ( ! wp_next_scheduled( 'fi_rate_limit_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'fi_rate_limit_cleanup' );
        }

        // Schedule daily shared-report expiry pruning (v2.1.0).
        if ( ! wp_next_scheduled( 'fi_shared_report_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'fi_shared_report_cleanup' );
        }

        // Schedule daily GDPR lead retention cleanup (v2.2.0).
        // Respects the fi_lead_retention_days option — no-op when set to 0.
        if ( ! wp_next_scheduled( 'fi_lead_retention_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'fi_lead_retention_cleanup' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Halt activation with a human-readable notice if server requirements
     * are not met.  deactivate_plugins() is called so WordPress does not
     * mark the plugin as active after the activation hook returns.
     */
    private static function check_requirements() {
        $errors = array();

        if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
            $errors[] = sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __( 'F! Insights requires PHP %1$s or higher. Your server is running PHP %2$s.', 'f-insights' ),
                self::MIN_PHP,
                PHP_VERSION
            );
        }

        global $wp_version;
        if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
            $errors[] = sprintf(
                /* translators: 1: required WP version, 2: current WP version */
                __( 'F! Insights requires WordPress %1$s or higher. You are running WordPress %2$s.', 'f-insights' ),
                self::MIN_WP,
                $wp_version
            );
        }

        if ( empty( $errors ) ) {
            return; // All good.
        }

        // Prevent WordPress from marking the plugin as active.
        deactivate_plugins( plugin_basename( FI_PLUGIN_DIR . 'f-insights.php' ) );

        wp_die(
            '<p><strong>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</strong></p>' .
            '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' .
            esc_html__( '&larr; Return to Plugins', 'f-insights' ) . '</a></p>',
            esc_html__( 'Plugin Activation Error', 'f-insights' ),
            array( 'back_link' => false )
        );
    }
    
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Analytics table
        $analytics_table = $wpdb->prefix . 'fi_analytics';
        $analytics_sql = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            business_name varchar(255) NOT NULL,
            business_category varchar(100) DEFAULT NULL,
            google_place_id varchar(255) DEFAULT NULL,
            overall_score int(3) DEFAULT NULL,
            scan_date datetime NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_email varchar(255) DEFAULT NULL,
            report_data longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY business_category (business_category),
            KEY scan_date (scan_date),
            KEY ip_address (ip_address)
        ) $charset_collate;";
        
        // Leads table (v1.6.0) - Email report requests with business contact info
        // Updated v1.7.0: Added report_html and report_generated_at for report storage
        $leads_table = $wpdb->prefix . 'fi_leads';
        $leads_sql = "CREATE TABLE IF NOT EXISTS $leads_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            business_name varchar(255) NOT NULL,
            business_category varchar(100) DEFAULT NULL,
            business_website varchar(500) DEFAULT NULL,
            business_phone varchar(50) DEFAULT NULL,
            business_email varchar(255) DEFAULT NULL,
            business_address text DEFAULT NULL,
            visitor_email varchar(255) NOT NULL,
            overall_score int(3) DEFAULT NULL,
            pain_points text DEFAULT NULL,
            request_date datetime NOT NULL,
            follow_up_status varchar(50) DEFAULT 'new',
            follow_up_notes text DEFAULT NULL,
            google_place_id varchar(255) DEFAULT NULL,
            ip_address varchar(45) NOT NULL,
            report_html LONGTEXT DEFAULT NULL,
            report_generated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY request_date (request_date),
            KEY follow_up_status (follow_up_status),
            KEY business_category (business_category),
            KEY google_place_id (google_place_id)
        ) $charset_collate;";
        
        // Cache table
        $cache_table = $wpdb->prefix . 'fi_cache';
        $cache_sql = "CREATE TABLE IF NOT EXISTS $cache_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            cache_value longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Rate limit table
        $rate_limit_table = $wpdb->prefix . 'fi_rate_limits';
        $rate_limit_sql = "CREATE TABLE IF NOT EXISTS $rate_limit_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            scan_count int(11) NOT NULL DEFAULT 0,
            reset_time datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address),
            KEY reset_time (reset_time)
        ) $charset_collate;";

        // Shared reports table (v2.1.0) — stores full report JSON keyed by a public UUID
        $shared_reports_table = $wpdb->prefix . 'fi_shared_reports';
        $shared_reports_sql = "CREATE TABLE IF NOT EXISTS $shared_reports_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token varchar(36) NOT NULL,
            report_json longtext NOT NULL,
            business_name varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($analytics_sql);
        dbDelta($leads_sql);
        dbDelta($cache_sql);
        dbDelta($rate_limit_sql);
        dbDelta($shared_reports_sql);
    }
    
    private static function set_default_options() {
        // Options that autoload on every WordPress page load (keep this list short —
        // only options actually needed on the frontend request path).
        $autoload_options = array(
            // Needed by frontend_scripts() on pages with the shortcode
            'fi_google_api_key'              => '',
            'fi_claude_api_key'              => '',
            'fi_rate_limit_enabled'          => '1',
            'fi_rate_limit_per_ip'           => 3,
            'fi_rate_limit_window'           => 3600,
            'fi_competitor_radius_miles'     => 5,
            'fi_autocomplete_radius_miles'   => 10,
            'fi_report_retention_days'       => 30,
            'fi_show_credit_link'            => '1',
            // White-label options needed in wp_localize_script() on frontend
            'fi_wl_cta_button_enabled'       => '0',
            'fi_wl_cta_button_text'          => 'Book a Free Consultation',
            'fi_wl_cta_button_url'           => '',
            'fi_wl_hide_branding'            => '0',
            'fi_scan_btn_text'               => '',
            'fi_scan_btn_icon'               => 'fa-solid fa-magnifying-glass',
            'fi_scan_placeholder'            => '',
            'fi_email_btn_text'              => '',
            'fi_email_btn_icon'              => '',
            'fi_email_placeholder'           => '',
            'fi_wl_cta_btn_icon'             => '',
        );

        // Options only read in WP admin or specific plugin pages — do NOT autoload
        // these on every frontend request as they waste memory for nothing.
        $no_autoload_options = array(
            // Claude model selection — only read when a scan/report fires
            'fi_claude_model'                => 'claude-sonnet-4-20250514',
            // Cache duration — only read during scan handler
            'fi_cache_duration'              => 86400,
            // Lead capture flags (deprecated v1.3.0)
            'fi_lead_capture_enabled'        => '0',
            'fi_lead_capture_required'       => '0',
            // White-label email — only used when sending email reports
            'fi_wl_sender_name'              => '',
            'fi_wl_reply_to'                 => '',
            'fi_wl_logo_url'                 => '',
            'fi_wl_footer_cta'              => '',
            'fi_wl_report_title'             => '',
            // Brand colors — only read in admin settings and email builder
            'fi_brand_button_bg'             => '#2271B1',
            'fi_brand_button_text'           => '#FFFFFF',
            'fi_brand_link_color'            => '#2271B1',
            'fi_brand_card_header_bg'        => '#F6F7F7',
            'fi_brand_card_header_text'      => '#1E1E1E',
            'fi_brand_score_badge'           => '#2271B1',
            'fi_brand_background_tint'       => 'transparent',
            'fi_brand_use_custom_status'     => '0',
            'fi_brand_status_excellent'      => '#00a32a',
            'fi_brand_status_good'           => '#f0b429',
            'fi_brand_status_alert'          => '#dc3232',
            'fi_brand_status_critical'       => '#8b0000',
            'fi_brand_font'                  => '',
            // Legacy colors (v1.8 and earlier)
            'fi_brand_primary_color'         => '#0073aa',
            'fi_brand_secondary_color'       => '#f0f0f1',
            'fi_brand_accent_color'          => '#00a32a',
            // Lead notifications — only read in admin and scan handler
            'fi_lead_notifications_enabled'  => '1',
            'fi_lead_notification_email'     => get_option('admin_email'),
            'fi_lead_notification_threshold' => 100,
            // IP exclusion list — only read during scans (heavy option, often multi-line)
            'fi_analytics_ip_blacklist'      => '',
            // Free scan counter — only read on the admin analytics page
            'fi_free_scan_count'             => 0,
            // Batch scanner settings (v2.2.0) — admin-only, no-autoload
            'fi_batch_max_size'              => 10,
            'fi_batch_daily_quota'           => 50,
            // GDPR lead retention (v2.2.0) — 0 = keep indefinitely
            'fi_lead_retention_days'         => 0,
            // CRM webhook (v2.3.0) — POST new lead data to external CRM/Zapier/Make endpoint
            'fi_crm_webhook_url'             => '',
        );

        foreach ( $autoload_options as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value ); // autoload defaults to 'yes'
            }
        }

        foreach ( $no_autoload_options as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value, '', 'no' ); // explicit no-autoload
            }
        }
    }

    /**
     * One-time data migrations that fix saved values from previous versions.
     *
     * Each migration is idempotent — safe to run on every activation.
     * Add new migrations here chronologically; never remove old ones.
     */
    private static function run_migrations() {

        // v2.2.0 — Set autoload=no on heavy options that don't need to load on
        // every WordPress request. This reduces per-request memory on sites with
        // lots of traffic by preventing unnecessary option bloat in wp_load_alloptions().
        // Runs once: skipped if the flag is already set.
        if ( ! get_option( 'fi_migration_autoload_no' ) ) {
            global $wpdb;
            $no_autoload = array(
                'fi_claude_model',
                'fi_cache_duration',
                'fi_lead_capture_enabled',
                'fi_lead_capture_required',
                'fi_wl_sender_name',
                'fi_wl_reply_to',
                'fi_wl_logo_url',
                'fi_wl_footer_cta',
                'fi_wl_report_title',
                'fi_brand_button_bg',
                'fi_brand_button_text',
                'fi_brand_link_color',
                'fi_brand_card_header_bg',
                'fi_brand_card_header_text',
                'fi_brand_score_badge',
                'fi_brand_background_tint',
                'fi_brand_use_custom_status',
                'fi_brand_status_excellent',
                'fi_brand_status_good',
                'fi_brand_status_alert',
                'fi_brand_status_critical',
                'fi_brand_font',
                'fi_brand_primary_color',
                'fi_brand_secondary_color',
                'fi_brand_accent_color',
                'fi_lead_notifications_enabled',
                'fi_lead_notification_email',
                'fi_lead_notification_threshold',
                'fi_analytics_ip_blacklist',
                'fi_free_scan_count',
            );
            foreach ( $no_autoload as $option_name ) {
                $wpdb->update(
                    $wpdb->options,
                    array( 'autoload' => 'no' ),
                    array( 'option_name' => $option_name )
                );
            }
            add_option( 'fi_migration_autoload_no', '1', '', 'no' );
        }

        // v1.9.1 — The Haiku 4 model slug shipped incorrectly as
        // 'claude-haiku-4-20250514' (not a real Anthropic model string).
        // Correct slug is 'claude-haiku-4-5-20251001'. Silently update any
        // saved setting so existing installs stop sending 400 errors to the
        // Anthropic API without requiring manual admin intervention.
        // Fixed in all per-context options, not just the legacy fi_claude_model key.
        $bad_slug  = 'claude-haiku-4-20250514';
        $good_slug = 'claude-haiku-4-5-20251001';
        $model_options = array(
            'fi_claude_model',
            'fi_claude_model_scan',
            'fi_claude_model_internal',
            'fi_claude_model_intel',
        );
        foreach ( $model_options as $opt ) {
            if ( get_option( $opt, '' ) === $bad_slug ) {
                update_option( $opt, $good_slug );
            }
        }
    }

    /**
     * Idempotent table creation for fi_shared_reports.
     * Called once on first page load after upgrade to v2.1.0.
     */
    public static function maybe_create_shared_reports_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fi_shared_reports';
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token varchar(36) NOT NULL,
            report_json longtext NOT NULL,
            business_name varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

}
