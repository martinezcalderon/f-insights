<?php
/**
 * Analytics tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Analytics {

    /**
     * Option key that counts scans run while on a free license.
     * Incremented on every scan; never decremented. Shown on the
     * locked Analytics page to create urgency around upgrading.
     */
    const FREE_SCAN_COUNTER = 'fi_free_scan_count';

    /**
     * Check if premium features are available.
     *
     * @return bool
     */
    private static function is_premium(): bool {
        return FI_License::is_active();
    }

    /**
     * Track a scan.
     *
     * Premium: writes a full row to the analytics DB table.
     * Free:    increments a lightweight option counter only.
     *
     * Only called when the scanning IP is NOT on the exclusion list —
     * the check happens upstream in FI_Ajax::scan_business().
     */
    public static function track_scan($business_name, $category, $place_id, $overall_score, $report_data, $user_email = null) {
        if ( self::is_premium() ) {
            // ── Premium: full analytics row ──────────────────────────────────
            global $wpdb;
            $table = $wpdb->prefix . 'fi_analytics';

            $wpdb->insert(
                $table,
                array(
                    'business_name'     => $business_name,
                    'business_category' => $category,
                    'google_place_id'   => $place_id,
                    'overall_score'     => $overall_score,
                    'scan_date'         => current_time( 'mysql' ),
                    'ip_address'        => self::get_client_ip(),
                    'user_email'        => $user_email,
                    'report_data'       => json_encode( $report_data ),
                ),
                array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
            );
        } else {
            // ── Free: increment scan counter, then check trial unlock ─────────
            $count = intval( get_option( self::FREE_SCAN_COUNTER, 0 ) );
            update_option( self::FREE_SCAN_COUNTER, $count + 1, false );
            // 'false' = do not autoload — only read when the admin views the
            // locked Analytics page, not on every page load.

            // Unlock the 30-day trial once the threshold is reached (once, ever).
            FI_License::maybe_unlock_trial();
        }
    }

    /**
     * Return the number of scans run while on a free license.
     *
     * @return int
     */
    public static function get_free_scan_count() {
        return intval( get_option( self::FREE_SCAN_COUNTER, 0 ) );
    }
    
    /**
     * Get dashboard analytics data.
     *
     * @param array $filters {
     *   @type string $date_from  Optional start date (YYYY-MM-DD, UTC).
     *   @type string $date_to    Optional end date   (YYYY-MM-DD, UTC).
     * }
     */
    public static function get_dashboard_data( $filters = array() ) {
        global $wpdb;
        $table       = $wpdb->prefix . 'fi_analytics';
        $leads_table = $wpdb->prefix . 'fi_leads';

        // ── Date-range filter ─────────────────────────────────────────────────
        // Validate and sanitise the caller-supplied date strings.
        $date_from = isset( $filters['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] )
            ? $filters['date_from'] : '';
        $date_to   = isset( $filters['date_to'] )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] )
            ? $filters['date_to']   : '';

        // Build a reusable WHERE clause applied to every query that touches scan_date.
        $date_where_parts = array( '1=1' );
        $date_where_vals  = array();
        if ( $date_from !== '' ) {
            $date_where_parts[] = 'scan_date >= %s';
            $date_where_vals[]  = $date_from . ' 00:00:00';
        }
        if ( $date_to !== '' ) {
            $date_where_parts[] = 'scan_date <= %s';
            $date_where_vals[]  = $date_to . ' 23:59:59';
        }
        $date_where_sql = implode( ' AND ', $date_where_parts );

        // Helper: builds the full WHERE clause and returns a prepared SQL string.
        $prepared_where = function( $extra_parts = array(), $extra_vals = array() ) use ( $wpdb, $date_where_parts, $date_where_vals ) {
            $parts = array_merge( $date_where_parts, $extra_parts );
            $vals  = array_merge( $date_where_vals,  $extra_vals  );
            $sql   = implode( ' AND ', $parts );
            return empty( $vals ) ? $sql : $wpdb->prepare( $sql, $vals );
        };

        $is_filtered = ( $date_from !== '' || $date_to !== '' );

        // Total scans
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_scans = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE " . $prepared_where() );
        
        // This month scans — use gmdate() to match the UTC timestamps stored by track_scan().
        // current_time() returns WordPress local time, which diverges from the UTC values
        // in the database on sites where the WP timezone is not UTC.
        $now_utc   = gmdate( 'Y-m-d H:i:s' );
        $utc_month = (int) gmdate( 'n' );
        $utc_year  = (int) gmdate( 'Y' );

        $month_scans = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE MONTH(scan_date) = %d AND YEAR(scan_date) = %d",
            $utc_month, $utc_year
        ));
        
        // This week scans
        $week_scans = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE YEARWEEK(scan_date) = YEARWEEK(%s)",
            $now_utc
        ));
        
        // Average score (within filtered window)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $avg_score = $wpdb->get_var( "SELECT AVG(overall_score) FROM $table WHERE overall_score > 0 AND " . $prepared_where() );

        // ── Prior-period deltas (vs same window last month) ──────────────────
        // Always reflects the current calendar month regardless of any date filter,
        // so the KPI "this month" card stays a fixed reference point.
        $prev_month       = $utc_month === 1 ? 12 : $utc_month - 1;
        $prev_month_year  = $utc_month === 1 ? $utc_year - 1 : $utc_year;
        $prev_month_scans = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE MONTH(scan_date) = %d AND YEAR(scan_date) = %d",
            $prev_month, $prev_month_year
        ) );
        $month_delta = $prev_month_scans > 0
            ? round( ( ( $month_scans - $prev_month_scans ) / $prev_month_scans ) * 100, 1 )
            : null;

        // ── Scan trend (daily counts) ─────────────────────────────────────────
        // When a date filter is active: show daily counts for the filtered window.
        // When no filter: show the standard last-30-days view.
        if ( $is_filtered ) {
            $trend_from = $date_from ?: gmdate( 'Y-m-d', strtotime( '-29 days' ) );
            $trend_to   = $date_to   ?: gmdate( 'Y-m-d' );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $trend_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE(scan_date) AS day, COUNT(*) AS scans
                     FROM $table
                     WHERE scan_date >= %s AND scan_date <= %s
                     GROUP BY DATE(scan_date)
                     ORDER BY day ASC",
                    $trend_from . ' 00:00:00',
                    $trend_to   . ' 23:59:59'
                ),
                ARRAY_A
            );
            // Fill every day in the range.
            $trend    = array();
            $cursor   = strtotime( $trend_from );
            $end_ts   = strtotime( $trend_to );
            while ( $cursor <= $end_ts ) {
                $trend[ gmdate( 'Y-m-d', $cursor ) ] = 0;
                $cursor = strtotime( '+1 day', $cursor );
            }
        } else {
            $trend_rows = $wpdb->get_results(
                "SELECT DATE(scan_date) AS day, COUNT(*) AS scans
                 FROM $table
                 WHERE scan_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                 GROUP BY DATE(scan_date)
                 ORDER BY day ASC",
                ARRAY_A
            );
            // Fill sparse results so every day in range has a value.
            $trend = array();
            for ( $i = 29; $i >= 0; $i-- ) {
                $trend[ gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ) ] = 0;
            }
        }
        foreach ( $trend_rows as $row ) {
            $trend[ $row['day'] ] = (int) $row['scans'];
        }

        // ── Average score trend (monthly) ────────────────────────────────────
        // When filtered: show monthly averages within the window.
        // When unfiltered: show last 6 calendar months.
        if ( $is_filtered ) {
            $score_trend_where = $prepared_where( array( 'overall_score > 0' ) );
        } else {
            $score_trend_where = "scan_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND overall_score > 0";
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $score_trend_rows = $wpdb->get_results(
            "SELECT DATE_FORMAT(scan_date, '%Y-%m') AS month, AVG(overall_score) AS avg_score
             FROM $table
             WHERE $score_trend_where
             GROUP BY DATE_FORMAT(scan_date, '%Y-%m')
             ORDER BY month ASC",
            ARRAY_A
        );
        $score_trend = array();
        foreach ( $score_trend_rows as $row ) {
            $score_trend[ $row['month'] ] = round( floatval( $row['avg_score'] ), 1 );
        }

        // ── Scan-to-lead conversion rate ──────────────────────────────────────
        $total_leads     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table" );
        $conversion_rate = $total_scans > 0
            ? round( ( $total_leads / $total_scans ) * 100, 1 )
            : 0;

        // ── Score distribution buckets (within filtered window) ───────────────
        $dist_where = $prepared_where( array( 'overall_score > 0' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $score_distribution = $wpdb->get_results(
            "SELECT
               SUM(overall_score BETWEEN 0  AND 19) AS bucket_0_19,
               SUM(overall_score BETWEEN 20 AND 39) AS bucket_20_39,
               SUM(overall_score BETWEEN 40 AND 59) AS bucket_40_59,
               SUM(overall_score BETWEEN 60 AND 79) AS bucket_60_79,
               SUM(overall_score BETWEEN 80 AND 100) AS bucket_80_100
             FROM $table
             WHERE $dist_where",
            ARRAY_A
        );
        $dist = $score_distribution[0] ?? array();

        // ── Claude API cost estimate (current month) ─────────────────────────
        $month_key   = 'fi_token_usage_' . gmdate( 'Y_m' );
        $token_usage = get_option( $month_key, array( 'input' => 0, 'output' => 0, 'scans' => 0, 'by_model' => array() ) );

        // Per-model pricing (USD per 1M tokens) — source: https://www.anthropic.com/pricing
        $model_pricing = array(
            'claude-opus-4-20250514'    => array( 'input' => 15.00, 'output' => 75.00 ),
            'claude-sonnet-4-20250514'  => array( 'input' =>  3.00, 'output' => 15.00 ),
            'claude-haiku-4-5-20251001' => array( 'input' =>  0.80, 'output' =>  4.00 ),
            // Fallback for any unrecognised model slug: use Sonnet pricing as a
            // conservative middle estimate rather than showing $0.00.
            'unknown'                   => array( 'input' =>  3.00, 'output' => 15.00 ),
        );
        $fallback_pricing = $model_pricing['unknown'];

        $est_cost_usd = 0.0;
        $by_model     = isset( $token_usage['by_model'] ) ? $token_usage['by_model'] : array();

        if ( ! empty( $by_model ) ) {
            // New path: we have per-model breakdowns — compute exact cost per bucket.
            foreach ( $by_model as $model_slug => $usage ) {
                $pricing       = isset( $model_pricing[ $model_slug ] ) ? $model_pricing[ $model_slug ] : $fallback_pricing;
                $est_cost_usd += ( $usage['input']  / 1_000_000 * $pricing['input'] )
                               + ( $usage['output'] / 1_000_000 * $pricing['output'] );
            }
        } else {
            // Legacy path: old records have no per-model data.  We still have the
            // aggregate token counts; use Sonnet pricing as the most conservative
            // middle estimate and note this in the UI.
            $est_cost_usd = ( $token_usage['input']  / 1_000_000 * $fallback_pricing['input'] )
                          + ( $token_usage['output'] / 1_000_000 * $fallback_pricing['output'] );
        }
        $est_cost_usd = round( $est_cost_usd, 2 );

        // Top categories — with dominant pain point pulled from leads table
        $cat_where = $prepared_where( array( 'business_category IS NOT NULL' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $top_categories_raw = $wpdb->get_results(
            "SELECT business_category as category, COUNT(*) as count, AVG(overall_score) as avg_score
            FROM $table
            WHERE $cat_where
            GROUP BY business_category
            ORDER BY count DESC
            LIMIT 10",
            ARRAY_A
        );

        // ── Batch-fetch pain points for all top categories in one query ────────
        // Previously this was an N+1 loop (up to 20 queries for 10 categories).
        // Now: one query for all categories combined, grouped in PHP.
        $top_categories     = array();
        $pain_by_category   = array();
        $cat_names          = array_column( $top_categories_raw, 'category' );
        if ( ! empty( $cat_names ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $cat_names ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $pain_rows_all = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT business_category, pain_points
                     FROM $leads_table
                     WHERE business_category IN ($placeholders)
                       AND pain_points IS NOT NULL AND pain_points != '[]'
                     ORDER BY business_category, request_date DESC",
                    $cat_names
                ),
                ARRAY_A
            );
            // Group by category, keeping up to 10 most recent rows per category.
            foreach ( $pain_rows_all as $pr ) {
                $bc = $pr['business_category'];
                if ( ! isset( $pain_by_category[ $bc ] ) ) {
                    $pain_by_category[ $bc ] = array();
                }
                if ( count( $pain_by_category[ $bc ] ) < 10 ) {
                    $pain_by_category[ $bc ][] = $pr['pain_points'];
                }
            }
        }

        foreach ( $top_categories_raw as $cat ) {
            $cat_name      = $cat['category'];
            $dominant_pain = '';
            if ( ! empty( $pain_by_category[ $cat_name ] ) ) {
                $tally = array();
                foreach ( $pain_by_category[ $cat_name ] as $pain_json ) {
                    $pts = json_decode( $pain_json, true ) ?: array();
                    foreach ( $pts as $pt ) {
                        $lbl = $pt['category'] ?? '';
                        if ( $lbl ) {
                            $tally[ $lbl ] = ( $tally[ $lbl ] ?? 0 ) + 1;
                        }
                    }
                }
                if ( ! empty( $tally ) ) {
                    arsort( $tally );
                    $dominant_pain = array_key_first( $tally );
                }
            }
            $cat['dominant_pain'] = $dominant_pain;
            $top_categories[]     = $cat;
        }

        // Recent scans — include top pain point from leads table.
        // ── Batch-fetch pain points for all recent scans in one query ──────────
        // Previously this was an N+1 loop (up to 20 individual queries).
        $recent_where = $prepared_where();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $recent_scans_raw = $wpdb->get_results(
            "SELECT a.business_name, a.business_category, a.overall_score, a.scan_date, a.google_place_id
             FROM $table a
             WHERE $recent_where
             ORDER BY a.scan_date DESC
             LIMIT 20",
            ARRAY_A
        );

        $pain_by_place_id = array();
        $place_ids        = array_filter( array_column( $recent_scans_raw, 'google_place_id' ) );
        if ( ! empty( $place_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $place_ids ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $place_pain_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT google_place_id, pain_points
                     FROM $leads_table
                     WHERE google_place_id IN ($placeholders)
                       AND pain_points IS NOT NULL
                     ORDER BY google_place_id, request_date DESC",
                    array_values( $place_ids )
                ),
                ARRAY_A
            );
            // Keep only the most-recent (first) pain_points row per place_id.
            foreach ( $place_pain_rows as $pr ) {
                $pid = $pr['google_place_id'];
                if ( ! isset( $pain_by_place_id[ $pid ] ) ) {
                    $pain_by_place_id[ $pid ] = $pr['pain_points'];
                }
            }
        }

        $recent_scans = array();
        foreach ( $recent_scans_raw as $scan ) {
            $top_pain = '';
            $pid      = $scan['google_place_id'];
            if ( $pid && isset( $pain_by_place_id[ $pid ] ) ) {
                $pts = json_decode( $pain_by_place_id[ $pid ], true ) ?: array();
                // Sort by score ascending — lowest score = biggest pain
                usort( $pts, function( $a, $b ) { return ( $a['score'] ?? 100 ) - ( $b['score'] ?? 100 ); } );
                $top_pain = $pts[0]['category'] ?? '';
            }
            $scan['top_pain'] = $top_pain;
            $recent_scans[]   = $scan;
        }
        
        return array(
            'total_scans'        => intval($total_scans),
            'month_scans'        => intval($month_scans),
            'week_scans'         => intval($week_scans),
            'avg_score'          => floatval($avg_score),
            'month_delta'        => $month_delta,
            'trend'              => $trend,
            'score_trend'        => $score_trend,
            'conversion_rate'    => $conversion_rate,
            'total_leads'        => $total_leads,
            'score_distribution' => $dist,
            'token_usage'        => $token_usage,
            'est_cost_usd'       => $est_cost_usd,
            'top_categories'     => $top_categories,
            'recent_scans'       => $recent_scans,
            // Date filter metadata — passed back so the UI can show a "Filtered" badge.
            'is_filtered'        => $is_filtered,
            'date_from'          => $date_from,
            'date_to'            => $date_to,
        );
    }
    
    /**
     * Check whether a given IP address is on the owner's analytics exclusion list.
     *
     * Comparison is case-insensitive for IPv6 addresses. The blacklist is
     * stored as a newline-separated option value — we cache it as a static
     * variable so repeated calls within the same request don't hit the DB.
     *
     * @param  string $ip  IPv4 or IPv6 address to test.
     * @return bool        True if the IP should be blocked (scan returns polite error).
     */
    public static function is_ip_excluded( $ip ) {
        static $blacklisted_ips = null;

        if ( $blacklisted_ips === null ) {
            $raw = get_option( 'fi_analytics_ip_blacklist', '' );
            if ( empty( $raw ) ) {
                $blacklisted_ips = array();
            } else {
                $blacklisted_ips = array_filter(
                    array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) )
                );
                // Normalise to lowercase for case-insensitive IPv6 comparison.
                $blacklisted_ips = array_map( 'strtolower', $blacklisted_ips );
            }
        }

        return in_array( strtolower( trim( $ip ) ), $blacklisted_ips, true );
    }

    /**
     * Get client IP address.
     *
     * Only REMOTE_ADDR is used. HTTP_CLIENT_IP and HTTP_X_FORWARDED_FOR are
     * user-controlled request headers — trusting them would let anyone spoof
     * their IP to bypass the exclusion list or corrupt analytics data.
     *
     * If your site sits behind a trusted proxy (e.g. Cloudflare, a load
     * balancer), configure the proxy to overwrite REMOTE_ADDR at the network
     * layer rather than relying on forwarded headers.
     */
    public static function get_client_ip(): string {
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    }
    
    /**
     * Get leads dashboard data (v1.6.0)
     * Returns lead counts and recent leads
     */
    public static function get_leads_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'fi_leads';
        
        // Lead counts by status
        $status_counts = $wpdb->get_results(
            "SELECT follow_up_status, COUNT(*) as count 
            FROM $table 
            GROUP BY follow_up_status",
            ARRAY_A
        );
        
        $counts = array(
            'new' => 0,
            'contacted' => 0,
            'qualified' => 0,
            'closed' => 0,
            'lost' => 0,
        );
        
        foreach ($status_counts as $row) {
            $counts[$row['follow_up_status']] = intval($row['count']);
        }
        
        // New leads (last 24 hours) — no dynamic values, so no prepare() needed.
        $new_leads = $wpdb->get_results(
            "SELECT id, business_name, business_category, business_website,
                    business_phone, business_email, business_address,
                    visitor_email, overall_score, pain_points, request_date,
                    follow_up_status, follow_up_notes, google_place_id,
                    ip_address, report_generated_at
             FROM $table
             WHERE request_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY request_date DESC",
            ARRAY_A
        );
        
        // All leads (most recent first) — exclude report_html (LONGTEXT) to
        // prevent loading potentially MBs of HTML into memory on every analytics
        // page load. The report viewer fetches it on-demand via fi_view_report.
        $all_leads = $wpdb->get_results(
            "SELECT id, business_name, business_category, business_website,
                    business_phone, business_email, business_address,
                    visitor_email, overall_score, pain_points, request_date,
                    follow_up_status, follow_up_notes, google_place_id,
                    ip_address, report_generated_at
             FROM $table
             ORDER BY request_date DESC
             LIMIT 50",
            ARRAY_A
        );
        
        // This month's leads — use UTC to match stored timestamps.
        $month_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE MONTH(request_date) = %d AND YEAR(request_date) = %d",
            (int) gmdate('n'),
            (int) gmdate('Y')
        ));
        
        return array(
            'counts'       => $counts,
            'new_leads'    => $new_leads,
            'all_leads'    => $all_leads,
            'month_leads'  => intval($month_leads),
            'total_leads'  => array_sum($counts),
        );
    }
    
    /**
     * Get paginated, filtered leads for the All Leads tab.
     *
     * @param array $args {
     *   @type string $search   Search term matched against business_name and visitor_email.
     *   @type string $status   Status filter: 'all' or a valid status slug.
     *   @type int    $page     1-based page number.
     *   @type int    $per_page Rows per page (default 20).
     * }
     * @return array { leads: array, total: int, pages: int }
     */
    public static function get_leads_paged( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fi_leads';

        $search   = isset( $args['search'] )   ? sanitize_text_field( $args['search'] ) : '';
        $status   = isset( $args['status'] )   ? sanitize_key( $args['status'] )        : 'all';
        $page     = isset( $args['page'] )     ? max( 1, intval( $args['page'] ) )      : 1;
        $per_page = isset( $args['per_page'] ) ? max( 1, intval( $args['per_page'] ) )  : 20;
        $offset   = ( $page - 1 ) * $per_page;

        // Sort — allowlist column names and direction to prevent injection.
        $valid_columns = array( 'request_date', 'overall_score', 'business_name', 'follow_up_status' );
        $orderby       = isset( $args['orderby'] ) && in_array( $args['orderby'], $valid_columns, true )
            ? $args['orderby']
            : 'request_date';
        $order         = isset( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql     = "`$orderby` $order";

        $valid_statuses = array( 'new', 'contacted', 'qualified', 'closed', 'lost' );
        $where_parts    = array( '1=1' );
        $params         = array();

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_parts[] = '( business_name LIKE %s OR visitor_email LIKE %s OR business_category LIKE %s )';
            $params[]      = $like;
            $params[]      = $like;
            $params[]      = $like;
        }

        if ( $status !== 'all' && in_array( $status, $valid_statuses, true ) ) {
            $where_parts[] = 'follow_up_status = %s';
            $params[]      = $status;
        }

        $where_sql = implode( ' AND ', $where_parts );

        // Total count for pagination
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total     = (int) ( empty( $params )
            ? $wpdb->get_var( $count_sql )
            : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );

        // Paged rows — only use prepare() if there are WHERE params (avoids WP 6.1+ warnings)
        $rows_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY $order_sql LIMIT %d OFFSET %d";
        if ( empty( $params ) ) {
            $leads = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM $table ORDER BY $order_sql LIMIT %d OFFSET %d", $per_page, $offset ),
                ARRAY_A
            );
        } else {
            $rows_params = array_merge( $params, array( $per_page, $offset ) );
            $leads       = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );
        }

        return array(
            'leads'   => $leads ?: array(),
            'total'   => $total,
            'pages'   => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
            'orderby' => $orderby,
            'order'   => $order,
        );
    }

    /**
     * Get leads by status (v1.6.0)
     * 
     * @param string $status  Status filter (new, contacted, qualified, closed, lost)
     * @return array          Array of lead records
     */
    public static function get_leads_by_status($status = 'all') {
        global $wpdb;
        $table = $wpdb->prefix . 'fi_leads';
        
        if ($status === 'all') {
            return $wpdb->get_results(
                "SELECT * FROM $table ORDER BY request_date DESC",
                ARRAY_A
            );
        }
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE follow_up_status = %s ORDER BY request_date DESC",
                $status
            ),
            ARRAY_A
        );
    }
    
    /**
     * Update lead status (v1.6.0)
     * 
     * @param int    $lead_id  Lead ID
     * @param string $status   New status
     * @return bool            Success or failure
     */
    public static function update_lead_status($lead_id, $status) {
        global $wpdb;
        
        $valid_statuses = array('new', 'contacted', 'qualified', 'closed', 'lost');
        if (!in_array($status, $valid_statuses)) {
            return false;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'fi_leads',
            array('follow_up_status' => $status),
            array('id' => intval($lead_id)),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }
    
    /**
     * Add or update lead notes (v1.6.0)
     * 
     * @param int    $lead_id  Lead ID
     * @param string $notes    Notes text
     * @return bool            Success or failure
     */
    public static function update_lead_notes($lead_id, $notes) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'fi_leads',
            array('follow_up_notes' => sanitize_textarea_field($notes)),
            array('id' => intval($lead_id)),
            array('%s'),
            array('%d')
        );

        // $wpdb->update() returns 0 (falsy) when the value is unchanged — that
        // is still a success. Only false means an actual DB error.
        return $result !== false;
    }
}