<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Analytics_Page
 * Renders the Market Leads admin page and its tabs.
 * Analytics tab: interpretive briefing, no vanity metrics.
 * Market Intel tab: action launcher with progressive data thresholds.
 */
class FI_Analytics_Page {

    // Scan thresholds for unlocking Market Intel actions
    const THRESHOLD_BASIC    = 10;   // basic signals readable
    const THRESHOLD_ACTIONS  = 25;   // enough data to generate useful outputs
    const THRESHOLD_FULL     = 50;   // full forecasting + SEO strategy
    const THRESHOLD_ASSET    = 100;  // data asset tier — defensible competitive advantage
    const THRESHOLD_PLATFORM = 250;  // platform tier — the data is the business

    public static function render(): void {
        $tab_raw = sanitize_key( $_GET['tab'] ?? 'leads' );

        if ( ! FI_Premium::is_active() ) {
            self::render_free_teaser();
            return;
        }

        $tabs = [
            'lead-form'   => 'Lead Form',
            'leads'       => 'Leads',
            'analytics'   => 'Analytics',
            'market-intel'=> '🧠 Market Intel',
            'bulk-scan'   => '⚡ Bulk Scan',
            'reviews'     => '⭐ Reviews',
        ];

        $tab = array_key_exists( $tab_raw, $tabs ) ? $tab_raw : 'leads';
        ?>
        <div class="wrap fi-market-intel-wrap">
            <h1>Market Leads</h1>
            <nav class="fi-tabs">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=fi-market-intel&tab=$key" ) ); ?>"
                   class="fi-tab<?php echo $tab === $key ? ' fi-tab--active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="fi-tab-content">
                <?php if ( $tab === 'lead-form' ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'fi_save_settings' ); ?>
                    <input type="hidden" name="action" value="fi_save_settings">
                    <input type="hidden" name="_tab"   value="lead-form">
                    <?php FI_Admin_Tab_Lead_Form::render(); ?>
                </form>
                <?php else :
                switch ( $tab ) {
                    case 'leads':        self::tab_leads();        break;
                    case 'analytics':    self::tab_analytics();    break;
                    case 'market-intel': self::tab_market_intel(); break;
                    case 'bulk-scan':    self::tab_bulk_scan();    break;
                    case 'reviews':      FI_Admin_Tab_Reviews::render(); break;
                }
                endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Tab: Leads
    // =========================================================================

    private static function tab_leads(): void {
        $search   = sanitize_text_field( $_GET['search'] ?? '' );
        $now_ts   = current_time( 'timestamp' );
        $hot_threshold = $now_ts - ( 24 * 60 * 60 );

        // ── Pagination ────────────────────────────────────────────────────
        $per_page       = 50;
        $leads_page     = max( 1, (int) ( $_GET['leads_page']     ?? 1 ) );
        $prospects_page = max( 1, (int) ( $_GET['prospects_page'] ?? 1 ) );

        $leads_total     = FI_DB::count_leads( [ 'type' => 'lead',     'search' => $search ] );
        $prospects_total = FI_DB::count_leads( [ 'type' => 'prospect', 'search' => $search ] );
        $leads_pages     = max( 1, (int) ceil( $leads_total     / $per_page ) );
        $prospects_pages = max( 1, (int) ceil( $prospects_total / $per_page ) );

        // Clamp to valid range after count is known
        $leads_page     = min( $leads_page,     $leads_pages );
        $prospects_page = min( $prospects_page, $prospects_pages );

        // ── Counts ────────────────────────────────────────────────────────
        $total_leads     = FI_DB::total_leads();
        $total_prospects = FI_DB::total_prospects();
        $this_month      = FI_DB::leads_this_month();

        // ── Fetch current page ────────────────────────────────────────────
        $leads     = FI_DB::get_leads( [
            'type'   => 'lead',
            'search' => $search,
            'limit'  => $per_page,
            'offset' => ( $leads_page - 1 ) * $per_page,
        ] );
        $prospects = FI_DB::get_leads( [
            'type'   => 'prospect',
            'search' => $search,
            'limit'  => $per_page,
            'offset' => ( $prospects_page - 1 ) * $per_page,
        ] );

        // ── Sort within page ──────────────────────────────────────────────
        $sort_fn = fn( $a, $b ) => strtotime( $b->created_at ) <=> strtotime( $a->created_at );
        usort( $leads,     $sort_fn );
        usort( $prospects, $sort_fn );

        // Batch-load scans and share URLs
        $all_scan_ids = array_unique( array_merge(
            array_column( $leads,     'scan_id' ),
            array_column( $prospects, 'scan_id' )
        ) );
        $scans_by_id = $shares_by_id = [];
        if ( ! empty( $all_scan_ids ) ) {
            $scans_by_id  = FI_DB::get_scans_by_ids( $all_scan_ids );
            $shares_by_id = FI_DB::get_active_shares_by_scan_ids( $all_scan_ids );
        }

        // Batch-load Reviews records for closed leads — avoids N+1 per row.
        // Builds a map of lead_id => review_record so the pipeline table can
        // render the correct button without an individual DB call per row.
        $reviews_by_lead_id = [];
        if ( class_exists( 'FI_Reviews' ) ) {
            $all_lead_ids = array_merge(
                array_column( $leads,     'id' ),
                array_column( $prospects, 'id' )
            );
            $closed_lead_ids = [];
            foreach ( array_merge( $leads, $prospects ) as $r ) {
                if ( $r->status === 'closed' ) {
                    $closed_lead_ids[] = (int) $r->id;
                }
            }
            if ( ! empty( $closed_lead_ids ) ) {
                global $wpdb;
                $placeholders = implode( ',', array_fill( 0, count( $closed_lead_ids ), '%d' ) );
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, lead_id FROM " . FI_Reviews::table() . " WHERE lead_id IN ($placeholders)",
                        ...$closed_lead_ids
                    )
                );
                foreach ( $rows as $rv ) {
                    $reviews_by_lead_id[ (int) $rv->lead_id ] = (int) $rv->id;
                }
            }
        }
        ?>

        <!-- ── Stats bar ──────────────────────────────────────────────────── -->
        <div class="fi-stats-row" style="margin-bottom:20px;">
            <?php self::stat( 'LEADS',      $total_leads,     'Organic captures' ); ?>
            <?php self::stat( 'THIS MONTH', $this_month,      'New leads' ); ?>
            <?php self::stat( 'PROSPECTS',  $total_prospects, 'Bulk imported' ); ?>
        </div>

        <!-- ── Toolbar ────────────────────────────────────────────────────── -->
        <div class="fi-pipeline-toolbar">
            <form method="get" class="fi-pipeline-search-form">
                <input type="hidden" name="page" value="fi-market-intel">
                <input type="hidden" name="tab"  value="leads">
                <input type="text"   name="search" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search businesses…" class="fi-input fi-input-sm">
            </form>
            <button type="button" class="button" id="fi-export-csv">Export CSV</button>
        </div>

        <!-- ── Prospects section ──────────────────────────────────────────── -->
        <div class="fi-pipeline-section">
            <div class="fi-pipeline-section-header">
                <h3 class="fi-pipeline-section-title">
                    Prospects
                    <span class="fi-pipeline-count"><?php echo number_format( $prospects_total ); ?></span>
                </h3>
            </div>

            <?php if ( empty( $prospects ) ) : ?>
            <div class="fi-pipeline-empty">
                No prospects yet. Use <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-market-intel&tab=bulk-scan' ) ); ?>">Bulk Scan</a> to import businesses.
            </div>
            <?php else : ?>
            <table class="fi-pipeline-table" id="fi-prospects-table">
                <thead>
                    <tr>
                        <th data-sort="name" data-table="fi-prospects-table">Business</th>
                        <th>Phone</th>
                        <th>Website</th>
                        <th data-sort="score" data-table="fi-prospects-table">Score</th>
                        <th data-sort="pain" data-table="fi-prospects-table">Top Pain Points</th>
                        <th data-sort="status" data-table="fi-prospects-table">Status</th>
                        <th>Follow-up</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $prospects as $rec ) :
                    $sc   = FI_Utils::score_color( (int) $rec->overall_score );
                    $scan = $scans_by_id[ $rec->scan_id ] ?? null;
                    $rurl = $shares_by_id[ $rec->scan_id ] ?? '';
                    $pain = array_filter( array_map( 'trim', explode( ',', $rec->pain_points ?? '' ) ) );
                    $is_hot = strtotime( $rec->created_at ) >= $hot_threshold;
                ?>
                <tr class="fi-pipeline-row<?php echo $is_hot ? ' fi-pipeline-row--hot' : ''; ?>" data-lead-id="<?php echo (int) $rec->id; ?>">
                    <td class="fi-pipeline-cell-name" data-sort-name="<?php echo esc_attr( $rec->business_name ); ?>">
                        <?php
                        $snapshot_url = add_query_arg( [
                            'action' => 'fi_view_lead_snapshot',
                            'id'     => (int) $rec->id,
                            'nonce'  => wp_create_nonce( 'fi_admin_nonce' ),
                        ], admin_url( 'admin-ajax.php' ) );
                        $captured = wp_date( 'M j, Y', strtotime( $rec->created_at ) );
                        $has_snap = ! empty( $rec->report_snapshot );
                        ?>
                        <a href="<?php echo esc_url( $snapshot_url ); ?>" class="fi-pipeline-name-link fi-report-popup"
                           data-url="<?php echo esc_url( $snapshot_url ); ?>"
                           data-title="<?php echo esc_attr( $rec->business_name ); ?>">
                            <?php echo esc_html( $rec->business_name ); ?>
                        </a>
                        <?php if ( $is_hot ) echo '<span class="fi-pipeline-badge fi-pipeline-badge--hot">🔥 New</span>'; ?>
                        <div class="fi-pipeline-sub"><?php echo esc_html( $captured ); ?><?php echo $has_snap ? ' · snapshot' : ''; ?></div>
                    </td>
                    <td class="fi-pipeline-cell-phone">
                        <?php if ( $scan && $scan->phone ) echo '<a href="tel:' . esc_attr( $scan->phone ) . '" class="fi-pipeline-link">' . esc_html( $scan->phone ) . '</a>'; ?>
                    </td>
                    <td class="fi-pipeline-cell-website">
                        <?php if ( $scan && $scan->website ) {
                            $host = preg_replace( '/^www\./i', '', parse_url( $scan->website, PHP_URL_HOST ) ?? $scan->website );
                            echo '<a href="' . esc_url( $scan->website ) . '" target="_blank" rel="noopener" class="fi-pipeline-link fi-pipeline-domain" title="' . esc_attr( $scan->website ) . '">' . esc_html( $host ) . '</a>';
                        } ?>
                    </td>
                    <td class="fi-pipeline-cell-score">
                        <span class="fi-score-pill" style="background:<?php echo esc_attr( $sc ); ?>1a;color:<?php echo esc_attr( $sc ); ?>;">
                            <?php echo (int) $rec->overall_score; ?>
                        </span>
                    </td>
                    <td class="fi-pipeline-cell-pain">
                        <?php if ( ! empty( $pain ) ) :
                            $top = array_slice( $pain, 0, 2 );
                            foreach ( $top as $p ) echo '<span class="fi-pain-label">' . esc_html( trim( explode( '(', $p )[0] ) ) . '</span>';
                            if ( count( $pain ) > 2 ) echo '<span class="fi-pain-more">+' . ( count( $pain ) - 2 ) . '</span>';
                        endif; ?>
                    </td>
                    <td class="fi-pipeline-cell-status">
                        <?php
                        $statuses = [ 'uncontacted' => 'Uncontacted', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'closed' => 'Closed', 'lost' => 'Lost' ];
                        $cur_status = $rec->status ?: 'uncontacted';
                        echo '<select class="fi-status-select fi-select" data-lead-id="' . (int) $rec->id . '">';
                        foreach ( $statuses as $val => $label ) {
                            echo '<option value="' . esc_attr( $val ) . '"' . selected( $cur_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
                        }
                        echo '</select>';
                        ?>
                    </td>
                    <td class="fi-pipeline-cell-followup">
                        <?php
                        $fup = $rec->follow_up_date ?: '';
                        $is_overdue = $fup && strtotime( $fup ) < strtotime( current_time( 'Y-m-d' ) );
                        echo '<input type="date" class="fi-followup-date fi-input fi-input-sm' . ( $is_overdue ? ' fi-overdue' : '' ) . '" '
                           . 'data-lead-id="' . (int) $rec->id . '" '
                           . 'value="' . esc_attr( $fup ) . '" '
                           . 'title="' . ( $is_overdue ? 'Overdue' : 'Set follow-up date' ) . '">';
                        ?>
                    </td>
                    <td class="fi-pipeline-cell-notes">
                        <textarea class="fi-notes-field fi-input fi-input-sm" rows="2"
                            data-lead-id="<?php echo (int) $rec->id; ?>"
                            placeholder="Notes…"><?php echo esc_textarea( $rec->notes ?? '' ); ?></textarea>
                    </td>
                    <td class="fi-pipeline-cell-actions">
                        <button type="button" class="button button-small fi-gen-pitch-btn"
                            data-lead-id="<?php echo (int) $rec->id; ?>"
                            title="Generate cold outreach email">✉ Pitch</button>
                        <?php if ( $rec->status === 'closed' && class_exists( 'FI_Reviews' ) ) :
                            $rv_record_id = $reviews_by_lead_id[ (int) $rec->id ] ?? 0;
                            if ( $rv_record_id ) :
                                $rv_url = admin_url( 'admin.php?page=fi-market-intel&tab=reviews&review_id=' . $rv_record_id );
                        ?>
                        <a href="<?php echo esc_url( $rv_url ); ?>"
                           class="button button-small fi-reviews-manage-btn">⭐ Reviews</a>
                        <?php else : ?>
                        <button type="button" class="button button-small fi-reviews-setup-btn"
                                data-lead-id="<?php echo (int) $rec->id; ?>"
                                title="Create a Reviews record for this client">⭐ Set Up Reviews</button>
                        <?php endif; endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php if ( $prospects_pages > 1 ) : ?>
            <div class="fi-pipeline-pager">
                <?php for ( $p = 1; $p <= $prospects_pages; $p++ ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'prospects_page' => $p, 'leads_page' => $leads_page, 'search' => $search ] ) ); ?>"
                   class="fi-pager-btn<?php echo $p === $prospects_page ? ' fi-pager-btn--active' : ''; ?>">
                    <?php echo (int) $p; ?>
                </a>
                <?php endfor; ?>
                <span class="fi-pager-meta"><?php echo (int) $prospects_total; ?> total</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Leads section ──────────────────────────────────────────────── -->
        <div class="fi-pipeline-section" style="margin-top:32px;">
            <div class="fi-pipeline-section-header">
                <h3 class="fi-pipeline-section-title">
                    Leads
                    <span class="fi-pipeline-count"><?php echo number_format( $leads_total ); ?></span>
                    <span class="fi-pipeline-section-hint">Raised their hand, report already in their inbox</span>
                </h3>
            </div>

            <?php if ( empty( $leads ) ) : ?>
            <div class="fi-pipeline-empty">
                No leads yet. Leads are captured when a business owner submits their email through the scanner.
            </div>
            <?php else : ?>
            <table class="fi-pipeline-table" id="fi-leads-table">
                <thead>
                    <tr>
                        <th data-sort="name" data-table="fi-leads-table">Business</th>
                        <th data-sort="email" data-table="fi-leads-table">Email</th>
                        <th>Phone</th>
                        <th>Website</th>
                        <th data-sort="score" data-table="fi-leads-table">Score</th>
                        <th data-sort="pain" data-table="fi-leads-table">Top Pain Points</th>
                        <th data-sort="status" data-table="fi-leads-table">Status</th>
                        <th>Follow-up</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $leads as $rec ) :
                    $sc   = FI_Utils::score_color( (int) $rec->overall_score );
                    $scan = $scans_by_id[ $rec->scan_id ] ?? null;
                    $rurl = $shares_by_id[ $rec->scan_id ] ?? '';
                    $pain = array_filter( array_map( 'trim', explode( ',', $rec->pain_points ?? '' ) ) );
                    $is_hot = strtotime( $rec->created_at ) >= $hot_threshold;
                ?>
                <tr class="fi-pipeline-row<?php echo $is_hot ? ' fi-pipeline-row--hot' : ''; ?>" data-lead-id="<?php echo (int) $rec->id; ?>">
                    <td class="fi-pipeline-cell-name" data-sort-name="<?php echo esc_attr( $rec->business_name ); ?>">
                        <?php
                        $snapshot_url = add_query_arg( [
                            'action' => 'fi_view_lead_snapshot',
                            'id'     => (int) $rec->id,
                            'nonce'  => wp_create_nonce( 'fi_admin_nonce' ),
                        ], admin_url( 'admin-ajax.php' ) );
                        $captured = wp_date( 'M j, Y', strtotime( $rec->created_at ) );
                        $has_snap = ! empty( $rec->report_snapshot );
                        ?>
                        <a href="<?php echo esc_url( $snapshot_url ); ?>" class="fi-pipeline-name-link fi-report-popup"
                           data-url="<?php echo esc_url( $snapshot_url ); ?>"
                           data-title="<?php echo esc_attr( $rec->business_name ); ?>">
                            <?php echo esc_html( $rec->business_name ); ?>
                        </a>
                        <?php if ( $is_hot ) echo '<span class="fi-pipeline-badge fi-pipeline-badge--hot">🔥 New</span>'; ?>
                        <div class="fi-pipeline-sub"><?php echo esc_html( $captured ); ?><?php echo $has_snap ? ' · snapshot' : ''; ?></div>
                    </td>
                    <td class="fi-pipeline-cell-email">
                        <?php if ( $rec->email ) echo '<a href="mailto:' . esc_attr( $rec->email ) . '" class="fi-pipeline-link">' . esc_html( $rec->email ) . '</a>'; ?>
                    </td>
                    <td class="fi-pipeline-cell-phone">
                        <?php if ( $scan && $scan->phone ) echo '<a href="tel:' . esc_attr( $scan->phone ) . '" class="fi-pipeline-link">' . esc_html( $scan->phone ) . '</a>'; ?>
                    </td>
                    <td class="fi-pipeline-cell-website">
                        <?php if ( $scan && $scan->website ) {
                            $host = preg_replace( '/^www\./i', '', parse_url( $scan->website, PHP_URL_HOST ) ?? $scan->website );
                            echo '<a href="' . esc_url( $scan->website ) . '" target="_blank" rel="noopener" class="fi-pipeline-link fi-pipeline-domain" title="' . esc_attr( $scan->website ) . '">' . esc_html( $host ) . '</a>';
                        } ?>
                    </td>
                    <td class="fi-pipeline-cell-score">
                        <span class="fi-score-pill" style="background:<?php echo esc_attr( $sc ); ?>1a;color:<?php echo esc_attr( $sc ); ?>;">
                            <?php echo (int) $rec->overall_score; ?>
                        </span>
                    </td>
                    <td class="fi-pipeline-cell-pain">
                        <?php if ( ! empty( $pain ) ) :
                            $top = array_slice( $pain, 0, 2 );
                            foreach ( $top as $p ) echo '<span class="fi-pain-label">' . esc_html( trim( explode( '(', $p )[0] ) ) . '</span>';
                            if ( count( $pain ) > 2 ) echo '<span class="fi-pain-more">+' . ( count( $pain ) - 2 ) . '</span>';
                        endif; ?>
                    </td>
                    <td class="fi-pipeline-cell-status">
                        <?php
                        $statuses = [ 'new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'closed' => 'Closed', 'lost' => 'Lost' ];
                        $cur_status = $rec->status ?: 'new';
                        echo '<select class="fi-status-select fi-select" data-lead-id="' . (int) $rec->id . '">';
                        foreach ( $statuses as $val => $label ) {
                            echo '<option value="' . esc_attr( $val ) . '"' . selected( $cur_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
                        }
                        echo '</select>';
                        ?>
                    </td>
                    <td class="fi-pipeline-cell-followup">
                        <?php
                        $fup = $rec->follow_up_date ?: '';
                        $is_overdue = $fup && strtotime( $fup ) < strtotime( current_time( 'Y-m-d' ) );
                        echo '<input type="date" class="fi-followup-date fi-input fi-input-sm' . ( $is_overdue ? ' fi-overdue' : '' ) . '" '
                           . 'data-lead-id="' . (int) $rec->id . '" '
                           . 'value="' . esc_attr( $fup ) . '" '
                           . 'title="' . ( $is_overdue ? 'Overdue' : 'Set follow-up date' ) . '">';
                        ?>
                    </td>
                    <td class="fi-pipeline-cell-notes">
                        <textarea class="fi-notes-field fi-input fi-input-sm" rows="2"
                            data-lead-id="<?php echo (int) $rec->id; ?>"
                            placeholder="Notes…"><?php echo esc_textarea( $rec->notes ?? '' ); ?></textarea>
                    </td>
                    <td class="fi-pipeline-cell-actions">
                        <button type="button" class="button button-small fi-gen-reply-btn"
                            data-lead-id="<?php echo (int) $rec->id; ?>"
                            title="Draft a warm follow-up reply">✉ Reply Draft</button>
                        <?php if ( $rec->status === 'closed' && class_exists( 'FI_Reviews' ) ) :
                            $rv_record_id = $reviews_by_lead_id[ (int) $rec->id ] ?? 0;
                            if ( $rv_record_id ) :
                                $rv_url = admin_url( 'admin.php?page=fi-market-intel&tab=reviews&review_id=' . $rv_record_id );
                        ?>
                        <a href="<?php echo esc_url( $rv_url ); ?>"
                           class="button button-small fi-reviews-manage-btn">⭐ Reviews</a>
                        <?php else : ?>
                        <button type="button" class="button button-small fi-reviews-setup-btn"
                                data-lead-id="<?php echo (int) $rec->id; ?>"
                                title="Create a Reviews record for this client">⭐ Set Up Reviews</button>
                        <?php endif; endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php if ( $leads_pages > 1 ) : ?>
            <div class="fi-pipeline-pager">
                <?php for ( $p = 1; $p <= $leads_pages; $p++ ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'leads_page' => $p, 'prospects_page' => $prospects_page, 'search' => $search ] ) ); ?>"
                   class="fi-pager-btn<?php echo $p === $leads_page ? ' fi-pager-btn--active' : ''; ?>">
                    <?php echo (int) $p; ?>
                </a>
                <?php endfor; ?>
                <span class="fi-pager-meta"><?php echo (int) $leads_total; ?> total</span>
            </div>
            <?php endif; ?>
        </div>

        <?php
    }

    // =========================================================================
    // Tab: Analytics — interpretive briefing, no vanity metrics
    // =========================================================================

    private static function tab_analytics(): void {
        $b = FI_Analytics::get_briefing();

        $total       = $b['total'];
        $avg         = $b['avg'];
        $dist        = $b['dist'];
        $high_need   = $b['high_need'];
        $total_leads = $b['total_leads'];
        $conv_rate   = $b['conv_rate'];
        $last7       = $b['last7'];
        $last30      = $b['last30'];
        $weakest     = $b['weakest'];
        $converting  = $b['converting'];
        $cities      = $b['cities'];
        $pain_by_ind = $b['pain_by_ind'];
        $pain_all    = $b['pain_all'];

        if ( $total === 0 ) : ?>
        <div class="fi-card fi-card--empty">
            <h3>No scan data yet.</h3>
            <p>Add the <code>[f_insights]</code> shortcode to a page, run some scans, and your market briefing will appear here.</p>
        </div>
        <?php return; endif; ?>

        <?php if ( $total < self::THRESHOLD_BASIC ) : ?>
        <div class="fi-intel-nudge fi-intel-nudge--top">
            <strong>Building your market picture.</strong> You have <?php echo (int) $total; ?> scan<?php echo $total !== 1 ? 's' : ''; ?>.
            At <?php echo self::THRESHOLD_BASIC; ?> scans the market signals become meaningful.
            <?php echo (int) ( self::THRESHOLD_BASIC - $total ); ?> more to go.
        </div>
        <?php endif; ?>

        <!-- ── Market Health ──────────────────────────────────────────────── -->
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">Market Health</h2>
            <div class="fi-briefing-grid fi-briefing-grid--3">

                <div class="fi-briefing-card">
                    <div class="fi-briefing-card-value fi-score-colored" style="color:<?php echo esc_attr( FI_Utils::score_color( (int) $avg ) ); ?>">
                        <?php echo esc_html( $avg ); ?>/100
                    </div>
                    <div class="fi-briefing-card-label">Market Average Score</div>
                    <?php if ( $b['market_signal'] ) : ?>
                    <div class="fi-briefing-card-signal"><?php echo esc_html( $b['market_signal'] ); ?></div>
                    <?php endif; ?>
                </div>

                <div class="fi-briefing-card">
                    <div class="fi-briefing-card-value" style="color:#dc2626;">
                        <?php echo (int) $high_need; ?>
                        <span class="fi-briefing-card-denom">/ <?php echo (int) $total; ?></span>
                    </div>
                    <div class="fi-briefing-card-label">Businesses Below 60</div>
                    <div class="fi-briefing-card-signal">
                        <?php
                        $pct = $total ? round( ( $high_need / $total ) * 100 ) : 0;
                        echo esc_html( $pct . '% of your scanned market has significant, actionable gaps; these are your warmest cold prospects.' );
                        ?>
                    </div>
                </div>

                <div class="fi-briefing-card">
                    <?php
                    $weak_pct    = $total ? round( ( (int) $dist->weak    / $total ) * 100 ) : 0;
                    $strong_pct  = $total ? round( ( (int) $dist->strong  / $total ) * 100 ) : 0;
                    ?>
                    <div class="fi-briefing-card-value"><?php echo absint( $weak_pct ); ?>%</div>
                    <div class="fi-briefing-card-label">Weak Profiles (&lt;60)</div>
                    <div class="fi-briefing-card-signal">
                        <?php echo esc_html( $strong_pct . '% are strong (80+). The ' . $weak_pct . '% that aren\'t are the ones most likely to say yes to help.' ); ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Lead Conversion ───────────────────────────────────────────── -->
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">Your Lead Engine</h2>
            <div class="fi-briefing-grid fi-briefing-grid--3">

                <div class="fi-briefing-card">
                    <div class="fi-briefing-card-value"><?php echo esc_html( $conv_rate ); ?>%</div>
                    <div class="fi-briefing-card-label">Scan → Lead Conversion</div>
                    <?php if ( $b['conv_signal'] ) : ?>
                    <div class="fi-briefing-card-signal"><?php echo esc_html( $b['conv_signal'] ); ?></div>
                    <?php elseif ( $total < self::THRESHOLD_BASIC ) : ?>
                    <div class="fi-briefing-card-signal">Needs more data to interpret. I will not lie like the others.</div>
                    <?php endif; ?>
                </div>

                <div class="fi-briefing-card">
                    <div class="fi-briefing-card-value"><?php echo (int) $last7; ?></div>
                    <div class="fi-briefing-card-label">Scans This Week</div>
                    <?php if ( $b['velocity_signal'] ) : ?>
                    <div class="fi-briefing-card-signal"><?php echo esc_html( $b['velocity_signal'] ); ?></div>
                    <?php else : ?>
                    <div class="fi-briefing-card-signal"><?php echo (int) $last30; ?> in the last 30 days.</div>
                    <?php endif; ?>
                </div>

                <div class="fi-briefing-card">
                    <div class="fi-briefing-card-value"><?php echo (int) $total_leads; ?></div>
                    <div class="fi-briefing-card-label">Total Leads Captured</div>
                    <div class="fi-briefing-card-signal">
                        <?php
                        if ( $total_leads === 0 ) {
                            echo 'No email captures yet. Enable the lead form in Lead Form settings.';
                        } else {
                            $per_lead = $total_leads ? round( $total / $total_leads, 1 ) : 0;
                            echo esc_html( 'You generate one lead for every ' . $per_lead . ' scans. Each lead is a business owner who already knows their score.' );
                        }
                        ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Opportunity Map ───────────────────────────────────────────── -->
        <?php if ( ! empty( $weakest ) || ! empty( $converting ) ) : ?>
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">Where the Opportunity Is</h2>

            <?php if ( $b['opportunity_signal'] ) : ?>
            <div class="fi-briefing-insight">
                <?php echo esc_html( $b['opportunity_signal'] ); ?>
            </div>
            <?php endif; ?>

            <div class="fi-briefing-grid fi-briefing-grid--2">

                <?php if ( ! empty( $weakest ) ) : ?>
                <div class="fi-briefing-table-wrap">
                    <div class="fi-briefing-table-title">Weakest Categories <span class="fi-hint-inline">(lowest avg score with ≥2 scans)</span></div>
                    <table class="fi-briefing-table">
                        <thead><tr><th>Category</th><th>Scans</th><th>Avg Score</th><th>Signal</th></tr></thead>
                        <tbody>
                        <?php foreach ( $weakest as $row ) :
                            $signal = $row->avg_score < 45 ? '🔴 Critical' : ( $row->avg_score < 60 ? '🟠 Weak' : '🟡 Average' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( ucfirst( $row->category ) ); ?></td>
                            <td><?php echo (int) $row->scans; ?></td>
                            <td><strong style="color:<?php echo esc_attr( FI_Utils::score_color( (int) $row->avg_score ) ); ?>"><?php echo esc_html( $row->avg_score ); ?></strong></td>
                            <td><?php echo esc_html( $signal ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $converting ) ) : ?>
                <div class="fi-briefing-table-wrap">
                    <div class="fi-briefing-table-title">Highest Converting Categories <span class="fi-hint-inline">(scan → lead rate)</span></div>
                    <table class="fi-briefing-table">
                        <thead><tr><th>Category</th><th>Scans</th><th>Leads</th><th>Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ( $converting as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( ucfirst( $row->category ) ); ?></td>
                            <td><?php echo (int) $row->scans; ?></td>
                            <td><?php echo (int) $row->leads; ?></td>
                            <td><strong><?php echo esc_html( $row->conversion_rate ); ?>%</strong></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="fi-hint" style="margin-top:8px;">High conversion + low score = the best possible cold outreach target.</p>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── Pain Points ───────────────────────────────────────────────── -->
        <?php if ( ! empty( $pain_all ) ) : ?>
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">What the Market Is Telling You</h2>
            <p class="fi-briefing-intro">
                These are the most frequently flagged problems across all leads captured. Each one is a service you can sell.
            </p>
            <p class="fi-hint" style="margin-bottom:12px;">
                Each row is a distinct problem flagged by a lead. The number on the right is how many leads reported it;
                the more leads that share a gap, the more confidently you can pitch a solution.
            </p>
            <div class="fi-pain-grid">
                <?php
                // Deduplicate by normalised label (strip score suffix + lowercase) before rendering
                $deduped = [];
                foreach ( $pain_all as $point => $count ) {
                    $label_key = strtolower( trim( explode( '(', $point )[0] ) );
                    if ( isset( $deduped[ $label_key ] ) ) {
                        $deduped[ $label_key ]['count'] += $count;
                    } else {
                        $deduped[ $label_key ] = [ 'label' => trim( explode( '(', $point )[0] ), 'count' => $count ];
                    }
                }
                usort( $deduped, fn( $a, $b ) => $b['count'] <=> $a['count'] );
                $rank = 0;
                $max  = $deduped[0]['count'] ?? 1;
                foreach ( array_slice( $deduped, 0, 8 ) as $item ) :
                    $rank++;
                    $width = $max ? round( ( $item['count'] / $max ) * 100 ) : 0;
                ?>
                <div class="fi-pain-row">
                    <div class="fi-pain-row-label">
                        <span class="fi-pain-rank"><?php echo absint( $rank ); ?></span>
                        <?php echo esc_html( $item['label'] ); ?>
                    </div>
                    <div class="fi-pain-bar-wrap">
                        <div class="fi-pain-bar" style="width:<?php echo (int) $width; ?>%"></div>
                    </div>
                    <span class="fi-pain-count" title="<?php echo (int) $item['count']; ?> lead<?php echo $item['count'] !== 1 ? 's' : ''; ?> flagged this">
                        <?php echo (int) $item['count']; ?> lead<?php echo $item['count'] !== 1 ? 's' : ''; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $pain_by_ind ) ) : ?>
            <div class="fi-briefing-table-wrap" style="margin-top:20px;">
                <div class="fi-briefing-table-title">Top Pain Points by Industry</div>
                <table class="fi-briefing-table">
                    <thead><tr><th>Industry</th><th>Top Issues</th></tr></thead>
                    <tbody>
                    <?php foreach ( array_slice( $pain_by_ind, 0, 8, true ) as $cat => $points ) :
                        $labels = array_map( fn( $p ) => trim( explode( '(', $p )[0] ), array_keys( $points ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( ucfirst( $cat ) ); ?></td>
                        <td><?php echo esc_html( implode( ' · ', $labels ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Raw Scan Data ─────────────────────────────────────────────── -->
        <?php
        $raw_scans = FI_DB::get_scans_for_table( 200 );
        if ( ! empty( $raw_scans ) ) :
        ?>
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">All Scans</h2>
            <p class="fi-briefing-intro">
                Every business scanned through your tool. Click a column header to sort.
                Score is colour-coded: <span style="color:#16a34a;font-weight:600;">green 80+</span>,
                <span style="color:#d97706;font-weight:600;">amber 60–79</span>,
                <span style="color:#dc2626;font-weight:600;">red below 60</span>.
            </p>
            <div class="fi-scan-table-wrap">
            <table class="fi-scan-table fi-briefing-table" id="fi-scan-raw-table">
                <thead>
                    <tr>
                        <th class="fi-sortable" data-col="0">Industry <span class="fi-sort-icon">↕</span></th>
                        <th class="fi-sortable" data-col="1">Business Name <span class="fi-sort-icon">↕</span></th>
                        <th class="fi-sortable" data-col="2">Scan Date <span class="fi-sort-icon">↕</span></th>
                        <th>Top Pain Point</th>
                        <th class="fi-sortable" data-col="4">City <span class="fi-sort-icon">↕</span></th>
                        <th class="fi-sortable" data-col="5">State <span class="fi-sort-icon">↕</span></th>
                        <th class="fi-sortable fi-col-score" data-col="6">Score <span class="fi-sort-icon">↕</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $raw_scans as $scan ) :
                    // Parse city / state from address string ("123 Main St, City, ST 12345, Country")
                    $addr_parts = array_map( 'trim', explode( ',', $scan->address ) );
                    $city  = '';
                    $state = '';
                    if ( count( $addr_parts ) >= 3 ) {
                        $city = $addr_parts[ count( $addr_parts ) - 3 ];
                        $state_raw = $addr_parts[ count( $addr_parts ) - 2 ];
                        $state = preg_replace( '/\s*\d+.*$/', '', $state_raw );
                    } elseif ( count( $addr_parts ) === 2 ) {
                        $city = $addr_parts[0];
                    }

                    // Top pain point: first item from comma-separated list
                    $pain_raw = $scan->pain_points ?? '';
                    $top_pain = '';
                    if ( $pain_raw ) {
                        $first    = trim( explode( ',', $pain_raw )[0] );
                        $top_pain = trim( explode( '(', $first )[0] );
                    }

                    $score       = (int) $scan->overall_score;
                    $score_color = FI_Utils::score_color( $score );
                    $score_label = $score >= 80 ? 'Strong' : ( $score >= 60 ? 'Average' : 'Weak' );
                ?>
                <tr>
                    <td><?php echo esc_html( ucfirst( $scan->category ?: '-' ) ); ?></td>
                    <td><?php echo esc_html( $scan->business_name ); ?></td>
                    <td data-sort="<?php echo esc_attr( $scan->scanned_at ); ?>"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $scan->scanned_at ) ) ); ?></td>
                    <td><?php echo $top_pain ? esc_html( $top_pain ) : '<span class="fi-muted">No lead captured</span>'; ?></td>
                    <td><?php echo esc_html( $city ?: '-' ); ?></td>
                    <td><?php echo esc_html( trim( $state ) ?: '-' ); ?></td>
                    <td>
                        <span class="fi-score-pill" style="background:<?php echo esc_attr( $score_color ); ?>1a;color:<?php echo esc_attr( $score_color ); ?>;">
                            <?php echo absint( $score ); ?> <span class="fi-score-pill-label"><?php echo esc_html( $score_label ); ?></span>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <script>
        (function(){
            var tbl = document.getElementById('fi-scan-raw-table');
            if (!tbl) return;
            var sortDir = {};
            tbl.querySelectorAll('th.fi-sortable').forEach(function(th){
                th.style.cursor = 'pointer';
                th.addEventListener('click', function(){
                    var col = parseInt(th.dataset.col);
                    var asc = !sortDir[col];
                    sortDir = {};
                    sortDir[col] = asc;
                    var tbody = tbl.querySelector('tbody');
                    var rows = Array.from(tbody.querySelectorAll('tr'));
                    rows.sort(function(a, b){
                        var ac = a.cells[col], bc = b.cells[col];
                        var av = ac.dataset.sort || ac.textContent.trim();
                        var bv = bc.dataset.sort || bc.textContent.trim();
                        var an = parseFloat(av), bn = parseFloat(bv);
                        if (!isNaN(an) && !isNaN(bn)) return asc ? an-bn : bn-an;
                        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
                    });
                    rows.forEach(function(r){ tbody.appendChild(r); });
                    tbl.querySelectorAll('.fi-sort-icon').forEach(function(i){ i.textContent = '↕'; });
                    th.querySelector('.fi-sort-icon').textContent = asc ? '↑' : '↓';
                });
            });
        })();
        </script>
        <?php endif; ?>

        <!-- ── Geographic Footprint ──────────────────────────────────────── -->
        <?php if ( ! empty( $cities ) ) : ?>
        <div class="fi-briefing-section">
            <h2 class="fi-briefing-heading">Your Geographic Footprint</h2>
            <p class="fi-briefing-intro">
                Cities derived from scanned business addresses. High scan volume in a city means you have the data to speak with authority there, use it.
            </p>
            <div class="fi-briefing-grid fi-briefing-grid--auto">
                <?php foreach ( $cities as $city ) : ?>
                <div class="fi-city-card">
                    <div class="fi-city-name"><?php echo esc_html( $city->city ); ?></div>
                    <div class="fi-city-scans"><?php echo (int) $city->scans; ?> scan<?php echo $city->scans !== 1 ? 's' : ''; ?></div>
                    <div class="fi-city-score" style="color:<?php echo esc_attr( FI_Utils::score_color( (int) $city->avg_score ) ); ?>">
                        avg <?php echo esc_html( $city->avg_score ); ?>/100
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif;
    }

    // =========================================================================
    // Tab: Market Intel — action launcher
    // =========================================================================

    // =========================================================================
    // Tab: Market Intel — filter bar + tiered action launcher
    // =========================================================================

    private static function tab_market_intel(): void {
        $total      = FI_DB::total_scans();
        $categories = FI_DB::all_categories( 1 );

        $tiers = [
            [
                'label'     => 'Tier 1: First Signal',
                'threshold' => self::THRESHOLD_BASIC,
                'subtitle'  => 'Enough to say something true about your market.',
                'actions'   => [
                    'industry_report'       => [ 'icon' => '📄', 'title' => 'Industry Report',          'desc' => 'A publishable market intelligence article that positions you as the person who knows this market.' ],
                    'landing_page'          => [ 'icon' => '🌐', 'title' => 'Landing Page Copy',        'desc' => 'Full conversion copy for your highest-opportunity industry, written to convert skeptical owners.' ],
                    'prospect_hit_list'     => [ 'icon' => '🎯', 'title' => 'Prospect Hit List',        'desc' => 'Ranked list of your 10 highest-need business types with a specific cold opener for each.' ],
                    'objection_cheat_sheet' => [ 'icon' => '🛡️', 'title' => 'Objection Cheat Sheet',  'desc' => 'The 6 most likely objections from your market, and exactly how to answer each one.' ],
                    'market_one_pager'      => [ 'icon' => '📋', 'title' => 'Market One-Pager',         'desc' => 'A single-page research document to leave behind after meetings or attach to cold emails.' ],
                ],
            ],
            [
                'label'     => 'Tier 2: Pattern Recognition',
                'threshold' => self::THRESHOLD_ACTIONS,
                'subtitle'  => 'The data is telling you something repeatable.',
                'actions'   => [
                    'cold_outreach'          => [ 'icon' => '✉️', 'title' => 'Cold Outreach Sequence',     'desc' => '5-email sequence referencing real industry scores and pain patterns, not generic templates.' ],
                    'pitch_deck'             => [ 'icon' => '📊', 'title' => 'Pitch Deck',                 'desc' => '10-slide framework with talking points, ROI framing, and a slide you personalize from any scan report.' ],
                    'discovery_call_script'  => [ 'icon' => '📞', 'title' => 'Discovery Call Script',     'desc' => 'Structured 20-minute diagnostic call framework with qualifying questions drawn from your pain patterns.' ],
                    'social_media_series'    => [ 'icon' => '📱', 'title' => 'Social Media Series',       'desc' => '5 posts for Facebook, Instagram, Threads, or X, each a different angle on your market data.', 'platform_select' => true ],
                    'pricing_anchor_script'  => [ 'icon' => '💰', 'title' => 'Pricing Anchor Script',     'desc' => 'How to frame your price against the cost of the problem, with a conservative ROI walkthrough.' ],
                    'niche_positioning'      => [ 'icon' => '📍', 'title' => 'Niche Positioning Statement','desc' => 'Core positioning, bio, cold email signature line, elevator pitch, and headline options, all market-specific.' ],
                    'google_ads_brief'       => [ 'icon' => '🔍', 'title' => 'Google Ads Brief',          'desc' => 'Complete brief: keywords, 3 full ads, negative keywords, landing page notes, budget guidance.' ],
                    'follow_up_templates'    => [ 'icon' => '↩️', 'title' => 'Follow-Up Templates',      'desc' => '3 situational follow-ups: after a discovery call, after a proposal, after 30+ days of silence.' ],
                ],
            ],
            [
                'label'     => 'Tier 3: Market Authority',
                'threshold' => self::THRESHOLD_FULL,
                'subtitle'  => 'Enough data to make claims that hold up.',
                'actions'   => [
                    'content_strategy'       => [ 'icon' => '📅', 'title' => 'SEO Content Strategy',      'desc' => '90-day editorial calendar, target keywords, and a data asset only you can publish.' ],
                    'annual_market_report'   => [ 'icon' => '📑', 'title' => 'Annual Market Report',      'desc' => 'Full structured report for a chamber submission, local publication, or gated lead magnet.' ],
                    'partnership_pitch'      => [ 'icon' => '🤝', 'title' => 'Partnership Pitch',         'desc' => 'Pitch a local association with co-branded research, they get data, you get their member list.' ],
                    'competitor_gap_analysis'=> [ 'icon' => '🔬', 'title' => 'Competitor Gap Analysis',   'desc' => 'Which niches have the highest need and fewest providers, where you can own a category.' ],
                    'case_study_template'    => [ 'icon' => '⭐', 'title' => 'Case Study Template',       'desc' => 'Pre-filled structure with market context already written, add client numbers and publish.' ],
                    'webinar_outline'        => [ 'icon' => '🎙️', 'title' => 'Webinar Outline',          'desc' => 'Full 45-minute workshop with data slides, running order, and a post-event follow-up email.' ],
                    'video_script_series'    => [ 'icon' => '🎬', 'title' => 'Video Script Series',       'desc' => '3 short-form video scripts (60–90 sec) with on-screen text, b-roll, and thumbnail concepts.' ],
                    'proposal_template'      => [ 'icon' => '📝', 'title' => 'Proposal Template',         'desc' => 'Deal-closing proposal with market context pre-filled, add client data and send.' ],
                ],
            ],
            [
                'label'     => 'Tier 4: Data Asset Owner',
                'threshold' => self::THRESHOLD_ASSET,
                'subtitle'  => 'The data is now a defensible competitive advantage.',
                'actions'   => [
                    'press_release'           => [ 'icon' => '📰', 'title' => 'Press Release',                    'desc' => 'Newsworthy release announcing your findings, ready to submit to local business media.' ],
                    'franchise_brief'         => [ 'icon' => '🏢', 'title' => 'Franchise / Multi-Location Brief', 'desc' => 'Targeted pitch for a franchise brand whose location profiles your data shows are consistently weak.' ],
                    'referral_partner_script' => [ 'icon' => '🔗', 'title' => 'Referral Partner Script',          'desc' => 'How to approach accountants, attorneys, and bankers who serve your prospects.' ],
                    'newsletter_template'     => [ 'icon' => '📬', 'title' => 'Monthly Newsletter Template',      'desc' => 'Recurring framework using fresh scan data as the hook, completable in under 30 minutes/month.' ],
                    'media_pitch'             => [ 'icon' => '📡', 'title' => 'Local Media Pitch',                'desc' => 'Pitch to a journalist or podcast host with 3 story angles, positions you as a local expert source.' ],
                    'grant_proposal'          => [ 'icon' => '🏛️', 'title' => 'Grant / Sponsorship Proposal',    'desc' => 'Proposal for an SBA office or regional bank to sponsor your research as a community resource.' ],
                    'white_label_package'     => [ 'icon' => '🏷️', 'title' => 'White Label Package',             'desc' => 'Framework for licensing your scan data to agencies who publish it under their own brand.' ],
                ],
            ],
            [
                'label'     => 'Tier 5: Market Platform',
                'threshold' => self::THRESHOLD_PLATFORM,
                'subtitle'  => 'The data is the business. You are the infrastructure.',
                'actions'   => [
                    'paid_intelligence_brief'   => [ 'icon' => '💎', 'title' => 'Paid Intelligence Product',       'desc' => 'Launch a recurring paid briefing sold to business owners, associations, or competing agencies who want the data without doing the research.' ],
                    'score_directory'           => [ 'icon' => '🗂️', 'title' => 'Local Business Score Directory',  'desc' => 'A public-facing "[City] Business Profile Index" that generates organic search traffic, press coverage, and inbound leads without cold outreach.' ],
                    'academic_partnership'      => [ 'icon' => '🎓', 'title' => 'University Research Partnership', 'desc' => 'Pitch a local business school to use your scan data as primary research, co-authored studies, speaking invitations, institutional credibility.' ],
                    'city_hall_brief'           => [ 'icon' => '🏘️', 'title' => 'City / County Economic Brief',   'desc' => 'Present your data to a local economic development office, mayor\'s small business council, or county chamber as a community health indicator.' ],
                    'competitive_intel_service' => [ 'icon' => '🕵️', 'title' => 'Competitive Intel Service',      'desc' => 'A recurring B2B product: monthly competitor profile tracking sold direct to individual businesses. Recurring revenue, no cold outreach.' ],
                    'acquisition_package'       => [ 'icon' => '💼', 'title' => 'Agency Acquisition Package',      'desc' => 'Frame your data asset, lead system, and client base as an acquirable business, positioning deck and seller narrative for agency buyers.' ],
                    'annual_summit'             => [ 'icon' => '🎤', 'title' => 'Annual Business Summit',          'desc' => 'The definitive local business intelligence event; sponsor tables, speaking slots, and a room full of your exact prospects.' ],
                ],
            ],
        ];
        ?>
        <div class="fi-intel-wrap">

            <!-- Filter bar -->
            <div class="fi-intel-filter-bar">
                <div class="fi-intel-filter-left">
                    <span class="fi-intel-filter-label">Generating from:</span>
                    <span id="fi-filter-summary" class="fi-intel-filter-summary">
                        All industries · All time · <strong id="fi-filter-count"><?php echo (int) $total; ?></strong> scans
                    </span>
                </div>
                <div class="fi-intel-filter-right">
                    <select id="fi-filter-category" class="fi-intel-filter-select">
                        <option value="all">All industries</option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->category ); ?>">
                            <?php echo esc_html( ucfirst( $cat->category ) ); ?> (<?php echo (int) $cat->scans; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="fi-filter-date-range" class="fi-intel-filter-select">
                        <option value="all">All time</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="180">Last 6 months</option>
                    </select>
                    <button type="button" class="fi-intel-filter-reset" id="fi-filter-reset">Reset</button>
                </div>
            </div>

            <!-- Signal quality strip -->
            <div class="fi-intel-signal-strip" id="fi-intel-signal-strip">
                <span id="fi-signal-count-text"><strong><?php echo (int) $total; ?></strong> scans in selection</span>
                <span id="fi-signal-quality" class="fi-signal-badge fi-signal-badge--<?php echo $total >= 20 ? 'strong' : ( $total >= 10 ? 'moderate' : 'limited' ); ?>">
                    <?php echo $total >= 20 ? 'Strong signal' : ( $total >= 10 ? 'Moderate signal' : 'Limited data' ); ?>
                </span>
            </div>

            <!-- Tier groups -->
            <?php foreach ( $tiers as $tier_idx => $tier ) :
                $tier_threshold = $tier['threshold'];
                $tier_unlocked  = $total >= $tier_threshold;
            ?>
            <div class="fi-intel-tier" data-tier-threshold="<?php echo (int) $tier_threshold; ?>">
                <div class="fi-intel-tier-header">
                    <div>
                        <span class="fi-intel-tier-label"><?php echo esc_html( $tier['label'] ); ?></span>
                        <span class="fi-intel-tier-subtitle"><?php echo esc_html( $tier['subtitle'] ); ?></span>
                    </div>
                    <div class="fi-intel-tier-badge <?php echo $tier_unlocked ? 'fi-intel-tier-badge--unlocked' : 'fi-intel-tier-badge--locked'; ?>">
                        <?php if ( $tier_unlocked ) : ?>
                            ✓ Unlocked
                        <?php else : ?>
                            🔒 <?php echo (int) $tier_threshold; ?>+ scans
                            <span class="fi-intel-tier-progress"><?php echo (int) $total; ?>/<?php echo (int) $tier_threshold; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="fi-intel-actions-grid">
                    <?php foreach ( $tier['actions'] as $action_key => $action ) :
                        $has_platform = ! empty( $action['platform_select'] );
                    ?>
                    <div class="fi-intel-action-card<?php echo ! $tier_unlocked ? ' fi-intel-action-card--locked' : ''; ?>"
                         data-action="<?php echo esc_attr( $action_key ); ?>"
                         data-threshold="<?php echo (int) $tier_threshold; ?>">
                        <div class="fi-intel-action-icon"><?php echo $action['icon']; ?></div>
                        <div class="fi-intel-action-title"><?php echo esc_html( $action['title'] ); ?></div>
                        <div class="fi-intel-action-desc"><?php echo esc_html( $action['desc'] ); ?></div>

                        <?php if ( $tier_unlocked ) : ?>
                            <?php if ( $has_platform ) : ?>
                            <div class="fi-intel-platform-row">
                                <select class="fi-intel-platform-select fi-select" data-action="<?php echo esc_attr( $action_key ); ?>">
                                    <option value="facebook">Facebook</option>
                                    <option value="instagram">Instagram</option>
                                    <option value="threads">Threads</option>
                                    <option value="twitter">X / Twitter</option>
                                </select>
                                <button type="button"
                                        class="button button-primary fi-intel-run-btn"
                                        data-action="<?php echo esc_attr( $action_key ); ?>"
                                        data-title="<?php echo esc_attr( $action['title'] ); ?>"
                                        data-needs-platform="1">
                                    Generate →
                                </button>
                            </div>
                            <?php else : ?>
                            <button type="button"
                                    class="button button-primary fi-intel-run-btn"
                                    data-action="<?php echo esc_attr( $action_key ); ?>"
                                    data-title="<?php echo esc_attr( $action['title'] ); ?>">
                                Generate →
                            </button>
                            <?php endif; ?>
                        <?php else : ?>
                        <div class="fi-intel-action-locked">
                            🔒 Needs <span class="fi-card-threshold-count"><?php echo (int) $tier_threshold; ?></span>+ scans in selection
                            <span class="fi-intel-progress fi-card-scan-progress"><?php echo (int) $total; ?>/<?php echo (int) $tier_threshold; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Output panel -->
            <div id="fi-intel-output-wrap" style="display:none;">
                <div class="fi-intel-output-header">
                    <div class="fi-intel-output-meta">
                        <strong id="fi-intel-output-title"></strong>
                        <span id="fi-intel-output-context" class="fi-intel-output-context"></span>
                    </div>
                    <div class="fi-intel-output-actions">
                        <button type="button" class="button" id="fi-intel-copy-btn">📋 Copy</button>
                        <button type="button" class="button" id="fi-intel-close-btn">✕ Close</button>
                    </div>
                </div>
                <div id="fi-intel-loading" style="display:none;">
                    <span class="fi-spinner"></span> Generating, this may take up to 30 seconds…
                </div>
                <div id="fi-intel-output" class="fi-intel-output"></div>
            </div>

        </div>
        <?php
    }

    // =========================================================================
    // Tab: Bulk Scan
    // =========================================================================

    private static function tab_bulk_scan(): void {
        $nonce       = wp_create_nonce( 'fi_bulk_scan' );
        $ajax_url    = admin_url( 'admin-ajax.php' );
        $model       = get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' );
        $model_label = [
            'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
            'claude-sonnet-4-5-20251015' => 'Claude Sonnet 4.5',
            'claude-opus-4-5'            => 'Claude Opus 4.5',
        ][ $model ] ?? $model;

        $recent_jobs = FI_DB::get_scan_jobs( 10 );

        // Cron health: look for any running job, not just the 5 displayed.
        // A running job beyond the display limit still needs the cron banner.
        global $wpdb;
        $_t = FI_DB::tables();
        $active_job = $wpdb->get_row(
            "SELECT * FROM {$_t['scan_jobs']} WHERE status = 'running' ORDER BY id DESC LIMIT 1"
        );
        // Fall back to paused if nothing running — paused jobs need cron too
        if ( ! $active_job ) {
            $active_job = $wpdb->get_row(
                "SELECT * FROM {$_t['scan_jobs']} WHERE status = 'paused' ORDER BY id DESC LIMIT 1"
            );
        }
        $tick_scheduled = $active_job
            ? (bool) wp_next_scheduled( 'fi_bulk_scan_tick', [ (int) $active_job->id ] )
            : null;
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        ?>

        <div class="fi-bulk-wrap" id="fi-bulk-wrap"
             data-ajax="<?php echo esc_url( $ajax_url ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <?php if ( $active_job && ! $tick_scheduled ) : ?>
            <div class="fi-bulk-cron-banner fi-bulk-cron-banner--error">
                ⚠ <strong>Cron not firing:</strong> Job #<?php echo (int) $active_job->id; ?> is marked Running but no tick is scheduled.
                This usually means <code>DISABLE_WP_CRON</code> is <code>true</code> in <code>wp-config.php</code>, or your server rarely gets traffic.
                <a href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener">Set up a real cron job →</a>
                &nbsp;·&nbsp; <button type="button" class="button button-small" id="fi-cron-respawn">Try spawning cron now</button>
            </div>
            <?php elseif ( $cron_disabled ) : ?>
            <div class="fi-bulk-cron-banner fi-bulk-cron-banner--warn">
                ⚠ <code>DISABLE_WP_CRON</code> is enabled. Make sure a real cron job calls <code>wp-cron.php</code> at least every minute, or bulk scan jobs will stall.
            </div>
            <?php endif; ?>

            <!-- ── State A: Import + Estimator ───────────────────────────── -->
            <div id="fi-bulk-import" class="fi-bulk-state">

                <div class="fi-bulk-columns">

                    <!-- Left: CSV upload -->
                    <div class="fi-bulk-input-col">
                        <h2 class="fi-bulk-heading">Import Businesses</h2>
                        <p class="fi-bulk-intro">
                            Upload a CSV file to queue a batch of businesses for scanning.
                            Use separate columns for the best Place ID match accuracy,
                            especially important for common business names or non-US locations.
                            Required columns: <code>name</code>, <code>address</code>, <code>city</code>, <code>state</code>, <code>postal_code</code>.
                            Optional: <code>country</code> (two-letter ISO code, e.g. <code>US</code>, <code>MX</code>, <code>FR</code>: defaults to best match if omitted).
                        </p>

                        <div class="fi-bulk-upload-zone" id="fi-bulk-upload-zone">
                            <input type="file" id="fi-bulk-csv" accept=".csv" class="fi-bulk-csv-hidden" style="display:none!important;position:absolute;width:0;height:0;opacity:0;pointer-events:none;">
                            <div class="fi-bulk-upload-icon">
                                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M12 16V8M12 8L9 11M12 8L15 11" stroke="#6366f1" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M8 16H6.5a3.5 3.5 0 1 1 .7-6.93A5 5 0 1 1 17.5 12H16" stroke="#6366f1" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div class="fi-bulk-upload-label">Drop a CSV file here or <button type="button" class="fi-bulk-upload-browse" id="fi-bulk-browse-btn">browse</button></div>
                            <div class="fi-bulk-upload-hint">.csv files only</div>
                        </div>

                        <div id="fi-bulk-file-preview" class="fi-bulk-file-preview" style="display:none;">
                            <div class="fi-bulk-file-info">
                                <span class="fi-bulk-file-icon">📄</span>
                                <span id="fi-bulk-file-name" class="fi-bulk-file-name"></span>
                                <span id="fi-bulk-file-rows" class="fi-bulk-file-rows"></span>
                                <button type="button" id="fi-bulk-file-clear" class="fi-bulk-file-clear" title="Remove file">✕</button>
                            </div>
                            <div class="fi-bulk-preview-table-wrap">
                                <table class="fi-briefing-table fi-bulk-preview-table" id="fi-bulk-preview-table">
                                    <thead id="fi-bulk-preview-head"></thead>
                                    <tbody id="fi-bulk-preview-body"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="fi-bulk-actions">
                            <button type="button" id="fi-bulk-validate-btn" class="button button-primary fi-bulk-btn" disabled>
                                Review &amp; Estimate →
                            </button>
                            <span id="fi-bulk-line-count" class="fi-hint fi-bulk-line-count"></span>
                        </div>

                        <div class="fi-bulk-sample-row">
                            <a href="<?php echo esc_url( plugins_url( 'assets/bulk-scan-sample.csv', FI_FILE ) ); ?>"
                               download class="fi-bulk-sample-link">
                                ⬇ Download sample CSV
                            </a>
                            <span class="fi-hint">: shows all supported columns with example data</span>
                        </div>
                    </div>

                    <!-- Right: cost estimator (live) -->
                    <div class="fi-bulk-estimate-col" id="fi-bulk-estimate-panel">
                        <h3 class="fi-bulk-estimate-heading">Cost Estimate</h3>

                        <div class="fi-bulk-estimate-model">
                            Using: <strong><?php echo esc_html( $model_label ); ?></strong>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-insights&tab=api' ) ); ?>"
                               class="fi-hint" style="margin-left:4px;">change</a>
                        </div>

                        <div class="fi-bulk-estimate-grid" id="fi-bulk-estimate-grid">
                            <div class="fi-bulk-est-row">
                                <span class="fi-bulk-est-label">Businesses entered</span>
                                <span class="fi-bulk-est-value" id="fi-est-count">-</span>
                            </div>
                            <div class="fi-bulk-est-row">
                                <span class="fi-bulk-est-label">Est. tokens (total)</span>
                                <span class="fi-bulk-est-value" id="fi-est-tokens">-</span>
                            </div>
                            <div class="fi-bulk-est-row fi-bulk-est-row--indent">
                                <span class="fi-bulk-est-label">Input tokens</span>
                                <span class="fi-bulk-est-value" id="fi-est-input">-</span>
                            </div>
                            <div class="fi-bulk-est-row fi-bulk-est-row--indent">
                                <span class="fi-bulk-est-label">Output tokens</span>
                                <span class="fi-bulk-est-value" id="fi-est-output">-</span>
                            </div>
                            <div class="fi-bulk-est-divider"></div>
                            <div class="fi-bulk-est-row fi-bulk-est-row--cost">
                                <span class="fi-bulk-est-label">Claude cost (est.)</span>
                                <span class="fi-bulk-est-value" id="fi-est-cost">-</span>
                            </div>
                            <div class="fi-bulk-est-row fi-bulk-est-row--google">
                                <span class="fi-bulk-est-label">Google Places API</span>
                                <span class="fi-bulk-est-value fi-hint">billed to your<br>Google account</span>
                            </div>
                        </div>

                        <p class="fi-bulk-est-source" id="fi-est-source"></p>

                        <div class="fi-bulk-est-notice">
                            ⚠ Claude costs are billed directly to your Anthropic account.
                            <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener">
                                Check your balance →
                            </a>
                            If credits run out mid-job, remaining scans pause and can be resumed.
                        </div>
                    </div>
                </div><!-- .fi-bulk-columns -->
            </div><!-- #fi-bulk-import -->

            <!-- ── Confirmation panel (shown after validate, before start) ─ -->
            <div id="fi-bulk-confirm" class="fi-bulk-state" style="display:none;">
                <div class="fi-bulk-confirm-inner fi-card">
                    <h2 class="fi-bulk-heading">Ready to queue <span id="fi-confirm-count">0</span> scans</h2>

                    <div class="fi-bulk-confirm-grid">
                        <div class="fi-bulk-confirm-row">
                            <span>Estimated Claude cost</span>
                            <strong id="fi-confirm-cost">-</strong>
                        </div>
                        <div class="fi-bulk-confirm-row">
                            <span>Google API calls</span>
                            <span class="fi-hint">billed to your Google account</span>
                        </div>
                        <div class="fi-bulk-confirm-row">
                            <span>Estimated time</span>
                            <span id="fi-confirm-time">-</span>
                        </div>
                    </div>

                    <div id="fi-confirm-duplicates" style="display:none;">
                        <div class="fi-bulk-dup-header">
                            <span id="fi-dup-count">0</span> duplicate<?php /* plural handled by JS */ ?>
                            already in your scan database (still fresh)
                        </div>
                        <ul id="fi-dup-list" class="fi-bulk-dup-list"></ul>
                        <label class="fi-bulk-force-label">
                            <input type="checkbox" id="fi-force-rescan">
                            Force rescan these businesses anyway
                        </label>
                    </div>

                    <div class="fi-bulk-confirm-actions">
                        <button type="button" id="fi-confirm-back-btn" class="button fi-bulk-btn">
                            ← Back
                        </button>
                        <button type="button" id="fi-confirm-start-btn" class="button button-primary fi-bulk-btn">
                            Confirm &amp; Start Scanning
                        </button>
                    </div>
                </div>
            </div><!-- #fi-bulk-confirm -->

            <!-- ── State B: Queue monitor ─────────────────────────────────── -->
            <div id="fi-bulk-monitor" class="fi-bulk-state" style="display:none;">

                <div class="fi-bulk-monitor-header">
                    <div>
                        <h2 class="fi-bulk-heading" id="fi-monitor-heading">Bulk Scan | Job #<span id="fi-monitor-job-id">-</span></h2>
                        <p class="fi-bulk-monitor-meta" id="fi-monitor-meta"></p>
                    </div>
                    <div class="fi-bulk-monitor-controls">
                        <button type="button" id="fi-monitor-pause-btn"  class="button fi-bulk-btn" style="display:none;">Pause</button>
                        <button type="button" id="fi-monitor-resume-btn" class="button fi-bulk-btn" style="display:none;">Resume</button>
                        <button type="button" id="fi-monitor-kill-btn"   class="button fi-bulk-btn fi-bulk-btn--warn" style="display:none;" title="Force-fail all currently stuck scanning items and unblock the queue">⚠ Kill Stuck</button>
                        <button type="button" id="fi-monitor-cancel-btn" class="button fi-bulk-btn fi-bulk-btn--danger" style="display:none;">Cancel Job</button>
                        <button type="button" id="fi-monitor-new-btn"    class="button button-primary fi-bulk-btn" style="display:none;">+ New Job</button>
                    </div>
                </div>

                <div class="fi-bulk-progress-wrap">
                    <div class="fi-bulk-progress-bar">
                        <div class="fi-bulk-progress-fill" id="fi-progress-fill" style="width:0%"></div>
                    </div>
                    <span class="fi-bulk-progress-label" id="fi-progress-label">0 / 0</span>
                </div>

                <div class="fi-bulk-monitor-cols">

                    <!-- Left: queue table -->
                    <div class="fi-bulk-monitor-left">
                        <div class="fi-bulk-tokens-bar">
                            Tokens used: <strong id="fi-monitor-tokens">0</strong>
                            &nbsp;·&nbsp; Est. remaining: <span id="fi-monitor-tokens-remaining">-</span>
                        </div>
                        <table class="fi-bulk-queue-table" id="fi-bulk-queue-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Business</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Tokens</th>
                                    <th>Time</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="fi-queue-tbody"></tbody>
                        </table>
                    </div>

                    <!-- Right: live stats panel -->
                    <div class="fi-bulk-monitor-right" id="fi-monitor-stats">
                        <div class="fi-bulk-stat-card">
                            <div class="fi-bulk-stat-label">Est. Time Remaining</div>
                            <div class="fi-bulk-stat-value" id="fi-stat-eta">-</div>
                        </div>
                        <div class="fi-bulk-stat-card">
                            <div class="fi-bulk-stat-label">Avg. Time / Scan</div>
                            <div class="fi-bulk-stat-value" id="fi-stat-avg">-</div>
                        </div>
                        <div class="fi-bulk-stat-card">
                            <div class="fi-bulk-stat-label">Completed</div>
                            <div class="fi-bulk-stat-value" id="fi-stat-done">0</div>
                        </div>
                        <div class="fi-bulk-stat-card">
                            <div class="fi-bulk-stat-label">Failed</div>
                            <div class="fi-bulk-stat-value" id="fi-stat-failed">0</div>
                        </div>
                        <div class="fi-bulk-stat-card fi-bulk-stat-card--wide">
                            <div class="fi-bulk-stat-label">Currently Scanning</div>
                            <div class="fi-bulk-stat-value fi-bulk-stat-value--sm" id="fi-stat-current">-</div>
                            <div class="fi-bulk-stat-phase" id="fi-stat-phase"></div>
                        </div>
                        <div class="fi-bulk-stat-card fi-bulk-stat-card--wide fi-bulk-activity-card">
                            <div class="fi-bulk-stat-label">Activity</div>
                            <ul class="fi-bulk-activity-log" id="fi-activity-log"></ul>
                        </div>
                    </div>

                </div><!-- .fi-bulk-monitor-cols -->

                <div id="fi-monitor-complete-bar" class="fi-bulk-complete-bar" style="display:none;">
                    <div id="fi-monitor-complete-summary"></div>
                    <div class="fi-bulk-complete-actions">
                        <a id="fi-monitor-leads-link"
                           href="<?php echo esc_url( admin_url( 'admin.php?page=fi-market-intel&tab=leads' ) ); ?>"
                           class="button button-primary fi-bulk-btn">View in Leads</a>
                        <button type="button" id="fi-monitor-export-btn" class="button fi-bulk-btn">Export Results CSV</button>
                        <button type="button" id="fi-monitor-new-btn-2"  class="button fi-bulk-btn">+ New Job</button>
                    </div>
                </div>

            </div><!-- #fi-bulk-monitor -->

            <!-- ── Recent jobs ────────────────────────────────────────────── -->
            <?php if ( ! empty( $recent_jobs ) ) : ?>
            <div class="fi-bulk-history" id="fi-bulk-history">
                <h3 class="fi-bulk-history-heading">Recent Jobs</h3>
                <table class="fi-briefing-table fi-bulk-history-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Tokens</th>
                            <th>Started</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent_jobs as $job ) :
                        $pct = $job->total > 0 ? round( ( $job->completed / $job->total ) * 100 ) : 0;
                    ?>
                    <tr>
                        <td>#<?php echo (int) $job->id; ?></td>
                        <td><span class="fi-bulk-status-badge fi-bulk-status-badge--<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( ucfirst( $job->status ) ); ?></span></td>
                        <td><?php echo (int) $job->completed; ?>/<?php echo (int) $job->total; ?>
                            <?php if ( $job->failed ) echo ' <span class="fi-hint" style="color:#dc2626;">(' . (int) $job->failed . ' failed)</span>'; ?>
                        </td>
                        <td><?php echo number_format( $job->tokens_used ); ?></td>
                        <td><?php echo $job->started_at ? esc_html( wp_date( 'M j, g:i a', strtotime( $job->started_at ) ) ) : '-'; ?></td>
                        <td>
                            <?php if ( in_array( $job->status, [ 'running', 'paused', 'complete' ], true ) ) : ?>
                            <button type="button" class="button button-small fi-bulk-history-view"
                                    data-job-id="<?php echo (int) $job->id; ?>">
                                View
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- .fi-bulk-wrap -->

        <script>
        var fiInsightsBulk = {
            ajax:        <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
            nonce:       <?php echo wp_json_encode( $nonce ); ?>,
            activeJobId: <?php echo $active_job ? (int) $active_job->id : 'null'; ?>,
            activeJobStatus: <?php echo $active_job ? wp_json_encode( $active_job->status ) : 'null'; ?>
        };
        </script>
        <script>
        (function() {
            'use strict';

            // ── XSS helper — declared first so every function below can use it ──
            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            var AJAX     = fiInsightsBulk.ajax;
            var NONCE    = fiInsightsBulk.nonce;
            var currentJob   = null;
            var pollTimer    = null;
            var pendingValid = null;   // validated business list from last validate call
            var parsedCSV    = [];     // rows parsed from uploaded CSV
            var perScanEst   = <?php echo (int) FI_Bulk_Scan::estimate_tokens(1)['per_scan']; ?>;

            // ── DOM refs ──────────────────────────────────────────────────────
            var importEl     = document.getElementById('fi-bulk-import');
            var confirmEl    = document.getElementById('fi-bulk-confirm');
            var monitorEl    = document.getElementById('fi-bulk-monitor');
            var csvInput     = document.getElementById('fi-bulk-csv');
            var browseBtn    = document.getElementById('fi-bulk-browse-btn');
            var uploadZone   = document.getElementById('fi-bulk-upload-zone');
            var filePreview  = document.getElementById('fi-bulk-file-preview');
            var fileClear    = document.getElementById('fi-bulk-file-clear');
            var validateBtn  = document.getElementById('fi-bulk-validate-btn');
            var lineCount    = document.getElementById('fi-bulk-line-count');
            var confirmBack  = document.getElementById('fi-confirm-back-btn');
            var confirmStart = document.getElementById('fi-confirm-start-btn');
            var forceRescan  = document.getElementById('fi-force-rescan');
            var pauseBtn     = document.getElementById('fi-monitor-pause-btn');
            var resumeBtn    = document.getElementById('fi-monitor-resume-btn');
            var killBtn      = document.getElementById('fi-monitor-kill-btn');
            var cancelBtn    = document.getElementById('fi-monitor-cancel-btn');
            var newBtn       = document.getElementById('fi-monitor-new-btn');
            var newBtn2      = document.getElementById('fi-monitor-new-btn-2');
            var exportBtn    = document.getElementById('fi-monitor-export-btn');

            // ── Helpers ───────────────────────────────────────────────────────
            function post(action, data, cb) {
                data.action = action;
                data.nonce  = NONCE;
                var fd = new FormData();
                for (var k in data) fd.append(k, data[k]);
                fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(cb)
                    .catch(function(e){ console.error('[FI Bulk]', e); });
            }

            function show(el) { if (el) el.style.display = ''; }
            function hide(el) { if (el) el.style.display = 'none'; }

            function fmtNum(n) { return Number(n).toLocaleString(); }
            function fmtCost(c) { return c < 0.01 ? '< $0.01' : '$' + Number(c).toFixed(2); }
            function fmtTime(n) {
                var mins = Math.ceil((n * 35) / 60);
                return mins < 2 ? 'Under 2 minutes' : 'About ' + mins + ' minutes';
            }

            // Minimal RFC-4180-aware CSV parser (handles quoted fields with commas)
            function parseCSV(text) {
                var rows = [];
                var lines = text.split(/\r?\n/);
                lines.forEach(function(line) {
                    if (!line.trim()) return;
                    var fields = [];
                    var cur = '';
                    var inQ  = false;
                    for (var i = 0; i < line.length; i++) {
                        var ch = line[i];
                        if (ch === '"') {
                            if (inQ && line[i+1] === '"') { cur += '"'; i++; }
                            else inQ = !inQ;
                        } else if (ch === ',' && !inQ) {
                            fields.push(cur.trim()); cur = '';
                        } else {
                            cur += ch;
                        }
                    }
                    fields.push(cur.trim());
                    rows.push(fields);
                });
                return rows;
            }

            function loadCSVFile(file) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var rows = parseCSV(e.target.result);
                    if (!rows.length) return;

                    // Detect header row — first cell matches 'name' or 'business'
                    var headers = [];
                    var dataRows = rows;
                    if (/^(name|business)/i.test(rows[0][0])) {
                        headers  = rows[0].map(function(h){ return h.toLowerCase(); });
                        dataRows = rows.slice(1);
                    } else {
                        // No header — assume col 0 = name, col 1 = address
                        headers = ['name','address'];
                    }

                    var nameIdx    = headers.indexOf('name');
                    var addressIdx = headers.indexOf('address');
                    var cityIdx    = headers.indexOf('city');
                    var stateIdx   = headers.indexOf('state');
                    var postalIdx  = headers.indexOf('postal_code');
                    var countryIdx = headers.indexOf('country');
                    if (nameIdx === -1) nameIdx = 0;

                    parsedCSV = dataRows
                        .filter(function(r){ return r[nameIdx] && r[nameIdx].trim(); })
                        .map(function(r){
                            // Build full address from components if separate columns exist
                            var addr    = addressIdx >= 0 ? (r[addressIdx] || '').trim() : '';
                            var city    = cityIdx    >= 0 ? (r[cityIdx]    || '').trim() : '';
                            var state   = stateIdx   >= 0 ? (r[stateIdx]   || '').trim() : '';
                            var zip     = postalIdx  >= 0 ? (r[postalIdx]  || '').trim() : '';
                            var country = countryIdx >= 0 ? (r[countryIdx] || '').trim().toUpperCase() : '';
                            // Validate country if provided — must be 2-letter ISO code
                            if (country && !/^[A-Z]{2}$/.test(country)) country = '';
                            // Combine components — omit blanks
                            var parts = [addr, city, state, zip, country].filter(Boolean);
                            var fullAddr = parts.length > 0 ? parts.join(' ') : '';
                            return {
                                name:    r[nameIdx] || '',
                                address: fullAddr,
                            };
                        });

                    var n = parsedCSV.length;
                    document.getElementById('fi-bulk-file-name').textContent = file.name;
                    document.getElementById('fi-bulk-file-rows').textContent = n + ' business' + (n !== 1 ? 'es' : '');
                    lineCount.textContent = '';
                    validateBtn.disabled  = n === 0;

                    // Preview table — first 5 rows
                    var thead = document.getElementById('fi-bulk-preview-head');
                    var tbody = document.getElementById('fi-bulk-preview-body');
                    var hasAddrCols = cityIdx >= 0 || stateIdx >= 0 || postalIdx >= 0;
                    var cols = ['name', hasAddrCols ? 'address (combined)' : 'address'].filter(function(c,i){ return i===0 || addressIdx>=0 || hasAddrCols; });
                    thead.innerHTML = '<tr>' + cols.map(function(c){ return '<th>' + escHtml(c) + '</th>'; }).join('') + '</tr>';
                    tbody.innerHTML = parsedCSV.slice(0, 5).map(function(row){
                        return '<tr>'
                            + '<td>' + escHtml(row.name)    + '</td>'
                            + '<td>' + escHtml(row.address) + '</td>'
                            + '</tr>';
                    }).join('');
                    if (n > 5) {
                        tbody.innerHTML += '<tr><td colspan="2" class="fi-hint" style="padding:8px 12px;">… and ' + (n - 5) + ' more</td></tr>';
                    }

                    show(filePreview);
                    hide(uploadZone);

                    // Trigger live estimate
                    updateEstimate(n);
                };
                reader.readAsText(file);
            }

            function updateEstimate(n) {
                if (n === 0) return;
                post('fi_bulk_estimate', { count: n }, function(res) {
                    if (!res.success) return;
                    var d = res.data;
                    document.getElementById('fi-est-count').textContent  = fmtNum(n);
                    document.getElementById('fi-est-tokens').textContent = fmtNum(d.total_tokens);
                    document.getElementById('fi-est-input').textContent  = fmtNum(d.input_tokens);
                    document.getElementById('fi-est-output').textContent = fmtNum(d.output_tokens);
                    var modelKey = '<?php echo esc_js( $model ); ?>';
                    var cost = d.costs[modelKey] || Object.values(d.costs)[0] || 0;
                    document.getElementById('fi-est-cost').textContent = fmtCost(cost);
                    var src = document.getElementById('fi-est-source');
                    src.textContent = d.from_history
                        ? 'Based on your last 30 scans (' + fmtNum(d.per_scan) + ' tokens/scan avg)'
                        : 'Based on typical usage (' + fmtNum(d.per_scan) + ' tokens/scan est.)';
                });
            }

            // ── Upload zone interactions ──────────────────────────────────────
            browseBtn.addEventListener('click', function() { csvInput.click(); });

            csvInput.addEventListener('change', function() {
                if (csvInput.files[0]) loadCSVFile(csvInput.files[0]);
            });

            // Drag and drop
            uploadZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadZone.classList.add('fi-bulk-upload-zone--over');
            });
            uploadZone.addEventListener('dragleave', function() {
                uploadZone.classList.remove('fi-bulk-upload-zone--over');
            });
            uploadZone.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadZone.classList.remove('fi-bulk-upload-zone--over');
                var file = e.dataTransfer.files[0];
                if (file && file.name.endsWith('.csv')) loadCSVFile(file);
            });

            // Clear file
            fileClear.addEventListener('click', function() {
                parsedCSV = [];
                csvInput.value = '';
                validateBtn.disabled = true;
                hide(filePreview);
                show(uploadZone);
                // Reset estimator
                ['fi-est-count','fi-est-tokens','fi-est-input','fi-est-output','fi-est-cost'].forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.textContent = '-';
                });
                var src = document.getElementById('fi-est-source');
                if (src) src.textContent = '';
            });

            // ── Validate → confirm ────────────────────────────────────────────
            validateBtn.addEventListener('click', function() {
                var businesses = parsedCSV;
                if (!businesses.length) return;
                validateBtn.disabled = true;
                validateBtn.textContent = 'Checking…';

                post('fi_bulk_validate', { businesses: JSON.stringify(businesses) }, function(res) {
                    validateBtn.disabled = false;
                    validateBtn.textContent = 'Review & Estimate →';
                    if (!res.success) { alert(res.data || 'Validation failed.'); return; }

                    var d = res.data;
                    pendingValid = d.valid;

                    document.getElementById('fi-confirm-count').textContent = d.valid.length;
                    var modelKey = '<?php echo esc_js( $model ); ?>';
                    var cost = d.estimate.costs[modelKey] || Object.values(d.estimate.costs)[0] || 0;
                    document.getElementById('fi-confirm-cost').textContent = fmtCost(cost);
                    document.getElementById('fi-confirm-time').textContent = fmtTime(d.valid.length);

                    var dupWrap = document.getElementById('fi-confirm-duplicates');
                    var dupList = document.getElementById('fi-dup-list');
                    if (d.duplicates && d.duplicates.length > 0) {
                        document.getElementById('fi-dup-count').textContent = d.duplicates.length;
                        dupList.innerHTML = d.duplicates.map(function(dup) {
                            return '<li data-name="' + escHtml(dup.name) + '" data-address="' + escHtml(dup.address || '') + '"><strong>' + escHtml(dup.name) + '</strong>, score ' + dup.score + ', cache expires ' + escHtml(dup.expires_at) + '</li>';
                        }).join('');
                        show(dupWrap);
                    } else {
                        hide(dupWrap);
                    }

                    hide(importEl);
                    show(confirmEl);
                });
            });

            // ── Back to import ────────────────────────────────────────────────
            confirmBack.addEventListener('click', function() {
                hide(confirmEl);
                show(importEl);
            });

            // ── Confirm → start job ───────────────────────────────────────────
            confirmStart.addEventListener('click', function() {
                if (!pendingValid) return;
                confirmStart.disabled = true;
                confirmStart.textContent = 'Starting…';

                var businesses = pendingValid.slice();
                if (forceRescan.checked) {
                    // Merge back duplicates for force-rescan, restoring their original address
                    var dupItems = document.querySelectorAll('#fi-dup-list li');
                    dupItems.forEach(function(li) {
                        var name    = li.dataset.name    || '';
                        var address = li.dataset.address || '';
                        if (name) businesses.push({ name: name, address: address });
                    });
                }

                function doStart(confirmed) {
                    post('fi_bulk_start', {
                        businesses:   JSON.stringify(businesses),
                        force_rescan: forceRescan.checked ? 1 : 0,
                        confirmed:    confirmed ? 1 : 0,
                    }, function(res) {
                        confirmStart.disabled = false;
                        confirmStart.textContent = 'Confirm & Start Scanning';
                        if (!res.success) {
                            // Large-job soft warning — ask once then proceed with confirmed=1
                            if (res.data && res.data.code === 'confirm_required') {
                                var msg = 'Large job: ' + res.data.count + ' businesses'
                                    + (res.data.cost_est ? ', est. $' + res.data.cost_est.toFixed(2) + ' in Claude costs' : '')
                                    + '.\n\nThis will take several hours. Continue?';
                                if (confirm(msg)) {
                                    confirmStart.disabled = true;
                                    confirmStart.textContent = 'Starting…';
                                    doStart(true);
                                }
                            } else {
                                alert((res.data && res.data.message) || res.data || 'Could not start job.');
                            }
                            return;
                        }
                        currentJob = res.data.job_id;
                        hide(confirmEl);
                        showMonitor(currentJob, true);
                    });
                }
                doStart(false);
            });

            // ── Monitor ───────────────────────────────────────────────────────
            function showMonitor(jobId, fresh) {
                currentJob = jobId;
                document.getElementById('fi-monitor-job-id').textContent = jobId;
                hide(document.getElementById('fi-bulk-history') || document.createElement('div'));
                show(monitorEl);
                hide(document.getElementById('fi-monitor-complete-bar'));
                if (fresh) {
                    document.getElementById('fi-queue-tbody').innerHTML = '';
                    document.getElementById('fi-progress-fill').style.width = '0%';
                    document.getElementById('fi-progress-label').textContent = '0 / -';
                }
                startPolling();
            }

            function startPolling() {
                stopPolling();
                poll();
                pollTimer = setInterval(poll, 10000);
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            function poll() {
                if (!currentJob) return;
                post('fi_bulk_poll', { job_id: currentJob }, renderMonitor);
            }

            // Contextual phase rotation for scanning items
            var phaseTimers = {};
            var phases = [
                'Resolving Google place ID…',
                'Fetching Business Profile data…',
                'Pulling competitor listings…',
                'Running PageSpeed analysis…',
                'Scoring with AI…',
                'Writing recommendations…',
            ];
            function startPhaseRotation(itemId, el) {
                if (phaseTimers[itemId]) return;
                var i = 0;
                el.textContent = phases[0];
                phaseTimers[itemId] = setInterval(function() {
                    i = (i + 1) % phases.length;
                    el.textContent = phases[i];
                }, 4500);
            }
            function clearPhaseTimers() {
                Object.keys(phaseTimers).forEach(function(k) { clearInterval(phaseTimers[k]); });
                phaseTimers = {};
            }

            // Activity log (last 8 events)
            var activityLog = [];
            function logActivity(msg) {
                var now = new Date();
                var t = now.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
                activityLog.unshift('[' + t + '] ' + msg);
                if (activityLog.length > 8) activityLog.pop();
                var el = document.getElementById('fi-activity-log');
                if (el) el.innerHTML = activityLog.map(function(l){ return '<li>' + escHtml(l) + '</li>'; }).join('');
            }

            var lastCompletedCount = 0;
            var lastFailedCount    = 0;
            var lastScanningItem   = null;

            function renderMonitor(res) {
                if (!res.success) return;
                var d   = res.data;
                var job = d.job;

                // Progress bar
                var pct = job.total > 0 ? Math.round((job.completed / job.total) * 100) : 0;
                document.getElementById('fi-progress-fill').style.width  = pct + '%';
                document.getElementById('fi-progress-label').textContent = job.completed + ' / ' + job.total;

                // Tokens
                document.getElementById('fi-monitor-tokens').textContent = fmtNum(job.tokens_used);
                var done      = parseInt(job.completed) + parseInt(job.failed) + parseInt(job.skipped || 0);
                var remaining = Math.max(0, job.total - done);
                document.getElementById('fi-monitor-tokens-remaining').textContent =
                    remaining > 0 ? '~' + fmtNum(remaining * perScanEst) : '-';

                // Meta
                var meta = '';
                if (job.started_at) meta = 'Started ' + job.started_at;
                if (job.status === 'paused') {
                    meta += ' · Paused';
                    // Show rate-limit backoff reason if present
                    if (job.error_note) meta += ': ' + job.error_note;
                }
                document.getElementById('fi-monitor-meta').textContent = meta;

                // Control buttons
                var hasStuck = d.items.some(function(i){ return i.status === 'scanning'; });
                pauseBtn.style.display  = job.status === 'running'  ? '' : 'none';
                resumeBtn.style.display = job.status === 'paused'   ? '' : 'none';
                killBtn.style.display   = (job.status === 'running' && hasStuck) ? '' : 'none';
                cancelBtn.style.display = ['running','paused'].indexOf(job.status) >= 0 ? '' : 'none';
                newBtn.style.display    = ['complete','cancelled'].indexOf(job.status) >= 0 ? '' : 'none';

                // ── Stats panel ───────────────────────────────────────────────
                var completedItems = d.items.filter(function(i){ return i.status === 'complete'; });
                var avgMs = 0;
                if (completedItems.length > 0) {
                    var totalMs = completedItems.reduce(function(s,i){ return s + parseInt(i.duration_ms||0); }, 0);
                    avgMs = totalMs / completedItems.length;
                }
                var etaEl  = document.getElementById('fi-stat-eta');
                var avgEl  = document.getElementById('fi-stat-avg');
                var doneEl = document.getElementById('fi-stat-done');
                var failEl = document.getElementById('fi-stat-failed');
                if (doneEl) doneEl.textContent = job.completed;
                if (failEl) failEl.textContent = job.failed || 0;
                if (avgEl)  avgEl.textContent  = avgMs > 0 ? (avgMs/1000).toFixed(1) + 's' : '-';
                if (etaEl) {
                    if (remaining > 0 && avgMs > 0) {
                        var etaSec = Math.ceil((remaining * avgMs) / 1000);
                        etaEl.textContent = etaSec >= 60
                            ? Math.ceil(etaSec/60) + ' min'
                            : etaSec + 's';
                    } else if (remaining === 0) {
                        etaEl.textContent = 'Done';
                    } else {
                        etaEl.textContent = 'Calculating…';
                    }
                }

                // Activity log — detect transitions
                var newCompleted = parseInt(job.completed) - lastCompletedCount;
                var newFailed    = parseInt(job.failed||0) - lastFailedCount;
                if (newCompleted > 0) {
                    // Slice from the END — items are sorted by position ASC, so the
                    // ones that just finished are at the tail of completedItems.
                    completedItems.slice(-newCompleted).forEach(function(i){
                        logActivity('✓ Scanned: ' + i.input_name + (i.tokens_used ? ' (' + fmtNum(i.tokens_used) + ' tok)' : ''));
                    });
                }
                if (newFailed > 0) {
                    d.items.filter(function(i){ return i.status==='failed'; }).slice(0, newFailed).forEach(function(i){
                        logActivity('✗ Failed: ' + i.input_name + (i.error_message ? ': ' + i.error_message : ''));
                    });
                }
                lastCompletedCount = parseInt(job.completed);
                lastFailedCount    = parseInt(job.failed||0);

                // Currently scanning item
                var scanningItem = d.items.find(function(i){ return i.status === 'scanning'; });
                var curEl   = document.getElementById('fi-stat-current');
                var phaseEl = document.getElementById('fi-stat-phase');
                if (scanningItem) {
                    if (curEl) curEl.textContent = scanningItem.input_name;
                    if (scanningItem.id !== lastScanningItem) {
                        lastScanningItem = scanningItem.id;
                        clearPhaseTimers();
                        if (phaseEl) startPhaseRotation(scanningItem.id, phaseEl);
                        logActivity('⟳ Scanning: ' + scanningItem.input_name);
                    }
                } else {
                    if (curEl) curEl.textContent = job.status === 'running' ? 'Starting…' : '-';
                    if (phaseEl) phaseEl.textContent = '';
                    clearPhaseTimers();
                    lastScanningItem = null;
                }

                // Queue rows
                var tbody = document.getElementById('fi-queue-tbody');
                tbody.innerHTML = d.items.map(function(item) {
                    var statusIcon = {
                        queued:   '<span class="fi-qs-queued">· Queued</span>',
                        scanning: '<span class="fi-qs-scanning"><span class="fi-spinner-inline"></span> Scanning…</span>',
                        complete: '<span class="fi-qs-complete">✓ Complete</span>',
                        failed:   '<span class="fi-qs-failed">✗ Failed</span>',
                        skipped:  '<span class="fi-qs-skipped">Skipped</span>',
                    }[item.status] || item.status;

                    var scoreDisplay = '-';
                    if (item.status === 'complete' && item.overall_score != null) {
                        var sc   = parseInt(item.overall_score, 10);
                        var scCl = sc >= 80 ? 'fi-score--strong' : sc >= 60 ? 'fi-score--avg' : 'fi-score--weak';
                        scoreDisplay = '<span class="fi-queue-score ' + scCl + '">' + sc + '</span>';
                    }
                    var tokDisplay = (item.status === 'complete' && item.tokens_used > 0)
                        ? fmtNum(item.tokens_used) + ' tok'
                        : '-';
                    var time   = item.duration_ms > 0 ? (item.duration_ms / 1000).toFixed(1) + 's' : '-';
                    var action = '';
                    if (item.status === 'failed') {
                        action = '<button type="button" class="button button-small fi-bulk-retry" data-item-id="' + item.id + '">Retry</button>';
                        if (item.error_message) action += '<div class="fi-bulk-error-msg">' + escHtml(item.error_message) + '</div>';
                    }
                    if (item.status === 'scanning') {
                        action = '<button type="button" class="button button-small fi-bulk-kill-item fi-bulk-btn--warn-sm" data-item-id="' + item.id + '" title="Force-fail this item">Kill</button>';
                    }
                    // Lead link — only shown once scan is complete and a prospect was created
                    var leadLink = (item.status === 'complete' && item.lead_id)
                        ? ' <a class="fi-queue-lead-link" href="admin.php?page=fi-market-intel&tab=leads" title="View lead in pipeline" target="_blank">↗ Lead</a>'
                        : '';

                    return '<tr class="fi-queue-row fi-queue-row--' + item.status + '">'
                        + '<td>' + item.position + '</td>'
                        + '<td>' + escHtml(item.input_name) + (item.input_address ? '<br><span class="fi-hint">' + escHtml(item.input_address) + '</span>' : '') + leadLink + '</td>'
                        + '<td>' + statusIcon + '</td>'
                        + '<td>' + scoreDisplay + '</td>'
                        + '<td>' + tokDisplay + '</td>'
                        + '<td>' + time + '</td>'
                        + '<td>' + action + '</td>'
                        + '</tr>';
                }).join('');

                // Retry buttons
                tbody.querySelectorAll('.fi-bulk-retry').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        retryItem(parseInt(btn.dataset.itemId));
                    });
                });

                // Kill individual stuck item buttons
                tbody.querySelectorAll('.fi-bulk-kill-item').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        post('fi_bulk_kill_item', { item_id: btn.dataset.itemId }, function(res) {
                            if (res.success) { logActivity('⚠ Killed item #' + btn.dataset.itemId); poll(); }
                            else { btn.disabled = false; alert(res.data || 'Could not kill item.'); }
                        });
                    });
                });

                // Complete bar
                if (['complete','cancelled'].indexOf(job.status) >= 0) {
                    stopPolling();
                    clearPhaseTimers();
                    var bar = document.getElementById('fi-monitor-complete-bar');
                    var summary = job.completed + ' of ' + job.total + ' scanned';
                    if (job.failed > 0) summary += ' · ' + job.failed + ' failed';
                    if (job.skipped > 0) summary += ' · ' + job.skipped + ' skipped';
                    summary += ' · Tokens used: ' + fmtNum(job.tokens_used);
                    document.getElementById('fi-monitor-complete-summary').textContent = summary;
                    show(bar);
                }
            }

            // ── Pause / Resume / Cancel ───────────────────────────────────────
            pauseBtn.addEventListener('click', function() {
                post('fi_bulk_pause', { job_id: currentJob }, function(res) {
                    if (res.success) { stopPolling(); poll(); }
                });
            });

            resumeBtn.addEventListener('click', function() {
                post('fi_bulk_resume', { job_id: currentJob }, function(res) {
                    if (res.success) startPolling();
                });
            });

            if (killBtn) killBtn.addEventListener('click', function() {
                if (!confirm('Force-fail all currently stuck items and unblock the queue? They will be marked as Failed; you can retry them individually.')) return;
                killBtn.disabled = true;
                killBtn.textContent = 'Killing…';
                post('fi_bulk_kill_stuck', { job_id: currentJob }, function(res) {
                    killBtn.disabled = false;
                    killBtn.textContent = '⚠ Kill Stuck';
                    if (res.success) {
                        logActivity('⚠ Killed ' + (res.data.killed || '?') + ' stuck item(s)');
                        poll();
                    } else {
                        alert(res.data || 'Could not kill stuck items.');
                    }
                });
            });

            cancelBtn.addEventListener('click', function() {
                if (!confirm('Cancel this job? Queued scans will not run.')) return;
                post('fi_bulk_cancel', { job_id: currentJob }, function(res) {
                    if (res.success) { stopPolling(); poll(); }
                });
            });

            // ── New job ───────────────────────────────────────────────────────
            function resetToImport() {
                stopPolling();
                clearPhaseTimers();
                currentJob        = null;
                pendingValid      = null;
                parsedCSV         = [];
                activityLog       = [];
                lastCompletedCount = 0;
                lastFailedCount    = 0;
                lastScanningItem   = null;
                csvInput.value = '';
                validateBtn.disabled = true;
                lineCount.textContent = '';
                hide(filePreview);
                show(uploadZone);
                hide(monitorEl);
                hide(confirmEl);
                show(importEl);
            }
            if (newBtn)  newBtn.addEventListener('click',  resetToImport);
            if (newBtn2) newBtn2.addEventListener('click', resetToImport);

            // ── Retry single item ─────────────────────────────────────────────
            function retryItem(itemId) {
                post('fi_bulk_retry_item', { item_id: itemId }, function(res) {
                    if (res.success) startPolling();
                    else alert(res.data || 'Could not retry item.');
                });
            }

            // ── Export CSV ────────────────────────────────────────────────────
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    if (!currentJob) return;
                    window.location.href = AJAX + '?action=fi_bulk_export_csv&job_id=' + currentJob + '&nonce=' + NONCE;
                });
            }

            // ── History "View" buttons ────────────────────────────────────────
            document.querySelectorAll('.fi-bulk-history-view').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    hide(importEl);
                    showMonitor(parseInt(btn.dataset.jobId), false);
                });
            });

            // ── Auto-resume on page load ──────────────────────────────────────────
            // Server-provided activeJobId is more reliable than scanning the DOM
            // table — works even when the active job falls outside the display window.
            (function autoResume() {
                var jobId  = fiInsightsBulk.activeJobId;
                var status = fiInsightsBulk.activeJobStatus;
                if ( ! jobId ) return;
                hide(importEl);
                showMonitor(jobId, false);
                if ( status === 'running' ) {
                    startPolling();   // running: kick off the 10s interval
                } else if ( status === 'paused' ) {
                    poll();           // paused: one-shot so UI reflects current state
                }
            }());

        }());
        </script>

        <?php
    }

    private static function render_free_teaser(): void {
        $total        = FI_DB::total_scans();
        $upgrade_url  = FI_Premium::upgrade_url();
        $settings_url = admin_url( 'admin.php?page=fi-insights&tab=api' );
        $scan_line    = $total > 0
            ? '<p style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 20px;">You have ' . (int) $total . ' scan' . ( $total === 1 ? '' : 's' ) . ' in your database.</p>'
            : '';
        ?>
        <div class="wrap fi-market-intel-wrap">
            <h1>Market Leads</h1>
            <div class="fi-upgrade-prompt" style="text-align:center;padding:36px 32px;">
                <p style="display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;background:#f3f4f6;padding:4px 10px;border-radius:99px;margin:0 0 14px;">Premium Feature</p>
                <h3 style="font-size:20px;font-weight:700;color:#111827;margin:0 0 8px;">Unlock Market Leads &amp; Analytics</h3>
                <?php echo $scan_line; ?>
                <?php if ( $upgrade_url !== '#' ) : ?>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"
                   style="display:inline-block;padding:12px 28px;background:#059669;color:#fff;font-size:15px;font-weight:700;border-radius:8px;text-decoration:none;margin-bottom:14px;">
                    Upgrade to Premium
                </a>
                <br>
                <?php endif; ?>
                <span style="font-size:13px;color:#6b7280;">Already have a license?
                    <a href="<?php echo esc_url( $settings_url ); ?>" style="color:#1d4ed8;text-decoration:underline;">Enter your key in Settings → API Config</a>.
                </span>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function stat( string $label, $value, string $sub = '', string $type = '' ): void {
        $class = 'fi-stat-card' . ( $type ? " fi-stat-card--$type" : '' );
        echo '<div class="' . esc_attr( $class ) . '">';
        echo '<div class="fi-stat-label">'  . esc_html( $label ) . '</div>';
        echo '<div class="fi-stat-value">'  . esc_html( $value ) . '</div>';
        if ( $sub ) {
            echo '<div class="fi-stat-sub">' . wp_kses( $sub, [ 'a' => [ 'href' => [], 'target' => [] ] ] ) . '</div>';
        }
        echo '</div>';
    }

}
