<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Email
 * Sends fully inline-styled HTML report emails via wp_mail().
 * All copy driven by white-label settings.
 * Compatible with Gmail, Outlook, Apple Mail, Samsung Mail.
 */
class FI_Email {

    /**
     * Send the full report to the visitor who requested it.
     */
    public static function send_report( string $to, object $scan, array $report ): bool {
        $brand_name  = get_option( 'fi_brand_name', get_bloginfo( 'name' ) );
        $reply_to    = get_option( 'fi_reply_to_email', get_option( 'admin_email' ) );
        $report_title = get_option( 'fi_report_title', 'Your Business Insights Report' );

        $subject = $report_title . ': ' . $scan->business_name;
        $body    = self::build_html( $scan, $report );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $brand_name . ' <' . get_option( 'admin_email' ) . '>',
            'Reply-To: ' . $reply_to,
        ];

        $sent = wp_mail( $to, $subject, $body, $headers );
        FI_Logger::info( 'Report email ' . ( $sent ? 'sent' : 'FAILED' ) . " to $to for {$scan->business_name}" );
        return $sent;
    }

    /**
     * Notify admin of a new lead.
     */
    public static function notify_admin( array $lead ): void {
        if ( ! get_option( 'fi_notify_enabled', 0 ) ) return;

        $notify_to  = get_option( 'fi_notify_email', get_option( 'admin_email' ) );
        $threshold  = (int) get_option( 'fi_notify_threshold', 100 );

        // threshold = 100 means "all leads" — always notify.
        // Any lower value means "only notify if score is AT OR BELOW this number"
        // (i.e. only high-need leads). Skip notification when score exceeds the threshold.
        if ( $threshold < 100 && (int) $lead['overall_score'] > $threshold ) return;

        $score_color = FI_Utils::score_color( $lead['overall_score'] );
        $pain        = is_array( $lead['pain_points'] ) ? implode( "\n", $lead['pain_points'] ) : $lead['pain_points'];

        $subject = '🔥 New Lead: ' . $lead['business_name'] . ' (Score: ' . $lead['overall_score'] . ')';
        $body    = "New lead captured on Fricking Local Business Insights.\n\n"
                 . "Business: {$lead['business_name']}\n"
                 . "Score: {$lead['overall_score']}/100\n"
                 . "Email: {$lead['email']}\n\n"
                 . "Top Issues:\n$pain\n\n"
                 . "View leads: " . admin_url( 'admin.php?page=fi-market-intel&tab=leads' );

        wp_mail( $notify_to, $subject, $body );
    }

    // =========================================================================
    // HTML builder
    // =========================================================================

    private static function build_html( object $scan, array $report ): string {
        $brand_name   = get_option( 'fi_brand_name', get_bloginfo( 'name' ) );
        $report_title = get_option( 'fi_report_title', 'Your Business Insights Report' );
        $footer_cta   = get_option( 'fi_email_footer_cta', 'Want help putting these into action? Reply to this email.' );
        $logo_url     = get_option( 'fi_logo_url', '' );
        $cta_enabled  = get_option( 'fi_cta_enabled', 0 );
        $cta_text     = get_option( 'fi_cta_text', 'Book a Free Consultation' );
        $cta_url      = get_option( 'fi_cta_url', '' );
        $hide_credit  = get_option( 'fi_hide_credit', 0 );

        $overall      = $report['overall_score'] ?? 0;
        $score_color  = FI_Utils::score_color( $overall );
        $categories   = $report['categories'] ?? [];
        $priority     = $report['priority_actions'] ?? [];
        $narrative    = $report['competitive_narrative'] ?? '';

        // Score tagline
        $tagline = $overall >= 80 ? '🏆 Strong performance; a few tweaks and you\'re untouchable.'
                 : ( $overall >= 60 ? '👍 Good foundation, room to grow!'
                 : '🚨 Significant gaps are holding this business back.' );

        $cat_labels = FI_Utils::cat_labels();

        // ── Header: logo or brand name, title, business name ──────────────
        // If logo: show logo + report title on light background
        // If no logo: show brand emoji + report title on dark background
        if ( $logo_url ) {
            $header_bg    = '#f8fafc';
            $header_html  = '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $brand_name ) . '" style="max-height:60px;max-width:180px;display:block;margin:0 auto 12px;">';
            $title_color  = '#111827';
            $sub_color    = '#4b5563';
        } else {
            $header_bg    = '#1a1a2e';
            $header_html  = '';
            $title_color  = '#ffffff';
            $sub_color    = 'rgba(255,255,255,0.75)';
        }

        $header_section = '
  <tr><td style="background:' . $header_bg . ';padding:32px;border-radius:12px 12px 0 0;text-align:center;">
    ' . $header_html . '
    <h1 style="margin:0 0 6px;font-size:22px;font-weight:700;color:' . $title_color . ';">🎯 ' . esc_html( $report_title ) . '</h1>
    <p style="margin:0;font-size:14px;color:' . $sub_color . ';">for ' . esc_html( $scan->business_name ) . '</p>
  </td></tr>';

        // ── Overall score ───────────────────────────────────────────────────
        $score_section = '
  <tr><td style="background:#ffffff;padding:32px;text-align:center;border-bottom:1px solid #e5e7eb;">
    <p style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Overall Digital Presence Score</p>
    <div style="display:inline-block;width:100px;height:100px;background:' . $score_color . ';border-radius:50%;text-align:center;padding:22px 0;box-sizing:border-box;">
      <div style="font-size:36px;font-weight:800;color:#ffffff;line-height:1;">' . $overall . '</div>
      <div style="font-size:11px;color:rgba(255,255,255,0.8);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;">Overall Score</div>
    </div>
    <p style="margin:16px 0 0;font-size:14px;color:#374151;">' . $tagline . '</p>
  </td></tr>';

        // ── Business meta (address/phone/website) ───────────────────────────
        $meta_parts = [];
        if ( $scan->address ) $meta_parts[] = '📍 ' . esc_html( $scan->address );
        if ( $scan->phone )   $meta_parts[] = '📞 ' . esc_html( $scan->phone );
        if ( $scan->website ) $meta_parts[] = '🌐 <a href="' . esc_url( $scan->website ) . '" style="color:#2563eb;">' . esc_html( $scan->website ) . '</a>';

        $meta_section = '';
        if ( ! empty( $meta_parts ) ) {
            $meta_section = '
  <tr><td style="background:#f9fafb;padding:16px 32px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#4b5563;line-height:1.8;">
    ' . implode( '<br>', $meta_parts ) . '
  </td></tr>';
        }

        // ── Category cards ──────────────────────────────────────────────────
        $cats_html = '';
        foreach ( $cat_labels as $key => $label ) {
            if ( empty( $categories[ $key ] ) ) continue;
            $cat  = $categories[ $key ];
            $sc   = FI_Utils::score_color( $cat['score'] );
            $recs = '';
            foreach ( $cat['recommendations'] ?? [] as $rec ) {
                $recs .= '<li style="margin:4px 0;font-size:13px;color:#374151;">→ ' . esc_html( $rec ) . '</li>';
            }
            $cats_html .= '
            <div style="margin-bottom:14px;padding:16px;background:#f9fafb;border-radius:8px;border-left:4px solid ' . $sc . ';">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
                    <strong style="font-size:14px;color:#111827;">' . esc_html( $label ) . '</strong>
                    <span style="font-size:17px;font-weight:700;color:' . $sc . ';white-space:nowrap;">' . $cat['score'] . '<span style="font-size:11px;color:#6b7280;">/100</span></span>
                </div>
                <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#1f2937;">' . esc_html( $cat['headline'] ?? '' ) . '</p>
                <p style="margin:0 0 6px;font-size:13px;color:#4b5563;line-height:1.5;">' . esc_html( $cat['analysis'] ?? '' ) . '</p>
                ' . ( $recs ? '<ul style="margin:8px 0 0;padding-left:0;list-style:none;">' . $recs . '</ul>' : '' ) . '
            </div>';
        }

        // ── Priority actions ─────────────────────────────────────────────────
        $priority_html = '';
        foreach ( array_slice( $priority, 0, 5 ) as $action ) {
            $ic = $action['impact'] === 'high' ? '#15803d' : ( $action['impact'] === 'medium' ? '#d97706' : '#6b7280' );
            $ec = $action['effort'] === 'low'  ? '#15803d' : ( $action['effort'] === 'medium' ? '#d97706' : '#dc2626' );
            $priority_html .= '
            <div style="margin-bottom:10px;padding:13px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <strong style="font-size:13px;color:#111827;">' . esc_html( $action['title'] ?? '' ) . '</strong>
                <p style="margin:5px 0 7px;font-size:12px;color:#4b5563;line-height:1.5;">' . esc_html( $action['description'] ?? '' ) . '</p>
                <span style="font-size:11px;color:' . $ic . ';margin-right:10px;">Impact: ' . ucfirst( $action['impact'] ?? '' ) . '</span>
                <span style="font-size:11px;color:' . $ec . ';">Effort: ' . ucfirst( $action['effort'] ?? '' ) . '</span>
            </div>';
        }

        // ── PageSpeed section for email ──────────────────────────────────────
        $ps_html = '';
        $ps_cat  = $categories['pagespeed_insights'] ?? null;
        if ( $ps_cat && empty( $ps_cat['_error'] ) ) {
            $ps_recs = '';
            foreach ( $ps_cat['recommendations'] ?? [] as $rec ) {
                $ps_recs .= '<li style="margin:4px 0;font-size:13px;color:#374151;">→ ' . esc_html( $rec ) . '</li>';
            }
            $doing    = $ps_cat['doing_well']       ?? [];
            $fixing   = $ps_cat['needs_improvement'] ?? [];
            $well_html = '';
            foreach ( $doing as $item ) {
                $well_html .= '<li style="margin:3px 0;font-size:12px;color:#15803d;">✅ ' . esc_html( $item ) . '</li>';
            }
            $fix_html = '';
            foreach ( $fixing as $item ) {
                $fix_html .= '<li style="margin:3px 0;font-size:12px;color:#c2410c;">🔧 ' . esc_html( $item ) . '</li>';
            }

            $ps_html = '
  <tr><td style="background:#ffffff;padding:20px 32px;border-top:1px solid #e5e7eb;">
    <h2 style="margin:0 0 10px;font-size:13px;color:#374151;text-transform:uppercase;letter-spacing:.5px;">⚡ PageSpeed Insights</h2>
    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#1f2937;">' . esc_html( $ps_cat['headline'] ?? '' ) . '</p>
    <p style="margin:0 0 12px;font-size:13px;color:#4b5563;line-height:1.5;">' . esc_html( $ps_cat['analysis'] ?? '' ) . '</p>
    ' . ( $well_html || $fix_html ? '
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
    <tr>
      ' . ( $well_html ? '<td width="50%" valign="top" style="padding-right:8px;background:#f0fdf4;border-radius:6px;padding:10px;">
        <strong style="font-size:12px;color:#15803d;display:block;margin-bottom:6px;">Keep doing this</strong>
        <ul style="margin:0;padding:0;list-style:none;">' . $well_html . '</ul>
      </td>' : '' ) . '
      ' . ( $fix_html ? '<td width="50%" valign="top" style="padding-left:8px;background:#fff7ed;border-radius:6px;padding:10px;">
        <strong style="font-size:12px;color:#c2410c;display:block;margin-bottom:6px;">Fix these first</strong>
        <ul style="margin:0;padding:0;list-style:none;">' . $fix_html . '</ul>
      </td>' : '' ) . '
    </tr>
    </table>' : '' ) . '
    ' . ( $ps_recs ? '<ul style="margin:8px 0 0;padding-left:0;list-style:none;">' . $ps_recs . '</ul>' : '' ) . '
  </td></tr>';
        }
        // ── CTA button ───────────────────────────────────────────────────────
        $cta_html = '';
        if ( $cta_enabled && $cta_url ) {
            $cta_html = '
  <tr><td style="background:#ffffff;padding:8px 32px 24px;text-align:center;">
    <a href="' . esc_url( $cta_url ) . '" style="display:inline-block;padding:13px 32px;background:#1a6eff;color:#ffffff;text-decoration:none;border-radius:8px;font-size:15px;font-weight:600;">' . esc_html( $cta_text ) . '</a>
  </td></tr>';
        }

        // ── Credit ───────────────────────────────────────────────────────────
        $credit = $hide_credit ? '' : '<p style="text-align:center;font-size:11px;color:#6b7280;margin-top:12px;">Powered by <a href="https://fricking.website/f-insights" style="color:#6b7280;">Fricking Local Business Insights</a></p>';

        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 16px;">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

  ' . $header_section . '
  ' . $score_section . '
  ' . $meta_section . '

  <!-- Category breakdown -->
  <tr><td style="background:#ffffff;padding:24px 32px;">
    <h2 style="margin:0 0 14px;font-size:15px;color:#111827;text-transform:uppercase;letter-spacing:.5px;">Category Breakdown</h2>
    ' . $cats_html . '
  </td></tr>

  ' . ( $narrative ? '
  <!-- Competitive context -->
  <tr><td style="background:#f0f4ff;padding:20px 32px;border-top:1px solid #e5e7eb;">
    <h2 style="margin:0 0 8px;font-size:13px;color:#374151;text-transform:uppercase;letter-spacing:.5px;">🏁 Competitive Context</h2>
    <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;font-style:italic;">' . esc_html( $narrative ) . '</p>
  </td></tr>' : '' ) . '

  ' . $ps_html . '

  ' . ( $priority_html ? '
  <!-- Priority actions -->
  <tr><td style="background:#ffffff;padding:24px 32px;border-top:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;font-size:15px;color:#111827;text-transform:uppercase;letter-spacing:.5px;">⚡ Priority Actions</h2>
    ' . $priority_html . '
  </td></tr>' : '' ) . '

  ' . $cta_html . '

  <!-- Footer -->
  <tr><td style="background:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb;border-radius:0 0 12px 12px;">
    <p style="margin:0;font-size:13px;color:#4b5563;text-align:center;">' . esc_html( $footer_cta ) . '</p>
    ' . $credit . '
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }

}
