<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Followup_Reminder
 *
 * Sends a digest email to the admin listing all leads/prospects whose
 * follow-up date has arrived. One email per run — records are grouped
 * into three priority tiers based on score, then segmented by type
 * (Leads vs Prospects).
 *
 * Frequency options (stored in fi_reminder_frequency):
 *   daily    — runs once per day via fi_reminder_daily
 *   weekly   — runs every Monday via fi_reminder_weekly
 *   disabled — cron hooks exist but bail immediately
 *
 * reminded_at logic:
 *   - Only sends if reminded_at IS NULL (never sent for current date)
 *   - Stamps reminded_at after sending
 *   - When the user changes follow_up_date, JS calls fi_clear_reminder
 *     which clears reminded_at, allowing a fresh send on the new date
 */
class FI_Followup_Reminder {

    const HOOK_DAILY  = 'fi_reminder_daily';
    const HOOK_WEEKLY = 'fi_reminder_weekly';

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( self::HOOK_DAILY,  [ __CLASS__, 'run' ] );
        add_action( self::HOOK_WEEKLY, [ __CLASS__, 'run' ] );
    }

    /**
     * Schedule cron events on activation (or when frequency changes).
     * Called from activator and from the settings save handler.
     */
    public static function reschedule(): void {
        $freq = get_option( 'fi_reminder_frequency', 'daily' );

        // Clear both hooks first
        $ts_d = wp_next_scheduled( self::HOOK_DAILY );
        $ts_w = wp_next_scheduled( self::HOOK_WEEKLY );
        if ( $ts_d ) wp_unschedule_event( $ts_d, self::HOOK_DAILY );
        if ( $ts_w ) wp_unschedule_event( $ts_w, self::HOOK_WEEKLY );

        if ( $freq === 'disabled' ) return;

        if ( $freq === 'weekly' ) {
            if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
                // Fire every Monday at 8am site time
                $next_monday = self::next_weekday_timestamp( 1, 8, 0 );
                wp_schedule_event( $next_monday, 'weekly', self::HOOK_WEEKLY );
            }
        } else {
            // Default: daily at 8am site time
            if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
                $next_8am = self::next_occurrence_timestamp( 8, 0 );
                wp_schedule_event( $next_8am, 'daily', self::HOOK_DAILY );
            }
        }
    }

    /**
     * Unschedule everything — called on plugin deactivation.
     */
    public static function unschedule(): void {
        foreach ( [ self::HOOK_DAILY, self::HOOK_WEEKLY ] as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) wp_unschedule_event( $ts, $hook );
        }
    }

    // ── Main runner ───────────────────────────────────────────────────────

    public static function run(): void {
        if ( get_option( 'fi_reminder_frequency', 'daily' ) === 'disabled' ) return;
        if ( ! get_option( 'fi_reminder_enabled', 1 ) ) return;

        $today = current_time( 'Y-m-d' );
        $due   = FI_DB::get_reminder_due( $today );

        if ( empty( $due ) ) return;

        $sent = self::send_digest( $due );

        if ( $sent ) {
            FI_DB::mark_reminded( array_column( $due, 'id' ) );
        }
    }

    // ── Email builder ─────────────────────────────────────────────────────

    private static function send_digest( array $records ): bool {
        $to      = get_option( 'fi_reminder_email', get_bloginfo( 'admin_email' ) );
        $brand   = get_option( 'fi_brand_name', get_bloginfo( 'name' ) );
        $today   = current_time( 'F j, Y' );
        $count   = count( $records );
        $subject = "[{$brand}] Follow-up reminder: {$count} record" . ( $count !== 1 ? 's' : '' ) . " due today ({$today})";

        // Segment: leads vs prospects
        $leads     = array_filter( $records, fn( $r ) => ( $r->type ?? 'lead' ) === 'lead' );
        $prospects = array_filter( $records, fn( $r ) => ( $r->type ?? 'lead' ) === 'prospect' );

        $body  = self::email_header( $brand, $today, $count );
        $body .= self::build_section( 'Leads', '🙋 Raised their hand; already have their report', $leads );
        $body .= self::build_section( 'Prospects', '📋 Bulk-imported, cold outreach targets', $prospects );
        $body .= self::email_footer( $brand );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $brand . ' <' . get_bloginfo( 'admin_email' ) . '>',
        ];

        return wp_mail( $to, $subject, $body, $headers );
    }

    private static function build_section( string $title, string $subtitle, array $records ): string {
        if ( empty( $records ) ) return '';

        // Priority tiers by score
        $high   = array_filter( $records, fn( $r ) => (int) $r->overall_score >= 70 );
        $medium = array_filter( $records, fn( $r ) => (int) $r->overall_score >= 40 && (int) $r->overall_score < 70 );
        $low    = array_filter( $records, fn( $r ) => (int) $r->overall_score < 40 );

        $html  = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">';
        $html .= '<tr><td style="padding:0 0 6px 0;">';
        $html .= '<h2 style="font-size:18px;font-weight:700;color:#111827;margin:0 0 4px 0;">' . esc_html( $title ) . ' <span style="font-size:14px;font-weight:400;color:#6b7280;">(' . count( $records ) . ')</span></h2>';
        $html .= '<p style="font-size:13px;color:#9ca3af;margin:0 0 20px 0;">' . esc_html( $subtitle ) . '</p>';
        $html .= '</td></tr></table>';

        if ( ! empty( $high ) ) {
            $html .= self::tier_block( '🔴 High Priority', '#fee2e2', '#991b1b', $high, $title );
        }
        if ( ! empty( $medium ) ) {
            $html .= self::tier_block( '🟡 Medium Priority', '#fef9c3', '#854d0e', $medium, $title );
        }
        if ( ! empty( $low ) ) {
            $html .= self::tier_block( '⚪ Lower Priority', '#f3f4f6', '#374151', $low, $title );
        }

        return $html;
    }

    private static function tier_block( string $label, string $bg, string $color, array $records, string $type ): string {
        $admin_url = admin_url( 'admin.php?page=fi-market-intel&tab=leads' );

        $html  = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">';
        $html .= '<tr><td style="padding:6px 12px;background:' . $bg . ';border-radius:6px 6px 0 0;">';
        $html .= '<span style="font-size:12px;font-weight:700;color:' . $color . ';">' . esc_html( $label ) . '</span>';
        $html .= '</td></tr>';

        foreach ( $records as $r ) {
            $score      = (int) $r->overall_score;
            $score_color = $score >= 70 ? '#059669' : ( $score >= 40 ? '#d97706' : '#dc2626' );
            $due_date   = $r->follow_up_date ? wp_date( 'M j', strtotime( $r->follow_up_date ) ) : '';
            $pain_raw   = array_filter( array_map( 'trim', explode( ',', $r->pain_points ?? '' ) ) );
            $top_pain   = ! empty( $pain_raw ) ? trim( explode( '(', $pain_raw[0] )[0] ) : '';

            $html .= '<tr><td style="padding:12px;background:#fff;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;border-bottom:1px solid #f3f4f6;">';
            $html .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
            $html .= '<td style="width:40px;vertical-align:top;padding-right:12px;">';
            $html .= '<div style="width:36px;height:36px;border-radius:8px;background:' . $score_color . '1a;display:flex;align-items:center;justify-content:center;">';
            $html .= '<span style="font-size:13px;font-weight:700;color:' . $score_color . ';">' . $score . '</span>';
            $html .= '</div></td>';
            $html .= '<td style="vertical-align:top;">';
            $html .= '<strong style="font-size:14px;color:#111827;">' . esc_html( $r->business_name ) . '</strong>';
            if ( $r->category ?? '' ) {
                $html .= ' <span style="font-size:12px;color:#9ca3af;">· ' . esc_html( $r->category ) . '</span>';
            }
            $html .= '<br>';
            if ( ( $r->email ?? '' ) && $type === 'Leads' ) {
                $html .= '<a href="mailto:' . esc_attr( $r->email ) . '" style="font-size:12px;color:#6366f1;text-decoration:none;">' . esc_html( $r->email ) . '</a>';
                if ( $r->phone ?? '' ) $html .= ' <span style="color:#d1d5db;">·</span> <span style="font-size:12px;color:#6b7280;">' . esc_html( $r->phone ) . '</span>';
            } elseif ( $r->phone ?? '' ) {
                $html .= '<span style="font-size:12px;color:#6b7280;">' . esc_html( $r->phone ) . '</span>';
            }
            if ( $top_pain ) {
                $html .= '<br><span style="font-size:11px;color:#ef4444;background:#fee2e2;padding:1px 6px;border-radius:4px;margin-top:4px;display:inline-block;">' . esc_html( $top_pain ) . '</span>';
            }
            $html .= '</td>';
            $html .= '<td style="vertical-align:top;text-align:right;white-space:nowrap;padding-left:12px;">';
            if ( $due_date ) {
                $html .= '<span style="font-size:11px;color:#6b7280;">Due ' . esc_html( $due_date ) . '</span><br>';
            }
            $html .= '<a href="' . esc_url( $admin_url ) . '" style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600;">View in CRM →</a>';
            $html .= '</td>';
            $html .= '</tr></table>';
            $html .= '</td></tr>';
        }

        $html .= '<tr><td style="height:1px;background:#e5e7eb;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;border-radius:0 0 6px 6px;"></td></tr>';
        $html .= '</table>';

        return $html;
    }

    private static function email_header( string $brand, string $today, int $count ): string {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">'
            . '<tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">'
            . '<tr><td style="background:#111827;padding:24px 32px;">'
            . '<h1 style="color:#fff;font-size:20px;font-weight:700;margin:0;">' . esc_html( $brand ) . '</h1>'
            . '<p style="color:#9ca3af;font-size:13px;margin:4px 0 0 0;">Follow-up digest · ' . esc_html( $today ) . '</p>'
            . '</td></tr>'
            . '<tr><td style="padding:28px 32px 8px 32px;">'
            . '<p style="font-size:15px;color:#374151;margin:0 0 24px 0;">You have <strong>' . $count . ' follow-up' . ( $count !== 1 ? 's' : '' ) . '</strong> due. Records are sorted by priority, highest-scoring businesses first within each tier.</p>';
    }

    private static function email_footer( string $brand ): string {
        $settings_url = admin_url( 'admin.php?page=fi-insights&tab=notifications' );
        return '</td></tr>'
            . '<tr><td style="padding:20px 32px;border-top:1px solid #f3f4f6;">'
            . '<p style="font-size:11px;color:#9ca3af;margin:0;">Sent by ' . esc_html( $brand ) . ' · <a href="' . esc_url( $settings_url ) . '" style="color:#6366f1;text-decoration:none;">Manage reminder settings</a></p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    // ── Timestamp helpers ─────────────────────────────────────────────────

    /**
     * Unix timestamp for the next occurrence of HH:MM in site timezone.
     * If that time has already passed today, returns tomorrow's occurrence.
     */
    private static function next_occurrence_timestamp( int $hour, int $minute ): int {
        $tz   = wp_timezone();
        $now  = new DateTime( 'now', $tz );
        $next = new DateTime( 'today', $tz );
        $next->setTime( $hour, $minute, 0 );
        if ( $next <= $now ) {
            $next->modify( '+1 day' );
        }
        return $next->getTimestamp();
    }

    /**
     * Unix timestamp for the next occurrence of a weekday at HH:MM site time.
     * $weekday: 0 = Sunday … 6 = Saturday (PHP date('w') convention)
     */
    private static function next_weekday_timestamp( int $weekday, int $hour, int $minute ): int {
        $tz   = wp_timezone();
        $now  = new DateTime( 'now', $tz );
        $diff = ( $weekday - (int) $now->format( 'w' ) + 7 ) % 7;
        if ( $diff === 0 ) {
            // Same weekday — check if time has passed
            $candidate = clone $now;
            $candidate->setTime( $hour, $minute, 0 );
            if ( $candidate <= $now ) $diff = 7;
        }
        $next = clone $now;
        $next->modify( "+{$diff} days" );
        $next->setTime( $hour, $minute, 0 );
        return $next->getTimestamp();
    }
}
