<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Admin_Tab_Notifications
 *
 * Settings tab for admin notification preferences:
 *  - New lead scan alerts (existing fi_notify_enabled logic)
 *  - Follow-up reminder digest: frequency, recipient email
 *
 * Lives under Settings > Notifications.
 */
class FI_Admin_Tab_Notifications {

    public static function render(): void {

        if ( ! FI_Premium::is_active() ) {
            echo FI_Premium::upgrade_prompt( 'Notifications' );
            return;
        }

        $notify_enabled   = (int) get_option( 'fi_notify_enabled',       0 );
        $notify_email     = get_option( 'fi_notify_email',       get_bloginfo( 'admin_email' ) );
        $notify_threshold = (int) get_option( 'fi_notify_threshold',    100 );

        $reminder_enabled   = (int) get_option( 'fi_reminder_enabled',     1 );
        $reminder_freq      = get_option( 'fi_reminder_frequency',  'daily' );
        $reminder_email     = get_option( 'fi_reminder_email',      get_bloginfo( 'admin_email' ) );

        $next_run = self::next_run_label( $reminder_freq );
        ?>
        <div class="fi-settings-form">

            <!-- ── New lead alerts ──────────────────────────────────────── -->
            <div class="fi-section-title">New Lead Alerts</div>
            <p class="fi-hint" style="margin-bottom:20px;">
                Get an email when a new lead is captured through the scanner form.
            </p>

            <div class="fi-field-row">
                <label class="fi-label">Enable Alerts</label>
                <label class="fi-toggle">
                    <input type="checkbox" name="fi_notify_enabled" value="1"
                           id="fi-notify-enabled-cb"
                           <?php checked( $notify_enabled, 1 ); ?>>
                    <span class="fi-toggle-track"></span>
                </label>
                <p class="fi-hint">Send an email when a new organic lead submits the scanner form.</p>
            </div>

            <div id="fi-notify-fields" style="<?php echo $notify_enabled ? '' : 'display:none;'; ?>">
                <div class="fi-field-row">
                    <label class="fi-label" for="fi_notify_email">Alert Email</label>
                    <input type="email" name="fi_notify_email" id="fi_notify_email"
                           value="<?php echo esc_attr( $notify_email ); ?>"
                           class="fi-input" placeholder="admin@yoursite.com">
                    <p class="fi-hint">Leave blank to use the site admin email.</p>
                </div>

                <div class="fi-field-row">
                    <label class="fi-label" for="fi_notify_threshold">Score Threshold</label>
                    <input type="number" name="fi_notify_threshold" id="fi_notify_threshold"
                           value="<?php echo esc_attr( $notify_threshold ); ?>"
                           class="fi-input" style="max-width:100px;" min="0" max="100">
                    <p class="fi-hint">Set to 100 to alert on every lead. Lower values restrict alerts to leads whose score is <em>at or below</em> this number (e.g. 60 = only notify when a business scores 60 or below). This surfaces high-need leads first.</p>
                </div>
            </div>

            <hr class="fi-divider">

            <!-- ── Follow-up reminder digest ────────────────────────────── -->
            <div class="fi-section-title">Follow-Up Reminder Digest</div>
            <p class="fi-hint" style="margin-bottom:20px;">
                Receive a digest email listing all leads and prospects whose follow-up
                date has arrived. Records are grouped by type (Leads vs Prospects) and
                sorted by priority tier within each group. No more than one email per
                reminder cycle; you won't get one email per record.
            </p>

            <div class="fi-field-row">
                <label class="fi-label">Enable Reminders</label>
                <label class="fi-toggle">
                    <input type="checkbox" name="fi_reminder_enabled" value="1"
                           id="fi-reminder-enabled-cb"
                           <?php checked( $reminder_enabled, 1 ); ?>>
                    <span class="fi-toggle-track"></span>
                </label>
                <p class="fi-hint">Send a follow-up digest email when records are due.</p>
            </div>

            <div id="fi-reminder-fields" style="<?php echo $reminder_enabled ? '' : 'display:none;'; ?>">

                <div class="fi-field-row">
                    <label class="fi-label" for="fi_reminder_email">Reminder Email</label>
                    <input type="email" name="fi_reminder_email" id="fi_reminder_email"
                           value="<?php echo esc_attr( $reminder_email ); ?>"
                           class="fi-input" placeholder="admin@yoursite.com">
                    <p class="fi-hint">Who receives the digest. Defaults to the site admin email.</p>
                </div>

                <div class="fi-field-row">
                    <label class="fi-label" for="fi_reminder_frequency">Reminder Frequency</label>
                    <select name="fi_reminder_frequency" id="fi_reminder_frequency" class="fi-select">
                        <option value="daily"  <?php selected( $reminder_freq, 'daily' );  ?>>Daily (every morning at 8am)</option>
                        <option value="weekly" <?php selected( $reminder_freq, 'weekly' ); ?>>Weekly (every Monday at 8am)</option>
                    </select>
                    <p class="fi-hint">
                        Times are in your site's configured timezone
                        (<?php echo esc_html( wp_timezone_string() ); ?>).
                        <?php if ( $next_run ) : ?>
                        <strong>Next scheduled run: <?php echo esc_html( $next_run ); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="fi-field-row">
                    <label class="fi-label">How it works</label>
                    <div class="fi-hint fi-reminder-explainer">
                        <ul style="margin:6px 0 0 16px;padding:0;line-height:1.8;">
                            <li>Set a follow-up date on any lead or prospect row in the pipeline.</li>
                            <li>On the scheduled run, any record whose date has arrived (and hasn't been reminded yet) is included in the digest.</li>
                            <li>After sending, each reminded record is stamped so it won't repeat.</li>
                            <li>If you change a follow-up date, the reminder resets automatically; you'll get a fresh reminder on the new date.</li>
                            <li>Records with status <em>Closed</em> or <em>Lost</em> are never included.</li>
                        </ul>
                    </div>
                </div>

            </div>

            <?php FI_Admin::save_bar(); ?>

        </div>

        <script>
        (function($){
            $('#fi-notify-enabled-cb').on('change', function(){
                $('#fi-notify-fields').toggle( this.checked );
            });
            $('#fi-reminder-enabled-cb').on('change', function(){
                $('#fi-reminder-fields').toggle( this.checked );
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function next_run_label( string $freq ): string {
        if ( $freq === 'disabled' ) return '';

        $hook = $freq === 'weekly'
            ? FI_Followup_Reminder::HOOK_WEEKLY
            : FI_Followup_Reminder::HOOK_DAILY;

        $ts = wp_next_scheduled( $hook );
        if ( ! $ts ) return 'Not yet scheduled; will schedule on next page load.';

        return wp_date( 'D, M j \a\t g:ia', $ts );
    }
}