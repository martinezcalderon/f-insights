<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Activator {

    public static function activate(): void {
        // On multisite network-activation, provision tables for every existing
        // subsite. On single-site or per-site activation, this just runs once.
        if ( is_multisite() && is_network_admin() ) {
            $sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
            foreach ( $sites as $blog_id ) {
                switch_to_blog( $blog_id );
                self::provision();
                restore_current_blog();
            }
            return;
        }

        self::provision();
    }

    /**
     * Provision tables and defaults for the current blog.
     * Called on single-site activation and per-subsite on multisite.
     */
    public static function provision(): void {
        FI_DB::create_tables();
        update_option( 'fi_db_version', FI_DB_VERSION );

        // Set sensible defaults on first install only
        $defaults = [
            'fi_cache_duration'         => 24,
            'fi_competitor_radius'      => 5,
            'fi_autocomplete_radius'    => 10,
            'fi_share_expiry_days'      => 7,
            'fi_rate_limit_enabled'     => 1,
            'fi_rate_limit_max'         => 3,
            'fi_rate_limit_window'      => 3600,
            'fi_claude_model'           => 'claude-haiku-4-5-20251001',
            'fi_claude_model_report'    => 'claude-haiku-4-5-20251001',
            'fi_claude_model_admin'     => 'claude-haiku-4-5-20251001',
            'fi_scan_btn_text'          => 'Scan Business',
            'fi_search_placeholder'     => 'Type a business name to scan...',
            'fi_email_placeholder'      => 'Where should we send the report?',
            'fi_email_btn_text'         => 'Get My Free Report',
            'fi_report_title'           => 'Your Business Insights Report',
            'fi_email_footer_cta'       => 'Want help putting these into action? Reply to this email.',
            'fi_cta_enabled'            => 0,
            'fi_cta_text'               => 'Book a Vibe Check',
            'fi_hide_credit'            => 0,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }

        // Schedule daily cleanup of expired share links
        if ( ! wp_next_scheduled( 'fi_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'fi_daily_cleanup' );
        }
        // NOTE: the actual add_action( 'fi_daily_cleanup', ... ) lives in fi_init()
        // in f-insights.php so it is registered on every page load, not just at
        // activation. This add_action call here would only fire once (during activation)
        // and the cron would have no callback on future page loads.

        FI_Logger::info( 'Plugin activated v' . FI_VERSION );

        // Schedule follow-up reminder cron events.
        if ( class_exists( 'FI_Followup_Reminder' ) ) {
            FI_Followup_Reminder::reschedule();
        }

        // Flag for the one-time "configure API keys" admin notice.
        // Cleared after the user visits the API Config tab.
        set_transient( 'fi_show_setup_notice', 1, DAY_IN_SECONDS );
    }
}
