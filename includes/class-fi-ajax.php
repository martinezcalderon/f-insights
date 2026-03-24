<?php
/**
 * AJAX request handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_fi_search_business', array($this, 'search_business'));
        add_action('wp_ajax_nopriv_fi_search_business', array($this, 'search_business'));
        
        add_action('wp_ajax_fi_scan_business', array($this, 'scan_business'));
        add_action('wp_ajax_nopriv_fi_scan_business', array($this, 'scan_business'));
        
        add_action('wp_ajax_fi_email_report', array($this, 'email_report'));
        add_action('wp_ajax_nopriv_fi_email_report', array($this, 'email_report'));
        
        // Lead management AJAX (v1.6.0) - Admin only
        add_action('wp_ajax_fi_update_lead_status', array($this, 'update_lead_status'));
        add_action('wp_ajax_fi_update_lead_notes', array($this, 'update_lead_notes'));
        add_action('wp_ajax_fi_export_leads_csv', array($this, 'export_leads_csv'));
        add_action('wp_ajax_fi_get_leads', array($this, 'get_leads'));
        add_action('wp_ajax_fi_test_google_key', array($this, 'test_google_key'));
        add_action('wp_ajax_fi_test_claude_key', array($this, 'test_claude_key'));
        add_action('wp_ajax_fi_send_test_email', array($this, 'send_test_email'));
        add_action('wp_ajax_fi_reset_tab_defaults', array($this, 'reset_tab_defaults'));
        
        // Report viewer (v1.7.0) - Admin only
        add_action('wp_ajax_fi_view_report', array($this, 'view_report'));

        // Bulk lead status update (v2.0.1) - Admin only
        add_action('wp_ajax_fi_bulk_update_leads', array($this, 'bulk_update_leads'));

        // Rescan a stored lead (v2.0.1) - Admin only
        add_action('wp_ajax_fi_rescan_lead', array($this, 'rescan_lead'));
        add_action('wp_ajax_fi_market_intel', array($this, 'market_intel'));

        // Load a shared report by token (v2.1.0) - Public
        add_action('wp_ajax_fi_get_shared_report',        array($this, 'get_shared_report'));
        add_action('wp_ajax_nopriv_fi_get_shared_report', array($this, 'get_shared_report'));
        add_action('wp_ajax_fi_save_intel_model', array($this, 'save_intel_model'));

        // GDPR / Data management (v2.2.0) - Admin only
        add_action( 'wp_ajax_fi_delete_lead',            array( $this, 'delete_lead' ) );
        add_action( 'wp_ajax_fi_delete_leads_by_email',  array( $this, 'delete_leads_by_email' ) );
        add_action( 'wp_ajax_fi_bulk_delete_leads',      array( $this, 'bulk_delete_leads' ) );
    }
    
    /**
     * Search for businesses (autocomplete)
     */
    public function search_business() {
        check_ajax_referer('fi_frontend_nonce', 'nonce');

        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );

        if (empty($query)) {
            wp_send_json_error(array('message' => __('Please enter a business name', 'f-insights')));
        }

        // Accept browser geolocation coordinates for geo-biased results.
        // floatval() is safe here — non-numeric strings become 0.0.
        $lat = floatval( wp_unslash( $_POST['lat'] ?? 0 ) );
        $lng = floatval( wp_unslash( $_POST['lng'] ?? 0 ) );

        // Basic sanity-check: valid lat/lng ranges.
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $lat = 0.0;
            $lng = 0.0;
        }

        $scanner = new FI_Scanner();
        $results  = $scanner->search_business($query, $lat, $lng);

        if (is_wp_error($results)) {
            wp_send_json_error(array('message' => $results->get_error_message()));
        }

        wp_send_json_success(array('businesses' => $results));
    }
    
    /**
     * Perform full business scan
     */
    public function scan_business() {
        check_ajax_referer('fi_frontend_nonce', 'nonce');
        
        FI_Logger::info('=== Starting new business scan ===');

        // Check IP exclusion list FIRST — excluded IPs must never consume a rate-limit
        // slot. If we checked rate limit first, an excluded admin IP could exhaust the
        // counter and then get a confusing "rate limit exceeded" message instead of
        // the intended exclusion response.
        if ( FI_Analytics::is_ip_excluded( FI_Analytics::get_client_ip() ) ) {
            FI_Logger::info('Scan blocked: IP on exclusion list');
            wp_send_json_error(array(
                'message' => __( "We're not able to process scans from your current network. Please try from a different connection or contact the site owner.", 'f-insights' )
            ));
        }

        // Check rate limit
        $rate_check = FI_Rate_Limiter::check_limit();
        if (is_wp_error($rate_check)) {
            FI_Logger::warning('Rate limit exceeded');
            wp_send_json_error(array('message' => $rate_check->get_error_message()));
        }
        
        $place_id = sanitize_text_field( wp_unslash( $_POST['place_id'] ?? '' ) );
        $user_email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        
        FI_Logger::info('Scan parameters', array('place_id' => $place_id, 'email' => $user_email));
        
        if (empty($place_id)) {
            FI_Logger::error('Invalid place_id provided');
            wp_send_json_error(array('message' => __('Invalid business selected', 'f-insights')));
        }

        // Validate the place_id is safe before firing an API call.
        // Google Place IDs are alphanumeric and may include underscores, hyphens,
        // colons, and forward slashes (the New Places API v1 uses resource-name
        // format like "places/ChIJ..."). We block only characters that have no
        // business being in a Place ID: whitespace, HTML special chars, and anything
        // outside the documented character set.
        if ( strlen( $place_id ) > 500 || ! preg_match( '/^[A-Za-z0-9_\-\/:]+$/', $place_id ) ) {
            FI_Logger::error('Rejected malformed place_id', array('place_id' => substr( $place_id, 0, 50 )));
            wp_send_json_error(array('message' => __('Invalid business selected', 'f-insights')));
        }
        
        // Get business details
        FI_Logger::info('Initializing scanner');
        $scanner = new FI_Scanner();
        $business_data = $scanner->get_business_details($place_id);
        
        if (is_wp_error($business_data)) {
            FI_Logger::error('Failed to get business details', $business_data->get_error_message());
            wp_send_json_error(array('message' => $business_data->get_error_message()));
        }
        
        FI_Logger::info('Business data retrieved successfully', array('name' => $business_data['name']));
        
        // Analyze website if present
        $website_analysis = array();
        if (!empty($business_data['website'])) {
            FI_Logger::info('Analyzing website', array('url' => $business_data['website']));
            $website_analysis = $scanner->analyze_website($business_data['website']);
            FI_Logger::info('Website analysis complete', array_keys($website_analysis));
        }
        
        // Grade the business
        FI_Logger::info('Starting AI grading');
        $grader = new FI_Grader( 'scan' );
        $analysis = $grader->grade_business($business_data, $website_analysis);
        
        if (is_wp_error($analysis)) {
            FI_Logger::error('AI grading failed', $analysis->get_error_message());
            wp_send_json_error(array('message' => $analysis->get_error_message()));
        }
        
        FI_Logger::info('AI grading complete', array('overall_score' => $analysis['overall_score'] ?? 0));
        
        // Determine category
        $category = $grader->categorize_business($business_data['types'] ?? array());
        if (isset($analysis['category']) && !empty($analysis['category'])) {
            $category = $analysis['category'];
        }
        
        // Prepare complete report
        $report = array(
            'business_data' => $business_data,
            'website_analysis' => $website_analysis,
            'analysis' => $analysis,
            'scan_date' => current_time('mysql'),
        );
        
        // Track analytics (always — free and premium)
        FI_Logger::info('Tracking analytics');
        FI_Analytics::track_scan(
            $business_data['name'],
            $category,
            $place_id,
            $analysis['overall_score'] ?? 0,
            $report,
            $user_email
        );

        // NOTE: Lead capture is intentionally NOT called here.
        // A lead is only meaningful when we have a real visitor email — that
        // happens in email_report() when the visitor requests their copy.
        // Capturing an anonymous placeholder here caused duplicate lead rows:
        // one 'anonymous@visitor.com' row on scan + one real-email row on send,
        // because the dedup query matches on (place_id + visitor_email) and the
        // two emails never match.  Visitors who scan but never email are already
        // counted in fi_analytics via track_scan() above.
        
        FI_Logger::info('=== Scan completed successfully ===');

        // Save report for sharing and return the share token/metadata
        $share = $this->save_shared_report( $report, $business_data['name'] ?? '' );

        // Send response
        wp_send_json_success(array(
            'report'   => $report,
            'category' => $category,
            'share'    => $share, // null if saving failed or retention is 0
        ));
    }

    /**
     * Save a completed report to fi_shared_reports and return share metadata.
     *
     * @param array  $report        Full report array.
     * @param string $business_name Used for the expiry message display.
     * @return array|null  { url, expires, generated } or null on failure/disabled.
     */
    private function save_shared_report( array $report, string $business_name ): ?array {
        global $wpdb;

        $retention_days = absint( get_option( 'fi_report_retention_days', 30 ) );
        if ( $retention_days < 1 ) {
            return null; // Admin set to 0 — sharing disabled.
        }
        $retention_days = min( $retention_days, 90 );

        $token      = wp_generate_uuid4();
        $created_at = current_time( 'mysql' );
        $expires_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$retention_days} days" ) );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'fi_shared_reports',
            array(
                'token'         => $token,
                'report_json'   => wp_json_encode( $report ),
                'business_name' => $business_name,
                'created_at'    => $created_at,
                'expires_at'    => $expires_at,
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            FI_Logger::warning( 'Failed to save shared report', array( 'business' => $business_name ) );
            return null;
        }

        // Build the share URL using the frontend page URL passed by the JS.
        // We cannot use home_url( add_query_arg( null, null ) ) here because AJAX
        // requests are processed at wp-admin/admin-ajax.php — "the current page"
        // during an AJAX call is the AJAX endpoint, not the scanner page.
        $raw_page_url = isset( $_POST['page_url'] )
            ? esc_url_raw( wp_unslash( $_POST['page_url'] ) )
            : home_url( '/' );

        // Security: only accept URLs whose origin matches this WordPress site.
        // This prevents an attacker-supplied page_url from having share tokens
        // appended to an off-site URL and stored in the database.
        $home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
        $page_host    = wp_parse_url( $raw_page_url, PHP_URL_HOST );
        if ( empty( $page_host ) || strtolower( $page_host ) !== strtolower( $home_host ) ) {
            $raw_page_url = home_url( '/' );
        }

        // Strip query string entirely before appending the share token so we
        // never stack fi_report params or inherit stale query arguments.
        $page_path = wp_parse_url( $raw_page_url, PHP_URL_PATH ) ?? '/';
        $share_url = home_url( $page_path );
        $share_url = add_query_arg( 'fi_report', $token, $share_url );

        return array(
            'token'     => $token,
            'url'       => esc_url( $share_url ),
            'generated' => date_i18n( get_option( 'date_format' ), strtotime( $created_at ) ),
            'expires'   => date_i18n( get_option( 'date_format' ), strtotime( $expires_at ) ),
        );
    }

    /**
     * Public AJAX handler: load a shared report by token.
     * Called from the shortcode when ?fi_report=TOKEN is in the URL.
     */
    public function get_shared_report() {
        check_ajax_referer( 'fi_frontend_nonce', 'nonce' );

        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Missing token.' ) );
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_json, expires_at, created_at, business_name
               FROM {$wpdb->prefix}fi_shared_reports
              WHERE token = %s
              LIMIT 1",
            $token
        ) );

        if ( ! $row ) {
            wp_send_json_error( array( 'code' => 'not_found', 'message' => 'Report not found.' ) );
        }

        if ( strtotime( $row->expires_at ) < time() ) {
            wp_send_json_error( array(
                'code'    => 'expired',
                'message' => 'This report has expired.',
                'created' => date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ),
                'expired' => date_i18n( get_option( 'date_format' ), strtotime( $row->expires_at ) ),
            ) );
        }

        $report = json_decode( $row->report_json, true );
        if ( ! $report ) {
            wp_send_json_error( array( 'code' => 'invalid', 'message' => 'Report data is corrupted.' ) );
        }

        wp_send_json_success( array(
            'report'        => $report,
            'business_name' => $row->business_name,
            'is_shared'     => true,
        ) );
    }
    
    /**
     * Email report to user
     */
    public function email_report() {
        check_ajax_referer('fi_frontend_nonce', 'nonce');
        
        // Premium feature check - email sending is premium only
        if (!$this->is_premium()) {
            wp_send_json_error(array(
                'message' => __('Email reports are a premium feature. Please upgrade to send reports.', 'f-insights'),
                'upgrade_url' => 'https://fricking.website/pricing'
            ));
        }
        
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address', 'f-insights')));
        }

        // Fetch report from the server-side shared-reports table using the token
        // returned when the scan completed. This prevents clients from submitting
        // arbitrary or manipulated report data via the POST body.
        $token = sanitize_text_field( wp_unslash( $_POST['share_token'] ?? '' ) );
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Report token missing. Please re-run the scan and try again.', 'f-insights' ) ) );
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_json, expires_at FROM {$wpdb->prefix}fi_shared_reports WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $row ) {
            wp_send_json_error( array( 'message' => __( 'Report not found. Please re-run the scan.', 'f-insights' ) ) );
        }

        if ( strtotime( $row->expires_at ) < time() ) {
            wp_send_json_error( array( 'message' => __( 'This report has expired. Please re-run the scan.', 'f-insights' ) ) );
        }

        $report_data = json_decode( $row->report_json, true );
        if ( empty( $report_data ) ) {
            wp_send_json_error( array( 'message' => __( 'Report data is corrupted. Please re-run the scan.', 'f-insights' ) ) );
        }
        
        // Resolve white-label settings once, used for both headers and the HTML body.
        $wl = self::get_white_label_settings();

        // Generate HTML email — pass resolved settings to avoid re-reading options inside.
        $html = $this->generate_email_html( $report_data, $wl );

        $subject = sprintf(
            __( 'Your Report for %s', 'f-insights' ),
            $report_data['business_data']['name'] ?? ''
        );

        // From: display name uses the brand name; the actual sending address stays
        // as the WP admin email because most hosts authenticate on that address.
        // Reply-To directs replies to the configured reply-to address (may differ).
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $wl['sender_name'] . ' <' . get_bloginfo( 'admin_email' ) . '>',
            'Reply-To: ' . $wl['sender_name'] . ' <' . $wl['reply_to'] . '>',
        );
        
        $sent = wp_mail($email, $subject, $html, $headers);

        // Always capture the lead — email delivery problems (misconfigured mail
        // server, shared hosting restrictions, etc.) are the site owner's concern
        // and must not prevent the lead record from being stored or the visitor
        // from seeing a success message.  The scan result is already rendered on
        // the frontend; the email is a bonus, not the primary deliverable.
        if ( $this->is_premium() ) {
            $this->capture_lead( $email, $report_data );
        }

        if ($sent) {
            // NOTE: Owner notification (new lead alert with urgency + pain points + dashboard link)
            // is sent inside capture_lead() → notify_owner_of_new_lead() for new leads only.
            // send_summary_to_owner() has been removed — it duplicated that email to the same
            // address with no way to disable it, resulting in two owner emails per lead.
            wp_send_json_success(array('message' => __('Report sent successfully!', 'f-insights')));
        } else {
            // Log for the site owner to investigate; tell the visitor their
            // report was saved rather than showing a confusing failure message.
            FI_Logger::warning('wp_mail() returned false — email not delivered', array(
                'recipient' => $email,
                'business'  => $report_data['business_data']['name'] ?? 'unknown',
            ));
            wp_send_json_success(array(
                'message' => __('Your report has been saved. The email could not be delivered — please check your site\'s mail configuration or contact the site owner.', 'f-insights'),
            ));
        }
    }
    
    /**
     * Capture lead data when email report is requested (v1.6.0)
     * Enhanced v1.7.0: Store full report HTML for later viewing
     * 
     * @param string $visitor_email Email of person who requested report
     * @param array  $report_data   Full report data from scan
     */
    private function capture_lead($visitor_email, $report_data) {
        global $wpdb;
        
        $business = $report_data['business_data'] ?? array();
        $analysis = $report_data['analysis'] ?? array();
        $insights = $analysis['insights'] ?? array();
        
        // Extract business contact information
        $business_name     = $business['name'] ?? '';
        $business_category = $analysis['category'] ?? '';
        $business_website  = $business['website'] ?? '';
        $business_phone    = $business['phone'] ?? '';
        $business_address  = $business['address'] ?? '';
        $google_place_id   = $business['place_id'] ?? '';
        $overall_score     = intval($analysis['overall_score'] ?? 0);
        
        // Extract business email from website if available.
        // Cached for the same duration as other business data to avoid firing a
        // live HTTP request on every lead capture for the same business.
        $business_email = null;
        if (!empty($business_website)) {
            $email_cache_key = 'fi_biz_email_' . md5($business_website);
            $cached_email    = get_transient( $email_cache_key );
            if ( $cached_email !== false ) {
                $business_email = $cached_email === '__none__' ? null : $cached_email;
            } else {
                $scanner        = new FI_Scanner();
                $business_email = $scanner->extract_business_email($business_website);
                // Cache for 24 h. Store '__none__' sentinel so a failed lookup
                // is also cached and doesn't re-fire on the next lead for the same site.
                set_transient( $email_cache_key, $business_email ?? '__none__', DAY_IN_SECONDS );
            }
        }
        
        // Extract top pain points from insights (low-scoring categories)
        $pain_points = array();
        foreach ($insights as $category => $data) {
            $score = intval($data['score'] ?? 0);
            if ($score < 70) { // Consider anything under 70 as a pain point
                $pain_points[] = array(
                    'category' => ucwords(str_replace('_', ' ', $category)),
                    'score'    => $score,
                    'headline' => $data['headline'] ?? '',
                );
            }
        }
        
        // Always generate the report snapshot server-side from trusted data.
        // Accepting HTML from the POST body (the old "UNIFIED REPORT FIX" approach)
        // allowed any visitor to inject arbitrary content into the admin lead viewer
        // by crafting a malicious frontend_html POST param. The server-generated
        // snapshot is the authoritative, trusted version.
        $report_html = $this->generate_report_html_snapshot( $report_data );
        
        // Check if this business was already scanned (avoid duplicates)
        $existing_lead = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fi_leads WHERE google_place_id = %s AND visitor_email = %s",
            $google_place_id,
            $visitor_email
        ));
        
        if ($existing_lead) {
            // Update existing lead with new request date and report
            $wpdb->update(
                $wpdb->prefix . 'fi_leads',
                array(
                    'request_date'        => current_time('mysql'),
                    'overall_score'       => $overall_score,
                    'pain_points'         => json_encode($pain_points),
                    'report_html'         => $report_html,
                    'report_generated_at' => current_time('mysql'),
                ),
                array('id' => $existing_lead),
                array('%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );
            FI_Logger::info('Updated existing lead with new report', array('lead_id' => $existing_lead));
        } else {
            // Insert new lead
            $wpdb->insert(
                $wpdb->prefix . 'fi_leads',
                array(
                    'business_name'       => $business_name,
                    'business_category'   => $business_category,
                    'business_website'    => $business_website,
                    'business_phone'      => $business_phone,
                    'business_email'      => $business_email,
                    'business_address'    => $business_address,
                    'visitor_email'       => $visitor_email,
                    'overall_score'       => $overall_score,
                    'pain_points'         => json_encode($pain_points),
                    'request_date'        => current_time('mysql'),
                    'follow_up_status'    => 'new',
                    'google_place_id'     => $google_place_id,
                    'ip_address'          => FI_Analytics::get_client_ip(),
                    'report_html'         => $report_html,
                    'report_generated_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            $lead_id = $wpdb->insert_id;
            FI_Logger::info('Captured new lead with report', array('lead_id' => $lead_id, 'business' => $business_name));

            // Fire CRM webhook for new leads (v2.3.0).
            // Non-blocking so the visitor response is never delayed by a slow endpoint.
            $this->fire_crm_webhook( array(
                'business_name'     => $business_name,
                'business_category' => $business_category,
                'overall_score'     => $overall_score,
                'business_email'    => $business_email,
                'business_phone'    => $business_phone,
                'business_website'  => $business_website,
                'business_address'  => $business_address,
                'visitor_email'     => $visitor_email,
                'pain_points'       => $pain_points,
                'google_place_id'   => $google_place_id,
                'timestamp'         => current_time( 'c' ),
                'source'            => home_url( '/' ),
            ) );

            // Send notification to plugin owner
            $this->notify_owner_of_new_lead($business_name, $visitor_email, $overall_score, $pain_points);
        }
    }
    
    /**
     * Generate HTML snapshot of report for storage (v2.0.2)
     *
     * Produces a fully self-contained, richly formatted HTML document with every
     * style inlined or embedded in a <style> block so it renders correctly inside
     * a sandboxed iframe without any external resources.
     *
     * @param array $report_data Full report data from scan
     * @return string Standalone HTML snapshot
     */
    private function generate_report_html_snapshot( $report_data ) {
        $business         = $report_data['business_data']    ?? array();
        $analysis         = $report_data['analysis']          ?? array();
        $insights         = $analysis['insights']             ?? array();
        $strengths        = $analysis['strengths']            ?? array();
        $priority_actions = $analysis['priority_actions']     ?? array();
        $overall_score    = intval( $analysis['overall_score'] ?? 0 );

        // ── Score tier ──────────────────────────────────────────────────────────
        if ( $overall_score >= 80 ) {
            $score_color = '#059669';
            $score_bg    = '#ecfdf5';
            $score_label = 'Excellent';
            $score_icon  = '🏆';
        } elseif ( $overall_score >= 60 ) {
            $score_color = '#d97706';
            $score_bg    = '#fffbeb';
            $score_label = 'Good';
            $score_icon  = '👍';
        } else {
            $score_color = '#dc2626';
            $score_bg    = '#fef2f2';
            $score_label = 'Needs Attention';
            $score_icon  = '⚠️';
        }

        $business_name = esc_html( $business['name']    ?? 'Business' );
        $address       = esc_html( $business['address'] ?? '' );
        $rating        = isset( $business['rating'] ) ? floatval( $business['rating'] ) : null;
        $review_count  = isset( $business['user_ratings_total'] ) ? intval( $business['user_ratings_total'] ) : 0;
        $phone         = esc_html( $business['phone']   ?? '' );
        $website       = esc_url( $business['website']  ?? '' );
        $category      = esc_html( $analysis['category'] ?? ucwords( str_replace( '_', ' ', array_key_first( $insights ) ?? '' ) ) );
        $scan_date     = date_i18n( 'F j, Y \a\t g:i A' );

        // ── Star rating display ─────────────────────────────────────────────────
        $stars_html = '';
        if ( $rating !== null ) {
            $full  = floor( $rating );
            $half  = ( $rating - $full ) >= 0.5 ? 1 : 0;
            $empty = 5 - $full - $half;
            $stars_html = str_repeat( '★', $full ) . str_repeat( '½', $half ) . str_repeat( '☆', $empty );
        }

        ob_start();
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $business_name ); ?> — Business Insights Report</title>
<style>
/* ── Reset & base ─────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
    font-size: 14px;
    line-height: 1.7;
    color: #1f2937;
    background: #f3f4f6;
    padding: 24px 20px 40px;
}
/* ── Layout ───────────────────────────────────────── */
.report-wrap {
    max-width: 820px;
    margin: 0 auto;
}
/* ── Header card ──────────────────────────────────── */
.rpt-header {
    background: #111827;
    color: #fff;
    border-radius: 10px 10px 0 0;
    padding: 28px 32px 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}
.rpt-header-left h1 {
    font-size: 24px;
    font-weight: 700;
    letter-spacing: -0.3px;
    margin-bottom: 6px;
    color: #fff;
}
.rpt-header-meta {
    font-size: 12px;
    color: #9ca3af;
    line-height: 1.8;
}
.rpt-header-meta a {
    color: #60a5fa;
    text-decoration: none;
}
.rpt-stars {
    color: #fbbf24;
    font-size: 15px;
    letter-spacing: 1px;
}
.rpt-rating-count {
    color: #9ca3af;
    font-size: 12px;
    margin-left: 4px;
}
/* ── Score hero ───────────────────────────────────── */
.rpt-score-hero {
    background: <?php echo $score_bg; ?>;
    border: 2px solid <?php echo $score_color; ?>;
    border-top: none;
    padding: 28px 32px;
    display: flex;
    align-items: center;
    gap: 24px;
}
.rpt-score-circle {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: <?php echo $score_color; ?>;
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px <?php echo $score_color; ?>44;
}
.rpt-score-circle-num {
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
}
.rpt-score-circle-denom {
    font-size: 11px;
    opacity: 0.8;
    margin-top: 2px;
}
.rpt-score-label {
    font-size: 22px;
    font-weight: 700;
    color: <?php echo $score_color; ?>;
}
.rpt-score-sub {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
}
/* ── Section cards ────────────────────────────────── */
.rpt-section {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top: none;
    padding: 24px 32px;
}
.rpt-section:last-child {
    border-radius: 0 0 10px 10px;
}
.rpt-section-title {
    font-size: 15px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #374151;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f3f4f6;
    display: flex;
    align-items: center;
    gap: 8px;
}
/* ── Strengths ────────────────────────────────────── */
.rpt-strengths {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rpt-strength-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    background: #f0fdf4;
    border-left: 3px solid #10b981;
    border-radius: 0 6px 6px 0;
    font-size: 13px;
    color: #065f46;
}
.rpt-strength-item::before {
    content: '✓';
    font-weight: 700;
    color: #10b981;
    flex-shrink: 0;
    margin-top: 1px;
}
/* ── Priority actions ─────────────────────────────── */
.rpt-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.rpt-action-item {
    padding: 14px 16px;
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
    border-radius: 0 6px 6px 0;
}
.rpt-action-num {
    display: inline-block;
    width: 20px;
    height: 20px;
    background: #3b82f6;
    color: #fff;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 700;
    text-align: center;
    line-height: 20px;
    margin-right: 8px;
    flex-shrink: 0;
}
.rpt-action-title {
    font-weight: 700;
    font-size: 13px;
    color: #1e40af;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
}
.rpt-action-desc {
    font-size: 13px;
    color: #374151;
    padding-left: 28px;
}
/* ── Detailed analysis ────────────────────────────── */
.rpt-insights {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.rpt-insight-item {
    padding: 16px 0;
    border-bottom: 1px solid #f3f4f6;
}
.rpt-insight-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.rpt-insight-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    gap: 12px;
}
.rpt-insight-name {
    font-weight: 700;
    font-size: 14px;
    color: #111827;
}
.rpt-insight-bar-wrap {
    flex: 1;
    height: 6px;
    background: #f3f4f6;
    border-radius: 3px;
    overflow: hidden;
}
.rpt-insight-bar {
    height: 100%;
    border-radius: 3px;
}
.rpt-insight-score {
    font-weight: 700;
    font-size: 13px;
    white-space: nowrap;
    min-width: 52px;
    text-align: right;
}
.rpt-insight-summary {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 8px;
}
.rpt-insight-recs {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.rpt-insight-recs li {
    font-size: 12px;
    color: #6b7280;
    padding-left: 14px;
    position: relative;
}
.rpt-insight-recs li::before {
    content: '→';
    position: absolute;
    left: 0;
    color: #9ca3af;
}
/* ── Score badge colours ──────────────────────────── */
.sc-good  { color: #059669; }
.sc-warn  { color: #d97706; }
.sc-alert { color: #dc2626; }
.bar-good  { background: #10b981; }
.bar-warn  { background: #f59e0b; }
.bar-alert { background: #ef4444; }
/* ── Footer ───────────────────────────────────────── */
.rpt-footer {
    text-align: center;
    margin-top: 20px;
    font-size: 11px;
    color: #9ca3af;
}
</style>
</head>
<body>
<div class="report-wrap">

    <!-- Header -->
    <div class="rpt-header">
        <div class="rpt-header-left">
            <h1><?php echo esc_html( $business_name ); ?></h1>
            <div class="rpt-header-meta">
                <?php if ( $address ): ?><?php echo $address; ?><br><?php endif; ?>
                <?php if ( $category ): ?><?php echo $category; ?><br><?php endif; ?>
                <?php if ( $phone ): ?>📞 <?php echo $phone; ?><br><?php endif; ?>
                <?php if ( $website ): ?>🌐 <a href="<?php echo $website; ?>"><?php echo $website; ?></a><br><?php endif; ?>
            </div>
            <?php if ( $rating !== null ): ?>
            <div style="margin-top:10px;">
                <span class="rpt-stars"><?php echo $stars_html; ?></span>
                <span style="color:#f9fafb;font-size:13px;font-weight:600;margin-left:4px;"><?php echo number_format( $rating, 1 ); ?></span>
                <span class="rpt-rating-count">(<?php echo number_format( $review_count ); ?> reviews)</span>
            </div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Report Generated</div>
            <div style="font-size:13px;color:#d1d5db;font-weight:600;"><?php echo $scan_date; ?></div>
        </div>
    </div>

    <!-- Score hero -->
    <div class="rpt-score-hero">
        <div class="rpt-score-circle">
            <div class="rpt-score-circle-num"><?php echo $overall_score; ?></div>
            <div class="rpt-score-circle-denom">/ 100</div>
        </div>
        <div>
            <div class="rpt-score-label"><?php echo $score_icon . ' ' . $score_label; ?></div>
            <div class="rpt-score-sub">Overall business presence score</div>
            <?php if ( ! empty( $analysis['executive_summary'] ) ): ?>
            <div style="margin-top:10px;font-size:13px;color:#374151;max-width:560px;"><?php echo esc_html( $analysis['executive_summary'] ); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! empty( $strengths ) ): ?>
    <!-- Key Strengths -->
    <div class="rpt-section">
        <div class="rpt-section-title">✨ Key Strengths</div>
        <div class="rpt-strengths">
            <?php foreach ( $strengths as $strength ): ?>
            <div class="rpt-strength-item"><?php echo esc_html( $strength ); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $priority_actions ) ): ?>
    <!-- Priority Actions -->
    <div class="rpt-section">
        <div class="rpt-section-title">🎯 Priority Actions</div>
        <div class="rpt-actions">
            <?php foreach ( $priority_actions as $i => $action ): ?>
            <div class="rpt-action-item">
                <div class="rpt-action-title">
                    <span class="rpt-action-num"><?php echo $i + 1; ?></span>
                    <?php echo esc_html( $action['title'] ?? '' ); ?>
                </div>
                <?php if ( ! empty( $action['description'] ) ): ?>
                <div class="rpt-action-desc"><?php echo esc_html( $action['description'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $insights ) ): ?>
    <!-- Detailed Analysis -->
    <div class="rpt-section">
        <div class="rpt-section-title">📊 Detailed Analysis</div>
        <div class="rpt-insights">
            <?php foreach ( $insights as $cat_key => $data ):
                $score      = intval( $data['score'] ?? 0 );
                $cat_name   = ucwords( str_replace( '_', ' ', $cat_key ) );
                if ( $score >= 80 ) {
                    $sc_class  = 'sc-good';
                    $bar_class = 'bar-good';
                } elseif ( $score >= 60 ) {
                    $sc_class  = 'sc-warn';
                    $bar_class = 'bar-warn';
                } else {
                    $sc_class  = 'sc-alert';
                    $bar_class = 'bar-alert';
                }
            ?>
            <div class="rpt-insight-item">
                <div class="rpt-insight-header">
                    <span class="rpt-insight-name"><?php echo esc_html( $cat_name ); ?></span>
                    <div class="rpt-insight-bar-wrap">
                        <div class="rpt-insight-bar <?php echo $bar_class; ?>" style="width:<?php echo $score; ?>%"></div>
                    </div>
                    <span class="rpt-insight-score <?php echo $sc_class; ?>"><?php echo $score; ?>/100</span>
                </div>
                <?php if ( ! empty( $data['summary'] ) ): ?>
                <div class="rpt-insight-summary"><?php echo esc_html( $data['summary'] ); ?></div>
                <?php endif; ?>
                <?php if ( ! empty( $data['recommendations'] ) ): ?>
                <ul class="rpt-insight-recs">
                    <?php foreach ( $data['recommendations'] as $rec ): ?>
                    <li><?php echo esc_html( $rec ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- .report-wrap -->
<div class="rpt-footer">Report generated by F! Insights · <?php echo $scan_date; ?></div>
</body>
</html>
<?php
        // All dynamic values in this template are escaped at the point of output
        // using esc_html() or esc_url() — no further stripping is needed.
        // wp_kses_post() must NOT be used here: it is designed for post body
        // fragments and strips <!DOCTYPE>, <html>, <head>, <style>, <body>, and
        // <meta> tags, which would produce a completely broken, unstyled document.
        return ob_get_clean();
    }
    
    /**
     * Send email notification to plugin owner about new lead (v1.6.0)
     * 
     * @param string $business_name   Name of business that was scanned
     * @param string $visitor_email   Email of person who requested report
     * @param int    $overall_score   Business score (0-100)
     * @param array  $pain_points     Array of low-scoring categories
     */
    private function notify_owner_of_new_lead($business_name, $visitor_email, $overall_score, $pain_points) {
        // Check if notifications are enabled
        if (get_option('fi_lead_notifications_enabled', '1') !== '1') {
            return;
        }

        // Score threshold — only notify when score is AT OR BELOW the configured value.
        // Default 100 means "always notify". Set to e.g. 70 to suppress high-scoring leads.
        $threshold = intval( get_option( 'fi_lead_notification_threshold', 100 ) );
        if ( $overall_score > $threshold ) {
            FI_Logger::info( 'Lead notification suppressed by threshold', array(
                'score'     => $overall_score,
                'threshold' => $threshold,
            ) );
            return;
        }

        // Multi-recipient support: comma-separated list in fi_lead_notification_email.
        $raw_emails = get_option( 'fi_lead_notification_email', get_option( 'admin_email' ) );
        $recipients = array_filter( array_map(
            'sanitize_email',
            array_map( 'trim', explode( ',', $raw_emails ) )
        ) );

        if ( empty( $recipients ) ) {
            FI_Logger::warning('Lead notification email not configured properly');
            return;
        }
        
        // Determine urgency based on score
        if ($overall_score < 50) {
            $urgency_emoji = '🔥🔥🔥';
            $urgency_text = 'HIGH PRIORITY - Low score means high need!';
        } elseif ($overall_score < 70) {
            $urgency_emoji = '🔥';
            $urgency_text = 'Good opportunity - Clear room for improvement';
        } else {
            $urgency_emoji = '💼';
            $urgency_text = 'Standard lead';
        }
        
        $subject = $urgency_emoji . ' New Lead: ' . $business_name . ' (' . $overall_score . '/100)';
        
        // Build pain points list
        $pain_points_text = '';
        if (!empty($pain_points)) {
            $pain_points_text = "\n\nTOP ISSUES:\n";
            foreach (array_slice($pain_points, 0, 3) as $pain) {
                $pain_points_text .= "• {$pain['category']} ({$pain['score']}/100): {$pain['headline']}\n";
            }
        }
        
        $message = "
{$urgency_text}

A new lead just requested an email report from your site!

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

BUSINESS: {$business_name}
SCORE: {$overall_score}/100
REQUESTED BY: {$visitor_email}
TIME: " . current_time('F j, Y g:i A') . "
{$pain_points_text}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

👉 NEXT STEPS:
1. View full lead details in your dashboard
2. Follow up within 24 hours for best results
3. Reference their specific pain points in your outreach

View Lead Dashboard:
" . admin_url('admin.php?page=f-insights-analytics') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Tip: Leads with scores 40-60 have the highest conversion rates!

---
F! Insights Lead Notifications
Manage settings: " . admin_url('admin.php?page=f-insights') . "
";

        foreach ( $recipients as $recipient ) {
            wp_mail( $recipient, $subject, $message );
        }
        
        FI_Logger::info('Lead notification sent', array(
            'to'       => implode( ', ', $recipients ),
            'business' => $business_name,
        ));
    }

    /**
     * POST lead data as JSON to the configured CRM webhook URL (v2.3.0).
     *
     * Non-blocking (`blocking => false`) so a slow or unavailable endpoint
     * never delays the visitor's response.  Delivery failures are logged but
     * not retried — the site owner can configure retry logic in their CRM
     * automation tool (Zapier, Make, etc.).
     *
     * @param array $payload Associative array of lead fields to send.
     */
    private function fire_crm_webhook( array $payload ): void {
        $webhook_url = get_option( 'fi_crm_webhook_url', '' );
        if ( empty( $webhook_url ) ) {
            return; // Webhook not configured — nothing to do.
        }

        $response = wp_remote_post( $webhook_url, array(
            'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'     => wp_json_encode( $payload ),
            'timeout'  => 10,
            'blocking' => false, // Fire-and-forget: don't wait for a response.
        ) );

        // wp_remote_post() with blocking=false always returns true on success and
        // a WP_Error only if the request could not be dispatched at all (e.g. cURL
        // is completely unavailable). Log that edge-case so the site owner can act.
        if ( is_wp_error( $response ) ) {
            FI_Logger::warning( 'CRM webhook dispatch failed', array(
                'url'   => $webhook_url,
                'error' => $response->get_error_message(),
            ) );
        } else {
            FI_Logger::info( 'CRM webhook fired', array( 'url' => $webhook_url ) );
        }
    }

    /**
     * Check if premium features are available.
     *
     * @return bool
     */
    private function is_premium(): bool {
        return FI_License::is_active();
    }
    
    /**
     * Public proxy so FI_Admin::render_wl_preview_page() can call the
     * email generator without duplicating the template.
     *
     * @param array $report_data  Dummy or real report array.
     * @param array $wl           Resolved white-label settings.
     * @return string             Full HTML email string.
     */
    public function generate_email_html_public( $report_data, $wl ) {
        return $this->generate_email_html( $report_data, $wl );
    }

    /**
     * Public proxy so FI_Admin can resolve white-label settings using the
     * same logic without duplicating the fallback rules.
     *
     * @return array  Resolved white-label settings array.
     */
    public static function get_white_label_settings_public() {
        return self::get_white_label_settings();
    }

    /**
     * Resolve all white-label settings, applying fallbacks.
     * Called once per email send so we never scatter get_option() calls
     * across the template. Returns a flat associative array.
     */
    private static function get_white_label_settings() {
        $sender_name  = get_option( 'fi_wl_sender_name', '' );
        $reply_to     = get_option( 'fi_wl_reply_to', '' );
        $logo_url     = get_option( 'fi_wl_logo_url', '' );
        $report_title = get_option( 'fi_wl_report_title', '' );
        $footer_cta   = get_option( 'fi_wl_footer_cta', '' );

        // Pull the saved brand button color for the email header gradient.
        // Falls back to the default indigo-purple pair if no brand color is saved.
        $brand_btn_bg   = get_option( 'fi_brand_button_bg', '' );
        $header_color_1 = ( $brand_btn_bg && $brand_btn_bg !== '#2271B1' )
            ? sanitize_hex_color( $brand_btn_bg )
            : '#667eea';
        // Derive a slightly darker second stop by reusing the same color — keeps the
        // gradient subtle and on-brand without requiring a separate "gradient end" setting.
        $header_color_2 = ( $brand_btn_bg && $brand_btn_bg !== '#2271B1' )
            ? sanitize_hex_color( $brand_btn_bg )
            : '#764ba2';

        return array(
            'sender_name'    => $sender_name  ?: get_bloginfo( 'name' ),
            'reply_to'       => sanitize_email( $reply_to ?: get_bloginfo( 'admin_email' ) ),
            'logo_url'       => esc_url( $logo_url ),
            'report_title'   => $report_title ?: __( 'Your Business Insights Report', 'f-insights' ),
            'footer_cta'     => $footer_cta,
            'header_color_1' => $header_color_1,
            'header_color_2' => $header_color_2,
        );
    }

    /**
     * Generate HTML email content.
     *
     * Fully table-based layout with 100 % inline styles so the email renders
     * correctly in Gmail, Outlook (Win/Mac), Apple Mail, and mobile clients.
     * No flexbox, no CSS grid, no external stylesheets, no class-based rules
     * that email clients strip.
     *
     * @param array $report_data  The full report array from the scan.
     * @param array $wl           Resolved white-label settings.
     * @return string             Full <!DOCTYPE html> email string.
     */
    private function generate_email_html( $report_data, $wl ) {
        $business           = $report_data['business_data']    ?? array();
        $analysis           = $report_data['analysis']         ?? array();
        $insights           = $analysis['insights']            ?? array();
        $priority_actions   = $analysis['priority_actions']    ?? array();
        $strengths          = $analysis['strengths']           ?? array();
        $sentiment_analysis = $analysis['sentiment_analysis']  ?? array();
        $overall_score      = intval( $analysis['overall_score'] ?? 0 );

        // ── Score colour & phrase ────────────────────────────────────────────
        if ( $overall_score >= 80 ) {
            $score_color  = '#10b981';
            $score_phrase = '🎉 Excellent! You\'re doing great!';
        } elseif ( $overall_score >= 60 ) {
            $score_color  = '#f59e0b';
            $score_phrase = '👍 Good foundation, room to grow!';
        } elseif ( $overall_score >= 40 ) {
            $score_color  = '#f97316';
            $score_phrase = '📈 Solid opportunity for improvement!';
        } else {
            $score_color  = '#ef4444';
            $score_phrase = '🚀 Great potential to boost your presence!';
        }

        // ── Insight helpers ──────────────────────────────────────────────────
        $get_urgency = function ( $score ) {
            if ( $score >= 80 ) return 'good';
            if ( $score >= 50 ) return 'needs-attention';
            return 'urgent';
        };

        $get_status_meta = function ( $status ) {
            $map = array(
                'good'             => array( '#f0fdf4', '#10b981', '#065f46', '✅' ),
                'needs-attention'  => array( '#fffbeb', '#f59e0b', '#92400e', '⚠️' ),
                'urgent'           => array( '#fef2f2', '#ef4444', '#991b1b', '🚨' ),
            );
            return $map[ $status ] ?? array( '#f9fafb', '#6b7280', '#374151', '📊' );
        };

        // ── Shared inline style constants ────────────────────────────────────
        $td_reset  = 'padding:0;margin:0;';
        $font_base = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo esc_html( $wl['report_title'] ); ?></title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
    <style type="text/css">
        /* Client resets */
        body,table,td,p,a,li{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
        table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}
        img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none;}
        body{margin:0!important;padding:0!important;width:100%!important;}
        /* Outlook min-width fix */
        .ReadMsgBody{width:100%;}.ExternalClass{width:100%;}
        .ExternalClass,.ExternalClass p,.ExternalClass span,.ExternalClass font,
        .ExternalClass td,.ExternalClass div{line-height:100%;}
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;<?php echo $font_base; ?>">

<!-- Outer wrapper -->
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;background-color:#f3f4f6;">
    <tr>
        <td align="center" style="<?php echo $td_reset; ?>padding:24px 16px;">

            <!-- Email container -->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                   style="border-collapse:collapse;max-width:620px;background-color:#ffffff;border-radius:8px;overflow:hidden;">

                <!-- ══ HEADER ══════════════════════════════════════════════ -->
                <tr>
                    <td align="center"
                        style="<?php echo $td_reset; ?>background:linear-gradient(135deg,<?php echo esc_attr( $wl['header_color_1'] ); ?> 0%,<?php echo esc_attr( $wl['header_color_2'] ); ?> 100%);padding:32px 24px;border-radius:8px 8px 0 0;">

                        <?php if ( ! empty( $wl['logo_url'] ) ) : ?>
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;">
                            <tr>
                                <td align="center" style="<?php echo $td_reset; ?>padding-bottom:16px;">
                                    <img src="<?php echo esc_url( $wl['logo_url'] ); ?>"
                                         alt="<?php echo esc_attr( $wl['sender_name'] ); ?>"
                                         style="max-width:240px;max-height:80px;width:auto;height:auto;display:block;margin:0 auto;">
                                </td>
                            </tr>
                        </table>
                        <?php endif; ?>

                        <h1 style="margin:0 0 8px 0;font-size:24px;font-weight:700;color:#ffffff;<?php echo $font_base; ?>line-height:1.3;">
                            🎯 <?php echo esc_html( $wl['report_title'] ); ?>
                        </h1>
                        <?php if ( ! empty( $business['name'] ) ) : ?>
                        <p style="margin:0;font-size:17px;color:#e0e7ff;<?php echo $font_base; ?>">
                            for <?php echo esc_html( $business['name'] ); ?>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- ══ SCORE CIRCLE ════════════════════════════════════════ -->
                <tr>
                    <td align="center"
                        style="<?php echo $td_reset; ?>padding:32px 24px 8px 24px;background-color:#ffffff;">
                        <p style="margin:0 0 16px 0;font-size:17px;font-weight:600;color:#4b5563;<?php echo $font_base; ?>">
                            Overall Digital Presence Score
                        </p>
                        <!-- Score circle: table cell approach works in all clients -->
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0"
                               style="border-collapse:collapse;margin:0 auto;">
                            <tr>
                                <td align="center" valign="middle"
                                    width="150" height="150"
                                    style="width:150px;height:150px;border-radius:50%;background-color:#111827;text-align:center;vertical-align:middle;<?php echo $font_base; ?>">
                                    <span style="display:block;font-size:52px;font-weight:700;color:#ffffff;line-height:1;<?php echo $font_base; ?>">
                                        <?php echo esc_html( $overall_score ); ?>
                                    </span>
                                    <span style="display:block;font-size:13px;color:#d1d5db;margin-top:6px;<?php echo $font_base; ?>">
                                        Overall Score
                                    </span>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:16px 0 0 0;font-size:14px;color:#6b7280;<?php echo $font_base; ?>">
                            <?php echo esc_html( $score_phrase ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Divider -->
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:24px 24px 0 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;">
                            <tr><td height="1" style="background-color:#e5e7eb;font-size:1px;line-height:1px;">&nbsp;</td></tr>
                        </table>
                    </td>
                </tr>

                <!-- ══ BUSINESS INFO ════════════════════════════════════════ -->
                <?php if ( ! empty( $business['name'] ) ) : ?>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                            <tr>
                                <td style="<?php echo $td_reset; ?>padding:20px;">
                                    <p style="margin:0 0 16px 0;font-size:17px;font-weight:700;color:#1f2937;<?php echo $font_base; ?>">
                                        📍 Business Details
                                    </p>
                                    <?php if ( ! empty( $business['address'] ) ) : ?>
                                    <p style="margin:0 0 10px 0;<?php echo $font_base; ?>">
                                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;letter-spacing:0.5px;">Address</span><br>
                                        <span style="font-size:14px;color:#1f2937;"><?php echo esc_html( $business['address'] ); ?></span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $business['phone'] ) ) : ?>
                                    <p style="margin:0 0 10px 0;<?php echo $font_base; ?>">
                                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;letter-spacing:0.5px;">Phone</span><br>
                                        <span style="font-size:14px;color:#1f2937;"><?php echo esc_html( $business['phone'] ); ?></span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $business['rating'] ) ) : ?>
                                    <p style="margin:0 0 10px 0;<?php echo $font_base; ?>">
                                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;letter-spacing:0.5px;">Rating</span><br>
                                        <span style="font-size:14px;color:#1f2937;">⭐ <?php echo esc_html( $business['rating'] ); ?> (<?php echo esc_html( $business['user_ratings_total'] ?? 0 ); ?> reviews)</span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $business['website'] ) ) : ?>
                                    <p style="margin:0;<?php echo $font_base; ?>">
                                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;letter-spacing:0.5px;">Website</span><br>
                                        <a href="<?php echo esc_url( $business['website'] ); ?>"
                                           style="font-size:14px;color:<?php echo esc_attr( $wl['header_color_1'] ); ?>;text-decoration:none;">
                                            <?php echo esc_html( parse_url( $business['website'], PHP_URL_HOST ) ); ?>
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- ══ STRENGTHS ═══════════════════════════════════════════ -->
                <?php if ( ! empty( $strengths ) ) : ?>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:0 24px 16px 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;background-color:#ecfdf5;border-left:4px solid #10b981;border-radius:0 6px 6px 0;">
                            <tr>
                                <td style="<?php echo $td_reset; ?>padding:20px;">
                                    <p style="margin:0 0 6px 0;font-size:18px;font-weight:700;color:#065f46;<?php echo $font_base; ?>">
                                        🌟 What You're Doing Great
                                    </p>
                                    <p style="margin:0 0 14px 0;font-size:13px;color:#047857;<?php echo $font_base; ?>">
                                        These are your competitive advantages. Keep it up!
                                    </p>
                                    <?php foreach ( array_slice( $strengths, 0, 5 ) as $strength ) : ?>
                                    <p style="margin:0 0 8px 0;font-size:14px;color:#065f46;<?php echo $font_base; ?>">
                                        ✓ <?php echo esc_html( $strength ); ?>
                                    </p>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- ══ PRIORITY ACTIONS ════════════════════════════════════ -->
                <?php if ( ! empty( $priority_actions ) ) : ?>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:0 24px 16px 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;background-color:#fef3c7;border-left:4px solid #f59e0b;border-radius:0 6px 6px 0;">
                            <tr>
                                <td style="<?php echo $td_reset; ?>padding:20px;">
                                    <p style="margin:0 0 6px 0;font-size:18px;font-weight:700;color:#92400e;<?php echo $font_base; ?>">
                                        🎯 Top Priority Actions
                                    </p>
                                    <p style="margin:0 0 16px 0;font-size:13px;color:#78350f;<?php echo $font_base; ?>">
                                        Start here for the biggest impact.
                                    </p>
                                    <?php foreach ( array_slice( $priority_actions, 0, 5 ) as $action ) :
                                        $impact  = strtolower( $action['impact']  ?? 'medium' );
                                        $effort  = strtolower( $action['effort']  ?? 'medium' );
                                        $badge_impact = $impact  === 'high'   ? 'background-color:#dcfce7;color:#166534;' : ( $impact  === 'low'  ? 'background-color:#fee2e2;color:#991b1b;' : 'background-color:#fef9c3;color:#854d0e;' );
                                        $badge_effort = $effort  === 'low'    ? 'background-color:#dcfce7;color:#166534;' : ( $effort  === 'high' ? 'background-color:#fee2e2;color:#991b1b;' : 'background-color:#fef9c3;color:#854d0e;' );
                                    ?>
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                                           style="border-collapse:collapse;background-color:#ffffff;border:1px solid #fcd34d;border-radius:6px;margin-bottom:10px;">
                                        <tr>
                                            <td style="<?php echo $td_reset; ?>padding:14px;">
                                                <p style="margin:0 0 6px 0;font-size:14px;font-weight:700;color:#78350f;<?php echo $font_base; ?>">
                                                    <?php echo esc_html( $action['title'] ?? '' ); ?>
                                                </p>
                                                <p style="margin:0 0 10px 0;font-size:13px;color:#92400e;line-height:1.5;<?php echo $font_base; ?>">
                                                    <?php echo esc_html( $action['description'] ?? '' ); ?>
                                                </p>
                                                <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;margin-right:6px;<?php echo $badge_impact; ?><?php echo $font_base; ?>">
                                                    Impact: <?php echo esc_html( ucfirst( $impact ) ); ?>
                                                </span>
                                                <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;<?php echo $badge_effort; ?><?php echo $font_base; ?>">
                                                    Effort: <?php echo esc_html( ucfirst( $effort ) ); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- Divider -->
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:0 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;">
                            <tr><td height="1" style="background-color:#e5e7eb;font-size:1px;line-height:1px;">&nbsp;</td></tr>
                        </table>
                    </td>
                </tr>

                <!-- ══ DETAILED ANALYSIS ═══════════════════════════════════ -->
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:24px 24px 8px 24px;background-color:#ffffff;">
                        <p style="margin:0;font-size:20px;font-weight:700;color:#1f2937;<?php echo $font_base; ?>border-bottom:2px solid #e5e7eb;padding-bottom:10px;">
                            📊 Detailed Analysis
                        </p>
                    </td>
                </tr>

                <?php foreach ( $insights as $key => $insight ) :
                    $score  = intval( $insight['score'] ?? 0 );
                    $status = $insight['status'] ?? $get_urgency( $score );
                    list( $card_bg, $border_color, $text_color, $icon ) = $get_status_meta( $status );
                ?>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:8px 24px 0 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;background-color:<?php echo $card_bg; ?>;border:1px solid #e5e7eb;border-left:4px solid <?php echo $border_color; ?>;border-radius:0 8px 8px 0;">
                            <tr>
                                <td style="<?php echo $td_reset; ?>padding:16px;">
                                    <!-- Header row: title + score -->
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                                           style="border-collapse:collapse;margin-bottom:10px;">
                                        <tr>
                                            <td style="<?php echo $td_reset; ?>">
                                                <span style="font-size:15px;font-weight:700;color:#1f2937;<?php echo $font_base; ?>">
                                                    <?php echo $icon; ?> <?php echo esc_html( $insight['headline'] ?? ucwords( str_replace( '_', ' ', $key ) ) ); ?>
                                                </span>
                                            </td>
                                            <td align="right" style="<?php echo $td_reset; ?>white-space:nowrap;">
                                                <span style="font-size:17px;font-weight:700;color:<?php echo $border_color; ?>;<?php echo $font_base; ?>">
                                                    <?php echo esc_html( $score ); ?>/100
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0 0 12px 0;font-size:13px;color:#4b5563;line-height:1.6;<?php echo $font_base; ?>">
                                        <?php echo esc_html( $insight['summary'] ?? '' ); ?>
                                    </p>
                                    <?php if ( ! empty( $insight['recommendations'] ) ) : ?>
                                    <p style="margin:0 0 8px 0;font-size:13px;font-weight:600;color:#374151;<?php echo $font_base; ?>">
                                        💡 What You Can Do:
                                    </p>
                                    <?php foreach ( $insight['recommendations'] as $rec ) : ?>
                                    <p style="margin:0 0 6px 0;padding-left:16px;font-size:13px;color:#4b5563;<?php echo $font_base; ?>">
                                        → <?php echo esc_html( $rec ); ?>
                                    </p>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- ══ SENTIMENT ═══════════════════════════════════════════ -->
                <?php if ( ! empty( $sentiment_analysis ) ) : ?>
                <!-- Divider -->
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:24px 24px 0 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;">
                            <tr><td height="1" style="background-color:#e5e7eb;font-size:1px;line-height:1px;">&nbsp;</td></tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:24px 24px 8px 24px;background-color:#ffffff;">
                        <p style="margin:0 0 12px 0;font-size:20px;font-weight:700;color:#1f2937;<?php echo $font_base; ?>border-bottom:2px solid #e5e7eb;padding-bottom:10px;">
                            💬 Customer Sentiment
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="<?php echo $td_reset; ?>padding:0 24px 16px 24px;background-color:#ffffff;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                               style="border-collapse:collapse;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                            <tr>
                                <td style="<?php echo $td_reset; ?>padding:16px;">
                                    <?php
                                    $sentiment = $sentiment_analysis['overall_sentiment'] ?? 'neutral';
                                    $s_icon    = $sentiment === 'positive' ? '😊' : ( $sentiment === 'negative' ? '😟' : '😐' );
                                    ?>
                                    <p style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:#1f2937;<?php echo $font_base; ?>">
                                        <?php echo $s_icon; ?> Overall Sentiment: <?php echo esc_html( ucfirst( $sentiment ) ); ?>
                                    </p>
                                    <?php if ( ! empty( $sentiment_analysis['common_themes'] ) ) : ?>
                                    <p style="margin:0 0 6px 0;font-size:13px;font-weight:600;color:#374151;<?php echo $font_base; ?>">Common Themes:</p>
                                    <?php foreach ( $sentiment_analysis['common_themes'] as $theme ) : ?>
                                    <p style="margin:0 0 4px 0;padding-left:12px;font-size:13px;color:#4b5563;<?php echo $font_base; ?>">
                                        • <?php echo esc_html( $theme ); ?>
                                    </p>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $sentiment_analysis['customer_pain_points'] ) ) : ?>
                                    <p style="margin:12px 0 6px 0;font-size:13px;font-weight:600;color:#374151;<?php echo $font_base; ?>">Customer Pain Points:</p>
                                    <?php foreach ( $sentiment_analysis['customer_pain_points'] as $pain ) : ?>
                                    <p style="margin:0 0 4px 0;padding-left:12px;font-size:13px;color:#ef4444;<?php echo $font_base; ?>">
                                        • <?php echo esc_html( $pain ); ?>
                                    </p>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- ══ CALL TO ACTION ══════════════════════════════════════ -->
                <tr>
                    <td align="center" style="<?php echo $td_reset; ?>padding:24px;background-color:#ffffff;">
                        <p style="margin:0;font-size:15px;color:#4b5563;line-height:1.7;<?php echo $font_base; ?>">
                            <strong>So, yeah. Do that 👆🏾.</strong><br><br>
                            <?php if ( ! empty( $wl['footer_cta'] ) ) : ?>
                                <?php echo nl2br( esc_html( $wl['footer_cta'] ) ); ?>
                            <?php else : ?>
                                Start with the priority actions above and watch your online presence build momentum.
                            <?php endif; ?>
                            <br><br>
                            <strong><?php echo esc_html( $wl['sender_name'] ); ?></strong>
                        </p>
                    </td>
                </tr>

                <!-- ══ FOOTER ══════════════════════════════════════════════ -->
                <tr>
                    <td align="center"
                        style="<?php echo $td_reset; ?>background-color:#f9fafb;padding:20px 24px;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;">
                        <p style="margin:0 0 6px 0;font-size:14px;color:#6b7280;<?php echo $font_base; ?>">
                            <strong><?php echo esc_html( sprintf( __( 'Report generated by %s', 'f-insights' ), $wl['sender_name'] ) ); ?></strong>
                        </p>
                        <p style="margin:0 0 6px 0;font-size:12px;color:#9ca3af;<?php echo $font_base; ?>">
                            <?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ); ?>
                        </p>
                        <p style="margin:0;font-size:12px;color:#9ca3af;<?php echo $font_base; ?>">
                            <?php esc_html_e( 'This report was created using AI-powered analysis of public Google data.', 'f-insights' ); ?>
                        </p>
                    </td>
                </tr>

            </table><!-- /email container -->

        </td>
    </tr>
</table><!-- /outer wrapper -->

</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Test Google API key by making a live Places API request.
     */
    public function test_google_key() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => 'No key provided.' ) );
        }

        // Fetch a known valid Place ID — Google's own NYC office.
        $url      = 'https://places.googleapis.com/v1/places/ChIJOwg_06VPwokRYv534QaPC8g';
        $response = wp_remote_get( $url, array(
            'headers' => array(
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => 'id,displayName',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Request failed: ' . $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            wp_send_json_success( array( 'message' => 'Connected successfully.' ) );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? 'HTTP ' . $code;
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }

    public function test_claude_key() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        $model = sanitize_text_field( wp_unslash( $_POST['model'] ?? 'claude-haiku-4-5-20251001' ) );
        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => 'No key provided.' ) );
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => json_encode( array(
                'model'      => $model,
                'max_tokens' => 1,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => 'hi' ),
                ),
            ) ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Request failed: ' . $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            wp_send_json_success( array( 'message' => 'Connected successfully.' ) );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? 'HTTP ' . $code;
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }

    /**
     * Send a test email to the admin using the current white-label settings
     * and the same realistic dummy data shown on the Email Preview page.
     *
     * Called via: wp_ajax_fi_send_test_email
     * Requires:   admin nonce + manage_options capability
     */
    public function send_test_email() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        // Accept an optional override recipient; fall back to admin email.
        $to = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        // Use the same dummy data as the Email Preview page — single source of truth.
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
                'overall_score'         => 64,
                'competitive_narrative' => 'You\'re up against The Oak Table (4.7★, 812 reviews) and Riviera Grille (4.5★, 543 reviews). Your 4.2★ is solid, but your review volume lags behind both.',
                'strengths'             => array(
                    'Consistent 4★+ rating maintained over 18 months',
                    'Strong weekend foot traffic visible in peak-hour data',
                    'Active photo presence with 40+ recent customer uploads',
                ),
                'priority_actions' => array(
                    array(
                        'title'       => 'Set up a Google review request system',
                        'description' => 'Ask every satisfied customer for a review via a QR code at the table or a post-visit text.',
                        'impact'      => 'high',
                        'effort'      => 'low',
                    ),
                    array(
                        'title'       => 'Add your menu to your Google Business Profile',
                        'description' => 'Profiles with menus get 35% more clicks.',
                        'impact'      => 'high',
                        'effort'      => 'low',
                    ),
                ),
                'insights' => array(
                    'online_presence' => array(
                        'score'           => 72,
                        'status'          => 'needs-attention',
                        'headline'        => 'Good foundation, a few gaps to close',
                        'summary'         => 'Your Google Business Profile is claimed and reasonably complete.',
                        'recommendations' => array( 'Add a direct link to your menu under Products/Services' ),
                    ),
                    'customer_reviews' => array(
                        'score'           => 58,
                        'status'          => 'urgent',
                        'headline'        => 'Review velocity is below your competitors',
                        'summary'         => 'At 387 reviews you\'re behind The Oak Table (812) and Riviera Grille (543).',
                        'recommendations' => array( 'Start a post-visit review ask via SMS or table QR code' ),
                    ),
                ),
                'sentiment_analysis' => array(
                    'overall_sentiment'    => 'positive',
                    'common_themes'        => array( 'Friendly staff', 'Great brunch menu', 'Good portion sizes' ),
                    'customer_pain_points' => array( 'Long weekend wait times', 'Limited parking' ),
                ),
            ),
        );

        $wl      = self::get_white_label_settings();
        $html    = $this->generate_email_html( $dummy_report, $wl );
        $subject = sprintf(
            /* translators: %s: brand/sender name */
            __( '[Test] %s — Sample Business Insights Report', 'f-insights' ),
            $wl['sender_name']
        );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $wl['sender_name'] . ' <' . get_bloginfo( 'admin_email' ) . '>',
        );

        $sent = wp_mail( $to, $subject, $html, $headers );

        if ( $sent ) {
            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %s: recipient email */
                    __( 'Test email sent to %s. Check your inbox (and spam folder).', 'f-insights' ),
                    $to
                ),
            ) );
        } else {
            FI_Logger::warning( 'send_test_email: wp_mail() returned false', array( 'recipient' => $to ) );
            wp_send_json_error( array(
                'message' => __( 'wp_mail() returned false — check your site\'s mail configuration. See Debug Logs for details.', 'f-insights' ),
            ) );
        }
    }

    /**
     * Reset all options for a specific settings tab back to their defaults.
     *
     * Called via: wp_ajax_fi_reset_tab_defaults
     * POST params: nonce, tab (api|cache|rate-limiting|cta|white-label)
     *
     * Only resets options that belong to the requested tab — never touches
     * options from other tabs. API key fields are intentionally excluded from
     * the API tab reset (clearing them would lock the user out of the plugin).
     */
    public function reset_tab_defaults() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $tab = sanitize_key( wp_unslash( $_POST['tab'] ?? '' ) );

        // Per-tab default maps. Keys must match what save_settings() writes.
        // API keys are deliberately excluded — a reset should not clear credentials.
        $tab_defaults = array(

            'api' => array(
                'fi_claude_model' => 'claude-sonnet-4-20250514',
            ),

            'cache' => array(
                'fi_cache_duration'            => 86400,
                'fi_competitor_radius_miles'   => 5,
                'fi_autocomplete_radius_miles' => 10,
            ),

            'rate-limiting' => array(
                'fi_rate_limit_enabled' => '1',
                'fi_rate_limit_per_ip'  => 3,
                'fi_rate_limit_window'  => 3600,
            ),

            'white-label' => array(
                // Scanner customisation (migrated from former CTA tab, v2.1.0)
                'fi_scan_placeholder'      => 'Search a business',
                'fi_scan_btn_text'         => 'Search Business',
                'fi_scan_btn_icon'         => 'fa-solid fa-magnifying-glass',
                // Report-End CTA (migrated from former CTA tab, v2.1.0)
                'fi_wl_cta_button_enabled' => '0',
                'fi_wl_cta_button_text'    => 'Book a Free Consultation',
                'fi_wl_cta_btn_icon'       => '',
                'fi_wl_cta_button_url'     => '',
                // Email Report Button
                'fi_email_btn_text'        => 'Email Report',
                'fi_email_btn_icon'        => '',
                'fi_email_placeholder'     => 'Enter your email',
                // Brand Identity
                'fi_wl_sender_name'        => '',
                'fi_wl_reply_to'           => '',
                'fi_wl_logo_url'           => '',
                // Email Report Settings
                'fi_wl_report_title'       => '',
                'fi_wl_footer_cta'         => '',
                // Lead notifications
                'fi_lead_notifications_enabled'  => '1',
                'fi_lead_notification_email'     => get_option( 'admin_email' ),
                'fi_lead_notification_threshold' => 100,
            ),
        );

        if ( ! isset( $tab_defaults[ $tab ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown tab.', 'f-insights' ) ) );
        }

        // Premium-only tabs require an active license.
        if ( $tab === 'white-label' && ! FI_License::is_active() ) {
            wp_send_json_error( array( 'message' => __( 'Premium license required.', 'f-insights' ) ) );
        }

        foreach ( $tab_defaults[ $tab ] as $option => $default ) {
            update_option( $option, $default );
        }

        FI_Logger::info( 'Tab defaults reset', array( 'tab' => $tab ) );

        wp_send_json_success( array(
            'message'   => __( 'Settings reset to defaults. Reload the page to see the changes.', 'f-insights' ),
            'defaults'  => $tab_defaults[ $tab ],
        ) );
    }

    public function get_leads() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $result = FI_Analytics::get_leads_paged( array(
            'search'   => sanitize_text_field( wp_unslash( $_POST['search']   ?? '' ) ),
            'status'   => sanitize_key(        wp_unslash( $_POST['status']   ?? 'all' ) ),
            'page'     => intval(              wp_unslash( $_POST['page']     ?? 1 ) ),
            'per_page' => intval(              wp_unslash( $_POST['per_page'] ?? 20 ) ),
            'orderby'  => sanitize_key(        wp_unslash( $_POST['orderby']  ?? 'request_date' ) ),
            'order'    => sanitize_key(        wp_unslash( $_POST['order']    ?? 'DESC' ) ),
        ) );

        // Format dates and scores for the JS renderer
        foreach ( $result['leads'] as &$lead ) {
            $lead['date_formatted']  = date_i18n( get_option( 'date_format' ), strtotime( $lead['request_date'] ) );
            $lead['has_report']      = ! empty( $lead['report_html'] );
            $lead['score_class']     = $this->score_class( (int) $lead['overall_score'] );
            unset( $lead['report_html'] ); // don't send full HTML in list response
        }
        unset( $lead );

        wp_send_json_success( $result );
    }

    /** Map a score to a CSS class name. */
    private function score_class( int $score ): string {
        if ( $score >= 80 ) return 'good';
        if ( $score >= 60 ) return 'warning';
        return 'alert';
    }

    public function update_lead_status() {
        check_ajax_referer('fi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'f-insights')));
        }
        
        $lead_id = intval( wp_unslash( $_POST['lead_id'] ?? 0 ) );
        $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        
        if (empty($lead_id) || empty($status)) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'f-insights')));
        }
        
        $result = FI_Analytics::update_lead_status($lead_id, $status);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Status updated successfully', 'f-insights'),
                'status' => $status
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to update status', 'f-insights')));
        }
    }
    
    /**
     * Update lead notes via AJAX (v1.6.0)
     */
    public function update_lead_notes() {
        check_ajax_referer('fi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'f-insights')));
        }
        
        $lead_id = intval( wp_unslash( $_POST['lead_id'] ?? 0 ) );
        $notes   = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        // Enforce the 2000-character limit server-side as well as in the JS maxlength
        // attribute, so a direct POST can't bypass the frontend constraint.
        if ( mb_strlen( $notes ) > 2000 ) {
            $notes = mb_substr( $notes, 0, 2000 );
        }
        
        if (empty($lead_id)) {
            wp_send_json_error(array('message' => __('Invalid lead ID', 'f-insights')));
        }
        
        $result = FI_Analytics::update_lead_notes($lead_id, $notes);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Notes saved', 'f-insights')));
        } else {
            wp_send_json_error(array('message' => __('Failed to save notes', 'f-insights')));
        }
    }
    
    /**
     * Persist the Market Intel model preference from the Scan Intel card.
     * Called via AJAX whenever the user changes the model dropdown on the
     * analytics page — keeps it in sync with the DB so the next page load
     * shows their last-used model.
     */
    public function save_intel_model() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }
        $valid_models = array( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-20250514', 'claude-opus-4-20250514' );
        $model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
        if ( ! in_array( $model, $valid_models, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid model', 'f-insights' ) ) );
        }
        update_option( 'fi_claude_model_intel', $model );
        wp_send_json_success();
    }

    /**
     * Export leads to CSV (v1.6.0)
     * v2.0:   Added status, date_from, date_to filters and chunked query (5k rows/pass)
     *         to prevent PHP memory exhaustion on large datasets.
     * v2.2.0: Added column picker — callers pass a `columns[]` array of field keys.
     *         When omitted, all columns are exported (backwards-compatible).
     */
    public function export_leads_csv() {
        check_ajax_referer('fi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'f-insights'));
        }

        // ── Filters ────────────────────────────────────────────────────────────
        $status    = sanitize_key(        wp_unslash( $_POST['status']    ?? 'all' ) );
        $search    = sanitize_text_field( wp_unslash( $_POST['search']    ?? '' ) );
        $date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
        $date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
        $min_score = isset( $_POST['min_score'] ) ? absint( wp_unslash( $_POST['min_score'] ) ) : 0;
        $max_score = isset( $_POST['max_score'] ) ? absint( wp_unslash( $_POST['max_score'] ) ) : 100;

        if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = '';
        }
        if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = '';
        }

        // ── Column picker ──────────────────────────────────────────────────────
        // Map of allowed column keys → [DB column name, CSV header label].
        $all_columns = array(
            'business_name'     => array( 'business_name',     'Business Name' ),
            'business_category' => array( 'business_category', 'Category' ),
            'overall_score'     => array( 'overall_score',     'Score' ),
            'business_email'    => array( 'business_email',    'Business Email' ),
            'business_phone'    => array( 'business_phone',    'Business Phone' ),
            'business_website'  => array( 'business_website',  'Business Website' ),
            'business_address'  => array( 'business_address',  'Business Address' ),
            'visitor_email'     => array( 'visitor_email',     'Requested By' ),
            'request_date'      => array( 'request_date',      'Request Date' ),
            'follow_up_status'  => array( 'follow_up_status',  'Status' ),
            'follow_up_notes'   => array( 'follow_up_notes',   'Notes' ),
        );

        // If the caller specified a column list, validate each key against the allowlist.
        $requested_cols = array();
        if ( ! empty( $_POST['columns'] ) && is_array( $_POST['columns'] ) ) {
            foreach ( (array) wp_unslash( $_POST['columns'] ) as $col ) {
                $col = sanitize_key( $col );
                if ( isset( $all_columns[ $col ] ) ) {
                    $requested_cols[ $col ] = $all_columns[ $col ];
                }
            }
        }
        // Fall back to all columns when none specified (backwards-compat).
        $export_columns = empty( $requested_cols ) ? $all_columns : $requested_cols;

        // Build SELECT list from the chosen DB column names.
        $select_fields = implode( ', ', array_column( $export_columns, 0 ) );

        // ── Output headers ─────────────────────────────────────────────────────
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=f-insights-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // Write header row using the human-readable labels.
        fputcsv( $output, array_column( $export_columns, 1 ) );

        // ── Chunked query ──────────────────────────────────────────────────────
        global $wpdb;
        $table      = $wpdb->prefix . 'fi_leads';
        $chunk_size = 500;
        $offset     = 0;

        do {
            $conditions = array( '1=1' );
            $values     = array();

            if ( $status !== 'all' ) {
                $conditions[] = 'follow_up_status = %s';
                $values[]     = $status;
            }
            if ( $search !== '' ) {
                $conditions[] = '(business_name LIKE %s OR visitor_email LIKE %s)';
                $like         = '%' . $wpdb->esc_like( $search ) . '%';
                $values[]     = $like;
                $values[]     = $like;
            }
            if ( $date_from !== '' ) {
                $conditions[] = 'DATE(request_date) >= %s';
                $values[]     = $date_from;
            }
            if ( $date_to !== '' ) {
                $conditions[] = 'DATE(request_date) <= %s';
                $values[]     = $date_to;
            }
            if ( $min_score > 0 ) {
                $conditions[] = 'overall_score >= %d';
                $values[]     = $min_score;
            }
            if ( $max_score < 100 ) {
                $conditions[] = 'overall_score <= %d';
                $values[]     = $max_score;
            }

            $where_sql = implode( ' AND ', $conditions );
            $values[]  = $chunk_size;
            $values[]  = $offset;

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $chunk = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT $select_fields FROM $table WHERE $where_sql ORDER BY request_date DESC LIMIT %d OFFSET %d",
                    $values
                ),
                ARRAY_A
            );

            foreach ( $chunk as $lead ) {
                // Write only the requested columns in the declared order.
                $row = array();
                foreach ( $export_columns as $key => $col_def ) {
                    $row[] = $lead[ $col_def[0] ] ?? '';
                }
                fputcsv( $output, $row );
            }

            $offset += $chunk_size;
        } while ( count( $chunk ) === $chunk_size );

        fclose( $output );
        exit;
    }
    
    /**
     * View stored report HTML via AJAX (v1.7.0)
     */
    public function view_report() {
        check_ajax_referer('fi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'f-insights')));
        }
        
        $lead_id = intval( wp_unslash( $_POST['lead_id'] ?? 0 ) );
        
        if (empty($lead_id)) {
            wp_send_json_error(array('message' => __('Invalid lead ID', 'f-insights')));
        }
        
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT report_html, report_generated_at, business_name FROM {$wpdb->prefix}fi_leads WHERE id = %d",
            $lead_id
        ));
        
        if (!$lead || empty($lead->report_html)) {
            wp_send_json_error(array('message' => __('Report not found', 'f-insights')));
        }
        
        wp_send_json_success(array(
            'html' => $lead->report_html,
            'generated_at' => $lead->report_generated_at,
            'business_name' => $lead->business_name
        ));
    }

    /**
     * Bulk-update follow_up_status for multiple leads at once (v2.0.1).
     *
     * POST params:
     *   nonce      string   fi_admin_nonce
     *   lead_ids   array    Array of lead IDs (integers)
     *   status     string   Target status slug
     */
    public function bulk_update_leads() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $lead_ids = isset( $_POST['lead_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['lead_ids'] ) ) : array();
        $status   = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );

        $valid_statuses = array( 'new', 'contacted', 'qualified', 'closed', 'lost' );
        if ( empty( $lead_ids ) || ! in_array( $status, $valid_statuses, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'f-insights' ) ) );
        }

        $updated = 0;
        foreach ( $lead_ids as $id ) {
            if ( $id > 0 && FI_Analytics::update_lead_status( $id, $status ) ) {
                $updated++;
            }
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d number of leads updated */
                _n( '%d lead updated.', '%d leads updated.', $updated, 'f-insights' ),
                $updated
            ),
            'updated' => $updated,
            'status'  => $status,
        ) );
    }

    /**
     * Re-run the full scan pipeline on a stored lead and update its record (v2.0.2).
     *
     * Pulls the google_place_id from the stored lead, re-fetches live data from
     * Google (may be cached up to fi_cache_duration seconds), re-runs the Claude
     * analysis, and overwrites the lead's score, pain_points, report_html, and
     * report_generated_at in place. Only one version of the report is kept — the
     * latest. No scan history is accumulated.
     *
     * POST params:
     *   nonce    string   fi_admin_nonce
     *   lead_id  int      ID of the lead record to refresh
     */
    public function rescan_lead() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $lead_id = intval( wp_unslash( $_POST['lead_id'] ?? 0 ) );
        if ( $lead_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid lead ID', 'f-insights' ) ) );
        }

        global $wpdb;
        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, google_place_id, visitor_email FROM {$wpdb->prefix}fi_leads WHERE id = %d",
            $lead_id
        ) );

        if ( ! $lead || empty( $lead->google_place_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Lead not found or missing Place ID', 'f-insights' ) ) );
        }

        // ── Re-run the scan pipeline ─────────────────────────────────────────
        $scanner       = new FI_Scanner();
        $business_data = $scanner->get_business_details( $lead->google_place_id );

        if ( is_wp_error( $business_data ) ) {
            wp_send_json_error( array( 'message' => $business_data->get_error_message() ) );
        }

        $website_analysis = array();
        if ( ! empty( $business_data['website'] ) ) {
            $website_analysis = $scanner->analyze_website( $business_data['website'] );
        }

        $grader   = new FI_Grader( 'internal' );
        $analysis = $grader->grade_business( $business_data, $website_analysis );

        if ( is_wp_error( $analysis ) ) {
            wp_send_json_error( array( 'message' => $analysis->get_error_message() ) );
        }

        $category = $grader->categorize_business( $business_data['types'] ?? array() );
        if ( ! empty( $analysis['category'] ) ) {
            $category = $analysis['category'];
        }

        $report_data = array(
            'business_data'    => $business_data,
            'website_analysis' => $website_analysis,
            'analysis'         => $analysis,
            'scan_date'        => current_time( 'mysql' ),
        );

        // Build fresh pain points (categories scoring < 70)
        $insights    = $analysis['insights'] ?? array();
        $pain_points = array();
        foreach ( $insights as $cat_key => $data ) {
            if ( intval( $data['score'] ?? 0 ) < 70 ) {
                $pain_points[] = array(
                    'category' => ucwords( str_replace( '_', ' ', $cat_key ) ),
                    'score'    => intval( $data['score'] ),
                    'headline' => $data['headline'] ?? '',
                );
            }
        }

        // Generate a self-contained HTML snapshot using the same method as lead capture
        $report_html = $this->generate_report_html_snapshot( $report_data );

        // Update the lead row — preserve all contact info, only refresh scan data
        $overall_score = intval( $analysis['overall_score'] ?? 0 );
        $wpdb->update(
            $wpdb->prefix . 'fi_leads',
            array(
                'overall_score'       => $overall_score,
                'pain_points'         => json_encode( $pain_points ),
                'report_html'         => $report_html,
                'report_generated_at' => current_time( 'mysql' ),
                'request_date'        => current_time( 'mysql' ),
            ),
            array( 'id' => $lead_id ),
            array( '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        FI_Logger::info( 'Lead report refreshed', array(
            'lead_id'       => $lead_id,
            'place_id'      => $lead->google_place_id,
            'new_score'     => $overall_score,
        ) );

        wp_send_json_success( array(
            'message'        => sprintf(
                /* translators: %d new overall score */
                __( 'Report refreshed — new score: %d/100', 'f-insights' ),
                $overall_score
            ),
            'new_score'      => $overall_score,
            'score_class'    => $this->score_class( $overall_score ),
            'pain_points'    => $pain_points,
            'has_report'     => true,
        ) );
    }

    /**
     * AI Market Intelligence — analyse accumulated scan data and return
     * plain-English insights the plugin owner can act on.
     *
     * Pulls the 50 most recent scan records (business name, category, score,
     * pain_points from the leads table, date) and sends them to Claude with a
     * prompt that asks for: top patterns, underserved categories, pitch angles,
     * and one "hidden gem" observation. Response is streamed back as plain text.
     *
     * POST params:
     *   nonce   string   fi_admin_nonce
     *   focus   string   Optional focus area: 'patterns'|'opportunities'|'pitch'|'all'
     */
    public function market_intel() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        if ( ! FI_License::is_active() ) {
            wp_send_json_error( array( 'message' => __( 'Market Intelligence requires a paid plan.', 'f-insights' ) ) );
        }

        $api_key = FI_Crypto::get_key( FI_Crypto::CLAUDE_KEY_OPTION );
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Claude API key not configured.', 'f-insights' ) ) );
        }

        // ── Filters from the card UI ──────────────────────────────────────────
        $focus    = sanitize_key(        wp_unslash( $_POST['focus']    ?? 'all' ) );
        $industry = sanitize_text_field( wp_unslash( $_POST['industry'] ?? 'all' ) );
        $score    = sanitize_key(        wp_unslash( $_POST['score']    ?? 'all' ) );
        $window   = sanitize_key(        wp_unslash( $_POST['window']   ?? 'all' ) );

        // Per-run model — validated against allowlist
        $valid_models = array( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-20250514', 'claude-opus-4-20250514' );
        $model_raw    = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
        $model        = in_array( $model_raw, $valid_models, true )
            ? $model_raw
            : get_option( 'fi_claude_model_intel', get_option( 'fi_claude_model', 'claude-sonnet-4-20250514' ) );

        // ── Build WHERE clauses ───────────────────────────────────────────────
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'fi_analytics';
        $leads_table     = $wpdb->prefix . 'fi_leads';

        $where_parts = array( 'a.overall_score > 0' );
        $params      = array();

        if ( $industry !== 'all' && $industry !== '' ) {
            $where_parts[] = 'a.business_category = %s';
            $params[]      = $industry;
        }

        if ( $score === 'critical' ) {
            $where_parts[] = 'a.overall_score BETWEEN 0 AND 59';
        } elseif ( $score === 'warning' ) {
            $where_parts[] = 'a.overall_score BETWEEN 60 AND 79';
        } elseif ( $score === 'good' ) {
            $where_parts[] = 'a.overall_score BETWEEN 80 AND 100';
        }

        if ( in_array( $window, array( '30', '90', '180' ), true ) ) {
            $where_parts[] = 'a.scan_date >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[]      = intval( $window );
        }

        $where_sql = implode( ' AND ', $where_parts );

        // ── Pull filtered scans ───────────────────────────────────────────────
        // Table names ($analytics_table, $leads_table) are derived from $wpdb->prefix
        // and are trusted — they cannot be influenced by user input.
        // $where_sql is built exclusively from string literals and validated scalar
        // values passed through $wpdb->prepare() below; never from raw POST data.
        // If this query is ever refactored, ensure table names remain untainted and
        // all dynamic WHERE values continue to use %s/%d placeholders.
        $base_sql = "SELECT a.business_name, a.business_category, a.overall_score,
                            DATE(a.scan_date) as scan_date, l.pain_points
                     FROM {$analytics_table} a
                     LEFT JOIN {$leads_table} l ON l.google_place_id = a.google_place_id
                     WHERE {$where_sql}
                     ORDER BY a.scan_date DESC
                     LIMIT 50";

        $scans = empty( $params )
            ? $wpdb->get_results( $base_sql, ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $base_sql, $params ), ARRAY_A );

        if ( empty( $scans ) ) {
            wp_send_json_error( array( 'message' => __( 'No scans match your filters. Try widening the industry, score range, or time window.', 'f-insights' ) ) );
        }

        // ── Format data for Claude ────────────────────────────────────────────
        $scan_lines = array();
        foreach ( $scans as $scan ) {
            $pain_points_raw = $scan['pain_points'] ? json_decode( $scan['pain_points'], true ) : array();
            $pain_str        = ! empty( $pain_points_raw )
                ? implode( '; ', array_map( function( $p ) {
                    return ( $p['category'] ?? '' ) . ' (' . ( $p['score'] ?? '?' ) . '/100)';
                }, array_slice( $pain_points_raw, 0, 3 ) ) )
                : 'none recorded';
            $scan_lines[] = sprintf(
                '- %s (%s) | Score: %d/100 | Date: %s | Issues: %s',
                $scan['business_name'],
                $scan['business_category'] ?: 'Unknown',
                $scan['overall_score'],
                $scan['scan_date'],
                $pain_str
            );
        }

        $data_block  = implode( "\n", $scan_lines );
        $total_scans = count( $scans );
        $site_name   = get_bloginfo( 'name' );

        // Describe the active filters in plain English for the prompt context
        $filter_desc = array();
        if ( $industry !== 'all' ) $filter_desc[] = "industry: {$industry}";
        if ( $score    !== 'all' ) $filter_desc[] = "score range: {$score}";
        if ( $window   !== 'all' ) $filter_desc[] = "last {$window} days";
        $filter_note = ! empty( $filter_desc ) ? 'Filtered to: ' . implode( ', ', $filter_desc ) . '.' : 'No filters applied — all scans included.';

        $focus_instructions = array(
            'patterns'      => 'Focus on recurring patterns: which industries keep appearing, what pain points repeat across different businesses, and what that tells you about the local market.',
            'opportunities' => 'Focus on market gaps and opportunities: which business categories score lowest, what problems appear most frequently, and where a consultant could win the most deals.',
            'pitch'         => 'Focus on pitch intelligence: for each major industry in the data, give one sharp, specific opening line a consultant could use in a cold outreach or in-person pitch. Base it on the actual pain points in the data.',
            'all'           => 'Cover all angles: patterns across industries, the biggest market opportunities, and one sharp pitch angle per major industry.',
        );
        $focus_text = $focus_instructions[ $focus ] ?? $focus_instructions['all'];

        $prompt = <<<PROMPT
You are a sharp market intelligence analyst. A consultant runs a website called "{$site_name}" with a tool that scans local businesses and scores their online presence (0–100). Here is their filtered scan data ({$total_scans} scans). {$filter_note}

{$data_block}

{$focus_text}

Rules:
- Be direct and specific. No generic advice.
- Reference actual business names, categories, scores, and pain points from the data.
- Keep the total response under 450 words.
- Use plain text with short paragraphs. No markdown headers, no bullet lists — write like a sharp analyst talking to a peer.
- End with exactly one sentence labeled "The Blind Spot:" that identifies something the data hints at but doesn't fully reveal.
PROMPT;

        // ── Call Claude ───────────────────────────────────────────────────────
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => json_encode( array(
                'model'      => $model,
                'max_tokens' => 700,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API request failed: ' . $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? 'HTTP ' . $code;
            wp_send_json_error( array( 'message' => $msg ) );
        }

        $intel_text = $body['content'][0]['text'] ?? '';
        if ( empty( $intel_text ) ) {
            wp_send_json_error( array( 'message' => __( 'Claude returned an empty response.', 'f-insights' ) ) );
        }

        // Track token usage
        $input_tokens  = intval( $body['usage']['input_tokens']  ?? 0 );
        $output_tokens = intval( $body['usage']['output_tokens'] ?? 0 );
        if ( $input_tokens > 0 || $output_tokens > 0 ) {
            $month_key = 'fi_token_usage_' . gmdate( 'Y_m' );
            $current   = get_option( $month_key, array( 'input' => 0, 'output' => 0, 'scans' => 0, 'by_model' => array() ) );
            $by_model  = isset( $current['by_model'] ) ? $current['by_model'] : array();
            if ( ! isset( $by_model[ $model ] ) ) {
                $by_model[ $model ] = array( 'input' => 0, 'output' => 0, 'scans' => 0 );
            }
            $by_model[ $model ]['input']  += $input_tokens;
            $by_model[ $model ]['output'] += $output_tokens;
            $by_model[ $model ]['scans']  += 1;
            update_option( $month_key, array(
                'input'    => $current['input']  + $input_tokens,
                'output'   => $current['output'] + $output_tokens,
                'scans'    => $current['scans']  + 1,
                'by_model' => $by_model,
            ), false );
        }

        wp_send_json_success( array(
            'intel'    => $intel_text,
            'scans'    => $total_scans,
            'model'    => $model,
            'filters'  => $filter_note,
        ) );
    }

    // =========================================================================
    // GDPR / Data management  (v2.2.0)
    // =========================================================================

    /**
     * Delete a single lead by ID.
     *
     * POST params:
     *   nonce    string  fi_admin_nonce
     *   lead_id  int     Lead row ID
     */
    public function delete_lead() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $lead_id = absint( wp_unslash( $_POST['lead_id'] ?? 0 ) );
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid lead ID.', 'f-insights' ) ) );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'fi_leads';
        $deleted = $wpdb->delete( $table, array( 'id' => $lead_id ), array( '%d' ) );

        if ( $deleted === false ) {
            wp_send_json_error( array( 'message' => __( 'Database error while deleting lead.', 'f-insights' ) ) );
        }

        FI_Logger::info( 'Lead deleted', array( 'id' => $lead_id ) );
        wp_send_json_success( array( 'message' => __( 'Lead deleted.', 'f-insights' ), 'deleted' => $deleted ) );
    }

    /**
     * Bulk-delete selected leads by ID array.
     *
     * POST params:
     *   nonce    string  fi_admin_nonce
     *   lead_ids array   Array of integer lead IDs
     */
    public function bulk_delete_leads() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $raw_ids  = isset( $_POST['lead_ids'] ) ? (array) wp_unslash( $_POST['lead_ids'] ) : array();
        $lead_ids = array_filter( array_map( 'absint', $raw_ids ) );

        if ( empty( $lead_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No lead IDs provided.', 'f-insights' ) ) );
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'fi_leads';
        $placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $lead_ids )
        );

        FI_Logger::info( 'Bulk lead deletion', array( 'ids' => $lead_ids, 'deleted' => $deleted ) );
        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of deleted leads */
                _n( '%d lead deleted.', '%d leads deleted.', $deleted, 'f-insights' ),
                $deleted
            ),
            'deleted' => $deleted,
        ) );
    }

    /**
     * Delete all leads associated with a specific email address (GDPR erasure).
     *
     * POST params:
     *   nonce  string  fi_admin_nonce
     *   email  string  Visitor email address to erase
     */
    public function delete_leads_by_email() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'f-insights' ) ) );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'fi_leads';
        $deleted = $wpdb->delete( $table, array( 'visitor_email' => $email ), array( '%s' ) );

        FI_Logger::info( 'GDPR erasure: leads deleted by email', array( 'email' => $email, 'deleted' => $deleted ) );

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of deleted records */
                _n( '%d record deleted for that email.', '%d records deleted for that email.', $deleted, 'f-insights' ),
                $deleted
            ),
            'deleted' => (int) $deleted,
        ) );
    }
}