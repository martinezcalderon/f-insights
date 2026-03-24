<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Ajax
 * All AJAX endpoints. Each handler: validate → delegate → respond.
 */
class FI_Ajax {

    public static function init() {
        $public = [
            'fi_scan',
            'fi_autocomplete',
            'fi_email_report',
            'fi_create_share',
            'fi_photo_proxy',
        ];

        $admin = [
            'fi_test_google',
            'fi_test_claude',
            'fi_view_lead_snapshot',
            'fi_run_market_intel',
            'fi_get_intel_index',
            'fi_load_intel_asset',
            'fi_delete_intel_asset',
            'fi_get_filtered_scan_count',
            'fi_clear_logs',
            'fi_clear_cache',
            'fi_export_leads',
            'fi_save_ip_exclusions',
            'fi_download_logs',
            'fi_activate_license',
            'fi_deactivate_license',
            'fi_send_test_email',
            'fi_generate_pitch',
            'fi_generate_reply',
            'fi_update_lead_status',
            'fi_save_lead_notes',
            'fi_set_followup_date',
            'fi_clear_reminder',
            'fi_save_custom_label',
            'fi_reviews_create',
            'fi_reviews_update_field',
            'fi_reviews_add_surface',
            'fi_reviews_delete_surface',
            'fi_reviews_archive',
            'fi_reviews_restore',
        ];

        foreach ( $public as $action ) {
            $handler = [ __CLASS__, 'handle_' . str_replace( 'fi_', '', $action ) ];
            add_action( 'wp_ajax_' . $action,        $handler );
            add_action( 'wp_ajax_nopriv_' . $action, $handler );
        }

        foreach ( $admin as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'handle_' . str_replace( 'fi_', '', $action ) ] );
        }
    }

    // =========================================================================
    // Public handlers
    // =========================================================================

    public static function handle_autocomplete() {
        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        $query = sanitize_text_field( $_POST['query'] ?? '' );
        if ( strlen( $query ) < 2 ) wp_send_json_success( [] );

        if ( ! get_option( 'fi_google_api_key', '' ) ) {
            wp_send_json_error( 'Google API key not configured.' );
        }

        $lat = floatval( $_POST['lat'] ?? 0 );
        $lng = floatval( $_POST['lng'] ?? 0 );

        $suggestions = FI_Google::autocomplete( $query, $lat, $lng );

        wp_send_json_success( $suggestions );
    }

    public static function handle_scan() {
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        // ── Bot defenses ──────────────────────────────────────────────────
        // 1. Honeypot: hidden field bots fill in, humans don't see it.
        if ( ! empty( $_POST['fi_hp'] ) ) {
            // Silent rejection — don't tell bots they were caught
            wp_send_json_error( 'No business specified.' );
        }
        // 2. Timing: legitimate users take at least 2 seconds to find and
        //    submit a business. Sub-2s submissions are automated.
        $elapsed = (int) ( $_POST['fi_ts'] ?? 99 );
        if ( $elapsed < 2 ) {
            wp_send_json_error( 'No business specified.' );
        }

        $place_id   = sanitize_text_field( $_POST['place_id']   ?? '' );
        $place_name = sanitize_text_field( $_POST['place_name'] ?? '' );

        if ( ! $place_id && ! $place_name ) {
            wp_send_json_error( 'No business specified.' );
        }

        $ip      = FI_Rate_Limiter::get_client_ip();
        $scan_id = FI_Logger::generate_scan_id();

        if ( FI_Rate_Limiter::is_limited( $ip ) ) {
            wp_send_json_error( [
                'code'    => 'rate_limited',
                'message' => 'You\'ve reached the scan limit. Please try again later.',
            ] );
        }

        FI_Logger::info( "Scan start for: $place_name ($place_id)", [], $scan_id );

        $result = FI_Scan_Runner::run( $place_id, $place_name, $ip, $scan_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public static function handle_email_report() {
        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        if ( ! FI_Premium::is_active() ) {
            wp_send_json_error( [ 'code' => 'premium_required' ] );
        }

        $email   = sanitize_email( $_POST['email']   ?? '' );
        $scan_id = absint( $_POST['scan_id'] ?? 0 );

        if ( ! is_email( $email ) ) wp_send_json_error( 'Invalid email address.' );
        if ( ! $scan_id )           wp_send_json_error( 'Missing scan ID.' );

        $scan = FI_DB::get_scan_by_id( $scan_id );
        if ( ! $scan ) wp_send_json_error( 'Scan not found.' );

        $report      = json_decode( $scan->report_json, true );
        $pain_points = FI_Utils::extract_pain_points( $report );

        $extra = [
            'firstname' => sanitize_text_field( $_POST['firstname'] ?? '' ),
            'lastname'  => sanitize_text_field( $_POST['lastname']  ?? '' ),
            'phone'     => sanitize_text_field( $_POST['phone']     ?? '' ),
            'role'      => sanitize_text_field( $_POST['role']      ?? '' ),
            'employees' => sanitize_text_field( $_POST['employees'] ?? '' ),
            'custom'    => sanitize_text_field( $_POST['custom']    ?? '' ),
        ];
        $extra = array_filter( $extra, fn( $v ) => $v !== '' );

        // Attempt to send the report email with up to 2 retries.
        // wp_mail() can fail transiently on shared hosting (throttling, SMTP blip).
        // We retry once before giving up, with a short pause between attempts.
        $sent         = false;
        $max_attempts = 2;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $sent = FI_Email::send_report( $email, $scan, $report );
            if ( $sent ) break;
            if ( $attempt < $max_attempts ) sleep( 2 );
        }

        if ( ! $sent ) {
            // Email failed after retries. Still capture the lead so the
            // admin can follow up manually — the intent to receive the report
            // was clearly expressed. Flag it with a note so it's visible.
            $lead_id = FI_Leads::create(
                $scan_id,
                $email,
                $scan->business_name,
                (int) $scan->overall_score,
                implode( ', ', $pain_points ),
                array_merge( $extra, [ 'note' => 'Email delivery failed; follow up manually.' ] ),
                $scan->report_json
            );

            FI_Logger::error( "Report email FAILED after {$max_attempts} attempts for $email ({$scan->business_name})" );

            wp_send_json_success( [
                'sent'    => false,
                'lead_id' => $lead_id,
                'message' => 'I had trouble sending your report. Your details have been saved and I\'ll follow up with you directly.',
            ] );
        }

        $lead_id = FI_Leads::create(
            $scan_id,
            $email,
            $scan->business_name,
            (int) $scan->overall_score,
            implode( ', ', $pain_points ),
            $extra,
            $scan->report_json  // snapshot of the exact report that went to the lead's inbox
        );

        FI_Email::notify_admin( [
            'business_name' => $scan->business_name,
            'email'         => $email,
            'overall_score' => $scan->overall_score,
            'pain_points'   => $pain_points,
        ] );

        FI_Logger::info( "Lead captured: $email for {$scan->business_name} (score: {$scan->overall_score})" );

        wp_send_json_success( [
            'sent'    => true,
            'lead_id' => $lead_id,
        ] );
    }

    public static function handle_create_share() {
        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        $scan_id    = absint( $_POST['scan_id'] ?? 0 );
        if ( ! $scan_id ) wp_send_json_error( 'Missing scan ID.' );

        $source_url = esc_url_raw( wp_unslash( $_POST['source_url'] ?? '' ) );
        $home = home_url();
        if ( $source_url && strpos( $source_url, $home ) !== 0 ) {
            $source_url = ''; 
        }

        $url = FI_Share::create_or_get( $scan_id, $source_url );
        if ( ! $url ) wp_send_json_error( 'Could not create share link.' );

        $share      = FI_DB::get_share_by_scan_id( $scan_id );
        $expiry     = $share ? FI_Share::expiry_display( $share->expires_at ) : '';
        $expires_at = $share ? $share->expires_at : '';

        wp_send_json_success( [ 'url' => $url, 'expiry' => $expiry, 'expires_at' => $expires_at ] );
    }

    public static function handle_photo_proxy() {
        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        $ref = sanitize_text_field( $_GET['ref'] ?? '' );
        if ( ! $ref ) {
            http_response_code( 400 );
            exit;
        }

        $ref = ltrim( $ref, '/' );
        if ( ! preg_match( '#^places/[A-Za-z0-9_\.\-]+/photos/[A-Za-z0-9_\.\-]+$#', $ref ) ) {
            http_response_code( 400 );
            exit;
        }

        $key = get_option( 'fi_google_api_key', '' );
        if ( ! $key ) {
            http_response_code( 500 );
            exit;
        }

        $url = 'https://places.googleapis.com/v1/' . $ref
             . '/media?maxWidthPx=800&key=' . urlencode( $key );

        $response = wp_remote_get( $url, [
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            http_response_code( 502 );
            exit;
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $body         = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            http_response_code( $code );
            exit;
        }

        // Clamp to a known-safe image MIME type — never forward Google's raw
        // Content-Type header verbatim, as a poisoned or unexpected value could
        // serve non-image content (e.g. text/html) from our domain.
        $allowed_types = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        $mime          = strtolower( trim( explode( ';', (string) $content_type )[0] ) );
        $safe_type     = in_array( $mime, $allowed_types, true ) ? $mime : 'image/jpeg';

        header( 'Content-Type: ' . $safe_type );
        header( 'Cache-Control: public, max-age=86400' );
        echo $body;
        exit;
    }

    // =========================================================================
    // Admin handlers
    // =========================================================================

    private static function require_admin(): void {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    }

    public static function handle_test_google() {
        self::require_admin();
        $key = sanitize_text_field( $_POST['key'] ?? '' );
        $res = FI_Google::test_connection( $key );
        if ( is_wp_error( $res ) ) {
            wp_send_json( [ 'ok' => false, 'message' => $res->get_error_message() ] );
        } else {
            wp_send_json( [ 'ok' => true ] );
        }
    }

    public static function handle_test_claude() {
        self::require_admin();
        $key = sanitize_text_field( $_POST['key'] ?? '' );
        $res = FI_Claude::test_connection( $key );
        if ( is_wp_error( $res ) ) {
            wp_send_json( [ 'ok' => false, 'message' => $res->get_error_message() ] );
        } else {
            wp_send_json( [ 'ok' => true ] );
        }
    }


    /**
     * Serve the stored report snapshot for a lead as a standalone HTML page.
     * Opens in a new tab — admin only.
     * URL: /wp-admin/admin-ajax.php?action=fi_view_lead_snapshot&id=N&nonce=X
     */
    public static function handle_view_lead_snapshot() {
        // GET request — verify nonce manually
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $nonce = sanitize_text_field( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'fi_admin_nonce' ) ) wp_die( 'Invalid nonce' );

        $id   = absint( $_GET['id'] ?? 0 );
        $lead = $id ? FI_Leads::get( $id ) : null;
        if ( ! $lead ) wp_die( 'Lead not found.' );

        // If we have a stored snapshot, use it. Otherwise fall back to the
        // current scan report (may have been refreshed since capture).
        $report_json = $lead->report_snapshot ?? null;
        $is_snapshot = ! empty( $report_json );

        if ( ! $is_snapshot ) {
            $scan        = FI_DB::get_scan_by_id( $lead->scan_id );
            $report_json = $scan->report_json ?? null;
        }

        if ( ! $report_json ) wp_die( 'No report data found for this lead.' );

        $report        = json_decode( $report_json, true );
        $captured_date = wp_date( 'F j, Y \a\t g:ia', strtotime( $lead->created_at ) );
        $brand         = get_option( 'fi_brand_name', get_bloginfo( 'name' ) );

        // Build a minimal but readable standalone report page
        $score      = (int) ( $report['overall_score'] ?? $lead->overall_score );
        $categories = $report['categories'] ?? [];

        $score_color = FI_Utils::score_color( $score );

        header( 'Content-Type: text/html; charset=UTF-8' );
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $lead->business_name ); ?>: Lead Report Snapshot</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; color: #374151; }
  .wrap { max-width: 780px; margin: 0 auto; padding: 32px 20px 64px; }
  .banner { background: #111827; color: #fff; padding: 16px 24px; border-radius: 10px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
  .banner-brand { font-size: 14px; font-weight: 700; color: #9ca3af; }
  .banner-meta { font-size: 13px; color: #6b7280; }
  .banner-badge { font-size: 11px; background: <?php echo $is_snapshot ? '#d1fae5' : '#fef3c7'; ?>; color: <?php echo $is_snapshot ? '#065f46' : '#92400e'; ?>; padding: 3px 10px; border-radius: 99px; font-weight: 700; }
  .header { background: #fff; border-radius: 10px; padding: 24px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .business-name { font-size: 22px; font-weight: 800; color: #111827; }
  .business-meta { font-size: 13px; color: #9ca3af; margin-top: 4px; }
  .score-circle { width: 72px; height: 72px; border-radius: 50%; background: <?php echo esc_attr( $score_color ); ?>1a; display: flex; align-items: center; justify-content: center; flex-direction: column; flex-shrink: 0; }
  .score-num { font-size: 24px; font-weight: 800; color: <?php echo esc_attr( $score_color ); ?>; line-height: 1; }
  .score-label { font-size: 9px; font-weight: 700; color: <?php echo esc_attr( $score_color ); ?>; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }
  .categories { display: grid; gap: 12px; }
  .cat { background: #fff; border-radius: 10px; padding: 16px 20px; }
  .cat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
  .cat-name { font-size: 14px; font-weight: 700; color: #111827; }
  .cat-score { font-size: 13px; font-weight: 700; padding: 2px 10px; border-radius: 6px; }
  .cat-headline { font-size: 13px; color: #6b7280; line-height: 1.5; }
  .cat-detail { font-size: 12px; color: #9ca3af; margin-top: 6px; line-height: 1.6; }
  .bar-track { height: 5px; background: #f3f4f6; border-radius: 99px; margin-top: 10px; }
  .bar-fill { height: 5px; border-radius: 99px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="banner">
    <div>
      <div class="banner-brand"><?php echo esc_html( $brand ); ?></div>
      <div class="banner-meta">Lead captured <?php echo esc_html( $captured_date ); ?> · <?php echo esc_html( $lead->email ?: '-' ); ?></div>
    </div>
    <span class="banner-badge"><?php echo $is_snapshot ? esc_html( '📸 Exact report sent to lead' ) : esc_html( '⚠ Current report (no snapshot stored)' ); ?></span>
  </div>

  <div class="header">
    <div>
      <div class="business-name"><?php echo esc_html( $lead->business_name ); ?></div>
      <div class="business-meta"><?php echo esc_html( $lead->category ?? '' ); ?></div>
    </div>
    <div class="score-circle">
      <span class="score-num"><?php echo absint( $score ); ?></span>
      <span class="score-label">Score</span>
    </div>
  </div>

  <div class="categories">
  <?php
  $cat_labels = FI_Utils::cat_labels();
  foreach ( $categories as $key => $cat ) :
    $cs    = (int) ( $cat['score'] ?? 0 );
    $cc    = FI_Utils::score_color( $cs );
    $label = $cat_labels[ $key ] ?? $key;
  ?>
  <div class="cat">
    <div class="cat-header">
      <span class="cat-name"><?php echo esc_html( $label ); ?></span>
      <span class="cat-score" style="background:<?php echo esc_attr( $cc ); ?>1a;color:<?php echo esc_attr( $cc ); ?>;"><?php echo absint( $cs ); ?>/100</span>
    </div>
    <div class="bar-track"><div class="bar-fill" style="width:<?php echo absint( $cs ); ?>%;background:<?php echo esc_attr( $cc ); ?>;"></div></div>
    <?php if ( ! empty( $cat['headline'] ) ) : ?>
    <div class="cat-headline" style="margin-top:10px;"><?php echo esc_html( $cat['headline'] ); ?></div>
    <?php endif; ?>
    <?php if ( ! empty( $cat['detail'] ) ) : ?>
    <div class="cat-detail"><?php echo esc_html( $cat['detail'] ); ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>

</div>
</body></html>
        <?php
        exit;
    }

    public static function handle_run_market_intel() {
        self::require_admin();
        if ( ! FI_Premium::is_active() || ! class_exists( 'FI_Analytics' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $type = sanitize_key( $_POST['action_type'] ?? '' );

        // Per-admin cooldown — prevents rapid-fire calls that burn Claude credits.
        // Tier 4/5 actions (large prompts) get a longer cooldown than Tier 1/2.
        // The transient is keyed by user ID so multiple admins don't block each other.
        $heavy_actions = [
            'annual_market_report', 'press_release', 'franchise_brief',
            'referral_partner_script', 'newsletter_template', 'media_pitch',
            'grant_proposal', 'white_label_package', 'paid_intelligence_brief',
            'score_directory', 'academic_partnership', 'city_hall_brief',
            'competitive_intel_service', 'acquisition_package', 'annual_summit',
            'pitch_deck', 'content_strategy', 'webinar_outline', 'video_script_series',
            'proposal_template',
        ];
        $cooldown_secs   = in_array( $type, $heavy_actions, true ) ? 30 : 10;
        $cooldown_key    = 'fi_intel_cd_' . get_current_user_id() . '_' . $type;
        $last_run        = get_transient( $cooldown_key );

        if ( $last_run !== false ) {
            $remaining = $cooldown_secs - ( time() - (int) $last_run );
            if ( $remaining > 0 ) {
                wp_send_json_error( sprintf(
                    'Please wait %d second%s before generating this action again.',
                    $remaining,
                    $remaining === 1 ? '' : 's'
                ) );
            }
        }

        $filters = [
            'category'   => sanitize_text_field( $_POST['filter_category'] ?? 'all' ),
            'date_range' => sanitize_text_field( $_POST['filter_date_range'] ?? 'all' ),
            'platform'   => sanitize_text_field( $_POST['platform'] ?? '' ),
        ];

        $analysis = FI_Analytics::run( $type, $filters );

        if ( is_wp_error( $analysis ) ) {
            wp_send_json_error( $analysis->get_error_message() );
        }

        // Record the successful run time for cooldown enforcement
        set_transient( $cooldown_key, time(), $cooldown_secs );

        // Save the asset to DB — upserts (action_slug + industry) so regenerating
        // overwrites the previous row rather than accumulating versions.
        $industry   = $filters['category'];
        $scan_count = FI_DB::filtered_total_scans( $filters );
        $asset_id   = FI_DB::save_intel_asset( $type, $industry, $analysis, $scan_count );
        $generated_at = gmdate( 'Y-m-d H:i:s' );

        wp_send_json_success( [
            'analysis'     => $analysis,
            'asset_id'     => $asset_id,
            'scan_count'   => $scan_count,
            'generated_at' => $generated_at,
        ] );
    }

    /**
     * Return the saved-asset index for a given industry filter (no content).
     * Called on page load to hydrate card saved-states without fetching LONGTEXT.
     */
    public static function handle_get_intel_index() {
        self::require_admin();
        $industry = sanitize_key( $_POST['industry'] ?? 'all' );
        $rows     = FI_DB::get_intel_asset_index( $industry );

        // current scan count so JS can calculate staleness delta
        $current_count = FI_DB::filtered_total_scans( [
            'category'   => $industry === 'all' ? 'all' : $industry,
            'date_range' => 'all',
        ] );

        // Build a map keyed by action_slug for easy JS lookup
        $index = [];
        foreach ( $rows as $row ) {
            $index[ $row->action_slug ] = [
                'id'           => (int) $row->id,
                'scan_count'   => (int) $row->scan_count,
                'generated_at' => $row->generated_at,
            ];
        }

        wp_send_json_success( [
            'index'         => $index,
            'current_count' => $current_count,
        ] );
    }

    /**
     * Load the full content of a saved asset by ID.
     */
    public static function handle_load_intel_asset() {
        self::require_admin();
        $id    = absint( $_POST['asset_id'] ?? 0 );
        $asset = $id ? FI_DB::get_intel_asset( $id ) : null;

        if ( ! $asset ) {
            wp_send_json_error( 'Asset not found.' );
        }

        wp_send_json_success( [
            'content_md'   => $asset->content_md,
            'scan_count'   => (int) $asset->scan_count,
            'generated_at' => $asset->generated_at,
        ] );
    }

    /**
     * Delete a saved asset by ID.
     */
    public static function handle_delete_intel_asset() {
        self::require_admin();
        $id = absint( $_POST['asset_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }
        FI_DB::delete_intel_asset( $id );
        wp_send_json_success();
    }

    public static function handle_get_filtered_scan_count() {
        self::require_admin();
        $filters = [
            'category'   => sanitize_text_field( $_POST['filter_category'] ?? 'all' ),
            'date_range' => sanitize_text_field( $_POST['filter_date_range'] ?? 'all' ),
        ];
        wp_send_json_success( [ 'count' => FI_DB::filtered_total_scans( $filters ) ] );
    }

    public static function handle_clear_logs() {
        self::require_admin();
        FI_Logger::clear_all();
        wp_send_json_success();
    }

    public static function handle_clear_cache() {
        self::require_admin();
        FI_Cache::clear_all();
        wp_send_json_success();
    }

    public static function handle_export_leads() {
        self::require_admin();
        if ( ! FI_Premium::is_active() ) wp_send_json_error( 'premium_required' );

        $csv      = FI_Leads::export_csv();
        $filename = 'fi-leads-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        echo $csv;
        exit;
    }

    public static function handle_update_lead_status() {
        self::require_admin();
        $id     = absint( $_POST['lead_id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );
        // 'new' is only valid for leads; 'uncontacted' is only valid for prospects.
        // The combined list is accepted here — type-specific enforcement is left
        // to the UI dropdowns which already show only the relevant subset.
        $allowed = [ 'uncontacted', 'new', 'contacted', 'qualified', 'closed', 'lost' ];
        if ( ! $id || ! in_array( $status, $allowed, true ) ) {
            wp_send_json_error( 'Invalid parameters.' );
        }
        // Prevent type/status mismatches at the DB level
        $lead = FI_DB::get_leads( [ 'id' => $id, 'limit' => 1 ] )[0] ?? null;
        if ( $lead ) {
            if ( $lead->type === 'prospect' && $status === 'new' ) {
                wp_send_json_error( 'Invalid status for prospect.' );
            }
            if ( $lead->type === 'lead' && $status === 'uncontacted' ) {
                wp_send_json_error( 'Invalid status for lead.' );
            }
        }
        FI_DB::update_lead( $id, [ 'status' => $status ] );
        wp_send_json_success();
    }

    public static function handle_save_lead_notes() {
        self::require_admin();
        $id    = absint( $_POST['lead_id'] ?? 0 );
        $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing lead ID.' );
        FI_DB::update_lead( $id, [ 'notes' => $notes ] );
        wp_send_json_success();
    }

    public static function handle_set_followup_date() {
        self::require_admin();
        $id   = absint( $_POST['lead_id'] ?? 0 );
        $date = sanitize_text_field( $_POST['date'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing lead ID.' );
        // Validate format YYYY-MM-DD or empty (clear)
        $value = ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) ? $date : null;
        FI_DB::update_lead( $id, [ 'follow_up_date' => $value, 'reminded_at' => null ] );
        wp_send_json_success();
    }

    public static function handle_clear_reminder() {
        self::require_admin();
        $id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing lead ID.' );
        FI_DB::clear_reminded( $id );
        wp_send_json_success();
    }

    public static function handle_save_ip_exclusions() {
        self::require_admin();
        update_option( 'fi_excluded_ips', sanitize_textarea_field( $_POST['ips'] ?? '' ) );
        wp_send_json_success();
    }

    public static function handle_save_custom_label() {
        self::require_admin();
        $label = sanitize_text_field( $_POST['label'] ?? '' );
        update_option( 'fi_field_custom_label', $label );
        wp_send_json_success();
    }

    public static function handle_download_logs() {
        self::require_admin();
        FI_Logger::download_today();
    }

    public static function handle_activate_license() {
        self::require_admin();
        $key = sanitize_text_field( $_POST['key'] ?? '' );
        if ( ! $key ) wp_send_json_error( 'Missing license key' );
        
        $res = FI_Premium::activate_license( $key, get_bloginfo( 'name' ) );
        is_wp_error( $res )
            ? wp_send_json_error( $res->get_error_message() )
            : wp_send_json_success( [ 'message' => 'License activated' ] );
    }

    public static function handle_deactivate_license() {
        self::require_admin();
        FI_Premium::deactivate_license();
        wp_send_json_success( [ 'message' => 'License deactivated' ] );
    }

    public static function handle_send_test_email() {
        self::require_admin();

        $to         = get_option( 'admin_email' );
        $brand_name = get_option( 'fi_brand_name', get_bloginfo( 'name' ) );
        $fake_scan  = (object) [
            'id'            => 0,
            'business_name' => 'Sample Business',
            'address'       => '123 Main St, Anytown, USA',
            'phone'         => '(555) 123-4567',
            'website'       => 'https://example.com',
            'overall_score' => 62,
        ];

        $fake_report = [
            'overall_score'         => 62,
            'competitive_narrative' => 'This is a preview of the competitive context section.',
            'priority_actions'      => [
                [ 'title' => 'Respond to recent reviews',  'description' => 'Three unanswered 1-star reviews are dragging down the rating.', 'impact' => 'high', 'effort' => 'low' ],
                [ 'title' => 'Add 10+ photos',             'description' => 'Competitors average 45 photos; this profile has 3.',             'impact' => 'high', 'effort' => 'low' ],
            ],
            'categories' => [
                'online_presence'  => [ 'score' => 58, 'headline' => 'Profile is incomplete.', 'analysis' => 'No website, description, or hours.', 'recommendations' => [ 'Add hours', 'Write description', 'Add website' ] ]
            ],
        ];

        $sent = FI_Email::send_report( $to, $fake_scan, $fake_report );
        $sent
            ? wp_send_json_success( "Test email sent to $to" )
            : wp_send_json_error( 'wp_mail() returned false. Check your email configuration.' );
    }

    public static function handle_generate_pitch() {
        self::require_admin();

        if ( ! FI_Premium::is_active() || ! class_exists( 'FI_Pitch' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) wp_send_json_error( 'Missing lead ID.' );

        $result = FI_Pitch::generate( $lead_id );

        is_wp_error( $result )
            ? wp_send_json_error( $result->get_error_message() )
            : wp_send_json_success( $result );
    }

    public static function handle_generate_reply() {
        self::require_admin();

        if ( ! FI_Premium::is_active() || ! class_exists( 'FI_Pitch' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) wp_send_json_error( 'Missing lead ID.' );

        $result = FI_Pitch::generate_reply( $lead_id );

        is_wp_error( $result )
            ? wp_send_json_error( $result->get_error_message() )
            : wp_send_json_success( $result );
    }


    /**
     * @deprecated 1.0.12 Use FI_Utils::extract_pain_points() directly.
     */
    private static function extract_pain_points( array $report ): array {
        return FI_Utils::extract_pain_points( $report );
    }

    // =========================================================================
    // F! Reviews handlers
    // =========================================================================

    /**
     * Create a Reviews record from a closed lead.
     * Returns the record ID and redirect URL on success.
     */
    public static function handle_reviews_create() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) wp_send_json_error( 'Missing lead ID.' );

        // Confirm the lead is actually closed before creating
        $lead = FI_DB::get_leads( [ 'id' => $lead_id, 'limit' => 1 ] )[0] ?? null;
        if ( ! $lead ) wp_send_json_error( 'Lead not found.' );
        if ( $lead->status !== 'closed' ) wp_send_json_error( 'Lead must be marked Closed first.' );

        $record_id = FI_Reviews::create_from_lead( $lead_id );
        if ( ! $record_id ) wp_send_json_error( 'Could not create Reviews record.' );

        wp_send_json_success( [
            'record_id'   => $record_id,
            'redirect_url'=> admin_url( 'admin.php?page=fi-market-intel&tab=reviews&review_id=' . $record_id ),
        ] );
    }

    /**
     * Autosave a single field on a Reviews record.
     * Used by all text inputs, selects, and checkboxes on the detail screen.
     */
    public static function handle_reviews_update_field() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $record_id = absint( $_POST['record_id'] ?? 0 );
        $field     = sanitize_key( $_POST['field'] ?? '' );
        $value     = wp_unslash( $_POST['value'] ?? '' );

        if ( ! $record_id || ! $field ) wp_send_json_error( 'Missing parameters.' );

        // Boolean fields arrive as '1' or '0' from checkboxes
        if ( str_starts_with( $field, 'feature_' ) ) {
            $value = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
        }

        // FI_Reviews::update() sanitizes all values internally by field type
        $updated = FI_Reviews::update( $record_id, [ $field => $value ] );
        $updated ? wp_send_json_success() : wp_send_json_error( 'Update failed.' );
    }

    /**
     * Add a tracking surface to a Reviews record.
     */
    public static function handle_reviews_add_surface() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $record_id = absint( $_POST['record_id'] ?? 0 );
        $label     = sanitize_text_field( $_POST['label'] ?? '' );
        // sanitize_key lowercases and strips non [a-z0-9_-]; replace hyphens for consistency
        $param     = str_replace( '-', '_', sanitize_key( $_POST['param'] ?? '' ) );

        if ( ! $record_id || ! $label || ! $param ) {
            wp_send_json_error( 'Label and param are both required.' );
        }

        $surface_id = FI_Reviews::add_tracking_surface( $record_id, $label, $param );
        if ( ! $surface_id ) {
            wp_send_json_error( 'Could not add surface. The param may already exist for this record.' );
        }

        // Build the tagged URL to return to JS
        $record = FI_Reviews::get( $record_id );
        $tagged = $record ? FI_Reviews::build_tracked_url( $record, $param ) : '';

        wp_send_json_success( [
            'surface_id' => $surface_id,
            'label'      => $label,
            'param'      => $param,
            'tagged_url' => $tagged,
        ] );
    }

    /**
     * Delete a single tracking surface.
     */
    public static function handle_reviews_delete_surface() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $surface_id = absint( $_POST['surface_id'] ?? 0 );
        if ( ! $surface_id ) wp_send_json_error( 'Missing surface ID.' );

        FI_Reviews::delete_tracking_surface( $surface_id )
            ? wp_send_json_success()
            : wp_send_json_error( 'Could not delete surface.' );
    }

    /**
     * Archive a Reviews record (snippet fires fallback, data retained).
     */
    public static function handle_reviews_archive() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $record_id = absint( $_POST['record_id'] ?? 0 );
        if ( ! $record_id ) wp_send_json_error( 'Missing record ID.' );

        FI_Reviews::archive( $record_id )
            ? wp_send_json_success()
            : wp_send_json_error( 'Could not archive record.' );
    }

    /**
     * Restore an archived Reviews record.
     */
    public static function handle_reviews_restore() {
        self::require_admin();

        if ( ! class_exists( 'FI_Reviews' ) ) {
            wp_send_json_error( 'premium_required' );
        }

        $record_id = absint( $_POST['record_id'] ?? 0 );
        if ( ! $record_id ) wp_send_json_error( 'Missing record ID.' );

        FI_Reviews::restore( $record_id )
            ? wp_send_json_success()
            : wp_send_json_error( 'Could not restore record.' );
    }
}