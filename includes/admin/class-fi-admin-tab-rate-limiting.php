<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_Rate_Limiting {

    public static function render(): void {
        $enabled       = get_option( 'fi_rate_limit_enabled', 0 );
        $max           = get_option( 'fi_rate_limit_max', 3 );
        $window        = get_option( 'fi_rate_limit_window', 3600 );
        $trust_proxy   = get_option( 'fi_trust_proxy_headers', 0 );
        $const_set     = defined( 'FI_TRUST_PROXY_HEADERS' ) && FI_TRUST_PROXY_HEADERS;

        // Detect the actual IP method in use so we can show it in the UI
        $detected_ip   = FI_Rate_Limiter::get_client_ip();
        $server_ip     = $_SERVER['REMOTE_ADDR'] ?? '-';
        $ip_source     = ( $trust_proxy || $const_set ) ? 'proxy headers' : 'REMOTE_ADDR';

        // Snap any legacy raw-seconds value to the nearest preset
        $presets = [ 3600, 10800, 21600, 43200, 86400 ];
        if ( ! in_array( (int) $window, $presets, true ) ) {
            $closest = $presets[0];
            foreach ( $presets as $p ) {
                if ( abs( $p - $window ) < abs( $closest - $window ) ) $closest = $p;
            }
            $window = $closest;
        }

        // Claude usage counters
        $tokens_in  = (int) get_option( 'fi_tokens_input',  0 );
        $tokens_out = (int) get_option( 'fi_tokens_output', 0 );
        $api_calls  = (int) get_option( 'fi_api_calls',     0 );
        $updated    = get_option( 'fi_tokens_updated', '' );
        $total_tok  = $tokens_in + $tokens_out;
        ?>
        <div class="fi-settings-form">

            <!-- ── Visitor Rate Limiting ─────────────────────────────────── -->
            <h3 class="fi-section-heading" style="margin-top:0;">Visitor Rate Limiting</h3>
            <p class="fi-hint" style="margin-bottom:16px;">Controls how often a single IP address can trigger a scan. This protects your Claude API budget from abuse.</p>

            <div class="fi-field-row">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <input type="checkbox" name="fi_rate_limit_enabled" id="fi_rate_limit_enabled"
                           value="1" <?php checked( $enabled, 1 ); ?>>
                    Enable Rate Limiting
                </label>
                <p class="fi-hint">Limits how many times a single IP can scan per time window. Recommended on public pages.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_rate_limit_max">Max Scans Per IP</label>
                <input type="number" name="fi_rate_limit_max" id="fi_rate_limit_max"
                       value="<?php echo esc_attr( $max ); ?>"
                       class="fi-input fi-input-sm" min="1" max="100">
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_rate_limit_window">Time Window</label>
                <select name="fi_rate_limit_window" id="fi_rate_limit_window" class="fi-select">
                    <?php
                    $window_options = [
                        3600  => '1 hour',
                        10800 => '3 hours',
                        21600 => '6 hours',
                        43200 => '12 hours',
                        86400 => '24 hours',
                    ];
                    foreach ( $window_options as $seconds => $label ) :
                    ?>
                    <option value="<?php echo absint( $seconds ); ?>" <?php selected( $window, $seconds ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="fi-hint">IPs in the Exclusions list always bypass this.</p>
            </div>

            <!-- ── Proxy / CDN Header Trust ─────────────────────────────────── -->
            <h3 class="fi-section-heading" style="margin-top:28px;">Proxy &amp; CDN Detection</h3>
            <p class="fi-hint" style="margin-bottom:16px;">
                On shared or managed hosting (Kinsta, Flywheel, Cloudflare), every visitor
                may share the same <code>REMOTE_ADDR</code>, causing one user hitting the scan limit
                to lock out everyone else. Enable this if your site is behind a trusted reverse proxy or CDN.
            </p>

            <?php if ( $const_set ) : ?>
            <div class="notice notice-info inline" style="margin:0 0 16px;padding:10px 14px;">
                <p style="margin:0;font-size:13px;">
                    <strong>FI_TRUST_PROXY_HEADERS is defined in wp-config.php</strong> and takes precedence.
                    Proxy headers are already trusted, the checkbox below has no additional effect.
                </p>
            </div>
            <?php endif; ?>

            <div class="fi-field-row">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <input type="checkbox" name="fi_trust_proxy_headers" id="fi_trust_proxy_headers"
                           value="1" <?php checked( $trust_proxy || $const_set, true ); ?>
                           <?php disabled( $const_set, true ); ?>>
                    Trust proxy headers for IP detection
                </label>
                <p class="fi-hint" style="margin-top:6px;">
                    When enabled, reads the visitor IP from <code>CF-Connecting-IP</code>,
                    <code>X-Forwarded-For</code>, or <code>X-Real-IP</code> headers.
                    <strong>Only enable this if your server sits behind a trusted CDN or load balancer</strong>
                    that strips and rewrites these headers before forwarding requests.
                    Enabling on an unprotected server lets visitors spoof any IP and bypass rate limiting entirely.
                </p>
            </div>

            <div class="fi-field-row">
                <span class="fi-label">Current IP detected as</span>
                <code style="font-size:13px;background:#f3f4f6;border:1px solid #e5e7eb;padding:3px 8px;border-radius:5px;">
                    <?php echo esc_html( $detected_ip ); ?>
                </code>
                <p class="fi-hint">
                    Source: <?php echo esc_html( $ip_source ); ?>.
                    <?php if ( $detected_ip !== $server_ip && ! $trust_proxy && ! $const_set ) : ?>
                    REMOTE_ADDR is <code><?php echo esc_html( $server_ip ); ?></code>; if this looks like a load balancer
                    address rather than a real visitor IP, enabling proxy header trust will fix rate limiting.
                    <?php endif; ?>
                </p>
            </div>


            <!-- ── Share Link Expiry ──────────────────────────────────── -->
            <h3 class="fi-section-heading" style="margin-top:28px;">Share Link Expiry</h3>
            <div class="fi-field-row">
                <label class="fi-label" for="fi_share_expiry_days">Shareable report links expire after</label>
                <select name="fi_share_expiry_days" id="fi_share_expiry_days" class="fi-select">
                    <?php
                    $share_expiry = (int) get_option( 'fi_share_expiry_days', 7 );
                    foreach ( [
                        7   => '7 days',
                        14  => '14 days',
                        30  => '30 days (recommended)',
                        60  => '60 days',
                        90  => '90 days',
                        365 => '1 year',
                    ] as $days => $label ) : ?>
                    <option value="<?php echo (int) $days; ?>" <?php selected( $share_expiry, $days ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php FI_Admin::save_bar(); ?>

        </div><!-- /.fi-settings-form -->

        <!-- ── Claude API Usage — full width, outside the narrow settings form ── -->
        <hr style="margin:32px 0;border:none;border-top:1px solid #e5e7eb;">

        <h3 style="font-size:15px;font-weight:700;color:#111827;margin:0 0 6px;">Claude API Usage</h3>
        <p style="font-size:13px;color:#6b7280;margin:0 0 20px;max-width:640px;">
            Tracked since the plugin was activated. Resets only if you clear it manually.
            For full account-level billing, visit your
            <a href="https://console.anthropic.com/settings/usage" target="_blank" rel="noopener" style="color:#2563eb;">Anthropic Console → Usage</a>.
        </p>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(160px,220px));gap:12px;margin-bottom:16px;">

            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;">
                <div style="font-size:26px;font-weight:700;color:#111827;line-height:1.1;"><?php echo number_format( $api_calls ); ?></div>
                <div style="font-size:12px;font-weight:600;color:#374151;margin-top:5px;">Total API calls</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Scans + Market Intel + Pitches</div>
            </div>

            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;">
                <div style="font-size:26px;font-weight:700;color:#111827;line-height:1.1;"><?php echo number_format( $tokens_in ); ?></div>
                <div style="font-size:12px;font-weight:600;color:#374151;margin-top:5px;">Input tokens sent</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Prompts + business data</div>
            </div>

            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;">
                <div style="font-size:26px;font-weight:700;color:#111827;line-height:1.1;"><?php echo number_format( $tokens_out ); ?></div>
                <div style="font-size:12px;font-weight:600;color:#374151;margin-top:5px;">Output tokens received</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Reports + generated content</div>
            </div>

        </div>

        <?php if ( $total_tok > 0 ) :
            $avg_per_call = $api_calls ? number_format( round( $total_tok / $api_calls ) ) : '-';
        ?>
        <p style="font-size:12px;color:#6b7280;margin:0 0 16px;">
            Avg <?php echo absint( $avg_per_call ); ?> tokens per call.<?php if ( $updated ) : ?>
            Last call: <?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $updated ) ) ); ?>.<?php endif; ?>
        </p>
        <?php endif; ?>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a href="https://console.anthropic.com/settings/usage" target="_blank" rel="noopener" class="button">
                View full usage in Anthropic Console ↗
            </a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fi_reset_usage' ), 'fi_reset_usage' ) ); ?>"
               class="button"
               onclick="return confirm('Reset all tracked usage counters to zero?');">
                Reset counters
            </a>
        </div>

        <?php
    }
}