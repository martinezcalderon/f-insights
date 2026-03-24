<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Premium
 *
 * Premium-gate and upgrade-prompt helper.
 * All license logic is delegated to FI_Polar (Polar.sh MoR integration).
 *
 * is_active()          → FI_Polar::is_active()
 * activate_license()   → FI_Polar::activate_license()
 * deactivate_license() → FI_Polar::deactivate_license()
 * upgrade_url()        → FI_Polar::checkout_url()
 *
 * FI_DEV_MODE bypasses all checks for local development.
 */
class FI_Premium {

    // ─── Upgrade URL ─────────────────────────────────────────────────────────

    /**
     * The URL "Upgrade to Premium" buttons link to.
     * Sourced from FI_Polar::checkout_url() (fi_polar_checkout_url option).
     * Falls back to the legacy fi_upgrade_url option for backwards compatibility.
     */
    public static function upgrade_url(): string {
        $polar_url = FI_Polar::checkout_url();
        if ( $polar_url !== '#' ) return $polar_url;

        // Legacy fallback.
        $url = (string) get_option( 'fi_upgrade_url', '' );
        $url = apply_filters( 'fi_upgrade_url', $url );
        return ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) ? esc_url( $url ) : '#';
    }

    // ─── License state ───────────────────────────────────────────────────────

    /**
     * Is premium active?
     *
     * Returns true immediately in dev mode.
     * Otherwise delegates to FI_Polar::is_active(), which checks:
     *   1. Webhook-activated flag (order.paid / subscription.active)
     *   2. License key validity via Polar API (with 1-hour cache)
     */
    public static function is_active(): bool {
        if ( defined( 'FI_DEV_MODE' ) && FI_DEV_MODE ) {
            return true;
        }
        return FI_Polar::is_active();
    }

    // ─── Upgrade prompt ──────────────────────────────────────────────────────

    /**
     * Upgrade prompt HTML shown to free users on locked pages.
     */
    public static function upgrade_prompt( string $feature = '' ): string {
        global $wpdb;

        $scan_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fi_scans" );
        $feature_label = $feature ?: 'this feature';
        $settings_url  = esc_url( admin_url( 'admin.php?page=fi-insights&tab=api' ) );
        $upgrade_url   = self::upgrade_url();

        $scan_line = $scan_count > 0
            ? '<p style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 20px;">'
              . 'You have ' . number_format( $scan_count ) . ' scan' . ( $scan_count !== 1 ? 's' : '' ) . ' in your database.'
              . '</p>'
            : '';

        $upgrade_btn = $upgrade_url !== '#'
            ? '<a href="' . esc_url( $upgrade_url ) . '" style="display:inline-block;padding:12px 28px;background:#059669;color:#fff;font-size:15px;font-weight:700;border-radius:8px;text-decoration:none;margin-bottom:14px;">Upgrade to Premium</a>'
            : '<p style="font-size:13px;color:#6b7280;margin-bottom:14px;">Contact the site owner to enable premium features.</p>';

        return '<div class="fi-upgrade-prompt" style="text-align:center;padding:36px 32px;">'
             . '<p style="display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;background:#f3f4f6;padding:4px 10px;border-radius:99px;margin:0 0 14px;">Premium Feature</p>'
             . '<h3 style="font-size:20px;font-weight:700;color:#111827;margin:0 0 8px;">Unlock ' . esc_html( $feature_label ) . '</h3>'
             . $scan_line
             . $upgrade_btn
             . '<br>'
             . '<span style="font-size:13px;color:#6b7280;">Already have a license? '
             . '<a href="' . $settings_url . '" style="color:#1d4ed8;text-decoration:underline;">Enter your key in Settings → API Config</a>.'
             . '</span>'
             . '</div>';
    }

    // ─── License management (delegates to FI_Polar) ──────────────────────────

    /**
     * Masked license key for display in wp-admin.
     */
    public static function get_masked_key(): string {
        return FI_Polar::masked_key();
    }

    /**
     * Activate a license key — delegates to FI_Polar::activate_license().
     *
     * @return array|WP_Error
     */
    public static function activate_license( string $key, string $instance_name = '' ): array|WP_Error {
        return FI_Polar::activate_license( $key );
    }

    /**
     * Deactivate the current license — delegates to FI_Polar::deactivate_license().
     */
    public static function deactivate_license(): void {
        FI_Polar::deactivate_license();
    }

    /**
     * @deprecated Validate endpoint logic now lives in FI_Polar.
     * Kept for backwards compat if third-party code calls it.
     */
    public static function validate_license_api( string $key, string $instance_id = '' ): array|WP_Error {
        $result = FI_Polar::validate_license_key( $key );
        if ( is_wp_error( $result ) ) return $result;
        return [ 'valid' => $result ];
    }
}
