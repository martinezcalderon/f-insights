<?php
/**
 * Plugin Name: F! Insights
 * Plugin URI: https://fricking.website
 * Description: AI-powered business insights scanner for Google Business Profiles using Claude AI. Analyze businesses, audit websites, and visualize competitive landscapes with interactive maps. Now with lead capture & management + stored reports!
 * Version: 2.1.2
 * Author: Saïd
 * Author URI: https://saidmartinezcalderon.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: f-insights
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FI_VERSION', '2.1.2');
define('FI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// =============================================================================
// FREEMIUS SDK BOOTSTRAP
// Copied verbatim from Freemius Dashboard → SDK Integration.
// For local testing, add to wp-config.php (remove before deploying to production):
//   define( 'WP_FS__DEV_MODE', true );
//   define( 'WP_FS__SKIP_EMAIL_ACTIVATION', true );
//   define( 'WP_FS__f-insights_SECRET_KEY', 'sk_q>+X&[R>;QJg@O<.LoDl1tBo<~T^s' );
// =============================================================================

if ( ! function_exists( 'fi_fs' ) ) {
    // Create a helper function for easy SDK access.
    function fi_fs() {
        global $fi_fs;

        if ( ! isset( $fi_fs ) ) {
            // Activate multisite network integration.
            if ( ! defined( 'WP_FS__PRODUCT_24447_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_24447_MULTISITE', true );
            }

            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/freemius/start.php';

            $fi_fs = fs_dynamic_init( array(
                'id'               => '24447',
                'slug'             => 'f-insights',
                'type'             => 'plugin',
                'public_key'       => 'pk_e42c9078aabff6201c719f6685396',
                'is_premium'       => false,
                'has_addons'       => false,
                'has_paid_plans'   => false,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'f-insights',
                    'contact' => false,
                    'support' => false,
                ),
            ) );
        }

        return $fi_fs;
    }

    // Init Freemius.
    fi_fs();
    // Signal that SDK was initiated.
    do_action( 'fi_fs_loaded' );
}

/**
 * Opt-in screen customisation.
 *
 * IMPORTANT: add_action must be called BEFORE fi_fs() initialises, OR we must
 * use did_action() to catch the case where fi_fs_loaded already fired.
 * We register on 'plugins_loaded' priority 1 which runs before our bootstrap
 * on priority 10, ensuring the listener is always attached in time.
 *
 * @see https://freemius.com/help/documentation/wordpress-sdk/opt-in-message/
 */
function fi_fs_register_optin_hooks() {
    if ( ! function_exists( 'fi_fs' ) ) {
        return;
    }

    // Plugin icon — points to local asset since the plugin is not yet on WordPress.org.
    // Freemius would otherwise try to fetch it from .org and fall back to a grey box.
    fi_fs()->add_filter( 'plugin_icon', function() {
        return FI_PLUGIN_DIR . 'assets/img/icon.jpg';
    } );

    // Opt-in message for new users.
    fi_fs()->add_filter( 'connect_message', function(
        $message, $user_first_name, $product_title,
        $user_login, $site_link, $freemius_link
    ) {
        return sprintf(
            /* translators: 1: user first name, 2: plugin name (bold), 3: Freemius link */
            __( 'Hey %1$s! Help us improve %2$s by sharing non-sensitive diagnostic data. This lets us push security & feature updates directly to you. %3$s.', 'f-insights' ),
            $user_first_name,
            '<b>' . $product_title . '</b>',
            $freemius_link
        );
    }, 10, 6 );

    // Opt-in message for existing users seeing the screen after a plugin update.
    fi_fs()->add_filter( 'connect_message_on_update', function(
        $message, $user_first_name, $product_title,
        $user_login, $site_link, $freemius_link
    ) {
        return sprintf(
            /* translators: 1: user first name, 2: plugin name (bold), 3: Freemius link */
            __( 'Hey %1$s! We added opt-in telemetry to %2$s to help us fix bugs faster and ship better features. If you skip this, the plugin works exactly as before. %3$s.', 'f-insights' ),
            $user_first_name,
            '<b>' . $product_title . '</b>',
            $freemius_link
        );
    }, 10, 6 );
}
// Hook early enough that the listener is registered before fi_fs() is called.
add_action( 'plugins_loaded', 'fi_fs_register_optin_hooks', 1 );
// Include required files
require_once FI_PLUGIN_DIR . 'includes/class-fi-premium.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-logger.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-crypto.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-activator.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-deactivator.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-admin.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-scanner.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-grader.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-analytics.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-rate-limiter.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-shortcode.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-ajax.php';
require_once FI_PLUGIN_DIR . 'includes/class-fi-batch-scanner.php';

// Activation and deactivation hooks
register_activation_hook(__FILE__, array('FI_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('FI_Deactivator', 'deactivate'));

/**
 * Main plugin class
 */
class F_Insights {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'), 5); // Priority 5 to show at top
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));

        // SRI (Subresource Integrity) — add integrity + crossorigin to CDN tags.
        add_filter( 'style_loader_tag',  array( $this, 'add_sri_to_style' ),  10, 2 );
        add_filter( 'script_loader_tag', array( $this, 'add_sri_to_script' ), 10, 2 );

        // Rate-limit table pruning — runs daily via WP-Cron (scheduled on activation).
        add_action( 'fi_rate_limit_cleanup', array( 'FI_Rate_Limiter', 'cleanup' ) );

        // Shared-report expiry pruning (v2.1.0) — delete rows past their expires_at.
        add_action( 'fi_shared_report_cleanup', array( $this, 'cleanup_shared_reports' ) );

        // GDPR lead retention cleanup (v2.2.0) — deletes leads older than admin-set days.
        add_action( 'fi_lead_retention_cleanup', array( $this, 'cleanup_old_leads' ) );

        // Batch Prospect Scanner AJAX hooks (v2.2.0).
        FI_Batch_Scanner::register_ajax_hooks();

        // Handle form saves and redirects before any output is sent.
        FI_Admin::register_hooks();
    }
    
    /**
     * Check if premium features are available.
     *
     * @return bool
     */
    private static function is_premium(): bool {
        return FI_Premium::is_active();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('f-insights', false, dirname(FI_PLUGIN_BASENAME) . '/languages');
    }
    
    public function init() {
        // Initialize shortcode
        new FI_Shortcode();

        // Initialize AJAX handlers
        new FI_Ajax();

        // Ensure fi_shared_reports table exists for sites that activated before
        // v2.1.0. dbDelta is idempotent — safe to call on every page load once
        // the option flag is absent; after creation we set the flag so it runs once.
        if ( ! get_option( 'fi_shared_reports_table_created' ) ) {
            FI_Activator::maybe_create_shared_reports_table();
            update_option( 'fi_shared_reports_table_created', '1' );
        }
    }

    /**
     * Delete expired rows from fi_shared_reports.
     * Hooked to the fi_shared_report_cleanup daily cron event.
     *
     * Uses a transient mutex so concurrent WP-Cron invocations (possible on
     * high-traffic sites where multiple requests fire the missed cron within
     * the same second) don't run duplicate DELETE statements simultaneously.
     */
    public function cleanup_shared_reports() {
        // Acquire a 5-minute lock — if another process is already cleaning up,
        // skip this run rather than racing to delete the same rows twice.
        if ( get_transient( 'fi_shared_report_cleanup_lock' ) ) {
            return;
        }
        set_transient( 'fi_shared_report_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fi_shared_reports WHERE expires_at < NOW()"
        );
        FI_Logger::info( 'Shared report cleanup complete', array( 'deleted' => $deleted ) );

        delete_transient( 'fi_shared_report_cleanup_lock' );
    }

    /**
     * GDPR lead retention cleanup.
     * Hooked to fi_lead_retention_cleanup daily cron event.
     * Deletes fi_leads rows older than the admin-configured retention window.
     * Skipped entirely when retention is set to 0 (keep indefinitely).
     */
    public function cleanup_old_leads() {
        $days = absint( get_option( 'fi_lead_retention_days', 0 ) );
        if ( $days < 1 ) {
            return; // Retention disabled — nothing to do.
        }

        if ( get_transient( 'fi_lead_retention_cleanup_lock' ) ) {
            return;
        }
        set_transient( 'fi_lead_retention_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}fi_leads WHERE request_date < %s",
                $cutoff
            )
        );
        FI_Logger::info( 'Lead retention cleanup complete', array( 'deleted' => $deleted, 'days' => $days ) );

        delete_transient( 'fi_lead_retention_cleanup_lock' );
    }
    
    public function admin_menu() {
        add_menu_page(
            __('F Insights', 'f-insights'),
            __('F Insights', 'f-insights'),
            'manage_options',
            'f-insights',
            array('FI_Admin', 'render_settings_page'),
            'dashicons-chart-line',
            2 // Position at top of menu
        );
        
        add_submenu_page(
            'f-insights',
            __('Settings', 'f-insights'),
            __('Settings', 'f-insights'),
            'manage_options',
            'f-insights',
            array('FI_Admin', 'render_settings_page')
        );
        
        // Analytics page — always visible in menu.
        // Premium users see live data; free users see the locked urgency page
        // showing their unrecorded scan count.
        add_submenu_page(
            'f-insights',
            __('Your Market Intel', 'f-insights'),
            __('Market Intel', 'f-insights'),
            'manage_options',
            'f-insights-analytics',
            array('FI_Admin', 'render_analytics_page')
        );
        
        // Batch Prospect Scanner (v2.2.0)
        add_submenu_page(
            'f-insights',
            __( 'Batch Scanner', 'f-insights' ),
            __( 'Batch Scanner', 'f-insights' ),
            'manage_options',
            'f-insights-batch',
            array( 'FI_Batch_Scanner', 'render_page' )
        );

        // Debug Logs - Available to all users
        add_submenu_page(
            'f-insights',
            __('Debug Logs', 'f-insights'),
            __('Debug Logs', 'f-insights'),
            'manage_options',
            'f-insights-logs',
            array('FI_Admin', 'render_logs_page')
        );

        // White-label email preview — visible only in the menu so the URL
        // is shareable, but we intentionally omit a nav label so it does not
        // clutter the sidebar. Accessible via the Settings page "Preview Email" button.
        add_submenu_page(
            null, // No parent: hidden from the sidebar menu.
            __('Email Preview', 'f-insights'),
            __('Email Preview', 'f-insights'),
            'manage_options',
            'f-insights-wl-preview',
            array('FI_Admin', 'render_wl_preview_page')
        );
    }
    
    /**
     * SRI (Subresource Integrity) hashes for CDN-loaded assets.
     *
     * These protect against supply-chain attacks by instructing the browser to
     * reject any CDN response that doesn't match the expected hash.
     *
     * HOW TO UPDATE: If you upgrade a CDN library version, recompute the hash:
     *   curl -sL <CDN_URL> | openssl dgst -sha512 -binary | openssl base64 -A
     * Then prefix the result with "sha512-" and update the constant below.
     *
     * These hashes must be verified against the live CDN files before deploying.
     * See: https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity
     */
    const FA_SRI_HASH      = 'sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==';
    const CHARTJS_SRI_HASH = 'sha512-ZdZyBkA5AmOsvi5VRSzF0bWXBFMIBmV+UUTP5bEluwmh/FDkJBlM75Eqd98qI8JUgAU8L0L5gy1OaB9Mnd1w==';

    /**
     * Add SRI integrity + crossorigin attributes to CDN-loaded style tags.
     * Hooked to style_loader_tag for our CDN handles.
     */
    public function add_sri_to_style( $html, $handle ) {
        $sri_map = array(
            'font-awesome-free'       => self::FA_SRI_HASH,
            'font-awesome-free-admin' => self::FA_SRI_HASH,
        );
        if ( isset( $sri_map[ $handle ] ) ) {
            $html = str_replace(
                "rel='stylesheet'",
                "rel='stylesheet' integrity='" . esc_attr( $sri_map[ $handle ] ) . "' crossorigin='anonymous'",
                $html
            );
        }
        return $html;
    }

    /**
     * Add SRI integrity + crossorigin attributes to CDN-loaded script tags.
     * Hooked to script_loader_tag for our CDN handles.
     */
    public function add_sri_to_script( $tag, $handle ) {
        $sri_map = array(
            'chartjs' => self::CHARTJS_SRI_HASH,
        );
        if ( isset( $sri_map[ $handle ] ) ) {
            $tag = str_replace(
                '<script ',
                '<script integrity="' . esc_attr( $sri_map[ $handle ] ) . '" crossorigin="anonymous" ',
                $tag
            );
        }
        return $tag;
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'f-insights') === false) {
            return;
        }

        wp_enqueue_style('fi-admin-css', FI_PLUGIN_URL . 'assets/css/admin.css', array(), FI_VERSION);

        // Font Awesome Free — loaded on all F! Insights admin pages so the
        // icon picker on the White-Label tab renders the real glyphs.
        wp_enqueue_style(
            'font-awesome-free-admin',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
            array(),
            '6.5.2'
        );

        // Chart.js — only needed on the analytics page; skip it for all other
        // F! Insights admin pages to avoid loading ~200 KB of unused JS.
        if ( $hook === 'f-insights_page_f-insights-analytics' ) {
            wp_enqueue_script(
                'chartjs',
                'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
                array(),
                '4.4.1',
                true
            );
        }

        wp_enqueue_script('fi-admin-js', FI_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'chartjs'), FI_VERSION, true);

        // Media library needed for the logo picker on the White-Label tab.
        // Enqueue on all F! Insights pages so it's available regardless of
        // which tab is active or how the URL was reached.
        wp_enqueue_media();
        
        wp_localize_script('fi-admin-js', 'fiAdmin', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('fi_admin_nonce'),
            'isPremium' => self::is_premium(), // Used by admin JS to toggle premium-only UI
            'strings'   => array(
                'copied'     => __('Copied!', 'f-insights'),
                'copyFailed' => __('Copy failed', 'f-insights'),
            )
        ));
    }
    
    public function frontend_scripts() {
        // Don't load on Divi builder to prevent conflicts with module settings
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
            return;
        }
        
        // Also check for Divi builder query params
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
            return;
        }
        
        // Only load assets on pages that actually contain the shortcode.
        // This avoids adding ~40 KB of JS/CSS + the Google Maps SDK to every
        // page on the site (homepage, blog, checkout, etc.).
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'f_insights' ) ) {
            return;
        }

        // ── Script version — includes a short fingerprint of the white-label
        // options that are inlined into the page via wp_localize_script.
        // When any of those options change (save_settings calls purge_page_caches),
        // the version string changes too, so any page-caching layer that keys on
        // the script src URL will serve a fresh page with the updated fInsights object.
        $wl_fingerprint = substr( md5( implode( '|', array(
            get_option( 'fi_email_btn_text',        'Email Report'                ),
            get_option( 'fi_email_btn_icon',        ''                            ),
            get_option( 'fi_email_placeholder',     'Enter your email'            ),
            get_option( 'fi_scan_btn_text',         'Search Business'             ),
            get_option( 'fi_scan_btn_icon',         'fa-solid fa-magnifying-glass' ),
            get_option( 'fi_scan_placeholder',      'Search a business'           ),
            get_option( 'fi_wl_cta_button_enabled', '0'                           ),
            get_option( 'fi_wl_cta_button_text',    ''                            ),
            get_option( 'fi_wl_cta_btn_icon',       ''                            ),
            get_option( 'fi_wl_cta_button_url',     ''                            ),
            get_option( 'fi_wl_hide_branding',      '0'                           ),
            get_option( 'fi_show_credit_link',       '1'                           ),
        ) ) ), 0, 8 );
        $script_version = FI_VERSION . '-' . $wl_fingerprint;

        wp_enqueue_style( 'fi-frontend-css', FI_PLUGIN_URL . 'assets/css/frontend.css', array(), $script_version );

        // ── FontAwesome (Free CDN) — loaded when any icon is configured.
        // The scan button defaults to fa-solid fa-magnifying-glass, so FA loads on
        // fresh installs too. Existing installs that stored '' will still load FA
        // if any other icon is set; the scan button will fall back to the SVG.
        $needs_fa = get_option( 'fi_scan_btn_icon', 'fa-solid fa-magnifying-glass' )
                 || get_option( 'fi_email_btn_icon', '' )
                 || get_option( 'fi_wl_cta_btn_icon', '' );
        if ( $needs_fa ) {
            wp_enqueue_style(
                'font-awesome-free',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
                array(),
                '6.5.2'
            );
        }

        // Typography customisation removed — CSS variables are defined statically in frontend.css.

        wp_enqueue_script( 'fi-frontend-js', FI_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), $script_version, true );

        // Google Maps SDK is intentionally NOT enqueued here via wp_enqueue_script.
        // Instead we pass the URL to the fInsights JS object and let frontend.js
        // inject the <script> tag on-demand the first time the competitor map is
        // about to render. This avoids loading ~120 KB of Maps SDK on every page
        // view when many visitors never open the map panel.
        $maps_key    = FI_Crypto::get_key( FI_Crypto::GOOGLE_KEY_OPTION );
        $maps_sdk_url = ! empty( $maps_key )
            ? 'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $maps_key ) . '&loading=async'
            : '';

        wp_localize_script( 'fi-frontend-js', 'fInsights', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'fi_frontend_nonce' ),
            'emailEnabled' => self::is_premium(), // Premium feature flag
            // Google Maps SDK URL — frontend.js injects this lazily on first map render.
            // The API key is embedded in the URL and is already subject to HTTP
            // referrer restrictions set in Google Cloud Console.
            'mapsSdkUrl'   => $maps_sdk_url,
            'googleApiKey' => $maps_key, // still needed for Places photo media URLs
            'strings' => array(
                'searching'        => __( 'Searching...', 'f-insights' ),
                'analyzing'        => __( 'Analyzing business data...', 'f-insights' ),
                'generatingReport' => __( 'Generating insights...', 'f-insights' ),
                'error'            => __( 'An error occurred. Please try again.', 'f-insights' ),
                'emailSent'        => __( 'Report sent to your email! Check your spam folder if you don\'t see it.', 'f-insights' ),
            ),
            // Scan input placeholder — white-label (premium only), defaults for free
            'scanPlaceholder' => self::is_premium()
                ? get_option( 'fi_scan_placeholder', __( 'Search a business', 'f-insights' ) )
                : __( 'Search a business', 'f-insights' ),
            // Scan button label + optional FA icon — white-label (premium only), defaults for free
            'scanBtnText' => self::is_premium()
                ? get_option( 'fi_scan_btn_text', __( 'Search Business', 'f-insights' ) )
                : __( 'Search Business', 'f-insights' ),
            'scanBtnIcon' => self::is_premium()
                ? get_option( 'fi_scan_btn_icon', 'fa-solid fa-magnifying-glass' )
                : 'fa-solid fa-magnifying-glass',
            // Email report button label + icon + placeholder — premium only (shown via emailEnabled flag)
            'emailBtnText'     => self::is_premium() ? get_option( 'fi_email_btn_text', __( 'Email Report', 'f-insights' ) )          : __( 'Email Report', 'f-insights' ),
            'emailBtnIcon'     => self::is_premium() ? get_option( 'fi_email_btn_icon', '' )                                           : '',
            'emailPlaceholder' => self::is_premium() ? get_option( 'fi_email_placeholder', __( 'Enter your email', 'f-insights' ) )    : __( 'Enter your email', 'f-insights' ),
            // Report-end CTA link settings — premium only.
            // Free users always get enabled:false so previously saved values
            // from a prior premium subscription never surface after downgrade.
            'ctaButton' => self::is_premium() ? array(
                'enabled' => get_option( 'fi_wl_cta_button_enabled', '0' ) === '1',
                'text'    => get_option( 'fi_wl_cta_button_text', __( 'Book a Vibe Check', 'f-insights' ) ),
                'url'     => get_option( 'fi_wl_cta_button_url', '' ),
                'icon'    => get_option( 'fi_wl_cta_btn_icon', '' ),
            ) : array(
                'enabled' => false,
                'text'    => '',
                'url'     => '',
                'icon'    => '',
            ),
            // Hide credit link — logic:
            //   Free users:    hidden when fi_show_credit_link === '0' (opt-out, default: show)
            //   Premium users: hidden when fi_wl_hide_branding === '1' (opt-in to hide, via White-Label tab)
            //                  Premium users also inherit the free-user toggle so they can
            //                  use either control; branding is hidden if either says to hide it.
            'hideBranding'       => self::is_premium()
                ? ( get_option( 'fi_wl_hide_branding', '0' ) === '1' || get_option( 'fi_show_credit_link', '1' ) === '0' )
                : get_option( 'fi_show_credit_link', '1' ) === '0',
            // Share token — pass retention days so JS knows to expect share data
            'reportRetentionDays' => absint( get_option( 'fi_report_retention_days', 30 ) ),
        ) );
    }
}

// Initialize the plugin
function f_insights() {
    return F_Insights::get_instance();
}

f_insights();

// ── Freemius uninstall cleanup ────────────────────────────────────────────────
// Hooked to Freemius's 'after_uninstall' action so that uninstall telemetry
// and user feedback are sent to Freemius BEFORE our cleanup runs.
// This replaces the uninstall.php file (which has been removed) because
// WordPress's uninstall.php mechanism conflicts with Freemius's uninstall hook.
//
// Not like register_uninstall_hook(), you do NOT have to use a static function.
fi_fs()->add_action( 'after_uninstall', 'fi_fs_uninstall_cleanup' );

/**
 * Removes every database row and option that F! Insights creates so the site
 * is left in a perfectly clean state. Keep this in sync with:
 *   - class-fi-activator.php  (options set on activation)
 *   - class-fi-admin.php      (options saved from settings forms)
 *   - class-fi-analytics.php  (options written at runtime)
 *
 * IMPORTANT: fi_leads contains visitor PII (emails, business contact info).
 * Dropping it on uninstall is a GDPR compliance requirement.
 *
 * NOTE: User-uploaded files and WordPress core data are never touched.
 */
function fi_fs_uninstall_cleanup() {
    global $wpdb;

    // ── 1. Plugin options ─────────────────────────────────────────────────────
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

        // Credit link visibility (all users)
        'fi_show_credit_link',

        // Lead notifications (v1.6+)
        'fi_lead_notification_threshold',

        // Per-context model overrides (v1.9+)
        'fi_claude_model_scan',
        'fi_claude_model_internal',
        'fi_claude_model_intel',

        // Scan button / email button / CTA customisation (v2.0+)
        'fi_scan_btn_text',
        'fi_scan_btn_icon',
        'fi_scan_placeholder',
        'fi_email_btn_text',
        'fi_email_btn_icon',
        'fi_email_placeholder',
        'fi_wl_cta_btn_icon',

        // Batch scanner (v2.2+)
        'fi_batch_max_size',
        'fi_batch_daily_quota',

        // GDPR lead retention (v2.2+)
        'fi_lead_retention_days',

        // CRM webhook (v2.3+)
        'fi_crm_webhook_url',

        // Internal migration flags
        'fi_migration_autoload_no',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // ── 2. Transients ─────────────────────────────────────────────────────────
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

    // ── 3. Custom database tables ─────────────────────────────────────────────
    // IMPORTANT: fi_leads contains PII — must be dropped for GDPR compliance.

    $tables = array(
        $wpdb->prefix . 'fi_analytics',        // scan event log (v1.0+)
        $wpdb->prefix . 'fi_leads',            // lead records with PII (v1.6+) — GDPR: must drop
        $wpdb->prefix . 'fi_cache',            // cached Google Places / website results (v1.0+)
        $wpdb->prefix . 'fi_rate_limits',      // per-IP rate-limit counters (v1.0+)
        $wpdb->prefix . 'fi_shared_reports',   // shareable report store (v2.1.0)
    );

    foreach ( $tables as $table ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
    }

    // ── 4. Object cache ───────────────────────────────────────────────────────
    // Unschedule plugin cron events.
    $cron_events = array( 'fi_rate_limit_cleanup', 'fi_shared_report_cleanup', 'fi_lead_retention_cleanup' );
    foreach ( $cron_events as $event ) {
        $timestamp = wp_next_scheduled( $event );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $event );
        }
    }

    // Flush so stale in-memory references to our data do not persist.
    wp_cache_flush();
}