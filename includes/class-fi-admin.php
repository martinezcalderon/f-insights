<?php
/**
 * Admin settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Admin {

    /**
     * Check if premium features are available.
     *
     * @return bool
     */
    private static function is_premium(): bool {
        return FI_License::is_active();
    }

    /**
     * Register the admin_init hook so form saves and cache clears are handled
     * BEFORE WordPress sends any output. wp_redirect() must be called before
     * headers are sent — doing it inside a menu page callback is too late and
     * produces a blank screen.
     */
    public static function register_hooks() {
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
    }

    /**
     * Fires on admin_init — before any page output.
     * Handles both the settings form save and the clear-cache action.
     */
    public static function handle_admin_actions() {
        // Only act on our own admin page requests.
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'f-insights' ) === false ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ── Clear cache ──────────────────────────────────────────────────────
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_cache'
            && check_admin_referer( 'fi_clear_cache' ) ) {
            self::clear_all_cache();
            set_transient( 'fi_cache_cleared', true, 60 );
            wp_redirect( admin_url( 'admin.php?page=f-insights' ) );
            exit;
        }

        // ── Save lead retention setting ───────────────────────────────────────
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'save_lead_retention'
            && check_admin_referer( 'fi_save_lead_retention' ) ) {
            $days = absint( wp_unslash( $_POST['fi_lead_retention_days'] ?? '0' ) );
            $days = min( $days, 3650 ); // hard cap at 10 years
            update_option( 'fi_lead_retention_days', $days, 'no' );
            wp_redirect( add_query_arg(
                array( 'page' => 'f-insights-analytics', 'tab' => 'data-management', 'retention_saved' => '1' ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // ── Download debug log ────────────────────────────────────────────────
        // Must be handled here (before any output) so file headers can be sent.
        // The render_logs_page() callback is too late — WP has already started
        // the admin HTML by the time it fires.
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'download_logs'
            && isset( $_GET['page'] ) && $_GET['page'] === 'f-insights-logs'
            && check_admin_referer( 'fi_download_logs' ) ) {

            $log_file = FI_Logger::get_log_file();
            $filename = 'f-insights-debug-' . gmdate( 'Y-m-d' ) . '.log';

            header( 'Content-Type: text/plain; charset=UTF-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'X-Robots-Tag: noindex' );

            if ( file_exists( $log_file ) ) {
                header( 'Content-Length: ' . filesize( $log_file ) );
                readfile( $log_file );
            } else {
                $empty = '# F! Insights, no log entries yet.';
                header( 'Content-Length: ' . strlen( $empty ) );
                echo $empty;
            }
            exit;
        }

        // ── Save credit link setting (Shortcode & Scanner tab) ───────────────
        if ( isset( $_POST['fi_save_credit_link'] ) && check_admin_referer( 'fi_credit_link_nonce' ) ) {
            update_option( 'fi_show_credit_link', isset( $_POST['fi_show_credit_link'] ) ? '1' : '0' );
            self::purge_page_caches();
            set_transient( 'fi_settings_saved', true, 60 );
            wp_redirect( admin_url( 'admin.php?page=f-insights&tab=shortcode' ) );
            exit;
        }

        // ── Save settings ────────────────────────────────────────────────────
        if ( isset( $_POST['fi_save_settings'] ) && check_admin_referer( 'fi_settings_nonce' ) ) {
            self::save_settings();
            set_transient( 'fi_settings_saved', true, 60 );
            // Purge page caches (WP Rocket, LiteSpeed, W3TC, SG Optimizer, etc.)
            // so frontend pages re-read the updated ctaButton / brand settings on
            // the next visitor request rather than serving stale localized data.
            self::purge_page_caches();
            // Return to the tab the user was on, not always the default
            $tab = isset( $_POST['fi_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['fi_active_tab'] ) ) : '';
            $redirect = admin_url( 'admin.php?page=f-insights' );
            if ( $tab ) {
                $redirect = add_query_arg( 'tab', $tab, $redirect );
            }
            wp_redirect( $redirect );
            exit;
        }

        // ── Save IP blacklist (Settings > IP Exclusions tab) ─────────────────
        if ( isset( $_POST['fi_save_ip_blacklist'] ) && check_admin_referer( 'fi_ip_blacklist_nonce' ) ) {
            self::save_ip_blacklist();
            set_transient( 'fi_ip_blacklist_saved', true, 60 );
            wp_redirect( admin_url( 'admin.php?page=f-insights&tab=ip-exclusions' ) );
            exit;
        }
        
        // ── Clear test leads (v1.6.0) ────────────────────────────────────────
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_test_leads'
            && check_admin_referer( 'fi_clear_test_leads' ) ) {
            self::clear_test_leads();
            set_transient( 'fi_test_leads_cleared', true, 60 );
            wp_redirect( admin_url( 'admin.php?page=f-insights-analytics&tab=data-management' ) );
            exit;
        }
        
        // ── Reset all analytics (v1.6.0) — POST only, typed confirmation ────
        if ( isset( $_POST['action'] ) && wp_unslash( $_POST['action'] ) === 'reset_all_analytics'
            && check_admin_referer( 'fi_reset_analytics' )
            && isset( $_POST['fi_reset_confirm'] ) && wp_unslash( $_POST['fi_reset_confirm'] ) === 'RESET' ) {
            self::reset_all_analytics();
            set_transient( 'fi_analytics_reset', true, 60 );
            wp_redirect( admin_url( 'admin.php?page=f-insights-analytics&tab=data-management' ) );
            exit;
        }
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ── Resolve active tab ────────────────────────────────────────────────
        $valid_tabs = array( 'api', 'cache', 'rate-limiting', 'ip-exclusions', 'shortcode', 'white-label' );
        // Default to API tab on first run (no keys configured), shortcode otherwise
        $default_tab = ( FI_Crypto::get_key( FI_Crypto::GOOGLE_KEY_OPTION ) && FI_Crypto::get_key( FI_Crypto::CLAUDE_KEY_OPTION ) )
            ? 'shortcode'
            : 'api';
        $active_tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true )
            ? sanitize_key( $_GET['tab'] )
            : $default_tab;

        // ── Fetch options ─────────────────────────────────────────────────────
        $google_api_key          = FI_Crypto::get_key( FI_Crypto::GOOGLE_KEY_OPTION );
        $claude_api_key          = FI_Crypto::get_key( FI_Crypto::CLAUDE_KEY_OPTION );
        $claude_model            = get_option( 'fi_claude_model', 'claude-sonnet-4-20250514' );
        $claude_model_scan       = get_option( 'fi_claude_model_scan',     'claude-haiku-4-5-20251001' );
        $claude_model_internal   = get_option( 'fi_claude_model_internal', 'claude-sonnet-4-20250514' );
        $claude_model_intel      = get_option( 'fi_claude_model_intel',    'claude-sonnet-4-20250514' );
        $cache_duration          = get_option( 'fi_cache_duration', 86400 );
        $report_retention_days   = absint( get_option( 'fi_report_retention_days', 30 ) );
        $rate_limit_enabled      = get_option( 'fi_rate_limit_enabled', '1' );
        $rate_limit_per_ip       = get_option( 'fi_rate_limit_per_ip', 3 );
        $rate_limit_window       = get_option( 'fi_rate_limit_window', 3600 );
        $competitor_radius_miles = get_option( 'fi_competitor_radius_miles', 5 );
        $autocomplete_radius_miles = get_option( 'fi_autocomplete_radius_miles', 10 );
        $wl_sender_name          = get_option( 'fi_wl_sender_name', '' );
        $wl_reply_to             = get_option( 'fi_wl_reply_to', '' );
        $wl_logo_url             = get_option( 'fi_wl_logo_url', '' );
        $wl_footer_cta           = get_option( 'fi_wl_footer_cta', '' );
        $wl_report_title         = get_option( 'fi_wl_report_title', '' );
        $ip_blacklist            = get_option( 'fi_analytics_ip_blacklist', '' );
        $is_premium              = self::is_premium();

        // Tabs that have a save form (shortcode tab has no server-side save).
        // White-label is only form-wrapped for premium — free users see the upsell
        // outside any form so there is nothing to POST.
        $tabs_with_form = array( 'api', 'cache', 'rate-limiting', 'ip-exclusions' );
        if ( $is_premium ) {
            $tabs_with_form[] = 'white-label';
        }

        ?>
        <div class="wrap fi-admin-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

<?php // openssl notice intentionally suppressed — host confirmed openssl unavailable by design. ?>

            <?php if ( get_transient( 'fi_cache_cleared' ) ): ?>
                <?php delete_transient( 'fi_cache_cleared' ); ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php _e( 'Cache cleared successfully!', 'f-insights' ); ?></strong> <?php _e( 'All cached business data has been removed. New scans will fetch fresh data.', 'f-insights' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( get_transient( 'fi_settings_saved' ) ): ?>
                <?php delete_transient( 'fi_settings_saved' ); ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'Settings saved successfully!', 'f-insights' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( get_transient( 'fi_ip_blacklist_saved' ) ): ?>
                <?php delete_transient( 'fi_ip_blacklist_saved' ); ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'IP exclusion list saved.', 'f-insights' ); ?></p>
                </div>
            <?php endif; ?>

            <!-- ── Tab navigation ──────────────────────────────────────────── -->
            <nav class="fi-tab-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'f-insights' ); ?>">
                <?php
                $tabs = array(
                    'shortcode'    => __( 'Shortcode & Scanner', 'f-insights' ),
                    'api'          => __( 'API Configuration', 'f-insights' ),
                    'cache'        => __( 'Cache Settings', 'f-insights' ),
                    'rate-limiting'=> __( 'Rate Limiting', 'f-insights' ),
                    'ip-exclusions'=> __( 'IP Exclusions', 'f-insights' ),
                    'white-label'  => $is_premium
                        ? __( 'White-Label', 'f-insights' )
                        : 'White-Label <span aria-hidden="true">🔒</span><span class="screen-reader-text"> ' . esc_html__( '(Premium)', 'f-insights' ) . '</span>',
                );
                foreach ( $tabs as $slug => $label ) :
                    $url = add_query_arg( array( 'page' => 'f-insights', 'tab' => $slug ), admin_url( 'admin.php' ) );
                    $is_active = ( $active_tab === $slug );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="fi-tab-link<?php echo $is_active ? ' fi-tab-active' : ''; ?>"
                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo wp_kses( $label, array( 'span' => array( 'aria-hidden' => array(), 'class' => array() ) ) ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- ── Tab content ─────────────────────────────────────────────── -->
            <?php if ( in_array( $active_tab, $tabs_with_form, true ) ) : ?>

                <?php
                // IP Exclusions has its own nonce / save handler and its own <form> tag
                // inside render_analytics_ip_blacklist_section(). Do NOT wrap it in
                // another form here — that would produce invalid nested <form> tags.
                if ( $active_tab === 'ip-exclusions' ) :
                ?>
                <div class="fi-tab-panel">
                    <?php self::render_analytics_ip_blacklist_section( $ip_blacklist ); ?>
                </div>

                <?php else : ?>
                <form method="post" action="" class="fi-tab-form">
                    <?php wp_nonce_field( 'fi_settings_nonce' ); ?>
                    <input type="hidden" name="fi_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
                    <div class="fi-tab-panel">
                        <?php
                        switch ( $active_tab ) {
                            case 'api':
                                self::render_api_configuration_section( $google_api_key, $claude_api_key, $claude_model_scan, $claude_model_internal, $claude_model_intel );
                                break;
                            case 'cache':
                                self::render_cache_settings_section( $cache_duration, $competitor_radius_miles, $autocomplete_radius_miles, $report_retention_days );
                                break;
                            case 'rate-limiting':
                                self::render_rate_limiting_section( $rate_limit_enabled, $rate_limit_per_ip, $rate_limit_window );
                                break;
                            case 'white-label':
                                // Only reached when $is_premium (tabs_with_form excludes white-label for free users)
                                self::render_premium_white_label_section(
                                    $wl_sender_name, $wl_reply_to, $wl_logo_url,
                                    $wl_report_title, $wl_footer_cta
                                );
                                break;
                        }
                        ?>
                    </div>
                    <?php if ( $active_tab !== 'white-label' || $is_premium ) : ?>
                    <div class="fi-tab-form-footer">
                        <button type="submit" name="fi_save_settings" class="button button-primary button-large">
                            <?php _e( 'Save Settings', 'f-insights' ); ?>
                        </button>
                        <?php if ( $active_tab !== 'ip-exclusions' ) : ?>
                        <button type="button"
                                class="button button-secondary fi-reset-tab-defaults"
                                data-tab="<?php echo esc_attr( $active_tab ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'fi_admin_nonce' ) ); ?>"
                                style="margin-left:8px; color:#b45309; border-color:#b45309;">
                            <?php _e( 'Reset to Defaults', 'f-insights' ); ?>
                        </button>
                        <span class="fi-reset-tab-msg" style="margin-left:10px; font-size:13px; display:none;"></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

            <?php elseif ( $active_tab === 'shortcode' ) : ?>

                <div class="fi-tab-panel">
                    <?php self::render_shortcode_tab( $google_api_key, $claude_api_key, $cache_duration, $rate_limit_enabled, $rate_limit_per_ip, $rate_limit_window, $claude_model ); ?>
                </div>

            <?php elseif ( $active_tab === 'white-label' && ! $is_premium ) : ?>

                <div class="fi-tab-panel">
                    <?php self::render_premium_upsell(); ?>
                </div>

            <?php endif; ?>

        </div>
        <?php
    }

    
    /**
     * Render API Configuration section (always visible)
     */
    private static function render_api_configuration_section( $google_api_key, $claude_api_key, $claude_model_scan, $claude_model_internal, $claude_model_intel ) {
        // Model options shared across all three selects
        $models = array(
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 — Fastest · ~$0.01–$0.03/scan · Best for high-volume visitor scans',
            'claude-sonnet-4-20250514'  => 'Claude Sonnet 4 — Balanced · ~$0.03–$0.08/scan · Best for internal research & intelligence',
            'claude-opus-4-20250514'    => 'Claude Opus 4 — Deepest · ~$0.15–$0.40/scan · Best for high-stakes client presentations',
        );
        ?>
        <div class="fi-settings-section">
            <h2><?php _e( 'API Configuration', 'f-insights' ); ?></h2>
            <p class="description"><?php _e( 'One Claude API key works for everything. You can optionally create separate keys per workspace in the Anthropic Console — useful if you want separate billing or rate limits per use. Google Places is always one key.', 'f-insights' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fi_google_api_key"><?php _e( 'Google Places API Key', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="fi_google_api_key" name="fi_google_api_key"
                               value="<?php echo esc_attr( $google_api_key ); ?>" class="regular-text fi-api-key-input" autocomplete="off" />
                        <?php if ( $google_api_key ) : ?>
                            <span class="fi-key-status fi-key-configured">&#10003; <?php _e( 'Configured', 'f-insights' ); ?></span>
                        <?php else : ?>
                            <span class="fi-key-status fi-key-missing"><?php _e( 'Not set', 'f-insights' ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button button-secondary fi-toggle-key" data-target="fi_google_api_key" style="margin-left:6px;"><?php _e( 'Show', 'f-insights' ); ?></button>
                        <button type="button" class="button button-secondary fi-test-key" data-key-field="fi_google_api_key" data-action="fi_test_google_key" style="margin-left:6px;">
                            <?php _e( 'Test Connection', 'f-insights' ); ?>
                        </button>
                        <span class="fi-test-result" style="margin-left:8px; font-size:13px; font-weight:600;"></span>
                        <p class="description">
                            <?php _e( 'Get your key from', 'f-insights' ); ?>
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.<br>
                            <?php _e( 'Enable: <strong>Places API (New)</strong> and <strong>Maps JavaScript API</strong>.', 'f-insights' ); ?><br>
                        </p>
                        <?php if ( $google_api_key ) : ?>
                        <div class="notice notice-warning inline" style="margin:8px 0 4px;padding:8px 12px;">
                            <p style="margin:0;">
                                <strong>⚠️ <?php _e( 'Security reminder:', 'f-insights' ); ?></strong>
                                <?php _e( 'This key is loaded in the visitor\'s browser to render the competitor map, so it is visible in page source. You <strong>must</strong> set <strong>HTTP referrer restrictions</strong> (e.g. <code>https://yoursite.com/*</code>) in', 'f-insights' ); ?>
                                <a href="https://console.cloud.google.com/apis/credentials" target="_blank"><?php _e( 'Google Cloud Console', 'f-insights' ); ?></a>
                                <?php _e( 'to prevent unauthorized use of your key by third parties.', 'f-insights' ); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_claude_api_key"><?php _e( 'Claude API Key', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="fi_claude_api_key" name="fi_claude_api_key"
                               value="<?php echo esc_attr( $claude_api_key ); ?>" class="regular-text fi-api-key-input" autocomplete="off" />
                        <?php if ( $claude_api_key ) : ?>
                            <span class="fi-key-status fi-key-configured">&#10003; <?php _e( 'Configured', 'f-insights' ); ?></span>
                        <?php else : ?>
                            <span class="fi-key-status fi-key-missing"><?php _e( 'Not set', 'f-insights' ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button button-secondary fi-toggle-key" data-target="fi_claude_api_key" style="margin-left:6px;"><?php _e( 'Show', 'f-insights' ); ?></button>
                        <button type="button" class="button button-secondary fi-test-key" data-key-field="fi_claude_api_key" data-action="fi_test_claude_key" style="margin-left:6px;">
                            <?php _e( 'Test Connection', 'f-insights' ); ?>
                        </button>
                        <span class="fi-test-result" style="margin-left:8px; font-size:13px; font-weight:600;"></span>
                        <p class="description">
                            <?php _e( 'Get your key from', 'f-insights' ); ?>
                            <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic Console</a>.
                            <?php _e( 'One key works for all three scan modes below. To separate billing per mode, create one key per workspace in the Console and paste a different key here for each install.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="fi-settings-section">
            <h2><?php _e( 'Claude Model Selection', 'f-insights' ); ?></h2>
            <p class="description"><?php _e( 'Choose which Claude model powers each context. Haiku is cheapest for high-volume public scans. Sonnet is the best all-rounder for internal use. Opus is the deepest thinker; reserve it for when depth justifies the cost.', 'f-insights' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fi_claude_model_scan"><?php _e( 'Public Scanner', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <select id="fi_claude_model_scan" name="fi_claude_model_scan" class="regular-text">
                            <?php foreach ( $models as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $claude_model_scan, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e( 'Used when a visitor scans a business from your public-facing page. Volume can be high; Haiku keeps costs low without sacrificing scan quality.', 'f-insights' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fi_claude_model_internal"><?php _e( 'Admin Scanner', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <select id="fi_claude_model_internal" name="fi_claude_model_internal" class="regular-text">
                            <?php foreach ( $models as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $claude_model_internal, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e( 'Used when you run the scanner yourself from the Settings preview. This is your research tool; Sonnet or Opus gives you richer competitive narratives for prospect prep.', 'f-insights' ); ?></p>
                    </td>
                </tr>

            </table>
        </div>
        <?php
    }
    
    /**
     * Render Cache Settings section (always visible)
     */
    private static function render_cache_settings_section($cache_duration, $competitor_radius_miles, $autocomplete_radius_miles, $report_retention_days = 30) {
        ?>
        <div class="fi-settings-section">
            <h2><?php _e('Cache Settings', 'f-insights'); ?></h2>
            <p class="description"><?php _e('Control how long business data is cached to reduce API costs.', 'f-insights'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fi_cache_duration_preset"><?php _e('Cache Duration', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <?php
                        $presets = array(
                            0       => __( 'Disabled (always fetch fresh)', 'f-insights' ),
                            3600    => __( '1 hour', 'f-insights' ),
                            21600   => __( '6 hours', 'f-insights' ),
                            43200   => __( '12 hours', 'f-insights' ),
                            86400   => __( '24 hours (recommended)', 'f-insights' ),
                            172800  => __( '48 hours', 'f-insights' ),
                            604800  => __( '7 days', 'f-insights' ),
                            'custom'=> __( 'Custom (seconds)…', 'f-insights' ),
                        );
                        $is_preset = array_key_exists( (int) $cache_duration, $presets );
                        $preset_val = $is_preset ? (int) $cache_duration : 'custom';
                        ?>
                        <select id="fi_cache_duration_preset" style="max-width:260px;">
                            <?php foreach ( $presets as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $preset_val, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="fi-cache-custom-wrap" style="margin-top:8px; <?php echo $preset_val !== 'custom' ? 'display:none;' : ''; ?>">
                            <input type="number" id="fi_cache_duration"
                                   value="<?php echo esc_attr( $cache_duration ); ?>" min="0" step="60" class="small-text" />
                            <span style="margin-left:6px; font-size:13px; color:#646970;"><?php _e( 'seconds', 'f-insights' ); ?></span>
                        </div>
                        <input type="hidden" id="fi_cache_duration_hidden" name="fi_cache_duration"
                               value="<?php echo esc_attr( $cache_duration ); ?>" />
                        <p class="description">
                            <?php _e( 'How long to cache business scan results. Longer cache = fewer API calls and lower cost.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fi_competitor_radius_miles"><?php _e('Competitor Search Radius (miles)', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="fi_competitor_radius_miles" name="fi_competitor_radius_miles" 
                               value="<?php echo esc_attr($competitor_radius_miles); ?>" min="0.1" max="25" step="0.1" class="small-text" />
                        <p class="description">
                            <?php _e('How far to search for nearby competitors in miles. Default: 5 miles. Recommended range: 1-10 miles for local businesses.', 'f-insights'); ?>
                            <br><strong><?php _e('Tip:', 'f-insights'); ?></strong> <?php _e('Larger radius will show more competitors but may include less relevant businesses.', 'f-insights'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fi_autocomplete_radius_miles"><?php _e('Autocomplete Search Radius (miles)', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="fi_autocomplete_radius_miles" name="fi_autocomplete_radius_miles" 
                               value="<?php echo esc_attr($autocomplete_radius_miles); ?>" min="1" max="50" step="1" class="small-text" />
                        <p class="description">
                            <?php _e('Geographic range for business autocomplete suggestions when user shares location (in miles). Default: 10 miles. Recommended range: 5-10 miles for urban areas, 10-25 miles for rural areas.', 'f-insights'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="fi_report_retention_days"><?php _e('Shareable Report Retention (days)', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="fi_report_retention_days" name="fi_report_retention_days"
                               value="<?php echo esc_attr( $report_retention_days ); ?>"
                               min="0" max="90" step="1" class="small-text" />
                        <p class="description">
                            <?php _e( 'How many days a shared report link stays active (1–90). Set to <strong>0</strong> to disable link sharing entirely. Default: <strong>30 days</strong>.', 'f-insights' ); ?><br>
                            <?php _e( 'Visitors who open a shared link after this window will see an expiry notice and a prompt to run a fresh scan.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e('Clear Cache', 'f-insights'); ?></th>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=f-insights&action=clear_cache'), 'fi_clear_cache'); ?>" 
                           class="button button-secondary fi-clear-cache-link">
                            <?php _e('Clear All Cached Data', 'f-insights'); ?>
                        </a>
                        <p class="description">
                            <?php _e('Remove all cached business data. Use this after updating the plugin or if you are seeing outdated scan results.', 'f-insights'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render Rate Limiting section (always visible)
     */
    private static function render_rate_limiting_section($rate_limit_enabled, $rate_limit_per_ip, $rate_limit_window) {
        ?>
        <div class="fi-settings-section">
            <h2><?php _e('Rate Limiting', 'f-insights'); ?></h2>
            <p class="description"><?php _e('Prevent abuse by limiting scans per IP address.', 'f-insights'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fi_rate_limit_enabled"><?php _e('Enable Rate Limiting', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="fi_rate_limit_enabled" name="fi_rate_limit_enabled" 
                                   value="1" <?php checked($rate_limit_enabled, '1'); ?> />
                            <?php _e('Limit scans per IP address', 'f-insights'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="fi_rate_limit_per_ip"><?php _e('Max Scans Per IP', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="fi_rate_limit_per_ip" name="fi_rate_limit_per_ip" 
                               value="<?php echo esc_attr($rate_limit_per_ip); ?>" min="1" max="100" class="small-text" />
                        <p class="description">
                            <?php _e('Maximum number of scans allowed per time window.', 'f-insights'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="fi_rate_limit_window"><?php _e('Time Window', 'f-insights'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="fi_rate_limit_window" name="fi_rate_limit_window" 
                               value="<?php echo esc_attr($rate_limit_window); ?>" min="60" step="60" class="small-text" />
                        <span style="margin-left:8px; font-size:13px; color:#646970;">
                            <?php _e('seconds', 'f-insights'); ?>
                        </span>
                        <span id="fi-rate-window-label" style="margin-left:10px; font-size:13px; color:#2271b1; font-weight:600;">
                            <?php
                            // Render the initial human-readable equivalent server-side
                            $w = intval($rate_limit_window);
                            if ( $w >= 86400 && $w % 86400 === 0 )      echo '= ' . ($w/86400) . ' ' . _n('day','days',$w/86400,'f-insights');
                            elseif ( $w >= 3600 && $w % 3600 === 0 )    echo '= ' . ($w/3600)  . ' ' . _n('hour','hours',$w/3600,'f-insights');
                            elseif ( $w >= 60 && $w % 60 === 0 )        echo '= ' . ($w/60)    . ' ' . _n('minute','minutes',$w/60,'f-insights');
                            else                                          echo '= ' . $w         . ' ' . __('seconds','f-insights');
                            ?>
                        </span>
                        <p class="description">
                            <?php _e('How long before the scan counter resets per IP. Default: 3600 (= 1 hour).', 'f-insights'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render Analytics IP Blacklist panel — a self-contained form that saves
     * independently of the main Settings form. Rendered at the bottom of the
     * Analytics page for both free and premium users.
     *
     * Scans from blacklisted IPs are blocked with a polite error message
     * before any API call is made.
     *
     * @param string $blacklist Newline-separated list of saved IPs.
     */
    private static function render_analytics_ip_blacklist_section($blacklist) {
        ?>
        <div class="fi-analytics-section fi-ip-blacklist-section">

            <?php if ( get_transient('fi_ip_blacklist_saved') ): ?>
                <?php delete_transient('fi_ip_blacklist_saved'); ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom:16px;">
                    <p><?php _e('IP exclusion list saved.', 'f-insights'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php _e('Analytics IP Exclusions', 'f-insights'); ?></h2>
            <p class="description">
                <?php _e('Scans from IPs on this list are <strong>blocked with a polite message</strong> before any API call is made. Use this to keep your own testing out of your analytics data.', 'f-insights'); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('fi_ip_blacklist_nonce'); ?>

                <!-- Auto-detect row -->
                <div class="fi-ip-detect-row">
                    <span style="font-size:13px; color:#555;"><?php _e('Your current IP:', 'f-insights'); ?></span>
                    <code id="fi-detected-ip" class="fi-detected-ip-display"><?php _e('Detecting…', 'f-insights'); ?></code>
                    <button type="button" id="fi-add-my-ip" class="button button-secondary" disabled>
                        <?php _e('+ Add My IP', 'f-insights'); ?>
                    </button>
                    <span id="fi-ip-added-notice" class="fi-ip-added-notice" style="display:none;">
                        <?php _e('✓ Added', 'f-insights'); ?>
                    </span>
                </div>

                <!-- Manual textarea -->
                <textarea id="fi_analytics_ip_blacklist"
                          name="fi_analytics_ip_blacklist"
                          class="large-text fi-ip-blacklist-textarea"
                          rows="5"
                          placeholder="<?php esc_attr_e("One IP per line, e.g.\n192.168.1.1\n10.0.0.5\n2001:db8::1  (IPv6 supported)", 'f-insights'); ?>"><?php echo esc_textarea($blacklist); ?></textarea>

                <p class="description" style="margin-top:6px;">
                    <?php _e('One address per line. IPv4 and IPv6 supported. Invalid entries are silently dropped on save.', 'f-insights'); ?>
                </p>

                <p style="margin-top:12px;">
                    <button type="submit" name="fi_save_ip_blacklist" class="button button-primary">
                        <?php _e('Save IP Exclusions', 'f-insights'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Formerly rendered the CTA tab (free users).
     * All content — Search Placeholder, Scan Button, and Report-End CTA —
     * has been moved to render_premium_white_label_section() as premium-only
     * White-Label features. The CTA tab has been removed entirely.
     *
     * @deprecated 2.1.0  Do not call. Kept as a marker only.
     */
    private static function render_secondary_cta_section() {
        // Intentionally empty. See render_premium_white_label_section().
    }

    // -------------------------------------------------------------------------
    // Icon option lists
    // Centralised here so the same set is used in the White-Label render and
    // in the reset_tab_defaults AJAX handler without duplication.
    // -------------------------------------------------------------------------

    /**
     * Font Awesome Free icon options for the Scan Button.
     * Keys   = FA class string stored in the option.
     * Values = Human-readable label shown in the <select>.
     * Empty key = use the built-in SVG fallback.
     *
     * @return array<string,string>
     */
    /**
     * Render the White-Label settings tab (premium only).
     *
     * Sections, in order:
     *   1. Scanner Customization — Search placeholder, Scan button (label + icon)
     *   2. Report-End CTA        — Enable, text, icon, URL
     *   3. Email Report Button   — Placeholder, label, icon
     *   4. Brand Identity        — Sender name, reply-to, logo
     *   5. Email Report Settings — Report title, footer CTA
     *   6. Lead Notifications    — Alerts, recipients, threshold
     *
     * Typography controls were removed (v2.1.0) — they had no effect on the
     * frontend because the CSS variables are defined statically in frontend.css.
     */
    private static function render_premium_white_label_section(
        $wl_sender_name,
        $wl_reply_to,
        $wl_logo_url,
        $wl_report_title,
        $wl_footer_cta
    ) {
        // ── Read all option values up front ───────────────────────────────────
        $scan_placeholder    = get_option( 'fi_scan_placeholder', __( 'Search a business', 'f-insights' ) );
        $scan_btn_text       = get_option( 'fi_scan_btn_text',    __( 'Search Business',   'f-insights' ) );

        $wl_cta_button_enabled = get_option( 'fi_wl_cta_button_enabled', '0' );
        $wl_cta_button_text    = get_option( 'fi_wl_cta_button_text',    __( 'Book a Free Consultation', 'f-insights' ) );
        $wl_cta_button_url     = get_option( 'fi_wl_cta_button_url',     '' );
        $wl_hide_branding      = get_option( 'fi_wl_hide_branding',      '0' );

        $email_btn_text    = get_option( 'fi_email_btn_text',    __( 'Email Report',    'f-insights' ) );
        $email_placeholder = get_option( 'fi_email_placeholder', __( 'Enter your email', 'f-insights' ) );

        // ── Shared icon option lists ──────────────────────────────────────────
        ?>

        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 1. SCANNER CUSTOMIZATION                                           -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <h2><?php _e( 'Scanner Customization', 'f-insights' ); ?></h2>
            <p class="description">
                <?php _e( 'Tailor the search input and scan button to speak directly to your audience\'s niche.', 'f-insights' ); ?>
            </p>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_scan_placeholder"><?php _e( 'Input Placeholder', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_scan_placeholder" name="fi_scan_placeholder"
                               value="<?php echo esc_attr( $scan_placeholder ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Search a business', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Default: <strong>Search a business</strong>', 'f-insights' ); ?><br>
                            <?php _e( 'Try: "Enter your restaurant name" · "Search your salon" · "Find your auto shop" · "Look up your law firm"', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_scan_btn_text"><?php _e( 'Scan Button Label', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_scan_btn_text" name="fi_scan_btn_text"
                               value="<?php echo esc_attr( $scan_btn_text ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Search Business', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Default: <strong>Search Business</strong>', 'f-insights' ); ?><br>
                            <?php _e( 'Try: "Grade My Restaurant" · "Audit My Listing" · "Check My Salon" · "Score My Shop" · "Analyze My Practice"', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

            </table>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 2. REPORT-END CTA                                                  -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <h2><?php _e( 'Report-End CTA', 'f-insights' ); ?></h2>
            <p class="description">
                <?php _e( 'A button shown at the bottom of every scan report. Drive visitors to book a call, visit your contact page, email, or take any conversion action.', 'f-insights' ); ?>
            </p>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_wl_cta_button_enabled"><?php _e( 'Enable', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="fi_wl_cta_button_enabled" name="fi_wl_cta_button_enabled"
                                   value="1" <?php checked( $wl_cta_button_enabled, '1' ); ?> />
                            <?php _e( 'Show this button at the end of scan reports', 'f-insights' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_wl_cta_button_text"><?php _e( 'Button Text', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_wl_cta_button_text" name="fi_wl_cta_button_text"
                               value="<?php echo esc_attr( $wl_cta_button_text ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Book a Discovery Call', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Default: <strong>Book a Discovery Call</strong>', 'f-insights' ); ?><br>
                            <?php _e( 'Try: "Get a Free Fix Plan" · "Book a Vibe Check" · "Schedule My Strategy Call" · "Talk to an Expert" · "Claim Your Free Audit"', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_wl_cta_button_url"><?php _e( 'Link URL', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="fi_wl_cta_button_url" name="fi_wl_cta_button_url"
                               value="<?php echo esc_attr( $wl_cta_button_url ); ?>"
                               class="widefat"
                               style="max-width:350px;"
                               placeholder="<?php esc_attr_e( 'https://calendly.com/yourlink', 'f-insights' ); ?>" />
                        <p class="description"><?php _e( 'Opens in a new tab. Leave blank to disable the button even if the checkbox above is checked.', 'f-insights' ); ?></p>
                    </td>
                </tr>

        </div>


        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 2b. CREDIT LINK                                                     -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <h2><?php _e( 'Credit Link', 'f-insights' ); ?></h2>
            <p class="description">
                <?php _e( 'Control the small attribution link at the bottom of every scan report. You can also toggle it on the <strong>Shortcode &amp; Scanner</strong> tab.', 'f-insights' ); ?>
            </p>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_wl_hide_branding"><?php _e( 'Hide credit link', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="fi_wl_hide_branding" name="fi_wl_hide_branding"
                                   value="1" <?php checked( $wl_hide_branding, '1' ); ?> />
                            <?php _e( 'Check to remove the "get this tool for your wordpress site" link from scan reports', 'f-insights' ); ?>
                        </label>
                        <p class="description"><?php _e( 'When unchecked, a small "get this tool for your wordpress site" link linking to fricking.website/f-insights appears at the bottom of every report.', 'f-insights' ); ?></p>
                    </td>
                </tr>

            </table>
        </div>
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 3. EMAIL REPORT BUTTON                                             -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:4px;">
                <h2 style="margin:0;"><?php _e( 'Email Report Button', 'f-insights' ); ?></h2>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <button type="button"
                            id="fi-send-test-email"
                            class="button button-secondary"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'fi_admin_nonce' ) ); ?>">
                        ✉️ <?php _e( 'Send Test Email', 'f-insights' ); ?>
                    </button>
                    <span id="fi-test-email-msg" style="font-size:13px; display:none;"></span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=f-insights-wl-preview' ) ); ?>"
                       target="_blank"
                       class="button button-secondary">
                        📧 <?php _e( 'Preview Email Report →', 'f-insights' ); ?>
                    </a>
                </div>
            </div>
            <p class="description">
                <?php _e( 'The email capture field and button shown at the bottom of every report.', 'f-insights' ); ?>
            </p>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_email_placeholder"><?php _e( 'Input Placeholder', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_email_placeholder" name="fi_email_placeholder"
                               value="<?php echo esc_attr( $email_placeholder ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Enter your email', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Try: "Your work email" · "Where should we send it?" · "Drop your email here" · "Your best email"', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_email_btn_text"><?php _e( 'Button Label', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_email_btn_text" name="fi_email_btn_text"
                               value="<?php echo esc_attr( $email_btn_text ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Email Report', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Try: "Send to My Inbox" · "Email Me This" · "Get My Report" · "Send Report" · "Email My Results"', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

            </table>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 4. BRAND IDENTITY                                                  -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <h2><?php _e( 'Brand Identity', 'f-insights' ); ?></h2>
            <p class="description">
                <?php _e( 'Your agency\'s sender name, reply-to address, and logo shown in emailed reports.', 'f-insights' ); ?>
            </p>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_wl_sender_name"><?php _e( 'Brand Name', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_wl_sender_name" name="fi_wl_sender_name"
                               value="<?php echo esc_attr( $wl_sender_name ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'e.g. Your Agency Name', 'f-insights' ); ?>" />
                        <p class="description"><?php _e( 'Used as the "From" name in report emails.', 'f-insights' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_wl_reply_to"><?php _e( 'Reply-To Email', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="fi_wl_reply_to" name="fi_wl_reply_to"
                               value="<?php echo esc_attr( $wl_reply_to ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'hola@youragency.com', 'f-insights' ); ?>" />
                        <p class="description"><?php _e( 'Where client replies will be sent.', 'f-insights' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_wl_logo_url"><?php _e( 'Logo URL', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <input type="url" id="fi_wl_logo_url" name="fi_wl_logo_url"
                                   value="<?php echo esc_attr( $wl_logo_url ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'https://yoursite.com/logo.png', 'f-insights' ); ?>" />
                            <button type="button" id="fi-logo-media-btn" class="button button-secondary">
                                <?php _e( '📁 Choose from Media Library', 'f-insights' ); ?>
                            </button>
                        </div>
                        <div id="fi-logo-preview-wrap" style="margin-top:10px;<?php echo $wl_logo_url ? '' : ' display:none;'; ?>">
                            <img id="fi-logo-preview"
                                 src="<?php echo esc_url( $wl_logo_url ); ?>"
                                 alt="<?php esc_attr_e( 'Logo preview', 'f-insights' ); ?>"
                                 style="max-width:240px; max-height:80px; width:auto; height:auto; display:block; border:1px solid #ddd; border-radius:4px; padding:4px; background:#fff;" />
                        </div>
                        <p class="description" style="margin-top:6px;">
                            <?php _e( 'Logo shown at the top of email reports. PNG or SVG recommended.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

            </table>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 5. EMAIL REPORT SETTINGS                                           -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section">
            <h2><?php _e( 'Email Report Settings', 'f-insights' ); ?></h2>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_wl_report_title"><?php _e( 'Report Title', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fi_wl_report_title" name="fi_wl_report_title"
                               value="<?php echo esc_attr( $wl_report_title ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Your Business Insights Report', 'f-insights' ); ?>" />
                        <p class="description"><?php _e( 'Headline shown inside the email header.', 'f-insights' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_wl_footer_cta"><?php _e( 'Email Footer CTA', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <textarea id="fi_wl_footer_cta" name="fi_wl_footer_cta"
                                  class="large-text" rows="3"
                                  placeholder="<?php esc_attr_e( 'Want help putting these into action? Reply to this email.', 'f-insights' ); ?>"><?php echo esc_textarea( $wl_footer_cta ); ?></textarea>
                        <p class="description"><?php _e( 'Closing paragraph at the bottom of emails.', 'f-insights' ); ?></p>
                    </td>
                </tr>

            </table>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- 6. LEAD NOTIFICATIONS                                              -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <div class="fi-settings-section" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
            <h2><?php _e( 'Lead Notifications', 'f-insights' ); ?></h2>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="fi_lead_notifications_enabled"><?php _e( 'Email Alerts', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                            <input type="checkbox" id="fi_lead_notifications_enabled" name="fi_lead_notifications_enabled"
                                   value="1" <?php checked( get_option( 'fi_lead_notifications_enabled', '1' ), '1' ); ?> />
                            <span><?php _e( 'Email me when someone requests a report', 'f-insights' ); ?></span>
                        </label>

                        <input type="text" id="fi_lead_notification_email" name="fi_lead_notification_email"
                               value="<?php echo esc_attr( get_option( 'fi_lead_notification_email', get_option( 'admin_email' ) ) ); ?>"
                               class="large-text"
                               placeholder="<?php esc_attr_e( 'you@agency.com, teammate@agency.com', 'f-insights' ); ?>" />
                        <p class="description">
                            <?php _e( 'Separate multiple addresses with commas. All listed addresses will receive the lead alert.', 'f-insights' ); ?>
                        </p>

                        <label for="fi_lead_notification_threshold" style="display:block; margin-top:14px; font-weight:600; margin-bottom:4px;">
                            <?php _e( 'Only notify when score is at or below:', 'f-insights' ); ?>
                        </label>
                        <select id="fi_lead_notification_threshold" name="fi_lead_notification_threshold" style="min-width:180px;">
                            <?php
                            $current_threshold = intval( get_option( 'fi_lead_notification_threshold', 100 ) );
                            $threshold_options = array(
                                100 => __( 'Always notify (all scores)',          'f-insights' ),
                                90  => __( '90 — Skip only top performers',       'f-insights' ),
                                80  => __( '80 — Skip Excellent (80+)',           'f-insights' ),
                                70  => __( '70 — Skip Good + Excellent',          'f-insights' ),
                                60  => __( '60 — Needs Work and below only',      'f-insights' ),
                                50  => __( '50 — Critical leads only',            'f-insights' ),
                            );
                            foreach ( $threshold_options as $val => $label ) :
                                echo '<option value="' . esc_attr( $val ) . '"' . selected( $current_threshold, $val, false ) . '>' . esc_html( $label ) . '</option>';
                            endforeach;
                            ?>
                        </select>
                        <p class="description">
                            <?php _e( 'Reduces notification noise by suppressing alerts for leads that score above the threshold.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="fi_crm_webhook_url"><?php _e( 'CRM Webhook URL', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="fi_crm_webhook_url" name="fi_crm_webhook_url"
                               value="<?php echo esc_attr( get_option( 'fi_crm_webhook_url', '' ) ); ?>"
                               class="large-text"
                               placeholder="https://hooks.zapier.com/hooks/catch/…" />
                        <p class="description">
                            <?php _e( 'When a new lead is captured, F! Insights will POST the lead data as JSON to this URL. Leave blank to disable. Compatible with Zapier, Make (Integromat), GoHighLevel, HubSpot, and any webhook-capable CRM.', 'f-insights' ); ?>
                        </p>
                        <p class="description" style="margin-top:6px;">
                            <?php _e( 'Payload fields:', 'f-insights' ); ?>
                            <code>business_name</code>, <code>business_category</code>, <code>overall_score</code>,
                            <code>business_email</code>, <code>business_phone</code>, <code>business_website</code>,
                            <code>business_address</code>, <code>visitor_email</code>, <code>pain_points</code>,
                            <code>google_place_id</code>, <code>timestamp</code>, <code>source</code>
                        </p>
                    </td>
                </tr>

            </table>
        </div>

        <?php
    }

    /**
     * Render the trial / upgrade gate for free users on the White-Label tab.
     *
     * States:
     *  – Scans still needed  → show progress toward the trial unlock.
     *  – Trial lapsed        → settings are saved; prompt to subscribe.
     *  – Cancelled / default → same as lapsed (no history to reference).
     */
    private static function render_premium_upsell() {
        $scans_left  = FI_License::scans_until_trial();
        $scan_count  = FI_License::get_scan_count();
        $trial_lapsed = ( get_option( 'fi_trial_status', '' ) === 'lapsed' );
        ?>
        <div class="fi-ghost-lock" style="max-width:666px; margin:40px auto; text-align:center; background:#fff; border:2px solid #000; padding:40px; box-shadow:6px 6px 0 #000;">

            <?php if ( $trial_lapsed ) : ?>

                <!-- ── Trial lapsed ── -->
                <div style="font-size:40px; margin-bottom:16px;">⏳</div>
                <h2 style="margin:0 0 12px; font-size:20px; font-weight:700;">
                    <?php _e( 'Your trial ended. Your settings are still here.', 'f-insights' ); ?>
                </h2>
                <p style="font-size:14px; color:#444; margin-bottom:12px; line-height:1.7; max-width:460px; margin-left:auto; margin-right:auto;">
                    <?php _e( 'Everything you configured is saved and waiting — logo, colours, button text, all of it. Subscribe and it picks up exactly where you left off.', 'f-insights' ); ?>
                </p>
                <p style="font-size:13px; color:#666; margin-bottom:28px; line-height:1.6;">
                    <?php _e( 'Your renewal price is locked at the rate you originally subscribed at.', 'f-insights' ); ?>
                </p>
                <a href="https://fricking.website/pricing" target="_blank" class="button button-primary button-hero">
                    <?php _e( 'Reactivate &rarr;', 'f-insights' ); ?>
                </a>

            <?php else : ?>

                <!-- ── Progress toward trial unlock ── -->

                <!-- Three outcome pillars -->
                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:0; border:2px solid #000; margin-bottom:28px; text-align:left;">

                    <div style="padding:20px 18px; border-right:2px solid #000;">
                        <div style="font-size:22px; margin-bottom:8px;">📬</div>
                        <div style="font-size:13px; font-weight:700; color:#000; margin-bottom:6px; line-height:1.3;">
                            <?php _e( 'Clients get reports that look like you built them', 'f-insights' ); ?>
                        </div>
                        <div style="font-size:12px; color:#555; line-height:1.5;">
                            <?php _e( 'Your logo, your colours, your sign-off. No attribution anywhere.', 'f-insights' ); ?>
                        </div>
                    </div>

                    <div style="padding:20px 18px; border-right:2px solid #000;">
                        <div style="font-size:22px; margin-bottom:8px;">🎯</div>
                        <div style="font-size:13px; font-weight:700; color:#000; margin-bottom:6px; line-height:1.3;">
                            <?php _e( 'Leads arrive pre-qualified with their pain points listed', 'f-insights' ); ?>
                        </div>
                        <div style="font-size:12px; color:#555; line-height:1.5;">
                            <?php _e( 'Score, contact info, and the exact issues to open with.', 'f-insights' ); ?>
                        </div>
                    </div>

                    <div style="padding:20px 18px;">
                        <div style="font-size:22px; margin-bottom:8px;">📈</div>
                        <div style="font-size:13px; font-weight:700; color:#000; margin-bottom:6px; line-height:1.3;">
                            <?php _e( 'Every scan on your site builds your pipeline automatically', 'f-insights' ); ?>
                        </div>
                        <div style="font-size:12px; color:#555; line-height:1.5;">
                            <?php _e( 'New, contacted, closed. No spreadsheet needed.', 'f-insights' ); ?>
                        </div>
                    </div>

                </div>

                <?php if ( $scans_left > 0 ) : ?>
                    <!-- Progress counter -->
                    <div style="background:#f5f5f3; border:2px solid #000; padding:20px; margin-bottom:24px;">
                        <div style="font-size:48px; font-weight:700; line-height:1; color:#000; font-family:monospace;">
                            <?php echo esc_html( $scan_count ); ?><span style="font-size:24px; color:#888;">/10</span>
                        </div>
                        <div style="font-size:13px; font-weight:600; color:#000; margin-top:8px;">
                            <?php
                            echo esc_html( sprintf(
                                _n(
                                    '%d more organic scan and these settings unlock — free, for 30 days.',
                                    '%d more organic scans and these settings unlock — free, for 30 days.',
                                    $scans_left,
                                    'f-insights'
                                ),
                                $scans_left
                            ) );
                            ?>
                        </div>
                    </div>
                    <p style="font-size:13px; color:#666; margin-bottom:0; line-height:1.6;">
                        <?php _e( 'No card. No checkout. Visitors scan your shortcode, you earn access.', 'f-insights' ); ?>
                    </p>
                <?php else : ?>
                    <!-- Threshold reached but page somehow visible — should not normally occur -->
                    <p style="font-size:14px; color:#444; margin-bottom:0; line-height:1.7;">
                        <?php _e( 'You\'ve hit 10 scans. Refresh the page to see your trial dashboard.', 'f-insights' ); ?>
                    </p>
                <?php endif; ?>

            <?php endif; ?>

        </div>
        <?php
    }
    
    /**
     * Render Shortcode & Scanner tab — combines the old sidebar quick-start
     * content with the full frontend scanner embedded in the admin context.
     */
    private static function render_shortcode_tab( $google_api_key, $claude_api_key, $cache_duration, $rate_limit_enabled, $rate_limit_per_ip, $rate_limit_window, $claude_model = '' ) {
        ?>
        <div class="fi-settings-section">
            <h2><?php _e( 'Shortcode', 'f-insights' ); ?></h2>
            <p class="description"><?php _e( 'Paste this shortcode on any page or post to embed the public-facing scanner.', 'f-insights' ); ?></p>

            <div class="fi-shortcode-box">
                <code>[f_insights]</code>
                <button type="button" class="button button-small fi-copy-shortcode" data-clipboard-text="[f_insights]">
                    <?php _e( 'Copy', 'f-insights' ); ?>
                </button>
            </div>

            <?php
            // ── Page link helper ──────────────────────────────────────────────
            // Shows a dropdown of published pages so the admin can quickly get
            // the URL of whichever page has the shortcode embedded on it.
            $published_pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
            if ( ! empty( $published_pages ) ) :
            ?>
            <div style="margin-top:16px;">
                <label for="fi-page-link-select" style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">
                    <?php _e( 'Copy page link:', 'f-insights' ); ?>
                </label>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <select id="fi-page-link-select" style="max-width:340px;">
                        <option value=""><?php esc_html_e( '— select a page —', 'f-insights' ); ?></option>
                        <?php foreach ( $published_pages as $page ) : ?>
                            <option value="<?php echo esc_url( get_permalink( $page->ID ) ); ?>">
                                <?php echo esc_html( $page->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="fi-copy-page-link" class="button button-small" disabled>
                        <?php _e( 'Copy URL', 'f-insights' ); ?>
                    </button>
                    <a id="fi-open-page-link" href="#" target="_blank" class="button button-small" style="display:none;">
                        <?php _e( 'Open ↗', 'f-insights' ); ?>
                    </a>
                </div>
                <p class="description" style="margin-top:6px;">
                    <?php _e( 'Select the page where you placed <code>[f_insights]</code> to copy its URL or open it.', 'f-insights' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="fi-quick-start">
                <h4><?php _e( 'Quick Start', 'f-insights' ); ?></h4>
                <ol>
                    <li><?php _e( 'Configure your API keys on the <strong>API Configuration</strong> tab and save.', 'f-insights' ); ?></li>
                    <li><?php _e( 'Copy the shortcode above.', 'f-insights' ); ?></li>
                    <li><?php _e( 'Paste it on any page or post and publish.', 'f-insights' ); ?></li>
                </ol>
            </div>
        </div>

        <div class="fi-settings-section">
            <h2><?php _e( 'System Status', 'f-insights' ); ?></h2>
            <ul class="fi-system-status">
                <li>
                    <span class="dashicons <?php echo $google_api_key ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php _e( 'Google API:', 'f-insights' ); ?>
                    <strong><?php echo $google_api_key ? __( 'Configured', 'f-insights' ) : __( 'Not Configured', 'f-insights' ); ?></strong>
                </li>
                <li>
                    <span class="dashicons <?php echo $claude_api_key ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php _e( 'Claude API:', 'f-insights' ); ?>
                    <strong><?php echo $claude_api_key ? __( 'Configured', 'f-insights' ) : __( 'Not Configured', 'f-insights' ); ?></strong>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php _e( 'Cache:', 'f-insights' ); ?>
                    <strong><?php echo $cache_duration > 0 ? sprintf( __( '%s hours', 'f-insights' ), round( $cache_duration / 3600, 1 ) ) : __( 'Disabled', 'f-insights' ); ?></strong>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php _e( 'Rate Limit:', 'f-insights' ); ?>
                    <strong><?php echo $rate_limit_enabled ? sprintf( __( '%d scans per %s minutes', 'f-insights' ), $rate_limit_per_ip, round( $rate_limit_window / 60 ) ) : __( 'Disabled', 'f-insights' ); ?></strong>
                </li>
                <li>
                    <span class="dashicons dashicons-external"></span>
                    <?php _e( 'Claude API:', 'f-insights' ); ?>
                    <strong><a href="https://console.anthropic.com/settings/usage" target="_blank"><?php _e( 'Check token use →', 'f-insights' ); ?></a></strong>
                </li>
                <li>
                    <span class="dashicons <?php echo FI_Crypto::openssl_available() ? 'dashicons-lock' : 'dashicons-warning'; ?>"></span>
                    <?php _e( 'API Key Encryption:', 'f-insights' ); ?>
                    <strong><?php echo FI_Crypto::openssl_available() ? __( 'Active (AES-256)', 'f-insights' ) : __( 'Unavailable — openssl missing', 'f-insights' ); ?></strong>
                </li>
                <?php
                // Warn if WordPress is newer than the version this plugin was last tested on.
                // The tested_up_to value is read from readme.txt so there is a single source
                // of truth — updating readme.txt is all that's needed to clear this notice.
                $readme_path   = FI_PLUGIN_DIR . 'readme.txt';
                $tested_up_to  = '';
                if ( file_exists( $readme_path ) ) {
                    $first_kb = file_get_contents( $readme_path, false, null, 0, 1024 );
                    if ( preg_match( '/^Tested up to:\s*([\d.]+)/im', $first_kb, $m ) ) {
                        $tested_up_to = trim( $m[1] );
                    }
                }
                $wp_version = get_bloginfo( 'version' );
                // version_compare considers 6.7 < 6.7.1, so only warn when WP is strictly newer.
                if ( $tested_up_to && version_compare( $wp_version, $tested_up_to, '>' ) ) :
                ?>
                <li>
                    <span class="dashicons dashicons-warning" style="color:#f0b429;"></span>
                    <?php _e( 'WordPress Compatibility:', 'f-insights' ); ?>
                    <strong style="color:#b45309;">
                        <?php printf(
                            /* translators: 1: current WP version, 2: last tested version */
                            esc_html__( 'You are running WordPress %1$s — this plugin was last tested on %2$s. It should still work, but consider updating the plugin or checking for a newer release.', 'f-insights' ),
                            esc_html( $wp_version ),
                            esc_html( $tested_up_to )
                        ); ?>
                    </strong>
                </li>
                <?php elseif ( $tested_up_to ) : ?>
                <li>
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php _e( 'WordPress Compatibility:', 'f-insights' ); ?>
                    <strong><?php printf(
                        /* translators: %s: tested WP version */
                        esc_html__( 'Tested up to %s', 'f-insights' ),
                        esc_html( $tested_up_to )
                    ); ?></strong>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- ── Credit Link ──────────────────────────────────────────────────── -->
        <form method="post" action="">
            <?php wp_nonce_field( 'fi_credit_link_nonce' ); ?>
            <div class="fi-settings-section">
                <h2><?php _e( 'Credit Link', 'f-insights' ); ?></h2>
                <p class="description">
                    <?php _e( 'A small "get this tool for your wordpress site" link is shown at the bottom of every scan report. You can show it or hide it.', 'f-insights' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="fi_show_credit_link"><?php _e( 'Show credit link', 'f-insights' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="fi_show_credit_link" name="fi_show_credit_link"
                                       value="1" <?php checked( get_option( 'fi_show_credit_link', '1' ), '1' ); ?> />
                                <?php _e( 'Show a "get this tool for your wordpress site" link at the bottom of scan reports', 'f-insights' ); ?>
                            </label>
                            <p class="description">
                                <?php printf(
                                    /* translators: %s: URL */
                                    wp_kses( __( 'When checked, a small credit link pointing to <a href="%s" target="_blank" rel="noopener noreferrer">fricking.website/f-insights</a> appears at the bottom of every report.', 'f-insights' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
                                    'https://fricking.website/f-insights'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <div class="fi-tab-form-footer">
                    <button type="submit" name="fi_save_credit_link" class="button button-primary button-large">
                        <?php _e( 'Save Settings', 'f-insights' ); ?>
                    </button>
                </div>
            </div>
        </form>

        <div class="fi-settings-section">
            <h2><?php _e( 'Live Scanner Preview', 'f-insights' ); ?></h2>
            <p class="description"><?php _e( 'This is the same scanner your visitors use. Run it here to test or to look up a business for yourself.', 'f-insights' ); ?></p>

            <?php if ( ! $google_api_key || ! $claude_api_key ) : ?>
                <div class="notice notice-warning inline" style="margin-top:12px;">
                    <p><?php _e( 'Configure your Google and Claude API keys on the <strong>API Configuration</strong> tab before using the scanner.', 'f-insights' ); ?></p>
                </div>
            <?php else : ?>
                <div id="fi-admin-scanner-wrap" class="fi-admin-scanner-wrap">
                    <?php echo do_shortcode( '[f_insights]' ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @deprecated Retained for back-compat; shortcode + system status
     *             have moved to the Shortcode & Scanner settings tab.
     */
    private static function render_sidebar($google_api_key, $claude_api_key, $cache_duration, $rate_limit_enabled, $rate_limit_per_ip, $rate_limit_window) {
        // No-op: sidebar panel removed in tab refactor.
    }
    
    /**
     * Save settings — only writes options whose fields are present in $_POST.
     * Because each tab submits only its own fields, we must guard every option
     * with isset() so that saving on the API tab never silently resets the
     * competitor radius (a Cache tab field) and vice versa.
     */

    /**
     * Purge full-page caches from common caching plugins so that updated
     * settings (especially ctaButton and brand options that are baked into the
     * localized fInsights JS object) take effect immediately on the frontend
     * without requiring a manual cache flush.
     *
     * Covers: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache,
     * SG Optimizer, Cloudflare for WP, Swift Performance, WP Fastest Cache.
     */
    private static function purge_page_caches() {
        // WP Rocket
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        // LiteSpeed Cache
        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            LiteSpeed_Cache_API::purge_all();
        } elseif ( function_exists( 'litespeed_purge_all' ) ) {
            litespeed_purge_all();
        }
        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
        // WP Super Cache
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        // WP Fastest Cache
        if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
            $GLOBALS['wp_fastest_cache']->deleteCache( true );
        }
        // SG Optimizer (SiteGround)
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();
        }
        // Swift Performance
        if ( class_exists( 'Swift_Performance_Cache' ) ) {
            Swift_Performance_Cache::clear_all_cache();
        }
        // Cloudflare plugin
        if ( class_exists( 'CF\WordPress\Hooks' ) ) {
            do_action( 'cloudflare_purge_everything' );
        }
        // Generic hook — lets any other caching layer respond
        do_action( 'fi_after_settings_save' );
    }

    private static function save_settings() {
        // ── API Configuration tab ────────────────────────────────────────────
        if ( isset( $_POST['fi_google_api_key'] ) ) {
            FI_Crypto::save_key( FI_Crypto::GOOGLE_KEY_OPTION, sanitize_text_field( wp_unslash( $_POST['fi_google_api_key'] ) ) );
        }
        if ( isset( $_POST['fi_claude_api_key'] ) ) {
            FI_Crypto::save_key( FI_Crypto::CLAUDE_KEY_OPTION, sanitize_text_field( wp_unslash( $_POST['fi_claude_api_key'] ) ) );
        }
        // Three per-context model selectors (replace the old single fi_claude_model)
        $valid_models = array( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-20250514', 'claude-opus-4-20250514' );
        if ( isset( $_POST['fi_claude_model_scan'] ) && in_array( wp_unslash( $_POST['fi_claude_model_scan'] ), $valid_models, true ) ) {
            update_option( 'fi_claude_model_scan', sanitize_text_field( wp_unslash( $_POST['fi_claude_model_scan'] ) ) );
        }
        if ( isset( $_POST['fi_claude_model_internal'] ) && in_array( wp_unslash( $_POST['fi_claude_model_internal'] ), $valid_models, true ) ) {
            update_option( 'fi_claude_model_internal', sanitize_text_field( wp_unslash( $_POST['fi_claude_model_internal'] ) ) );
        }
        if ( isset( $_POST['fi_claude_model_intel'] ) && in_array( wp_unslash( $_POST['fi_claude_model_intel'] ), $valid_models, true ) ) {
            update_option( 'fi_claude_model_intel', sanitize_text_field( wp_unslash( $_POST['fi_claude_model_intel'] ) ) );
        }

        // ── Cache Settings tab ───────────────────────────────────────────────
        if ( isset( $_POST['fi_cache_duration'] ) ) {
            update_option( 'fi_cache_duration', absint( wp_unslash( $_POST['fi_cache_duration'] ) ) );
        }
        if ( isset( $_POST['fi_competitor_radius_miles'] ) ) {
            $competitor_radius = floatval( wp_unslash( $_POST['fi_competitor_radius_miles'] ) );
            update_option( 'fi_competitor_radius_miles', ( $competitor_radius >= 0.1 && $competitor_radius <= 25 ) ? $competitor_radius : 5 );
        }
        if ( isset( $_POST['fi_autocomplete_radius_miles'] ) ) {
            $autocomplete_radius = floatval( wp_unslash( $_POST['fi_autocomplete_radius_miles'] ) );
            update_option( 'fi_autocomplete_radius_miles', ( $autocomplete_radius >= 1 && $autocomplete_radius <= 50 ) ? $autocomplete_radius : 10 );
        }
        if ( isset( $_POST['fi_report_retention_days'] ) ) {
            $days = absint( wp_unslash( $_POST['fi_report_retention_days'] ) );
            update_option( 'fi_report_retention_days', min( $days, 90 ) );
        }

        // ── Rate Limiting tab ────────────────────────────────────────────────
        // Checkbox: only present in POST when the Rate Limiting tab is active.
        // Use fi_active_tab to distinguish "unchecked" from "different tab".
        if ( isset( $_POST['fi_active_tab'] ) && wp_unslash( $_POST['fi_active_tab'] ) === 'rate-limiting' ) {
            update_option( 'fi_rate_limit_enabled', isset( $_POST['fi_rate_limit_enabled'] ) ? '1' : '0' );
        }
        if ( isset( $_POST['fi_rate_limit_per_ip'] ) ) {
            update_option( 'fi_rate_limit_per_ip', absint( wp_unslash( $_POST['fi_rate_limit_per_ip'] ) ) );
        }
        if ( isset( $_POST['fi_rate_limit_window'] ) ) {
            update_option( 'fi_rate_limit_window', absint( wp_unslash( $_POST['fi_rate_limit_window'] ) ) );
        }

        // ── White-Label tab — premium only ───────────────────────────────────
        if ( self::is_premium() ) {

            // ── Scanner customization (Search Placeholder + Scan Button) ─────
            if ( isset( $_POST['fi_scan_placeholder'] ) ) {
                $text = sanitize_text_field( wp_unslash( $_POST['fi_scan_placeholder'] ) );
                update_option( 'fi_scan_placeholder', $text !== '' ? $text : __( 'Search a business', 'f-insights' ) );
            }
            if ( isset( $_POST['fi_scan_btn_text'] ) ) {
                $text = sanitize_text_field( wp_unslash( $_POST['fi_scan_btn_text'] ) );
                update_option( 'fi_scan_btn_text', $text !== '' ? $text : __( 'Search Business', 'f-insights' ) );
            }

            // ── Report-End CTA ────────────────────────────────────────────────
            // Checkbox: only update when the white-label tab is active so an
            // unchecked box from a different tab form doesn't silently disable it.
            if ( isset( $_POST['fi_active_tab'] ) && wp_unslash( $_POST['fi_active_tab'] ) === 'white-label' ) {
                update_option( 'fi_wl_cta_button_enabled', isset( $_POST['fi_wl_cta_button_enabled'] ) ? '1' : '0' );
                update_option( 'fi_wl_hide_branding',      isset( $_POST['fi_wl_hide_branding'] )      ? '1' : '0' );
            }
            if ( isset( $_POST['fi_wl_cta_button_text'] ) ) {
                update_option( 'fi_wl_cta_button_text', sanitize_text_field( wp_unslash( $_POST['fi_wl_cta_button_text'] ) ) );
            }
            if ( isset( $_POST['fi_wl_cta_button_url'] ) ) {
                update_option( 'fi_wl_cta_button_url', esc_url_raw( wp_unslash( $_POST['fi_wl_cta_button_url'] ) ) );
            }

            // ── Email Report Button ───────────────────────────────────────────
            if ( isset( $_POST['fi_email_btn_text'] ) ) {
                $text = sanitize_text_field( wp_unslash( $_POST['fi_email_btn_text'] ) );
                update_option( 'fi_email_btn_text', $text !== '' ? $text : __( 'Email Report', 'f-insights' ) );
            }
            if ( isset( $_POST['fi_email_placeholder'] ) ) {
                $text = sanitize_text_field( wp_unslash( $_POST['fi_email_placeholder'] ) );
                update_option( 'fi_email_placeholder', $text !== '' ? $text : __( 'Enter your email', 'f-insights' ) );
            }

            // ── Brand Identity ────────────────────────────────────────────────
            if ( isset( $_POST['fi_wl_sender_name'] ) ) {
                update_option( 'fi_wl_sender_name', sanitize_text_field( wp_unslash( $_POST['fi_wl_sender_name'] ) ) );
            }
            if ( isset( $_POST['fi_wl_reply_to'] ) ) {
                update_option( 'fi_wl_reply_to', sanitize_email( wp_unslash( $_POST['fi_wl_reply_to'] ) ) );
            }
            if ( isset( $_POST['fi_wl_logo_url'] ) ) {
                update_option( 'fi_wl_logo_url', esc_url_raw( wp_unslash( $_POST['fi_wl_logo_url'] ) ) );
            }

            // ── Email Report Settings ─────────────────────────────────────────
            if ( isset( $_POST['fi_wl_report_title'] ) ) {
                update_option( 'fi_wl_report_title', sanitize_text_field( wp_unslash( $_POST['fi_wl_report_title'] ) ) );
            }
            if ( isset( $_POST['fi_wl_footer_cta'] ) ) {
                update_option( 'fi_wl_footer_cta', sanitize_textarea_field( wp_unslash( $_POST['fi_wl_footer_cta'] ) ) );
            }

            // ── Lead Notifications ────────────────────────────────────────────
            // Checkbox: only update when the white-label tab is active.
            if ( isset( $_POST['fi_active_tab'] ) && wp_unslash( $_POST['fi_active_tab'] ) === 'white-label' ) {
                update_option( 'fi_lead_notifications_enabled', isset( $_POST['fi_lead_notifications_enabled'] ) ? '1' : '0' );
            }
            if ( isset( $_POST['fi_lead_notification_email'] ) ) {
                // Comma-separated list — sanitize each address individually.
                $raw_emails   = sanitize_text_field( wp_unslash( $_POST['fi_lead_notification_email'] ) );
                $clean_emails = implode( ', ', array_filter( array_map(
                    'sanitize_email',
                    array_map( 'trim', explode( ',', $raw_emails ) )
                ) ) );
                update_option( 'fi_lead_notification_email', $clean_emails );
            }
            if ( isset( $_POST['fi_lead_notification_threshold'] ) ) {
                update_option( 'fi_lead_notification_threshold', intval( wp_unslash( $_POST['fi_lead_notification_threshold'] ) ) );
            }
            if ( isset( $_POST['fi_crm_webhook_url'] ) ) {
                $webhook_url = esc_url_raw( wp_unslash( $_POST['fi_crm_webhook_url'] ) );
                // Only accept http/https URLs; blank string is also valid (disables webhook).
                if ( $webhook_url === '' || strpos( $webhook_url, 'http' ) === 0 ) {
                    update_option( 'fi_crm_webhook_url', $webhook_url );
                }
            }
        }
    }

    /**
     * Save the analytics IP blacklist from the Analytics page form.
     * Validates each line as a real IP address — invalid entries are dropped.
     */
    private static function save_ip_blacklist() {
        $raw_ips    = wp_unslash( $_POST['fi_analytics_ip_blacklist'] ?? '' );
        $lines      = preg_split( '/[\r\n]+/', $raw_ips );
        $clean_ips  = array();
        foreach ( $lines as $line ) {
            $ip = trim( $line );
            if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                $clean_ips[] = $ip;
            }
        }
        update_option( 'fi_analytics_ip_blacklist', implode( "\n", array_unique( $clean_ips ) ) );
    }
    
    /**
     * Clear test leads (v1.6.0)
     * Deletes leads with specific test patterns in business name or email
     */
    private static function clear_test_leads() {
        global $wpdb;
        $table = $wpdb->prefix . 'fi_leads';
        
        // Delete leads with "test" in business name or visitor email
        $wpdb->query(
            "DELETE FROM $table 
            WHERE LOWER(business_name) LIKE '%test%' 
            OR LOWER(visitor_email) LIKE '%test%'
            OR business_name = 'Example Business'"
        );
        
        FI_Logger::info('Test leads cleared from database');
    }
    
    /**
     * Reset all analytics data (v1.6.0)
     * Clears both leads and scan analytics tables
     */
    private static function reset_all_analytics() {
        global $wpdb;
        
        // Clear leads
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}fi_leads");
        
        // Clear analytics
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}fi_analytics");
        
        // Reset free scan counter
        update_option('fi_free_scan_count', 0);
        
        FI_Logger::info('All analytics data reset');
    }
    
    public static function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ── Resolve active tab ────────────────────────────────────────────────
        $valid_tabs = array( 'lead-overview', 'all-leads', 'scan-analytics', 'data-management' );
        $active_tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true )
            ? sanitize_key( $_GET['tab'] )
            : 'lead-overview';

        $is_premium = self::is_premium();

        // Date range filter — passed through GET params on the analytics page.
        // Validated (YYYY-MM-DD format) before use.
        $filter_date_from = '';
        $filter_date_to   = '';
        if ( $active_tab === 'scan-analytics' && $is_premium ) {
            $raw_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
            $raw_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '';
            if ( $raw_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_from ) ) {
                $filter_date_from = $raw_from;
            }
            if ( $raw_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_to ) ) {
                $filter_date_to = $raw_to;
            }
        }

        // Data only needed for premium
        $analytics  = $is_premium ? FI_Analytics::get_dashboard_data( array(
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
        ) ) : array();
        $leads_data = $is_premium ? FI_Analytics::get_leads_data() : array();
        ?>
        <div class="wrap fi-admin-wrap">
            <h1><?php _e( 'Your Market Intel', 'f-insights' ); ?></h1>

            <?php if ( get_transient( 'fi_test_leads_cleared' ) ): ?>
                <?php delete_transient( 'fi_test_leads_cleared' ); ?>
                <div class="notice notice-success is-dismissible"><p><?php _e( 'Test leads cleared successfully.', 'f-insights' ); ?></p></div>
            <?php endif; ?>

            <?php if ( get_transient( 'fi_analytics_reset' ) ): ?>
                <?php delete_transient( 'fi_analytics_reset' ); ?>
                <div class="notice notice-success is-dismissible"><p><?php _e( 'All analytics data has been reset.', 'f-insights' ); ?></p></div>
            <?php endif; ?>

            <!-- ── Tab navigation ──────────────────────────────────────────── -->
            <nav class="fi-tab-nav" aria-label="<?php esc_attr_e( 'Market Intel sections', 'f-insights' ); ?>">
                <?php
                $tabs = array(
                    'lead-overview'   => __( 'Lead Overview', 'f-insights' ),
                    'all-leads'       => __( 'All Leads', 'f-insights' ),
                    'scan-analytics'  => __( 'Scan Intel', 'f-insights' ),
                    'data-management' => __( '⚠ Data', 'f-insights' ),
                );
                foreach ( $tabs as $slug => $label ) :
                    $url = add_query_arg( array( 'page' => 'f-insights-analytics', 'tab' => $slug ), admin_url( 'admin.php' ) );
                    $is_active = ( $active_tab === $slug );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="fi-tab-link<?php echo $is_active ? ' fi-tab-active' : ''; ?><?php echo $slug === 'data-management' ? ' fi-tab-danger' : ''; ?>"
                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $label ); ?>
                        <?php if ( ! $is_premium && $slug !== 'data-management' ) : ?><span class="fi-tab-lock" title="<?php esc_attr_e( 'Premium feature', 'f-insights' ); ?>"><span aria-hidden="true">🔒</span><span class="screen-reader-text"><?php esc_html_e( '(Premium)', 'f-insights' ); ?></span></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- ── Tab content ─────────────────────────────────────────────── -->
            <div class="fi-tab-panel">
                <?php if ( ! $is_premium ) : ?>
                    <?php self::render_analytics_premium_gate(); ?>
                <?php else : ?>
                    <?php
                    switch ( $active_tab ) {
                        case 'lead-overview':
                            self::render_analytics_tab_lead_overview( $leads_data );
                            break;
                        case 'all-leads':
                            self::render_analytics_tab_all_leads( $leads_data );
                            break;
                        case 'scan-analytics':
                            self::render_analytics_tab_scan_analytics( $analytics, $leads_data );
                            break;
                        case 'data-management':
                            self::render_analytics_tab_data_management();
                            break;
                    }
                    ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- Report Viewer Modal (v2.0.2) -->
        <div id="fi-report-modal" class="fi-modal" style="display:none;">
            <div class="fi-modal-overlay"></div>
            <div class="fi-modal-content fi-modal-content--report">
                <div class="fi-modal-header">
                    <h2 id="fi-report-title"><?php _e( 'Business Report', 'f-insights' ); ?></h2>
                    <button type="button" class="fi-modal-close" aria-label="<?php esc_attr_e( 'Close', 'f-insights' ); ?>">&times;</button>
                </div>
                <div class="fi-modal-body" id="fi-report-body">
                    <div class="fi-loading"><?php _e( 'Loading report…', 'f-insights' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Trial / upgrade gate shown to free users on the Analytics page.
     *
     * States:
     *  – No scans yet        → explain what will be recorded; show scan CTA.
     *  – Scans still needed  → show real count + how many remain until trial.
     *  – Trial lapsed        → show count they're locked out of; prompt renewal.
     */
    private static function render_analytics_premium_gate() {
        $scan_count  = FI_License::get_scan_count();
        $scans_left  = FI_License::scans_until_trial();
        $trial_lapsed = ( get_option( 'fi_trial_status', '' ) === 'lapsed' );
        ?>
        <div class="fi-ghost-lock" style="max-width:666px; margin:40px auto; text-align:center; background:#fff; border:2px solid #000; padding:40px; box-shadow:6px 6px 0 #000;">

            <?php if ( $trial_lapsed ) : ?>

                <!-- ── Trial lapsed ── -->
                <?php if ( $scan_count > 0 ) : ?>
                    <div style="background:#f5f5f3; border:2px solid #000; padding:20px; margin-bottom:28px;">
                        <div style="font-size:52px; font-weight:700; line-height:1; color:#000; font-family:monospace;">
                            <?php echo esc_html( number_format( $scan_count ) ); ?>
                        </div>
                        <div style="font-size:14px; font-weight:600; color:#000; margin-top:6px;">
                            <?php
                            echo esc_html( sprintf(
                                _n(
                                    'scan recorded. Locked until you reactivate.',
                                    'scans recorded. Locked until you reactivate.',
                                    $scan_count,
                                    'f-insights'
                                ),
                                $scan_count
                            ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <h2 style="margin:0 0 12px; font-size:20px; font-weight:700;">
                    <?php _e( 'Your trial ended. Your data didn\'t go anywhere.', 'f-insights' ); ?>
                </h2>
                <p style="font-size:14px; color:#444; margin-bottom:28px; line-height:1.7; max-width:460px; margin-left:auto; margin-right:auto;">
                    <?php _e( 'Every scan, lead, and market data point collected during your trial is still here. Subscribe to unlock it. Your renewal price is locked at your original rate.', 'f-insights' ); ?>
                </p>
                <a href="https://fricking.website/pricing" target="_blank" class="button button-primary button-hero">
                    <?php _e( 'Reactivate &rarr;', 'f-insights' ); ?>
                </a>

            <?php elseif ( $scan_count === 0 ) : ?>

                <!-- ── No scans yet ── -->
                <span class="dashicons dashicons-chart-line" style="font-size:40px; width:40px; height:40px; color:#000; margin-bottom:16px; display:block;"></span>
                <h2 style="margin:0 0 12px; font-size:20px; font-weight:700;">
                    <?php _e( 'Your market is out there. Start scanning it.', 'f-insights' ); ?>
                </h2>
                <p style="font-size:14px; color:#444; margin-bottom:16px; line-height:1.7; max-width:460px; margin-left:auto; margin-right:auto;">
                    <?php _e( 'Every business scanned through your shortcode becomes a data point — category, score, pain points, contact info. After 10 organic scans, this whole dashboard unlocks free for 30 days.', 'f-insights' ); ?>
                </p>
                <p style="font-size:13px; color:#666; margin-bottom:0;">
                    <?php _e( 'No card. No checkout. Just put the scanner in front of people and let them use it.', 'f-insights' ); ?>
                </p>

            <?php else : ?>

                <!-- ── Scans accumulating, trial not yet unlocked ── -->
                <div style="background:#f5f5f3; border:2px solid #000; padding:20px; margin-bottom:28px;">
                    <div style="font-size:52px; font-weight:700; line-height:1; color:#000; font-family:monospace;">
                        <?php echo esc_html( number_format( $scan_count ) ); ?><span style="font-size:26px; color:#888;">/10</span>
                    </div>
                    <div style="font-size:14px; font-weight:600; color:#000; margin-top:6px;">
                        <?php
                        echo esc_html( sprintf(
                            _n(
                                'business scanned on your site.',
                                'businesses scanned on your site.',
                                $scan_count,
                                'f-insights'
                            ),
                            $scan_count
                        ) );
                        ?>
                    </div>
                    <div style="font-size:13px; color:#555; margin-top:8px; line-height:1.5;">
                        <?php
                        if ( $scan_count === 1 ) {
                            _e( 'One scan. You don\'t know who they are yet. They know exactly where they stand.', 'f-insights' );
                        } else {
                            echo esc_html( sprintf(
                                _n(
                                    '%d more scan and this whole dashboard unlocks for 30 days.',
                                    '%d more scans and this whole dashboard unlocks for 30 days.',
                                    $scans_left,
                                    'f-insights'
                                ),
                                $scans_left
                            ) );
                        }
                        ?>
                    </div>
                </div>

                <h2 style="margin:0 0 12px; font-size:20px; font-weight:700;">
                    <?php _e( 'The data is here. You just can\'t see it yet.', 'f-insights' ); ?>
                </h2>
                <p style="font-size:14px; color:#444; margin-bottom:16px; line-height:1.7; max-width:460px; margin-left:auto; margin-right:auto;">
                    <?php _e( 'Every scan records the business name, category, score, and pain points. As the picture fills in — industries, gaps, patterns — it becomes the kind of market intelligence that makes your pitch land differently.', 'f-insights' ); ?>
                </p>
                <p style="font-size:13px; color:#666; margin-bottom:0;">
                    <?php _e( 'No card. No checkout. Keep scanning.', 'f-insights' ); ?>
                </p>

            <?php endif; ?>

        </div>
        <?php
    }
    /** Analytics: Lead Overview tab */
    private static function render_analytics_tab_lead_overview( $leads_data ) {
        ?>
        <div class="fi-analytics-container">
            <div class="fi-stats-grid">
                <div class="fi-stat-card">
                    <h3><?php _e( 'Total Leads', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $leads_data['total_leads'] ) ); ?></div>
                    <div class="fi-stat-label"><?php _e( 'Email reports requested', 'f-insights' ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'This Month', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $leads_data['month_leads'] ) ); ?></div>
                    <div class="fi-stat-label"><?php _e( 'New leads captured', 'f-insights' ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'New Leads', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number fi-stat-highlight"><?php echo esc_html( number_format( $leads_data['counts']['new'] ) ); ?></div>
                    <div class="fi-stat-label"><?php _e( 'Need follow-up', 'f-insights' ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'Closed', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number fi-stat-success"><?php echo esc_html( number_format( $leads_data['counts']['closed'] ) ); ?></div>
                    <div class="fi-stat-label"><?php _e( 'Deals won!', 'f-insights' ); ?></div>
                </div>
            </div>

            <?php if ( ! empty( $leads_data['new_leads'] ) ) : ?>
            <div class="fi-analytics-section fi-leads-urgent">
                <h2>🔥 <?php _e( 'New Leads — Last 24 Hours', 'f-insights' ); ?></h2>
                <p class="fi-leads-subtitle"><?php _e( 'These leads are hot! Follow up now for best results.', 'f-insights' ); ?></p>
                <?php foreach ( $leads_data['new_leads'] as $lead ) :
                    $pain_points = json_decode( $lead['pain_points'], true ) ?: array();
                    $time_ago    = human_time_diff( strtotime( $lead['request_date'] ), current_time( 'timestamp' ) );
                ?>
                <div class="fi-lead-card">
                    <div class="fi-lead-header">
                        <h3><?php echo esc_html( $lead['business_name'] ); ?></h3>
                        <span class="fi-score-badge fi-score-<?php echo self::get_score_class( $lead['overall_score'] ); ?>">
                            <?php echo esc_html( $lead['overall_score'] ); ?>/100
                        </span>
                    </div>
                    <div class="fi-lead-details">
                        <?php if ( ! empty( $lead['business_email'] ) ) : ?>
                            <div class="fi-lead-contact"><strong>📧 <?php _e( 'Business:', 'f-insights' ); ?></strong> <a href="mailto:<?php echo esc_attr( $lead['business_email'] ); ?>"><?php echo esc_html( $lead['business_email'] ); ?></a></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $lead['business_phone'] ) ) : ?>
                            <div class="fi-lead-contact"><strong>📞 <?php _e( 'Phone:', 'f-insights' ); ?></strong> <a href="tel:<?php echo esc_attr( $lead['business_phone'] ); ?>"><?php echo esc_html( $lead['business_phone'] ); ?></a></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $lead['business_website'] ) ) : ?>
                            <div class="fi-lead-contact"><strong>🌐 <?php _e( 'Website:', 'f-insights' ); ?></strong> <a href="<?php echo esc_url( $lead['business_website'] ); ?>" target="_blank"><?php echo esc_html( $lead['business_website'] ); ?></a></div>
                        <?php endif; ?>
                        <div class="fi-lead-contact"><strong>📩 <?php _e( 'Requested by:', 'f-insights' ); ?></strong> <a href="mailto:<?php echo esc_attr( $lead['visitor_email'] ); ?>"><?php echo esc_html( $lead['visitor_email'] ); ?></a></div>
                        <div class="fi-lead-meta">
                            <span><strong>⏰</strong> <?php echo esc_html( $time_ago ); ?> <?php _e( 'ago', 'f-insights' ); ?></span>
                            <?php if ( ! empty( $lead['business_category'] ) ) : ?><span><strong>💼</strong> <?php echo esc_html( $lead['business_category'] ); ?></span><?php endif; ?>
                        </div>
                        <?php if ( ! empty( $pain_points ) ) : ?>
                        <div class="fi-lead-pain-points">
                            <strong>🚨 <?php _e( 'Top Issues:', 'f-insights' ); ?></strong>
                            <ul>
                                <?php foreach ( array_slice( $pain_points, 0, 3 ) as $pain ) : ?>
                                    <li><strong><?php echo esc_html( $pain['category'] ); ?></strong> (<?php echo esc_html( $pain['score'] ); ?>/100): <?php echo esc_html( $pain['headline'] ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Analytics: All Leads tab */
    private static function render_analytics_tab_all_leads( $leads_data ) {
        ?>
        <div class="fi-analytics-section">

            <!-- ── Pipeline summary + export ───────────────────────────────── -->
            <p class="fi-leads-subtitle" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <span>
                    <?php _e( 'Pipeline:', 'f-insights' ); ?>
                    <strong><?php echo esc_html( number_format( $leads_data['counts']['new'] ) ); ?></strong> <?php _e( 'New', 'f-insights' ); ?> |
                    <strong><?php echo esc_html( number_format( $leads_data['counts']['contacted'] ) ); ?></strong> <?php _e( 'Contacted', 'f-insights' ); ?> |
                    <strong><?php echo esc_html( number_format( $leads_data['counts']['qualified'] ) ); ?></strong> <?php _e( 'Qualified', 'f-insights' ); ?> |
                    <strong><?php echo esc_html( number_format( $leads_data['counts']['closed'] ) ); ?></strong> <?php _e( 'Closed', 'f-insights' ); ?> |
                    <strong><?php echo esc_html( number_format( $leads_data['counts']['lost'] ) ); ?></strong> <?php _e( 'Lost', 'f-insights' ); ?>
                </span>
                <button type="button" id="fi-export-csv-btn" class="button button-secondary">
                    📥 <?php _e( 'Export to CSV', 'f-insights' ); ?>
                </button>

                <!-- Column picker modal (hidden by default) -->
                <div id="fi-csv-picker-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;">
                    <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);" id="fi-csv-picker-overlay"></div>
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:420px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.25);z-index:1;">
                        <h3 style="margin:0 0 12px;"><?php _e( 'Export Options', 'f-insights' ); ?></h3>

                        <p style="font-size:13px;color:#646970;margin-bottom:16px;">
                            <?php _e( 'Select columns and filters. Only selected columns appear in the downloaded file.', 'f-insights' ); ?>
                        </p>

                        <!-- Column checkboxes -->
                        <fieldset style="border:1px solid #ddd;border-radius:4px;padding:12px;margin-bottom:16px;">
                            <legend style="font-size:12px;font-weight:600;color:#50575e;padding:0 6px;"><?php _e( 'Columns', 'f-insights' ); ?></legend>
                            <?php
                            $csv_columns = array(
                                'business_name'     => __( 'Business Name', 'f-insights' ),
                                'business_category' => __( 'Category', 'f-insights' ),
                                'overall_score'     => __( 'Score', 'f-insights' ),
                                'business_email'    => __( 'Business Email', 'f-insights' ),
                                'business_phone'    => __( 'Business Phone', 'f-insights' ),
                                'business_website'  => __( 'Business Website', 'f-insights' ),
                                'business_address'  => __( 'Business Address', 'f-insights' ),
                                'visitor_email'     => __( 'Requested By (Email)', 'f-insights' ),
                                'request_date'      => __( 'Request Date', 'f-insights' ),
                                'follow_up_status'  => __( 'Status', 'f-insights' ),
                                'follow_up_notes'   => __( 'Notes', 'f-insights' ),
                            );
                            foreach ( $csv_columns as $key => $label ) :
                            ?>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:4px;">
                                <input type="checkbox" class="fi-csv-col" value="<?php echo esc_attr( $key ); ?>" checked>
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <div style="margin-top:8px;display:flex;gap:8px;">
                                <a href="#" id="fi-csv-check-all" style="font-size:12px;"><?php _e( 'Check all', 'f-insights' ); ?></a>
                                <a href="#" id="fi-csv-uncheck-all" style="font-size:12px;"><?php _e( 'Uncheck all', 'f-insights' ); ?></a>
                            </div>
                        </fieldset>

                        <!-- Score range filter -->
                        <fieldset style="border:1px solid #ddd;border-radius:4px;padding:12px;margin-bottom:16px;">
                            <legend style="font-size:12px;font-weight:600;color:#50575e;padding:0 6px;"><?php _e( 'Score Range', 'f-insights' ); ?></legend>
                            <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                <label><?php _e( 'Min', 'f-insights' ); ?>
                                    <input type="number" id="fi-csv-min-score" value="0" min="0" max="100" class="small-text" style="width:55px;margin-left:4px;">
                                </label>
                                <span>–</span>
                                <label><?php _e( 'Max', 'f-insights' ); ?>
                                    <input type="number" id="fi-csv-max-score" value="100" min="0" max="100" class="small-text" style="width:55px;margin-left:4px;">
                                </label>
                                <span style="color:#646970;font-size:12px;"><?php _e( '(0–100)', 'f-insights' ); ?></span>
                            </div>
                        </fieldset>

                        <div style="display:flex;gap:10px;justify-content:flex-end;">
                            <button type="button" id="fi-csv-cancel-btn" class="button"><?php _e( 'Cancel', 'f-insights' ); ?></button>
                            <button type="button" id="fi-csv-download-btn" class="button button-primary">📥 <?php _e( 'Download CSV', 'f-insights' ); ?></button>
                        </div>
                    </div>
                </div>
                <script>
                (function($){
                    var $modal = $('#fi-csv-picker-modal');

                    $('#fi-export-csv-btn').on('click', function() { $modal.show(); });
                    $('#fi-csv-picker-overlay, #fi-csv-cancel-btn').on('click', function() { $modal.hide(); });
                    $('#fi-csv-check-all').on('click', function(e) { e.preventDefault(); $('.fi-csv-col').prop('checked', true); });
                    $('#fi-csv-uncheck-all').on('click', function(e) { e.preventDefault(); $('.fi-csv-col').prop('checked', false); });

                    $('#fi-csv-download-btn').on('click', function() {
                        var cols = $('.fi-csv-col:checked').map(function(){ return this.value; }).get();
                        if (!cols.length) { alert('Select at least one column.'); return; }

                        // Build a POST form and submit — AJAX can't trigger file downloads directly.
                        var $form = $('<form method="post" action="' + fiAdmin.ajaxUrl + '" style="display:none">');
                        $form.append($('<input>').attr({type:'hidden', name:'action', value:'fi_export_leads_csv'}));
                        $form.append($('<input>').attr({type:'hidden', name:'nonce',  value:fiAdmin.nonce}));
                        $form.append($('<input>').attr({type:'hidden', name:'status', value:$('#fi-leads-status-filter').val() || 'all'}));
                        $form.append($('<input>').attr({type:'hidden', name:'search', value:$('#fi-leads-search').val() || ''}));
                        $form.append($('<input>').attr({type:'hidden', name:'min_score', value:$('#fi-csv-min-score').val() || '0'}));
                        $form.append($('<input>').attr({type:'hidden', name:'max_score', value:$('#fi-csv-max-score').val() || '100'}));
                        cols.forEach(function(c){
                            $form.append($('<input>').attr({type:'hidden', name:'columns[]', value:c}));
                        });
                        $('body').append($form);
                        $form[0].submit();
                        $form.remove();
                        $modal.hide();
                    });
                })(jQuery);
                </script>
            </p>

            <!-- ── Search + filter toolbar ──────────────────────────────────── -->
            <div class="fi-leads-toolbar" style="display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                <input type="search"
                       id="fi-leads-search"
                       placeholder="<?php esc_attr_e( 'Search business, email, category…', 'f-insights' ); ?>"
                       style="width:260px; padding:6px 10px; border:1px solid #8c8f94; border-radius:4px; font-size:13px;" />

                <select id="fi-leads-status-filter" style="padding:6px 10px; border:1px solid #8c8f94; border-radius:4px; font-size:13px;">
                    <option value="all"><?php _e( 'All Statuses', 'f-insights' ); ?></option>
                    <option value="new"><?php _e( 'New', 'f-insights' ); ?></option>
                    <option value="contacted"><?php _e( 'Contacted', 'f-insights' ); ?></option>
                    <option value="qualified"><?php _e( 'Qualified', 'f-insights' ); ?></option>
                    <option value="closed"><?php _e( 'Closed', 'f-insights' ); ?></option>
                    <option value="lost"><?php _e( 'Lost', 'f-insights' ); ?></option>
                </select>

                <span id="fi-leads-count-label" style="font-size:13px; color:#646970; margin-left:4px;"></span>
            </div>

            <!-- ── Bulk action bar (shown when rows are selected) ────────────── -->
            <div id="fi-bulk-bar" style="display:none; align-items:center; gap:10px; margin-bottom:10px; padding:8px 12px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px;">
                <span id="fi-bulk-count" style="font-size:13px; font-weight:600; color:#1d2327;"></span>
                <select id="fi-bulk-status" style="padding:5px 8px; border:1px solid #8c8f94; border-radius:4px; font-size:13px;">
                    <option value=""><?php esc_html_e( '— Set status to… —', 'f-insights' ); ?></option>
                    <option value="new"><?php esc_html_e( 'New', 'f-insights' ); ?></option>
                    <option value="contacted"><?php esc_html_e( 'Contacted', 'f-insights' ); ?></option>
                    <option value="qualified"><?php esc_html_e( 'Qualified', 'f-insights' ); ?></option>
                    <option value="closed"><?php esc_html_e( 'Closed', 'f-insights' ); ?></option>
                    <option value="lost"><?php esc_html_e( 'Lost', 'f-insights' ); ?></option>
                </select>
                <button type="button" id="fi-bulk-apply" class="button button-primary"><?php esc_html_e( 'Apply', 'f-insights' ); ?></button>
                <button type="button" id="fi-bulk-deselect" class="button button-secondary"><?php esc_html_e( 'Deselect all', 'f-insights' ); ?></button>
            </div>

            <!-- ── Table (rows injected by JS) ──────────────────────────────── -->
            <table class="wp-list-table widefat fixed striped fi-leads-table">
                <thead>
                    <tr>
                        <th style="width:3%;" class="check-column">
                            <input type="checkbox" id="fi-select-all" title="<?php esc_attr_e( 'Select all on this page', 'f-insights' ); ?>" />
                        </th>
                        <th style="width:18%;" class="fi-sortable-col" data-col="business_name">
                            <?php _e( 'Business', 'f-insights' ); ?>
                            <span class="fi-sort-indicator" aria-hidden="true"></span>
                        </th>
                        <th style="width:11%;"><?php _e( 'Category', 'f-insights' ); ?></th>
                        <th style="width:7%;" class="fi-sortable-col" data-col="overall_score">
                            <?php _e( 'Score', 'f-insights' ); ?>
                            <span class="fi-sort-indicator" aria-hidden="true"></span>
                        </th>
                        <th style="width:14%;"><?php _e( 'Requested By', 'f-insights' ); ?></th>
                        <th style="width:11%;" class="fi-sortable-col fi-sort-active fi-sort-desc" data-col="request_date">
                            <?php _e( 'Date', 'f-insights' ); ?>
                            <span class="fi-sort-indicator" aria-hidden="true"></span>
                        </th>
                        <th style="width:11%;" class="fi-sortable-col" data-col="follow_up_status">
                            <?php _e( 'Status', 'f-insights' ); ?>
                            <span class="fi-sort-indicator" aria-hidden="true"></span>
                        </th>
                        <th style="width:25%;"><?php _e( 'Notes', 'f-insights' ); ?></th>
                    </tr>
                </thead>
                <tbody id="fi-leads-tbody">
                    <tr><td colspan="8" style="text-align:center; padding:30px; color:#888;">
                        <?php _e( 'Loading…', 'f-insights' ); ?>
                    </td></tr>
                </tbody>
            </table>

            <!-- ── Pagination ────────────────────────────────────────────────── -->
            <div id="fi-leads-pagination" style="display:flex; align-items:center; justify-content:space-between; margin-top:12px; flex-wrap:wrap; gap:8px;">
                <div id="fi-leads-page-info" style="font-size:13px; color:#646970;"></div>
                <div id="fi-leads-page-buttons" style="display:flex; gap:6px;"></div>
            </div>

        </div>
        <?php
    }

    /** Analytics: Scan Analytics tab */
    private static function render_analytics_tab_scan_analytics( $analytics, $leads_data = array() ) {
        $trend       = $analytics['trend']              ?? array();
        $score_trend = $analytics['score_trend']        ?? array();
        $dist        = $analytics['score_distribution'] ?? array();
        $token       = $analytics['token_usage']        ?? array( 'input' => 0, 'output' => 0, 'scans' => 0 );
        $month_delta = $analytics['month_delta']        ?? null;
        $is_filtered = ! empty( $analytics['is_filtered'] );
        $date_from   = $analytics['date_from'] ?? '';
        $date_to     = $analytics['date_to']   ?? '';
        $delta_label = '';
        if ( $month_delta !== null ) {
            $arrow       = $month_delta >= 0 ? '▲' : '▼';
            $delta_color = $month_delta >= 0 ? '#00a32a' : '#dc3232';
            $delta_label = "<span style='font-size:12px;color:{$delta_color};margin-left:6px;'>{$arrow} " . abs($month_delta) . "% vs last month</span>";
        }

        // Build the base URL for the filter form action (preserves page + tab params).
        $filter_base_url = add_query_arg(
            array( 'page' => 'f-insights-analytics', 'tab' => 'scan-analytics' ),
            admin_url( 'admin.php' )
        );
        $clear_url = $filter_base_url; // link to reset filter
        ?>
        <div class="fi-analytics-container">

            <!-- ── Date range filter ────────────────────────────────────────── -->
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
                  style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;padding:14px 16px;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;">
                <input type="hidden" name="page" value="f-insights-analytics">
                <input type="hidden" name="tab"  value="scan-analytics">
                <label style="font-weight:600;font-size:13px;" for="fi-filter-date-from"><?php _e( 'Date range:', 'f-insights' ); ?></label>
                <input type="date" id="fi-filter-date-from" name="date_from"
                       value="<?php echo esc_attr( $date_from ); ?>"
                       style="padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
                <span style="color:#666;">–</span>
                <input type="date" id="fi-filter-date-to" name="date_to"
                       value="<?php echo esc_attr( $date_to ); ?>"
                       style="padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
                <button type="submit" class="button"><?php _e( 'Apply', 'f-insights' ); ?></button>
                <?php if ( $is_filtered ) : ?>
                    <a href="<?php echo esc_url( $clear_url ); ?>" class="button" style="color:#b32d2e;"><?php _e( '✕ Clear filter', 'f-insights' ); ?></a>
                    <span style="font-size:12px;color:#646970;font-style:italic;">
                        <?php
                        printf(
                            /* translators: 1: from date, 2: to date */
                            esc_html__( 'Showing %1$s – %2$s', 'f-insights' ),
                            $date_from ?: esc_html__( 'all time', 'f-insights' ),
                            $date_to   ?: esc_html__( 'today', 'f-insights' )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </form>

            <!-- ── KPI row ──────────────────────────────────────────────────── -->
            <div class="fi-stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
                <div class="fi-stat-card">
                    <h3><?php echo $is_filtered ? esc_html__( 'Scans in Range', 'f-insights' ) : esc_html__( 'Total Scans', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $analytics['total_scans'] ) ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'This Month', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $analytics['month_scans'] ) ); ?><?php echo $delta_label; ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'This Week', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $analytics['week_scans'] ) ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'Avg. Score', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number"><?php echo esc_html( number_format( $analytics['avg_score'], 1 ) ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'Lead → Closed', 'f-insights' ); ?></h3>
                    <?php
                    $total_leads_for_rate = $leads_data['total_leads'] ?? 0;
                    $closed_leads         = $leads_data['counts']['closed'] ?? 0;
                    $close_rate           = $total_leads_for_rate > 0
                        ? round( ( $closed_leads / $total_leads_for_rate ) * 100, 1 )
                        : 0;
                    ?>
                    <div class="fi-stat-number fi-stat-highlight"><?php echo esc_html( $close_rate ); ?>%</div>
                    <div class="fi-stat-label"><?php echo esc_html( $closed_leads ); ?> <?php _e( 'closed of', 'f-insights' ); ?> <?php echo esc_html( $total_leads_for_rate ); ?> <?php _e( 'leads', 'f-insights' ); ?></div>
                </div>
                <div class="fi-stat-card">
                    <h3><?php _e( 'Claude API', 'f-insights' ); ?></h3>
                    <div class="fi-stat-number" style="font-size:13px; line-height:1.4; margin-top:8px;">
                        <a href="https://console.anthropic.com/settings/usage" target="_blank"><?php _e( 'Check token use →', 'f-insights' ); ?></a>
                    </div>
                </div>
            </div>

            <!-- ── AI Market Intelligence ───────────────────────────────────── -->
            <?php
            // Build list of scanned categories for the industry filter dropdown
            $intel_categories = array();
            if ( ! empty( $analytics['top_categories'] ) ) {
                foreach ( $analytics['top_categories'] as $cat ) {
                    if ( ! empty( $cat['category'] ) ) {
                        $intel_categories[] = $cat['category'];
                    }
                }
            }
            $intel_model     = get_option( 'fi_claude_model_intel', get_option( 'fi_claude_model', 'claude-sonnet-4-20250514' ) );
            $cost_estimates  = array(
                'claude-haiku-4-5-20251001' => '~$0.01–$0.02',
                'claude-sonnet-4-20250514'  => '~$0.03–$0.06',
                'claude-opus-4-20250514'    => '~$0.12–$0.25',
            );
            $current_cost_est = $cost_estimates[ $intel_model ] ?? '~$0.03–$0.06';
            ?>
            <div class="fi-analytics-section fi-market-intel-section" style="border:2px solid #000;padding:24px;margin-bottom:24px;background:#fff;">
                <div style="margin-bottom:16px;">
                    <h2 style="margin:0 0 4px;font-size:16px;font-weight:700;"><?php _e( 'Interpret Your Market Data', 'f-insights' ); ?></h2>
                    <p style="margin:0;font-size:13px;color:#646970;">
                        <?php _e( 'Claude reads your scan data and tells you what it means. Scope your analysis below; tighter scope = lower token cost + sharper insight.', 'f-insights' ); ?>
                    </p>
                </div>

                <!-- Controls grid -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">

                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#1d2327;margin-bottom:4px;"><?php _e( 'Industry', 'f-insights' ); ?></label>
                        <select id="fi-intel-industry" style="width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                            <option value="all"><?php _e( 'All Industries', 'f-insights' ); ?></option>
                            <?php foreach ( $intel_categories as $icat ) : ?>
                                <option value="<?php echo esc_attr( $icat ); ?>"><?php echo esc_html( $icat ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#1d2327;margin-bottom:4px;"><?php _e( 'Score Range', 'f-insights' ); ?></label>
                        <select id="fi-intel-score" style="width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                            <option value="all"><?php _e( 'All Scores', 'f-insights' ); ?></option>
                            <option value="critical"><?php _e( 'Critical (0–59)', 'f-insights' ); ?></option>
                            <option value="warning"><?php _e( 'Needs Work (60–79)', 'f-insights' ); ?></option>
                            <option value="good"><?php _e( 'Performing (80–100)', 'f-insights' ); ?></option>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#1d2327;margin-bottom:4px;"><?php _e( 'Time Window', 'f-insights' ); ?></label>
                        <select id="fi-intel-window" style="width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                            <option value="all"><?php _e( 'All Time', 'f-insights' ); ?></option>
                            <option value="30"><?php _e( 'Last 30 Days', 'f-insights' ); ?></option>
                            <option value="90"><?php _e( 'Last 90 Days', 'f-insights' ); ?></option>
                            <option value="180"><?php _e( 'Last 6 Months', 'f-insights' ); ?></option>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#1d2327;margin-bottom:4px;"><?php _e( 'Analysis Focus', 'f-insights' ); ?></label>
                        <select id="fi-intel-focus" style="width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                            <option value="all"><?php _e( 'All Angles', 'f-insights' ); ?></option>
                            <option value="patterns"><?php _e( 'Patterns', 'f-insights' ); ?></option>
                            <option value="opportunities"><?php _e( 'Opportunities', 'f-insights' ); ?></option>
                            <option value="pitch"><?php _e( 'Pitch Intel', 'f-insights' ); ?></option>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#1d2327;margin-bottom:4px;"><?php _e( 'Model', 'f-insights' ); ?></label>
                        <select id="fi-intel-model" style="width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;">
                            <option value="claude-haiku-4-5-20251001" <?php selected( $intel_model, 'claude-haiku-4-5-20251001' ); ?>><?php _e( 'Haiku — fastest, cheapest', 'f-insights' ); ?></option>
                            <option value="claude-sonnet-4-20250514"  <?php selected( $intel_model, 'claude-sonnet-4-20250514'  ); ?>><?php _e( 'Sonnet — balanced (default)', 'f-insights' ); ?></option>
                            <option value="claude-opus-4-20250514"    <?php selected( $intel_model, 'claude-opus-4-20250514'    ); ?>><?php _e( 'Opus — deepest analysis', 'f-insights' ); ?></option>
                        </select>
                    </div>

                </div>

                <!-- Cost estimate + Run button -->
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <p id="fi-intel-cost-note" style="margin:0;font-size:12px;color:#646970;">
                        <?php
                        printf(
                            /* translators: 1: cost estimate, 2: model name */
                            __( 'Est. cost: <strong>%1$s</strong> using %2$s. Uses your Claude API credits.', 'f-insights' ),
                            esc_html( $current_cost_est ),
                            esc_html( $intel_model )
                        );
                        ?>
                    </p>
                    <button type="button" id="fi-run-market-intel" class="button button-primary" <?php echo $analytics['total_scans'] < 1 ? 'disabled' : ''; ?>>
                        <?php _e( '▶ Run Analysis', 'f-insights' ); ?>
                    </button>
                </div>

                <?php if ( $analytics['total_scans'] < 3 ) : ?>
                <p style="margin:12px 0 0;font-size:12px;color:#888;border-top:1px solid #f0f0f0;padding-top:10px;">
                    <?php _e( 'You have fewer than 3 scans. Analysis works now but gets significantly sharper at 10+. Keep your scanner page active.', 'f-insights' ); ?>
                </p>
                <?php endif; ?>

                <div id="fi-intel-output" style="display:none;border-top:1px solid #e0e0e0;padding-top:16px;margin-top:16px;">
                    <div id="fi-intel-text" style="font-size:14px;line-height:1.8;color:#1d2327;white-space:pre-wrap;"></div>
                    <div id="fi-intel-meta" style="margin-top:12px;font-size:11px;color:#888;border-top:1px solid #f0f0f0;padding-top:8px;"></div>
                </div>
                <div id="fi-intel-loading" style="display:none;text-align:center;padding:24px;color:#646970;font-size:13px;">
                    <?php _e( 'Thinking…', 'f-insights' ); ?>
                </div>
            </div>

            <!-- ── 30-day scan trend chart ──────────────────────────────────── -->
            <div class="fi-analytics-section">
                <h2><?php _e( 'Scans — Last 30 Days', 'f-insights' ); ?></h2>
                <?php $has_trend = ! empty( array_filter( array_values( $trend ) ) ); ?>
                <?php if ( $has_trend ) : ?>
                <div style="position:relative;height:220px;">
                    <canvas id="fi-trend-chart" aria-label="<?php esc_attr_e( 'Daily scan volume over the last 30 days', 'f-insights' ); ?>"></canvas>
                </div>
                <?php else : ?>
                <p style="color:#646970;font-size:13px;padding:20px 0;">
                    <?php _e( 'No scans yet this month. Share your scanner page with potential clients; every scan is a data point that makes this chart and your market data interpretation sharper.', 'f-insights' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- ── Average score trend chart ───────────────────────────────── -->
            <div class="fi-analytics-section">
                <h2><?php _e( 'Average Score Trend — Last 6 Months', 'f-insights' ); ?></h2>
                <?php if ( ! empty( $score_trend ) && count( $score_trend ) > 1 ) : ?>
                <div style="position:relative;height:200px;">
                    <canvas id="fi-score-trend-chart" aria-label="<?php esc_attr_e( 'Monthly average scan score', 'f-insights' ); ?>"></canvas>
                </div>
                <?php else : ?>
                <p style="color:#646970;font-size:13px;padding:20px 0;">
                    <?php _e( 'Score trend needs at least two months of scan data. Keep scanning; this will show you whether the markets you\'re targeting are improving over time.', 'f-insights' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- ── Score distribution bar chart ────────────────────────────── -->
            <div class="fi-analytics-section">
                <h2><?php _e( 'Score Distribution', 'f-insights' ); ?></h2>
                <?php if ( $analytics['total_scans'] > 0 ) : ?>
                <div style="position:relative;height:180px;max-width:500px;">
                    <canvas id="fi-dist-chart" aria-label="<?php esc_attr_e( 'Distribution of scan scores across buckets', 'f-insights' ); ?>"></canvas>
                </div>
                <?php else : ?>
                <p style="color:#646970;font-size:13px;padding:20px 0;">
                    <?php _e( 'No score data yet. Once visitors start scanning businesses, you\'ll see here whether your audience is finding high-performing businesses or ones that need help; that\'s your market signal.', 'f-insights' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- ── Top Industries ───────────────────────────────────────────── -->
            <div class="fi-analytics-section">
                <h2><?php _e( 'Top Industries Scanned', 'f-insights' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php _e( 'Industry', 'f-insights' ); ?></th>
                        <th style="width:80px;"><?php _e( 'Scans', 'f-insights' ); ?></th>
                        <th style="width:100px;"><?php _e( 'Avg. Score', 'f-insights' ); ?></th>
                        <th><?php _e( 'Most Common Pain Point', 'f-insights' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if ( ! empty( $analytics['top_categories'] ) ) : ?>
                            <?php foreach ( $analytics['top_categories'] as $cat ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $cat['category'] ?: __( 'Uncategorized', 'f-insights' ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $cat['count'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $cat['avg_score'], 1 ) ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $cat['dominant_pain'] ) ) : ?>
                                            <span style="display:inline-block;padding:2px 8px;background:#fff3cd;border:1px solid #e6a817;border-radius:3px;font-size:12px;color:#7a4f01;">
                                                ⚠ <?php echo esc_html( $cat['dominant_pain'] ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span style="color:#aaa;font-size:12px;"><?php _e( 'No lead data yet', 'f-insights' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="4"><?php _e( 'No data yet', 'f-insights' ); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ── Recent Scans ─────────────────────────────────────────────── -->
            <div class="fi-analytics-section">
                <h2><?php _e( 'Recent Scans', 'f-insights' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php _e( 'Business', 'f-insights' ); ?></th>
                        <th><?php _e( 'Industry', 'f-insights' ); ?></th>
                        <th style="width:80px;"><?php _e( 'Score', 'f-insights' ); ?></th>
                        <th><?php _e( 'Top Issue', 'f-insights' ); ?></th>
                        <th style="width:130px;"><?php _e( 'Date', 'f-insights' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if ( ! empty( $analytics['recent_scans'] ) ) : ?>
                            <?php foreach ( $analytics['recent_scans'] as $scan ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $scan['business_name'] ); ?></td>
                                    <td><?php echo esc_html( $scan['business_category'] ?: __( 'Unknown', 'f-insights' ) ); ?></td>
                                    <td><span class="fi-score-badge fi-score-<?php echo self::get_score_class( $scan['overall_score'] ); ?>"><?php echo esc_html( $scan['overall_score'] ); ?></span></td>
                                    <td>
                                        <?php if ( ! empty( $scan['top_pain'] ) ) : ?>
                                            <span style="font-size:12px;color:#7a4f01;">⚠ <?php echo esc_html( $scan['top_pain'] ); ?></span>
                                        <?php else : ?>
                                            <span style="color:#aaa;font-size:12px;"><?php _e( '—', 'f-insights' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scan['scan_date'] ) ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="5"><?php _e( 'No scans yet', 'f-insights' ); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        // Print chart data as a global JS variable. The actual Chart.js initialization
        // lives in admin.js which loads after Chart.js in the footer — this was the
        // root cause of the blank charts (inline script fired before Chart.js loaded).
        $trend_labels  = array_keys( $trend );
        $trend_values  = array_values( $trend );
        $score_labels  = array_keys( $score_trend );
        $score_values  = array_values( $score_trend );
        $dist_labels   = array( '0–19', '20–39', '40–59', '60–79', '80–100' );
        $dist_values   = array(
            intval( $dist['bucket_0_19']   ?? 0 ),
            intval( $dist['bucket_20_39']  ?? 0 ),
            intval( $dist['bucket_40_59']  ?? 0 ),
            intval( $dist['bucket_60_79']  ?? 0 ),
            intval( $dist['bucket_80_100'] ?? 0 ),
        );
        ?>
        <script>
        window.fiChartData = {
            trend:      { labels: <?php echo json_encode( $trend_labels ); ?>, values: <?php echo json_encode( $trend_values ); ?> },
            scoreTrend: { labels: <?php echo json_encode( $score_labels ); ?>, values: <?php echo json_encode( $score_values ); ?> },
            dist:       { labels: <?php echo json_encode( $dist_labels );  ?>, values: <?php echo json_encode( $dist_values );  ?> }
        };
        </script>
        <?php
    }

    /** Analytics: Data Management tab */
    private static function render_analytics_tab_data_management() {
        ?>
        <div class="fi-analytics-section">
            <p class="description"><?php _e( 'Tools to manage your analytics and lead data.', 'f-insights' ); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( 'Clear Test Data', 'f-insights' ); ?></th>
                    <td>
                        <p class="description" style="margin-bottom:10px;"><?php _e( 'Remove leads with "test" in business name or email address. Useful for cleaning up after testing.', 'f-insights' ); ?></p>
                        <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=f-insights-analytics&tab=data-management&action=clear_test_leads' ), 'fi_clear_test_leads' ); ?>"
                           class="button button-secondary"
                           onclick="return confirm('<?php esc_attr_e( 'Clear all test leads? This cannot be undone.', 'f-insights' ); ?>');">
                            <?php _e( 'Clear Test Leads', 'f-insights' ); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'GDPR: Erase by Email', 'f-insights' ); ?></th>
                    <td>
                        <p class="description" style="margin-bottom:10px;">
                            <?php _e( 'Delete all lead records associated with a specific email address. Use to comply with right-to-erasure requests under GDPR / CCPA.', 'f-insights' ); ?>
                        </p>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <input type="email"
                                   id="fi-gdpr-email-input"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'visitor@example.com', 'f-insights' ); ?>"
                                   autocomplete="off"
                                   style="max-width:280px;" />
                            <button type="button" id="fi-gdpr-erase-btn" class="button button-secondary" style="color:#dc2626;border-color:#dc2626;">
                                <?php _e( 'Erase Records', 'f-insights' ); ?>
                            </button>
                            <span id="fi-gdpr-erase-msg" style="font-size:13px;display:none;"></span>
                        </div>
                        <script>
                        (function($){
                            $('#fi-gdpr-erase-btn').on('click', function(){
                                var email = $('#fi-gdpr-email-input').val().trim();
                                var $msg  = $('#fi-gdpr-erase-msg');
                                if ( !email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ) {
                                    $msg.css('color','#b32d2e').text('Enter a valid email address.').show();
                                    return;
                                }
                                if ( !confirm('<?php echo esc_js( __( 'Delete ALL lead records for this email? This cannot be undone.', 'f-insights' ) ); ?>') ) {
                                    return;
                                }
                                var $btn = $(this).prop('disabled', true).text('Erasing…');
                                $msg.hide();
                                $.post(fiAdmin.ajaxUrl, {
                                    action: 'fi_delete_leads_by_email',
                                    nonce:  fiAdmin.nonce,
                                    email:  email
                                }, function(response){
                                    if (response.success) {
                                        $msg.css('color','#00a32a').text('✓ ' + response.data.message).show();
                                        $('#fi-gdpr-email-input').val('');
                                    } else {
                                        $msg.css('color','#b32d2e').text('✗ ' + (response.data.message || 'Error')).show();
                                    }
                                }).always(function(){ $btn.prop('disabled',false).text('<?php echo esc_js( __( 'Erase Records', 'f-insights' ) ); ?>'); });
                            });
                        })(jQuery);
                        </script>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fi_lead_retention_days"><?php _e( 'Lead Retention (days)', 'f-insights' ); ?></label>
                    </th>
                    <td>
                        <?php $retention = absint( get_option( 'fi_lead_retention_days', 0 ) ); ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=f-insights-analytics&tab=data-management' ) ); ?>">
                            <?php wp_nonce_field( 'fi_save_lead_retention' ); ?>
                            <input type="hidden" name="action" value="save_lead_retention">
                            <input type="number" id="fi_lead_retention_days" name="fi_lead_retention_days"
                                   value="<?php echo esc_attr( $retention ); ?>"
                                   min="0" max="3650" step="1" class="small-text" />
                            <button type="submit" class="button"><?php _e( 'Save', 'f-insights' ); ?></button>
                        </form>
                        <p class="description" style="margin-top:6px;">
                            <?php _e( 'Automatically delete lead records older than this many days (via daily cron). Set to <strong>0</strong> to keep leads indefinitely. Default: 0 (no auto-deletion).', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'Reset All Analytics', 'f-insights' ); ?></th>
                    <td>
                        <p class="description" style="margin-bottom:10px; color:#dc2626;">
                            <strong><?php _e( '⚠️ Warning:', 'f-insights' ); ?></strong>
                            <?php _e( 'This will permanently delete ALL leads and scan analytics. This action cannot be undone!', 'f-insights' ); ?>
                        </p>
                        <form method="post" action="" id="fi-reset-analytics-form">
                            <?php wp_nonce_field( 'fi_reset_analytics' ); ?>
                            <input type="hidden" name="action" value="reset_all_analytics" />
                            <p style="margin-bottom:8px; font-size:13px; color:#555;">
                                <?php _e( 'Type <strong>RESET</strong> to confirm:', 'f-insights' ); ?>
                            </p>
                            <input type="text"
                                   name="fi_reset_confirm"
                                   id="fi-reset-confirm-input"
                                   class="regular-text"
                                   autocomplete="off"
                                   placeholder="<?php esc_attr_e( 'Type RESET here', 'f-insights' ); ?>"
                                   style="border-color:#dc2626; margin-bottom:10px; display:block;" />
                            <button type="submit"
                                    id="fi-reset-analytics-btn"
                                    class="button button-secondary"
                                    style="color:#dc2626; border-color:#dc2626;"
                                    disabled>
                                <?php _e( '⚠️ Reset All Analytics', 'f-insights' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    private static function get_score_class($score) {
        if ($score >= 80) return 'good';
        if ($score >= 60) return 'warning';
        return 'alert';
    }
    
    public static function render_logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle log clear action
        if (isset($_GET['action']) && $_GET['action'] === 'clear_logs' && check_admin_referer('fi_clear_logs')) {
            $log_file = FI_Logger::get_log_file();
            if (file_exists($log_file)) {
                unlink($log_file);
            }
            FI_Logger::cleanup_old_logs(0); // Clear all logs
            echo '<div class="notice notice-success"><p>' . __('Logs cleared successfully!', 'f-insights') . '</p></div>';
        }

        // NOTE: The "download_logs" action is intentionally NOT handled here.
        // It is handled in handle_admin_actions() on admin_init so that file
        // download headers can be sent before WordPress outputs any HTML.
        // Any request reaching this point with action=download_logs has already
        // been processed and exited — nothing to do here.
        
        $logs = FI_Logger::get_recent_logs(200);
        $log_file = FI_Logger::get_log_file();
        
        ?>
        <div class="wrap fi-admin-wrap">
            <h1><?php _e('F Insights Debug Logs', 'f-insights'); ?></h1>
            
            <p><?php _e('Recent debug logs (most recent 200 lines):', 'f-insights'); ?></p>
            
            <div style="margin-bottom: 15px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=f-insights-logs&action=clear_logs'), 'fi_clear_logs'); ?>" 
                   class="button button-secondary" 
                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs?', 'f-insights'); ?>');">
                    <?php _e('Clear All Logs', 'f-insights'); ?>
                </a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=f-insights-logs&action=download_logs'), 'fi_download_logs'); ?>"
                   class="button button-secondary">
                    <span class="dashicons dashicons-download" aria-hidden="true" style="vertical-align:middle; margin-right:4px;"></span><?php _e('Download Log', 'f-insights'); ?>
                </a>
                <span style="color: #666; font-size:12px;">
                    <?php _e('Log file:', 'f-insights'); ?> 
                    <code><?php echo esc_html( str_replace( ABSPATH, '', $log_file ) ); ?></code>
                </span>
            </div>

            <?php if ( ! empty( $logs ) ) : ?>
            <div style="margin-bottom:12px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;" id="fi-log-filters">
                <button type="button" class="button button-small fi-log-filter fi-log-filter-active" data-level="all"><?php _e('All', 'f-insights'); ?></button>
                <button type="button" class="button button-small fi-log-filter" data-level="ERROR" style="color:#ef4444;"><?php _e('Errors', 'f-insights'); ?></button>
                <button type="button" class="button button-small fi-log-filter" data-level="WARNING" style="color:#f59e0b;"><?php _e('Warnings', 'f-insights'); ?></button>
                <button type="button" class="button button-small fi-log-filter" data-level="INFO" style="color:#10b981;"><?php _e('Info', 'f-insights'); ?></button>
                <button type="button" class="button button-small fi-log-filter" data-level="DEBUG" style="color:#3b82f6;"><?php _e('Debug', 'f-insights'); ?></button>
                <button type="button" class="button button-small fi-log-filter" data-level="API" style="color:#8b5cf6;"><?php _e('API', 'f-insights'); ?></button>
                <span style="margin-left:auto; display:flex; align-items:center; gap:6px;">
                    <button type="button" id="fi-log-scroll-toggle" class="button button-small fi-log-scroll-active" title="<?php esc_attr_e('Toggle auto-scroll to newest entry', 'f-insights'); ?>">
                        ⬇ <?php _e('Scroll to bottom', 'f-insights'); ?>
                    </button>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if (empty($logs)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No logs yet. Logs will appear here when you run a business scan.', 'f-insights'); ?></p>
                </div>
            <?php else: ?>
                <div class="fi-log-viewer" style="background: #1e1e1e; color: #d4d4d4; padding: 20px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; max-height: 600px; overflow-y: auto; border: 2px solid #000;">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $line = esc_html($log);
                        // Detect level for data attribute
                        $level = 'OTHER';
                        if ( strpos($log, '[ERROR]') !== false )        { $level = 'ERROR'; }
                        elseif ( strpos($log, '[WARNING]') !== false )   { $level = 'WARNING'; }
                        elseif ( strpos($log, '[INFO]') !== false )      { $level = 'INFO'; }
                        elseif ( strpos($log, '[DEBUG]') !== false )     { $level = 'DEBUG'; }
                        elseif ( strpos($log, '[API_') !== false )       { $level = 'API'; }
                        // Colorize log levels
                        $line = preg_replace('/\[ERROR\]/', '<span style="color: #ef4444; font-weight: bold;">[ERROR]</span>', $line);
                        $line = preg_replace('/\[WARNING\]/', '<span style="color: #f59e0b; font-weight: bold;">[WARNING]</span>', $line);
                        $line = preg_replace('/\[INFO\]/', '<span style="color: #10b981; font-weight: bold;">[INFO]</span>', $line);
                        $line = preg_replace('/\[DEBUG\]/', '<span style="color: #3b82f6; font-weight: bold;">[DEBUG]</span>', $line);
                        $line = preg_replace('/\[API_REQUEST\]/', '<span style="color: #8b5cf6; font-weight: bold;">[API_REQUEST]</span>', $line);
                        $line = preg_replace('/\[API_RESPONSE\]/', '<span style="color: #ec4899; font-weight: bold;">[API_RESPONSE]</span>', $line);
                        echo '<span class="fi-log-line" data-level="' . esc_attr($level) . '">' . $line . "</span><br>";
                        ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <h2><?php _e('How to Debug', 'f-insights'); ?></h2>
                <ol>
                    <li><?php _e('Try scanning a business from the frontend', 'f-insights'); ?></li>
                    <li><?php _e('Return to this page to see detailed logs', 'f-insights'); ?></li>
                    <li><?php _e('Look for [ERROR] or [WARNING] entries', 'f-insights'); ?></li>
                    <li><?php _e('Check API_REQUEST and API_RESPONSE for Google/Claude issues', 'f-insights'); ?></li>
                </ol>
                
                <h3><?php _e('Common Issues', 'f-insights'); ?></h3>
                <ul>
                    <li><strong><?php _e('Google API Error 400:', 'f-insights'); ?></strong> <?php _e('Invalid API key or disabled API', 'f-insights'); ?></li>
                    <li><strong><?php _e('Google API Error 403:', 'f-insights'); ?></strong> <?php _e('API key restrictions or quota exceeded', 'f-insights'); ?></li>
                    <li><strong><?php _e('Claude API Error:', 'f-insights'); ?></strong> <?php _e('Check API key and account credits', 'f-insights'); ?></li>
                    <li><strong><?php _e('Could not fetch business details:', 'f-insights'); ?></strong> <?php _e('Place ID invalid or business not found', 'f-insights'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * White-label email preview page.
     *
     * Renders the report email template using the currently-saved white-label
     * settings and realistic dummy data, so admins can see exactly how a
     * sent report will look before they send a real one.
     *
     * Accessible via Settings → "Preview Email" button. Not shown in the
     * sidebar menu (registered with null parent in admin_menu).
     */
    public static function render_wl_preview_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ── Build realistic dummy report data ────────────────────────────────
        $dummy_report = array(
            'business_data' => array(
                'name'               => 'The Golden Spoon Bistro',
                'address'            => '142 Main Street, Springfield, IL 62701',
                'phone'              => '+1 (217) 555-0198',
                'website'            => 'https://goldenspoonsb.com',
                'rating'             => 4.2,
                'user_ratings_total' => 387,
            ),
            'analysis' => array(
                'overall_score'    => 64,
                'competitive_narrative' => 'You\'re up against The Oak Table (4.7★, 812 reviews) and Riviera Grille (4.5★, 543 reviews). Your 4.2★ is solid, but your review volume lags behind both. The good news: 387 reviews shows real community trust — the gap is closeable with a focused review-ask campaign.',
                'strengths'        => array(
                    'Consistent 4★+ rating maintained over 18 months',
                    'Strong weekend foot traffic visible in peak-hour data',
                    'Active photo presence with 40+ recent customer uploads',
                ),
                'priority_actions' => array(
                    array(
                        'title'       => 'Set up a Google review request system',
                        'description' => 'Ask every satisfied customer for a review via a QR code at the table or a post-visit text. Getting from 387 to 600 reviews would put you ahead of Riviera Grille.',
                        'impact'      => 'high',
                        'effort'      => 'low',
                    ),
                    array(
                        'title'       => 'Add your menu to your Google Business Profile',
                        'description' => 'Profiles with menus get 35% more clicks. You currently have no menu linked, which is likely costing you direct conversions from hungry searchers.',
                        'impact'      => 'high',
                        'effort'      => 'low',
                    ),
                    array(
                        'title'       => 'Respond to your 12 unanswered reviews',
                        'description' => 'You have 12 reviews — including 3 critical ones — with no owner response. Responding publicly shows prospective customers you care.',
                        'impact'      => 'medium',
                        'effort'      => 'low',
                    ),
                ),
                'insights' => array(
                    'online_presence' => array(
                        'score'           => 72,
                        'status'          => 'needs-attention',
                        'headline'        => 'Good foundation, a few gaps to close',
                        'summary'         => 'Your Google Business Profile is claimed and reasonably complete. Hours, address and phone are all correct. Missing: a menu link, business description, and Q&A section responses.',
                        'recommendations' => array(
                            'Add a direct link to your menu under Products/Services',
                            'Write a 200-word business description using your top keywords',
                        ),
                    ),
                    'customer_reviews' => array(
                        'score'           => 58,
                        'status'          => 'urgent',
                        'headline'        => 'Review velocity is below your competitors',
                        'summary'         => 'At 387 reviews you\'re behind The Oak Table (812) and Riviera Grille (543). Your average of 4.2★ is healthy but recency matters — your last 10 reviews average 3.8★, which is a signal worth addressing.',
                        'recommendations' => array(
                            'Start a post-visit review ask via SMS or table QR code',
                            'Respond to all reviews within 48 hours, especially negative ones',
                        ),
                    ),
                    'website_performance' => array(
                        'score'           => 55,
                        'status'          => 'urgent',
                        'headline'        => 'Site loads slowly and lacks SEO basics',
                        'summary'         => 'Your site loads in 4.2 seconds — nearly double the 2-second threshold where bounce rates spike. Missing meta description, no structured data, and images aren\'t compressed.',
                        'recommendations' => array(
                            'Compress images — your largest page images are 3-5MB each',
                            'Add a meta description and structured data (LocalBusiness schema)',
                        ),
                    ),
                ),
                'sentiment_analysis' => array(
                    'overall_sentiment'  => 'positive',
                    'common_themes'      => array( 'Friendly staff', 'Great brunch menu', 'Good portion sizes' ),
                    'customer_pain_points' => array( 'Long weekend wait times', 'Limited parking', 'Inconsistent service on busy nights' ),
                ),
            ),
        );

        // Resolve white-label settings (same logic as the live email send).
        $wl = FI_Ajax::get_white_label_settings_public();

        // ── Admin chrome ────────────────────────────────────────────────────
        echo '<div class="wrap" style="margin-bottom:0;">';
        echo '<h1>' . esc_html__( 'Email Preview — White-Label', 'f-insights' ) . '</h1>';
        echo '<p style="color:#666; margin-bottom:20px;">';
        echo esc_html__( 'This is how your report emails will look with your current White-Label settings. Change any setting and reload to see updates.', 'f-insights' );
        echo ' &mdash; <a href="' . esc_url( admin_url( 'admin.php?page=f-insights' ) ) . '">' . esc_html__( '&#8592; Back to Settings', 'f-insights' ) . '</a>';
        echo '</p>';

        // Callouts for any blank white-label fields so the admin knows what is still default.
        $missing = array();
        if ( empty( get_option( 'fi_wl_sender_name' ) ) ) {
            $missing[] = __( 'Sender Name', 'f-insights' );
        }
        if ( empty( get_option( 'fi_wl_logo_url' ) ) ) {
            $missing[] = __( 'Logo', 'f-insights' );
        }
        if ( empty( get_option( 'fi_wl_footer_cta' ) ) ) {
            $missing[] = __( 'Footer Call-to-Action', 'f-insights' );
        }
        if ( ! empty( $missing ) ) {
            echo '<div class="notice notice-info" style="margin-bottom:16px;"><p>';
            printf(
                /* translators: %s: comma-separated list of field names */
                esc_html__( 'Using built-in defaults for: %s. Fill these in on the Settings page to see your branding here.', 'f-insights' ),
                '<strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>'
            );
            echo '</p></div>';
        }

        echo '<p style="font-size:12px; color:#888; border:1px dashed #ccc; padding:10px; margin-bottom:24px;">';
        echo esc_html__( 'Preview uses realistic sample data, not a real business scan. The layout below is exactly what recipients will receive.', 'f-insights' );
        echo '</p>';
        echo '</div>';

        // ── Render the live email template ──────────────────────────────────
        // Instantiate FI_Ajax to call the email generator.
        $ajax = new FI_Ajax();
        echo $ajax->generate_email_html_public( $dummy_report, $wl );
    }

    /**
     * Clear all cached data from the custom cache table.
     *
     * @return int|false Number of rows deleted, or false on failure.
     */
    private static function clear_all_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'fi_cache';

        $deleted = $wpdb->query( "DELETE FROM `$table`" );

        FI_Logger::info( 'Cache cleared manually', array( 'deleted_rows' => $deleted ) );

        return $deleted;
    }
}