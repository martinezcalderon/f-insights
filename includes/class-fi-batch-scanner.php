<?php
/**
 * Batch Prospect Scanner  (v2.2.0)
 *
 * Allows admins to search for multiple businesses by category + location and
 * generate F! Insights reports for each, with configurable guardrails to
 * prevent runaway API costs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_Batch_Scanner {

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Minimum interval (seconds) between individual Claude API calls within a batch. */
    const INTER_SCAN_DELAY = 2;

    /** Hard ceiling: even if the admin sets a higher limit, we cap here. */
    const MAX_BATCH_SIZE_HARD_CAP = 25;

    // ── AJAX registration ─────────────────────────────────────────────────────

    public static function register_ajax_hooks() {
        add_action( 'wp_ajax_fi_batch_find_prospects', array( __CLASS__, 'ajax_find_prospects' ) );
        add_action( 'wp_ajax_fi_batch_scan_prospect',  array( __CLASS__, 'ajax_scan_prospect' ) );
    }

    // =========================================================================
    // AJAX: Find prospects (Google Places text search by category + location)
    // =========================================================================

    /**
     * Step 1 of the batch workflow: search Google Places for businesses matching
     * a category + location query and return a list of prospects (no Claude yet).
     *
     * POST params:
     *   nonce     string  fi_admin_nonce
     *   category  string  Business category / type (e.g. "HVAC contractors")
     *   location  string  City / region (e.g. "Austin, TX")
     *   max_count int     Number of results (capped to admin setting)
     */
    public static function ajax_find_prospects() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $category  = sanitize_text_field( wp_unslash( $_POST['category']  ?? '' ) );
        $location  = sanitize_text_field( wp_unslash( $_POST['location']  ?? '' ) );
        $max_count = absint( wp_unslash( $_POST['max_count'] ?? 5 ) );

        if ( empty( $category ) || empty( $location ) ) {
            wp_send_json_error( array( 'message' => __( 'Category and location are required.', 'f-insights' ) ) );
        }

        // Enforce the admin-configurable batch size limit.
        $admin_max  = absint( get_option( 'fi_batch_max_size', 10 ) );
        $admin_max  = min( $admin_max, self::MAX_BATCH_SIZE_HARD_CAP );
        $max_count  = min( $max_count, $admin_max );

        // Check daily batch quota before touching the API.
        $quota_error = self::check_daily_quota( $max_count );
        if ( is_wp_error( $quota_error ) ) {
            wp_send_json_error( array( 'message' => $quota_error->get_error_message() ) );
        }

        // Build a combined query: "<category> in <location>"
        $query   = trim( $category ) . ' in ' . trim( $location );
        $scanner = new FI_Scanner();

        // Use the existing search_business() which calls Google Places TextSearch.
        // We search with maxResultCount up to 20 so we can trim to $max_count.
        $results = $scanner->search_business( $query );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( array( 'message' => $results->get_error_message() ) );
        }

        // Trim to requested count.
        $prospects = array_slice( $results, 0, $max_count );

        FI_Logger::info( 'Batch prospect search', array(
            'query'     => $query,
            'found'     => count( $results ),
            'returning' => count( $prospects ),
        ) );

        wp_send_json_success( array(
            'prospects'  => $prospects,
            'query'      => $query,
            'quota_used' => self::get_daily_quota_used(),
            'quota_max'  => absint( get_option( 'fi_batch_daily_quota', 50 ) ),
        ) );
    }

    // =========================================================================
    // AJAX: Scan a single prospect (Claude analysis)
    // =========================================================================

    /**
     * Step 2 of the batch workflow: run a full F! Insights analysis on one
     * prospect (identified by place_id). Called per-item from the frontend JS
     * so progress can be shown incrementally without a single long-running request.
     *
     * POST params:
     *   nonce     string  fi_admin_nonce
     *   place_id  string  Google Place ID
     *   name      string  Business name (for logging)
     */
    public static function ajax_scan_prospect() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $place_id = sanitize_text_field( wp_unslash( $_POST['place_id'] ?? '' ) );
        $name     = sanitize_text_field( wp_unslash( $_POST['name']     ?? '' ) );

        if ( empty( $place_id ) ) {
            wp_send_json_error( array( 'message' => __( 'place_id is required.', 'f-insights' ) ) );
        }

        // Consume one slot from the daily quota.
        $quota_error = self::check_daily_quota( 1 );
        if ( is_wp_error( $quota_error ) ) {
            wp_send_json_error( array( 'message' => $quota_error->get_error_message() ) );
        }
        self::increment_daily_quota( 1 );

        // Fetch full business details from Google Places.
        $scanner  = new FI_Scanner();
        $business = $scanner->get_business_details( $place_id );

        if ( is_wp_error( $business ) ) {
            wp_send_json_error( array( 'message' => $business->get_error_message() ) );
        }

        // Run Claude analysis (same pipeline as a regular scan).
        $grader = new FI_Grader( 'scan' );
        $report = $grader->grade_business( $business );

        if ( is_wp_error( $report ) ) {
            wp_send_json_error( array(
                'message'   => $report->get_error_message(),
                'place_id'  => $place_id,
                'name'      => $name,
            ) );
        }

        // Track analytics (premium only — mirrors single-scan behaviour).
        FI_Analytics::track_scan( $business, $report );

        FI_Logger::info( 'Batch prospect scanned', array( 'place_id' => $place_id, 'name' => $name ) );

        wp_send_json_success( array(
            'place_id'  => $place_id,
            'name'      => $name,
            'report'    => $report,
            'business'  => $business,
            'quota_used'=> self::get_daily_quota_used(),
        ) );
    }

    // =========================================================================
    // Daily quota helpers
    // =========================================================================

    /**
     * Returns the number of batch scans run today (UTC day, stored as a transient).
     */
    public static function get_daily_quota_used() {
        return (int) get_transient( self::quota_transient_key() );
    }

    /**
     * Check whether running $count more scans would exceed today's daily limit.
     *
     * @return true|WP_Error  true if OK, WP_Error if quota exceeded.
     */
    private static function check_daily_quota( $count ) {
        $daily_max = absint( get_option( 'fi_batch_daily_quota', 50 ) );
        if ( $daily_max < 1 ) {
            return true; // 0 = unlimited (not recommended but valid admin choice)
        }
        $used = self::get_daily_quota_used();
        if ( $used + $count > $daily_max ) {
            return new WP_Error(
                'batch_quota_exceeded',
                sprintf(
                    /* translators: 1: scans used today, 2: daily maximum */
                    __( 'Daily batch quota reached (%1$d of %2$d scans used today). Resets at midnight UTC.', 'f-insights' ),
                    $used,
                    $daily_max
                )
            );
        }
        return true;
    }

    /**
     * Increment today's quota counter by $count.
     * The transient expires at the next UTC midnight automatically.
     */
    private static function increment_daily_quota( $count ) {
        $key  = self::quota_transient_key();
        $used = (int) get_transient( $key );
        // Calculate seconds until next UTC midnight for transient TTL.
        $seconds_until_midnight = strtotime( 'tomorrow midnight UTC' ) - time();
        set_transient( $key, $used + $count, max( 1, $seconds_until_midnight ) );
    }

    /** Generates a UTC-day-specific transient key for the quota counter. */
    private static function quota_transient_key() {
        return 'fi_batch_quota_' . gmdate( 'Y_m_d' );
    }

    // =========================================================================
    // Admin page renderer
    // =========================================================================

    /**
     * Render the Batch Prospect Scanner admin page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_premium   = FI_Premium::is_active();
        $admin_max    = min( absint( get_option( 'fi_batch_max_size', 10 ) ), self::MAX_BATCH_SIZE_HARD_CAP );
        $daily_quota  = absint( get_option( 'fi_batch_daily_quota', 50 ) );
        $quota_used   = self::get_daily_quota_used();
        $quota_remain = max( 0, $daily_quota - $quota_used );

        // Model cost reference for the UI hint.
        $scan_model   = get_option( 'fi_claude_model_scan', 'claude-haiku-4-5-20251001' );
        $cost_map     = array(
            'claude-haiku-4-5-20251001' => '$0.01–$0.02',
            'claude-sonnet-4-20250514'  => '$0.03–$0.06',
            'claude-opus-4-20250514'    => '$0.12–$0.25',
        );
        $cost_hint    = $cost_map[ $scan_model ] ?? '~$0.03–$0.06';
        ?>
        <div class="wrap fi-admin-wrap">
            <h1><?php _e( 'Batch Prospect Scanner', 'f-insights' ); ?></h1>
            <p class="description">
                <?php _e( 'Search for a category of businesses in a location and generate F! Insights reports for each prospect. Reports are saved to your leads and analytics tables — no user email required.', 'f-insights' ); ?>
            </p>

            <?php if ( ! $is_premium ) : ?>
                <div class="notice notice-warning inline" style="margin:16px 0;">
                    <p><?php _e( '<strong>Premium feature.</strong> Upgrade to use the Batch Prospect Scanner.', 'f-insights' ); ?></p>
                </div>
            <?php else : ?>

            <!-- ── Quota status bar ────────────────────────────────────────── -->
            <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="font-size:13px;">
                    <strong><?php _e( 'Today\'s quota:', 'f-insights' ); ?></strong>
                    <?php echo esc_html( $quota_used ); ?> / <?php echo esc_html( $daily_quota ); ?> scans used
                    &nbsp;·&nbsp;
                    <span style="color:<?php echo $quota_remain > 0 ? '#00a32a' : '#b32d2e'; ?>">
                        <?php echo esc_html( $quota_remain ); ?> remaining
                    </span>
                    &nbsp;·&nbsp;
                    <?php _e( 'Resets at midnight UTC', 'f-insights' ); ?>
                </span>
                <span style="font-size:12px;color:#646970;">
                    <?php
                    printf(
                        /* translators: 1: cost estimate per scan, 2: Claude model name */
                        esc_html__( 'Est. Claude cost per scan: %1$s (%2$s)', 'f-insights' ),
                        esc_html( $cost_hint ),
                        esc_html( $scan_model )
                    );
                    ?>
                </span>
            </div>

            <!-- ── Batch search form ────────────────────────────────────────── -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;">
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th scope="row"><label for="fi-batch-category"><?php _e( 'Business Category', 'f-insights' ); ?></label></th>
                        <td>
                            <input type="text" id="fi-batch-category" class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g. HVAC contractors, roofing companies, dentists', 'f-insights' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-location"><?php _e( 'Location', 'f-insights' ); ?></label></th>
                        <td>
                            <input type="text" id="fi-batch-location" class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g. Austin, TX or Miami, Florida', 'f-insights' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-count"><?php _e( 'Max Results', 'f-insights' ); ?></label></th>
                        <td>
                            <input type="number" id="fi-batch-count" class="small-text"
                                   value="5" min="1" max="<?php echo esc_attr( $admin_max ); ?>" step="1" />
                            <p class="description">
                                <?php printf(
                                    /* translators: %d: max batch size configured by admin */
                                    esc_html__( 'Maximum %d (set in Batch Scanner Settings). Each result costs one Claude API call (%s).', 'f-insights' ),
                                    $admin_max,
                                    esc_html( $cost_hint )
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px;">
                    <button type="button" id="fi-batch-find-btn" class="button button-primary">
                        <?php _e( '🔍 Find Prospects', 'f-insights' ); ?>
                    </button>
                    <span id="fi-batch-find-status" style="font-size:13px;color:#646970;display:none;"></span>
                </div>
            </div>

            <!-- ── Prospect list + scan progress ──────────────────────────── -->
            <div id="fi-batch-results" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                    <h3 style="margin:0;" id="fi-batch-results-heading"></h3>
                    <button type="button" id="fi-batch-scan-all-btn" class="button button-primary">
                        <?php _e( '▶ Scan All Prospects', 'f-insights' ); ?>
                    </button>
                </div>
                <p class="description" id="fi-batch-scan-note" style="margin-bottom:12px;">
                    <?php _e( 'Each prospect is scanned sequentially. You can close this page after starting — results are saved automatically.', 'f-insights' ); ?>
                </p>
                <div id="fi-batch-progress-bar-wrap" style="display:none;background:#f0f0f1;border-radius:4px;height:8px;margin-bottom:16px;">
                    <div id="fi-batch-progress-bar" style="background:#2271b1;height:8px;border-radius:4px;width:0%;transition:width .4s;"></div>
                </div>
                <table class="widefat fi-batch-table" style="border-radius:6px;overflow:hidden;">
                    <thead>
                        <tr>
                            <th><?php _e( 'Business', 'f-insights' ); ?></th>
                            <th><?php _e( 'Category', 'f-insights' ); ?></th>
                            <th><?php _e( 'Rating', 'f-insights' ); ?></th>
                            <th><?php _e( 'Score', 'f-insights' ); ?></th>
                            <th><?php _e( 'Status', 'f-insights' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fi-batch-tbody"></tbody>
                </table>
            </div>

            <?php endif; // is_premium ?>
        </div>

        <?php if ( $is_premium ) : ?>
        <script>
        (function($){
            'use strict';

            var prospects    = [];
            var scanQueue    = [];
            var scanningIdx  = -1;
            var totalToScan  = 0;

            // ── Find Prospects ────────────────────────────────────────────────
            $('#fi-batch-find-btn').on('click', function() {
                var category = $('#fi-batch-category').val().trim();
                var location = $('#fi-batch-location').val().trim();
                var count    = parseInt($('#fi-batch-count').val(), 10) || 5;
                var $btn     = $(this);
                var $status  = $('#fi-batch-find-status');

                if (!category || !location) {
                    $status.css('color','#b32d2e').text('Enter a category and location.').show();
                    return;
                }

                $btn.prop('disabled', true).text('Searching…');
                $status.css('color','#646970').text('Searching Google Places…').show();
                $('#fi-batch-results').hide();

                $.post(fiAdmin.ajaxUrl, {
                    action:    'fi_batch_find_prospects',
                    nonce:     fiAdmin.nonce,
                    category:  category,
                    location:  location,
                    max_count: count
                }, function(response) {
                    if (!response.success) {
                        $status.css('color','#b32d2e').text('✗ ' + (response.data.message || 'Search failed.'));
                        return;
                    }
                    prospects = response.data.prospects || [];
                    if (prospects.length === 0) {
                        $status.css('color','#b32d2e').text('No businesses found for that query. Try a different category or location.');
                        return;
                    }
                    $status.css('color','#00a32a').text('✓ Found ' + prospects.length + ' prospect(s).');
                    renderProspectTable(prospects);
                    $('#fi-batch-results').show();
                    $('#fi-batch-results-heading').text('Prospects (' + prospects.length + ')');
                }).fail(function() {
                    $status.css('color','#b32d2e').text('✗ Request failed.');
                }).always(function() {
                    $btn.prop('disabled', false).text('🔍 Find Prospects');
                });
            });

            // ── Render prospect table rows (pre-scan state) ───────────────────
            function renderProspectTable(list) {
                var html = '';
                list.forEach(function(p, idx) {
                    html += '<tr id="fi-batch-row-' + idx + '">'
                          + '<td><strong>' + escHtml(p.name) + '</strong>'
                          + (p.address ? '<br><small style="color:#666;">' + escHtml(p.address) + '</small>' : '')
                          + '</td>'
                          + '<td>' + escHtml(p.primary_type || '—') + '</td>'
                          + '<td>' + (p.rating ? '⭐ ' + p.rating + ' (' + (p.user_ratings_total||0) + ')' : '—') + '</td>'
                          + '<td id="fi-batch-score-' + idx + '" style="font-weight:600;">—</td>'
                          + '<td id="fi-batch-status-' + idx + '"><span style="color:#646970;">Pending</span></td>'
                          + '</tr>';
                });
                $('#fi-batch-tbody').html(html);
            }

            // ── Scan All ──────────────────────────────────────────────────────
            $('#fi-batch-scan-all-btn').on('click', function() {
                if (scanningIdx >= 0) { return; } // already running
                var $btn = $(this).prop('disabled', true).text('Scanning…');
                scanQueue    = prospects.map(function(p, idx) { return idx; });
                totalToScan  = scanQueue.length;
                scanningIdx  = 0;
                $('#fi-batch-progress-bar-wrap').show();
                scanNext($btn);
            });

            function scanNext($btn) {
                if (scanQueue.length === 0) {
                    $btn.prop('disabled', false).text('▶ Scan All Prospects');
                    scanningIdx = -1;
                    updateProgress(totalToScan, totalToScan);
                    return;
                }
                var idx      = scanQueue.shift();
                var prospect = prospects[idx];
                scanningIdx  = idx;

                $('#fi-batch-status-' + idx).html('<span style="color:#2271b1;">⟳ Scanning…</span>');

                $.ajax({
                    url:     fiAdmin.ajaxUrl,
                    type:    'POST',
                    timeout: 90000, // 90 s — Claude can be slow
                    data: {
                        action:   'fi_batch_scan_prospect',
                        nonce:    fiAdmin.nonce,
                        place_id: prospect.place_id,
                        name:     prospect.name
                    },
                    success: function(response) {
                        if (response.success) {
                            var score = response.data.report && response.data.report.overall_score
                                ? response.data.report.overall_score : '—';
                            var scoreColor = score >= 80 ? '#00a32a' : score >= 60 ? '#f0b429' : '#dc3232';
                            $('#fi-batch-score-' + idx).html('<span style="color:' + scoreColor + ';">' + score + '</span>');
                            $('#fi-batch-status-' + idx).html('<span style="color:#00a32a;">✓ Done</span>');
                        } else {
                            $('#fi-batch-status-' + idx).html('<span style="color:#b32d2e;" title="' + escHtml(response.data.message || '') + '">✗ Failed</span>');
                        }
                    },
                    error: function() {
                        $('#fi-batch-status-' + idx).html('<span style="color:#b32d2e;">✗ Timeout</span>');
                    },
                    complete: function() {
                        var done = totalToScan - scanQueue.length;
                        updateProgress(done, totalToScan);
                        // Small delay between calls to avoid rate-limiting.
                        setTimeout(function() { scanNext($btn); }, <?php echo (int) self::INTER_SCAN_DELAY * 1000; ?>);
                    }
                });
            }

            function updateProgress(done, total) {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                $('#fi-batch-progress-bar').css('width', pct + '%');
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

        })(jQuery);
        </script>
        <?php endif; ?>
        <?php
    }
}
