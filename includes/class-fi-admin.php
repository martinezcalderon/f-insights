<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Admin
 * Admin menu registration, asset enqueueing, settings save handler,
 * and shared UI helpers.
 *
 * Tab rendering is delegated to dedicated tab classes:
 *   FI_Admin_Tab_Shortcode, FI_Admin_Tab_Api, FI_Admin_Tab_Cache,
 *   FI_Admin_Tab_Rate_Limiting, FI_Admin_Tab_Ip_Exclusions, FI_Admin_Tab_White_Label
 *
 * To add a new settings tab:
 *   1. Create includes/admin/class-fi-admin-tab-{name}.php with a render() method.
 *   2. Add it to $fi_includes in f-insights.php.
 *   3. Add the slug to $tabs and $tab_labels in render_settings().
 *   4. Add the class::render() call in render_tab().
 */
class FI_Admin {

    public static function init(): void {
        add_action( 'admin_menu',                        [ __CLASS__, 'register_menu'   ] );
        add_action( 'admin_enqueue_scripts',             [ __CLASS__, 'enqueue'         ] );
        add_action( 'admin_post_fi_save_settings',       [ __CLASS__, 'save_settings'   ] );
        add_action( 'admin_post_fi_reset_usage',         [ __CLASS__, 'reset_usage'     ] );
        add_action( 'admin_post_fi_export_csv',          [ __CLASS__, 'export_csv'      ] );
        add_action( 'admin_notices',                     [ __CLASS__, 'saved_notice'    ] );
        add_action( 'admin_notices',                     [ __CLASS__, 'setup_notice'    ] );
    }

    // =========================================================================
    // Menu
    // =========================================================================

    public static function register_menu(): void {
        add_menu_page(
            'Fricking Local Business Insights', 'F! Insights', 'manage_options',
            'fi-insights', [ __CLASS__, 'render_settings' ],
            'dashicons-chart-bar', 2
        );
        add_submenu_page( 'fi-insights', 'Settings',     'Settings',     'manage_options', 'fi-insights',     [ __CLASS__, 'render_settings'    ] );
        add_submenu_page( 'fi-insights', 'Market Leads', 'Market Leads', 'manage_options', 'fi-market-intel', [ __CLASS__, 'render_market_intel' ] );
        add_submenu_page( 'fi-insights', 'Debug Logs',   'Debug Logs',   'manage_options', 'fi-debug-logs',   [ __CLASS__, 'render_debug_logs'   ] );
        add_submenu_page( 'fi-insights', 'Export Data',  'Export Data',  'manage_options', 'fi-export-data',  [ __CLASS__, 'render_export_data'  ] );
    }

    // =========================================================================
    // Assets
    // =========================================================================

    public static function enqueue( string $hook ): void {
        // Check the page slug directly rather than the generated hook suffix,
        // which varies depending on how WordPress sanitizes the menu title.
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $fi_pages = [ 'fi-insights', 'fi-market-intel', 'fi-debug-logs', 'fi-export-data' ];
        if ( ! in_array( $page, $fi_pages, true ) ) return;

        wp_enqueue_style(  'fi-admin', FI_ASSETS_URL . 'css/admin.css', [], FI_ASSET_VERSION );
        wp_enqueue_script( 'fi-admin', FI_ASSETS_URL . 'js/admin.js',  [ 'jquery' ], FI_ASSET_VERSION, true );
        // qrcode.js — client-side QR generation for the Reviews deploy panel
        wp_enqueue_script( 'fi-qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true );
        wp_enqueue_media();

        wp_localize_script( 'fi-admin', 'FI_Admin', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'fi_admin_nonce' ),
            'premium_active' => FI_Premium::is_active(),
            'colorDefaults'  => class_exists( 'FI_Admin_Tab_White_Label' ) ? FI_Admin_Tab_White_Label::color_defaults() : [],
        ] );
    }

    // =========================================================================
    // Admin notices
    // =========================================================================

    public static function saved_notice(): void {
        if ( isset( $_GET['page'] ) && str_starts_with( $_GET['page'], 'fi-' ) && isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>F! Insights:</strong> Settings saved.</p></div>';
        }
    }

    /**
     * One-time notice shown after activation until the user visits the API Config tab.
     * Prompts the user to enter their Google Places and Claude API keys.
     */
    public static function setup_notice(): void {
        // Only show on WP admin screens to users who can manage options.
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! get_transient( 'fi_show_setup_notice' ) ) return;

        // Dismiss once the user lands on the API Config tab.
        $on_api_tab = isset( $_GET['page'] ) && $_GET['page'] === 'fi-insights'
                   && ( ! isset( $_GET['tab'] ) || $_GET['tab'] === 'api' );
        if ( $on_api_tab && get_option( 'fi_google_api_key', '' ) ) {
            delete_transient( 'fi_show_setup_notice' );
            return;
        }

        $url = esc_url( admin_url( 'admin.php?page=fi-insights&tab=api' ) );
        echo '<div class="notice notice-warning is-dismissible">'
           . '<p><strong>F! Insights is almost ready, my guy.</strong> '
           . 'Enter your <a href="' . $url . '">Google Places and Claude API keys</a> '
           . 'to activate the scanner. Both keys are required before the shortcode will work.</p>'
           . '</div>';
    }

    // =========================================================================
    // Settings page
    // =========================================================================

    public static function render_settings(): void {
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'shortcode';
        $tabs = [ 'shortcode', 'api', 'cache', 'rate-limiting', 'ip-exclusions', 'white-label', 'notifications' ];
        if ( ! in_array( $tab, $tabs, true ) ) $tab = 'shortcode';

        $tab_labels = [
            'shortcode'     => 'Shortcode',
            'api'           => 'API Config',
            'cache'         => 'Cache',
            'rate-limiting' => 'Rate Limiting',
            'ip-exclusions' => 'IP Exclusions',
            'white-label'   => 'White-Label' . ( FI_Premium::is_active() ? '' : ' 🔒' ),
            'notifications' => 'Notifications',
        ];
        ?>
        <div class="wrap fi-settings-wrap">
            <h1>F! Insights: Settings</h1>
            <nav class="fi-tabs">
                <?php foreach ( $tab_labels as $t => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-insights&tab=' . $t ) ); ?>"
                       class="fi-tab <?php echo $tab === $t ? 'fi-tab--active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fi_save_settings' ); ?>
                <input type="hidden" name="action" value="fi_save_settings">
                <input type="hidden" name="_tab"   value="<?php echo esc_attr( $tab ); ?>">
                <div class="fi-tab-content">
                    <?php self::render_tab( $tab ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_tab( string $tab ): void {
        switch ( $tab ) {
            case 'shortcode':     FI_Admin_Tab_Shortcode::render();     break;
            case 'api':           FI_Admin_Tab_Api::render();           break;
            case 'cache':         FI_Admin_Tab_Cache::render();         break;
            case 'rate-limiting': FI_Admin_Tab_Rate_Limiting::render(); break;
            case 'ip-exclusions': FI_Admin_Tab_Ip_Exclusions::render(); break;
            case 'white-label':
                if ( class_exists( 'FI_Admin_Tab_White_Label' ) ) {
                    FI_Admin_Tab_White_Label::render();
                } else {
                    echo FI_Premium::upgrade_prompt( 'White-Label & Brand Colors' );
                }
                break;
            case 'notifications':
                if ( class_exists( 'FI_Admin_Tab_Notifications' ) ) {
                    FI_Admin_Tab_Notifications::render();
                }
                break;
        }
    }

    // =========================================================================
    // Market Intel page
    // =========================================================================

    public static function render_market_intel(): void {
        if ( class_exists( 'FI_Analytics_Page' ) ) {
            FI_Analytics_Page::render();
        } else {
            echo '<div class="wrap"><p>Analytics page not loaded.</p></div>';
        }
    }

    // =========================================================================
    // Debug Logs page
    // =========================================================================

    public static function render_debug_logs(): void {
        $logs     = FI_Logger::get_logs( 300 );
        $log_size = FI_Logger::get_log_size();
        $log_path = FI_LOG_DIR . 'debug-' . wp_date( 'Y-m-d' ) . '.log';
        ?>
        <div class="wrap">
            <h1>F! Insights: Debug Logs</h1>
            <p class="fi-log-meta">
                Log directory: <code><?php echo esc_html( $log_path ); ?></code> &nbsp;|&nbsp;
                Total size: <?php echo esc_html( self::format_bytes( $log_size ) ); ?>
            </p>

            <div class="fi-log-controls">
                <select id="fi-log-filter" class="fi-select" style="width:auto;">
                    <option value="all">All Levels</option>
                    <option value="ERROR">Errors Only</option>
                    <option value="API">API Calls Only</option>
                    <option value="INFO">Info Only</option>
                    <option value="WARN">Warnings Only</option>
                </select>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=fi_download_logs&nonce=' . wp_create_nonce( 'fi_admin_nonce' ) ) ); ?>"
                   class="button">⬇ Download Today's Log</a>
                <button type="button" id="fi-clear-logs" class="button">Clear All Logs</button>
            </div>

            <div class="fi-log-viewer">
                <?php if ( empty( $logs ) ) : ?>
                    <span class="fi-log-empty">No log entries yet. Run a scan to see output here.</span>
                <?php else : ?>
                    <?php foreach ( $logs as $line ) :
                        $level = 'INFO';
                        if ( preg_match( '/\[(ERROR|API|WARN|INFO)\]/', $line, $m ) ) $level = $m[1];
                    ?>
                        <span class="fi-log-line" data-level="<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $line ); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Settings save
    // =========================================================================

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'fi_save_settings' );

        $settings = [
            'fi_google_api_key'         => 'sanitize_text_field',
            'fi_claude_api_key'         => 'sanitize_text_field',
            'fi_claude_model'           => 'sanitize_text_field',
            'fi_claude_model_report'    => 'sanitize_text_field',
            'fi_claude_model_admin'     => 'sanitize_text_field',
            'fi_cache_duration'         => 'absint',
            'fi_competitor_radius'      => 'absint',
            'fi_autocomplete_radius'    => 'absint',
            'fi_share_expiry_days'      => 'absint',
            'fi_rate_limit_max'         => 'absint',
            'fi_rate_limit_window'      => 'absint',
            'fi_excluded_ips'           => 'sanitize_textarea_field',
            'fi_shortcode_page_id'      => 'intval',
            'fi_upgrade_url'                => 'esc_url_raw',   // legacy fallback — Polar checkout URL preferred
            // Polar.sh Merchant of Record integration
            'fi_polar_organization_id'      => 'sanitize_text_field',
            'fi_polar_checkout_url'         => 'esc_url_raw',
            'fi_polar_webhook_secret'       => 'sanitize_text_field',
            'fi_polar_access_token'         => 'sanitize_text_field',
            // Brand colors — validated as hex by sanitize_hex_color()
            'fi_color_primary'          => 'sanitize_hex_color',
            'fi_color_cta'              => 'sanitize_hex_color',
            'fi_color_header_bg'        => 'sanitize_hex_color',
            'fi_color_header_text'      => 'sanitize_hex_color',
            'fi_color_tab_bg'           => 'sanitize_hex_color',
            'fi_color_surface'          => 'sanitize_hex_color',
            'fi_color_body_text'        => 'sanitize_hex_color',
            'fi_color_link'             => 'sanitize_hex_color',
            // Brand identity
            'fi_brand_name'             => 'sanitize_text_field',
            'fi_reply_to_email'         => 'sanitize_email',
            'fi_logo_url'               => 'esc_url_raw',
            'fi_search_placeholder'     => 'sanitize_text_field',
            'fi_scan_btn_text'          => 'sanitize_text_field',
            'fi_cta_text'               => 'sanitize_text_field',
            'fi_cta_url'                => 'esc_url_raw',
            'fi_report_title'           => 'sanitize_text_field',
            'fi_email_footer_cta'       => 'sanitize_textarea_field',
            'fi_notify_email'           => 'sanitize_text_field',
            'fi_notify_threshold'       => 'absint',
            'fi_reminder_email'         => 'sanitize_email',
            'fi_reminder_frequency'     => 'sanitize_key',
            // Lead form copy
            'fi_form_headline'          => 'sanitize_text_field',
            'fi_form_subtext'           => 'sanitize_text_field',
            'fi_email_placeholder'      => 'sanitize_text_field',
            'fi_email_btn_text'         => 'sanitize_text_field',
            'fi_form_thankyou'          => 'sanitize_textarea_field',
            // Lead form custom field
            'fi_field_custom_label'     => 'sanitize_text_field',
            // Consent / GDPR
            'fi_consent_text'           => 'sanitize_textarea_field',
            'fi_consent_privacy_url'    => 'esc_url_raw',
            // Lead form field toggles (checkboxes handled separately below)
        ];

        $checkboxes = [
            'fi_rate_limit_enabled',
            'fi_trust_proxy_headers',
            'fi_cta_enabled',
            'fi_hide_credit',
            'fi_notify_enabled',
            'fi_reminder_enabled',
            'fi_field_firstname_enabled',  'fi_field_firstname_required',
            'fi_field_lastname_enabled',   'fi_field_lastname_required',
            'fi_field_phone_enabled',      'fi_field_phone_required',
            'fi_field_role_enabled',       'fi_field_role_required',
            'fi_field_employees_enabled',  'fi_field_employees_required',
            'fi_field_custom_enabled',     'fi_field_custom_required',
            'fi_consent_enabled',
        ];
        foreach ( $checkboxes as $key ) {
            update_option( $key, isset( $_POST[ $key ] ) ? 1 : 0 );
        }

        foreach ( $settings as $key => $sanitizer ) {
            if ( in_array( $key, $checkboxes, true ) ) continue;
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $sanitizer( $_POST[ $key ] ) );
            }
        }

        $tab  = sanitize_key( $_POST['_tab'] ?? 'shortcode' );
        $page = $tab === 'lead-form' ? 'fi-market-intel' : 'fi-insights';

        // If notification/reminder settings changed, reschedule cron events.
        if ( $tab === 'notifications' && class_exists( 'FI_Followup_Reminder' ) ) {
            FI_Followup_Reminder::reschedule();
        }

        wp_safe_redirect( add_query_arg( [ 'page' => $page, 'tab' => $tab, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function reset_usage(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'fi_reset_usage' );
        delete_option( 'fi_tokens_input' );
        delete_option( 'fi_tokens_output' );
        delete_option( 'fi_api_calls' );
        delete_option( 'fi_tokens_updated' );
        wp_safe_redirect( add_query_arg( [ 'page' => 'fi-insights', 'tab' => 'rate-limiting', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // =========================================================================
    // Shared UI helpers — used by tab classes via FI_Admin::save_bar()
    // =========================================================================

    /**
     * Render the sticky save bar with an optional "Saved" confirmation.
     * Called by tab classes: FI_Admin::save_bar()
     */
    public static function save_bar(): void {
        ?>
        <div class="fi-save-bar">
            <?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <span class="fi-saved-notice">✓ Saved</span>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function format_bytes( int $bytes ): string {
        if ( $bytes < 1024 )    return $bytes . ' B';
        if ( $bytes < 1048576 ) return round( $bytes / 1024, 1 ) . ' KB';
        return round( $bytes / 1048576, 1 ) . ' MB';
    }

    // =========================================================================
    // Export Data page
    // =========================================================================

    public static function render_export_data(): void {
        global $wpdb;

        $scan_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fi_scans" );
        $lead_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fi_leads" );
        $share_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fi_shares" );
        ?>
        <div class="wrap">
            <h1>F! Insights: Export Data</h1>
            <p>Download a copy of your data before uninstalling, or any time you need a backup.</p>

            <table class="widefat striped" style="max-width:500px;margin-bottom:24px;">
                <thead><tr><th>Table</th><th>Records</th></tr></thead>
                <tbody>
                    <tr><td>Scans</td><td><?php echo esc_html( number_format( $scan_count ) ); ?></td></tr>
                    <tr><td>Leads</td><td><?php echo esc_html( number_format( $lead_count ) ); ?></td></tr>
                    <tr><td>Shared Reports</td><td><?php echo esc_html( number_format( $share_count ) ); ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
                <?php wp_nonce_field( 'fi_export_csv' ); ?>
                <input type="hidden" name="action" value="fi_export_csv">
                <input type="hidden" name="fi_export_table" value="scans">
                <?php submit_button( '⬇ Export Scans (CSV)', 'primary', 'submit', false ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
                <?php wp_nonce_field( 'fi_export_csv' ); ?>
                <input type="hidden" name="action" value="fi_export_csv">
                <input type="hidden" name="fi_export_table" value="leads">
                <?php submit_button( '⬇ Export Leads (CSV)', 'primary', 'submit', false ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( 'fi_export_csv' ); ?>
                <input type="hidden" name="action" value="fi_export_csv">
                <input type="hidden" name="fi_export_table" value="shares">
                <?php submit_button( '⬇ Export Shared Reports (CSV)', 'secondary', 'submit', false ); ?>
            </form>

            <div style="margin-top:40px;padding:20px 24px;background:#fff3cd;border-left:4px solid #f59e0b;border-radius:4px;max-width:680px;">
                <h2 style="margin-top:0;color:#92400e;">⚠️ Before You Uninstall</h2>
                <p><strong>Uninstalling this plugin is permanent and cannot be undone. No: T________T. No, none of that.</strong> The following will be deleted forever:</p>
                <ul style="margin-left:20px;list-style:disc;">
                    <li>All <?php echo esc_html( number_format( $scan_count ) ); ?> scan records and their AI-generated reports</li>
                    <li>All <?php echo esc_html( number_format( $lead_count ) ); ?> captured leads, pipeline status, and notes</li>
                    <li>All <?php echo esc_html( number_format( $share_count ) ); ?> shareable report tokens</li>
                    <li>All plugin settings including your API keys, white-label branding, and rate limiting rules</li>
                    <li>All debug log files from <code><?php echo esc_html( WP_CONTENT_DIR . '/fi-insights-logs/' ); ?></code></li>
                </ul>
                <p><strong>Export your data using the buttons above before proceeding.</strong> WordPress will prompt you to confirm deletion on the Plugins screen.</p>
                <p style="margin-bottom:0;">If you are moving to a new server, export here and re-import after reinstalling the plugin.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Stream a table as a CSV download.
     */
    public static function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'fi_export_csv' );

        global $wpdb;

        $allowed = [ 'scans', 'leads', 'shares' ];
        $table   = sanitize_key( $_POST['fi_export_table'] ?? 'scans' );
        if ( ! in_array( $table, $allowed, true ) ) wp_die( 'Invalid table.' );

        $full_table = $wpdb->prefix . 'fi_' . $table;
        $rows       = $wpdb->get_results( "SELECT * FROM {$full_table}", ARRAY_A );

        $filename = 'fi-' . $table . '-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        if ( ! empty( $rows ) ) {
            fputcsv( $out, array_keys( $rows[0] ) );
            foreach ( $rows as $row ) {
                fputcsv( $out, $row );
            }
        } else {
            fputcsv( $out, [ 'No data found in this table.' ] );
        }

        fclose( $out );
        exit;
    }
}