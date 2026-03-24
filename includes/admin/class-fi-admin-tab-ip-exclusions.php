<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_Ip_Exclusions {

    public static function render(): void {
        $excluded = get_option( 'fi_excluded_ips', '' );
        $my_ip    = FI_Rate_Limiter::get_client_ip();
        ?>
        <div class="fi-settings-form">
            <p style="font-size:13px;color:#374151;margin-bottom:8px;">
                Every time you run a scan to test or demo your setup, it gets counted in your analytics and against your rate limit; just like a real visitor scan would. This list tells the plugin to ignore scans that come from <em>you</em>, so your data stays clean.
            </p>
            <p style="font-size:13px;color:#374151;margin-bottom:16px;">
                Click <strong>+ Add My Current IP</strong> below to add yourself. You can ignore the long strings of numbers and colons; those are just how the internet identifies your connection right now (your IP address). You don't need to understand them; just add them and move on.
            </p>
            <p style="font-size:13px;color:#374151;margin-bottom:16px;">
                If you see a different IP each time you visit this page, that's normal; most home and mobile internet connections are assigned a new address periodically by your provider. Just click the button again to add the new one. Old entries don't hurt anything and can be left as-is.
            </p>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_excluded_ips">Excluded IPs</label>
                <textarea name="fi_excluded_ips" id="fi_excluded_ips" class="fi-textarea" rows="8"
                          placeholder="One IP per line."><?php echo esc_textarea( $excluded ); ?></textarea>
                <p class="fi-hint">One IP address per line. If you work from multiple locations (home, office, coffee shop), click the button each time you visit from a new place.</p>
            </div>

            <button type="button" id="fi-add-my-ip" class="button button-primary" data-ip="<?php echo esc_attr( $my_ip ); ?>">
                + Add My Current IP
            </button>
            <p style="font-size:12px;color:#6b7280;margin-top:6px;">Your current connection is being identified as: <code><?php echo esc_html( $my_ip ); ?></code></p>

            <?php FI_Admin::save_bar(); ?>
        </div>
        <?php
    }
}