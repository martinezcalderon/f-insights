<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Admin_Tab_Reviews
 *
 * Renders the Reviews page under Market Leads.
 * Navigation: F! Insights > Market Leads > Reviews
 *
 * Record detail uses a three-step flow:
 *   Step 1 — Confirm: domain, review URL, label
 *   Step 2 — Configure: feature toggles + dependent settings
 *   Step 3 — Deploy: copy snippet, download QR, copy email template, manage tracking
 */
class FI_Admin_Tab_Reviews {

    public static function render(): void {
        $record_id = absint( $_GET['review_id'] ?? 0 );

        if ( $record_id ) {
            $record = FI_Reviews::get( $record_id );
            if ( $record ) {
                self::render_record_detail( $record );
                return;
            }
        }

        self::render_record_list();
    }

    // =========================================================================
    // Step readiness helpers
    // =========================================================================

    private static function step1_done( object $r ): bool {
        return ! empty( $r->domain ) && ! empty( $r->review_url );
    }

    private static function step2_done( object $r ): bool {
        return (bool) ( $r->feature_review_button
            || $r->feature_qr_display
            || $r->feature_display_widget );
    }

    private static function step3_done( object $r ): bool {
        return ! empty( $r->last_seen_at );
    }

    // =========================================================================
    // Record list
    // =========================================================================

    private static function render_record_list(): void {
        $search    = sanitize_text_field( $_GET['reviews_search'] ?? '' );
        $show_arch = ( ( $_GET['reviews_status'] ?? '' ) === 'archived' );
        $status    = $show_arch ? 'archived' : 'active';

        $records        = FI_Reviews::get_all( [ 'status' => $status, 'search' => $search, 'limit' => 100 ] );
        $active_count   = FI_Reviews::count( 'active' );
        $archived_count = FI_Reviews::count( 'archived' );
        ?>
        <div class="fi-reviews-wrap">

            <div class="fi-reviews-header">
                <p class="fi-reviews-intro">
                    Each record is a local business client you have decided to deploy review tools for.
                    Records are created from the <strong>Leads</strong> pipeline when you mark a deal
                    as <strong>Closed</strong> and click <strong>&#11088; Set Up Reviews</strong>.
                </p>
            </div>

            <div class="fi-reviews-toolbar">
                <form method="get" class="fi-reviews-search-form">
                    <input type="hidden" name="page" value="fi-market-intel">
                    <input type="hidden" name="tab"  value="reviews">
                    <?php if ( $show_arch ) : ?>
                    <input type="hidden" name="reviews_status" value="archived">
                    <?php endif; ?>
                    <input type="search" name="reviews_search"
                           value="<?php echo esc_attr( $search ); ?>"
                           placeholder="Search business, domain, or label..."
                           class="fi-input" style="width:280px;">
                    <button type="submit" class="button">Search</button>
                    <?php if ( $search ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-market-intel&tab=reviews' . ( $show_arch ? '&reviews_status=archived' : '' ) ) ); ?>"
                       class="button">Clear</a>
                    <?php endif; ?>
                </form>
                <div class="fi-reviews-toolbar-right">
                    <?php if ( ! $show_arch ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-market-intel&tab=reviews&reviews_status=archived' ) ); ?>"
                       class="fi-reviews-status-link">Archived (<?php echo $archived_count; ?>)</a>
                    <?php else : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fi-market-intel&tab=reviews' ) ); ?>"
                       class="fi-reviews-status-link">Active (<?php echo $active_count; ?>)</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( empty( $records ) ) : ?>
            <div class="fi-reviews-empty">
                <?php if ( $search ) : ?>
                    <p>No records matched <strong><?php echo esc_html( $search ); ?></strong>.</p>
                <?php elseif ( $show_arch ) : ?>
                    <p>No archived records.</p>
                <?php else : ?>
                    <p>No review records yet.</p>
                    <p class="fi-hint">Go to <strong>Leads</strong>, mark a deal as <strong>Closed</strong>,
                    and click <strong>&#11088; Set Up Reviews</strong> to create the first one.</p>
                <?php endif; ?>
            </div>
            <?php else : ?>

            <table class="fi-reviews-table widefat">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Domain</th>
                        <th>Progress</th>
                        <th>Features on</th>
                        <th>Tracking</th>
                        <th>Last seen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $records as $rec ) :
                    $surfaces     = FI_Reviews::get_tracking_surfaces( (int) $rec->id );
                    $total_clicks = array_sum( array_column( $surfaces, 'clicks' ) );
                    $total_views  = array_sum( array_column( $surfaces, 'views' ) );
                    $features_on  = [];
                    if ( $rec->feature_review_button )  $features_on[] = 'Button';
                    if ( $rec->feature_qr_display )     $features_on[] = 'QR';
                    if ( $rec->feature_display_widget ) $features_on[] = 'Display';
                    if ( $rec->feature_multi_location ) $features_on[] = 'Multi-loc';
                    $s1 = self::step1_done( $rec );
                    $s2 = self::step2_done( $rec );
                    $s3 = self::step3_done( $rec );
                    $detail_url = admin_url( 'admin.php?page=fi-market-intel&tab=reviews&review_id=' . $rec->id );
                ?>
                <tr class="fi-reviews-row" data-record-id="<?php echo (int) $rec->id; ?>">
                    <td class="fi-reviews-cell-name">
                        <a href="<?php echo esc_url( $detail_url ); ?>" class="fi-reviews-name-link">
                            <?php echo esc_html( $rec->label ?: $rec->business_name ); ?>
                        </a>
                        <?php if ( $rec->label && $rec->label !== $rec->business_name ) : ?>
                        <div class="fi-reviews-sub"><?php echo esc_html( $rec->business_name ); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="fi-reviews-cell-domain">
                        <?php if ( $rec->domain ) : ?>
                        <a href="<?php echo esc_url( 'https://' . $rec->domain ); ?>"
                           target="_blank" rel="noopener" class="fi-pipeline-link">
                            <?php echo esc_html( $rec->domain ); ?>
                        </a>
                        <?php else : ?>
                        <span class="fi-hint">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="fi-reviews-cell-progress">
                        <span class="fi-reviews-step-pip <?php echo $s1 ? 'fi-reviews-step-pip--done' : 'fi-reviews-step-pip--todo'; ?>"
                              title="Step 1: Confirm">1</span>
                        <span class="fi-reviews-step-pip <?php echo $s2 ? 'fi-reviews-step-pip--done' : ( $s1 ? 'fi-reviews-step-pip--next' : 'fi-reviews-step-pip--todo' ); ?>"
                              title="Step 2: Configure">2</span>
                        <span class="fi-reviews-step-pip <?php echo $s3 ? 'fi-reviews-step-pip--done' : ( $s2 ? 'fi-reviews-step-pip--next' : 'fi-reviews-step-pip--todo' ); ?>"
                              title="Step 3: Deploy">3</span>
                    </td>
                    <td class="fi-reviews-cell-features">
                        <?php if ( $features_on ) : ?>
                            <?php foreach ( $features_on as $f ) : ?>
                            <span class="fi-reviews-badge fi-reviews-badge--on"><?php echo esc_html( $f ); ?></span>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <span class="fi-hint">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="fi-reviews-cell-tracking">
                        <?php if ( $surfaces ) : ?>
                        <span class="fi-reviews-tracking-summary">
                            <?php echo count( $surfaces ); ?> surface<?php echo count( $surfaces ) !== 1 ? 's' : ''; ?> &mdash;
                            <?php echo number_format( $total_views ); ?> views /
                            <?php echo number_format( $total_clicks ); ?> clicks
                        </span>
                        <?php else : ?>
                        <span class="fi-hint">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="fi-reviews-cell-seen">
                        <?php echo $rec->last_seen_at
                            ? esc_html( human_time_diff( strtotime( $rec->last_seen_at ) ) . ' ago' )
                            : '<span class="fi-hint">Never</span>'; ?>
                    </td>
                    <td class="fi-reviews-cell-actions">
                        <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
                            <?php echo ( $s1 && $s2 ) ? 'Manage' : 'Continue setup'; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Record detail — three-step flow
    // =========================================================================

    private static function render_record_detail( object $record ): void {
        $back_url  = admin_url( 'admin.php?page=fi-market-intel&tab=reviews' );
        $snippet   = FI_Reviews::generate_snippet( $record );
        $surfaces  = FI_Reviews::get_tracking_surfaces( (int) $record->id );
        $email_tpl = FI_Reviews::email_template( $record );

        $default_attr_text = get_option( 'fi_white_label_name', get_bloginfo( 'name' ) );
        $default_attr_url  = home_url();

        $s1 = self::step1_done( $record );
        $s2 = self::step2_done( $record );
        $s3 = self::step3_done( $record );

        $open_step = 1;
        if ( $s1 ) $open_step = 2;
        if ( $s1 && $s2 ) $open_step = 3;
        ?>
        <div class="fi-reviews-detail-wrap">

            <div class="fi-reviews-breadcrumb">
                <a href="<?php echo esc_url( $back_url ); ?>">&larr; All Records</a>
                <span class="fi-reviews-breadcrumb-sep">/</span>
                <span><?php echo esc_html( $record->label ?: $record->business_name ); ?></span>
            </div>

            <div class="fi-reviews-detail-header">
                <div>
                    <h2 class="fi-reviews-detail-title">
                        <?php echo esc_html( $record->label ?: $record->business_name ); ?>
                    </h2>
                    <?php if ( $record->domain ) : ?>
                    <a href="<?php echo esc_url( 'https://' . $record->domain ); ?>"
                       target="_blank" rel="noopener" class="fi-reviews-detail-domain">
                        <?php echo esc_html( $record->domain ); ?> &nearr;
                    </a>
                    <?php else : ?>
                    <span class="fi-reviews-detail-domain fi-reviews-detail-domain--missing">No domain set yet</span>
                    <?php endif; ?>
                </div>
                <div class="fi-reviews-detail-header-actions">
                    <?php if ( $record->status === 'active' ) : ?>
                    <button class="button fi-reviews-archive-btn"
                            data-record-id="<?php echo (int) $record->id; ?>"
                            data-confirm="Archive this record? The snippet will show the fallback message until restored.">
                        Archive
                    </button>
                    <?php else : ?>
                    <button class="button fi-reviews-restore-btn"
                            data-record-id="<?php echo (int) $record->id; ?>">
                        Restore
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step rail -->
            <div class="fi-reviews-step-rail">
                <?php
                $steps = [
                    1 => [ 'label' => 'Confirm',    'desc' => 'Domain and review URL', 'done' => $s1 ],
                    2 => [ 'label' => 'Configure',  'desc' => 'Turn features on',      'done' => $s2 ],
                    3 => [ 'label' => 'Deploy',     'desc' => 'Hand off to client',    'done' => $s3 ],
                ];
                foreach ( $steps as $num => $step ) :
                    $cls = 'fi-reviews-step-node';
                    if ( $step['done'] )         $cls .= ' fi-reviews-step-node--done';
                    elseif ( $num === $open_step ) $cls .= ' fi-reviews-step-node--active';
                    else                           $cls .= ' fi-reviews-step-node--pending';
                ?>
                <button type="button" class="<?php echo $cls; ?>" data-step="<?php echo $num; ?>">
                    <span class="fi-reviews-step-num">
                        <?php echo $step['done'] ? '&#10003;' : $num; ?>
                    </span>
                    <span class="fi-reviews-step-info">
                        <strong><?php echo esc_html( $step['label'] ); ?></strong>
                        <span><?php echo esc_html( $step['desc'] ); ?></span>
                    </span>
                </button>
                <?php if ( $num < 3 ) : ?>
                <span class="fi-reviews-step-connector <?php echo $step['done'] ? 'fi-reviews-step-connector--done' : ''; ?>"></span>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Step 1: Confirm -->
            <div class="fi-reviews-step-panel <?php echo $open_step === 1 ? '' : 'fi-hidden'; ?>" id="fi-rv-step-1">
                <div class="fi-reviews-step-panel-inner">
                    <p class="fi-reviews-step-context">
                        This record was created from your lead pipeline. Confirm the details below before
                        configuring what the snippet delivers on the client site.
                    </p>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_rv_label">Internal label</label>
                        <input type="text" id="fi_rv_label" class="fi-input fi-reviews-autosave"
                               data-field="label" data-record-id="<?php echo (int) $record->id; ?>"
                               value="<?php echo esc_attr( $record->label ); ?>"
                               placeholder="<?php echo esc_attr( $record->business_name ); ?>"
                               style="max-width:360px;">
                        <p class="fi-hint">Your name for this client in the dashboard. Not visible to anyone else.</p>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_rv_domain">Client domain</label>
                        <div class="fi-reviews-domain-row">
                            <span class="fi-reviews-domain-prefix">https://</span>
                            <input type="text" id="fi_rv_domain" class="fi-input fi-reviews-autosave"
                                   data-field="domain" data-record-id="<?php echo (int) $record->id; ?>"
                                   value="<?php echo esc_attr( $record->domain ); ?>"
                                   placeholder="example.com" style="max-width:300px;">
                        </div>
                        <p class="fi-hint">Where the snippet will be deployed. If the domain doesn't match at load time, the fallback message shows instead of the widget.</p>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_rv_review_url">Google review link</label>
                        <input type="url" id="fi_rv_review_url" class="fi-input fi-reviews-autosave"
                               data-field="review_url" data-record-id="<?php echo (int) $record->id; ?>"
                               value="<?php echo esc_attr( $record->review_url ); ?>"
                               style="max-width:500px;">
                        <p class="fi-hint">Auto-built from the Place ID resolved during the original scan. Only change this if the business uses a custom short link for reviews.</p>
                        <?php if ( $record->review_url ) : ?>
                        <a href="<?php echo esc_url( $record->review_url ); ?>" target="_blank" rel="noopener"
                           class="fi-reviews-test-link">Test this link &nearr;</a>
                        <?php endif; ?>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_rv_notes">Notes</label>
                        <textarea id="fi_rv_notes" class="fi-textarea fi-reviews-autosave"
                                  data-field="notes" data-record-id="<?php echo (int) $record->id; ?>"
                                  rows="2" style="max-width:500px;"
                                  placeholder="Optional internal notes about this client..."><?php echo esc_textarea( $record->notes ?? '' ); ?></textarea>
                    </div>

                    <div class="fi-reviews-step-footer">
                        <button type="button" class="button button-primary fi-reviews-step-next" data-next="2"
                                <?php echo ( ! $record->domain || ! $record->review_url ) ? 'disabled' : ''; ?>>
                            Looks good &rarr; Choose features
                        </button>
                        <?php if ( ! $record->domain || ! $record->review_url ) : ?>
                        <span class="fi-hint" style="margin-left:12px;">Set the domain and review link to continue.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Step 2: Configure -->
            <div class="fi-reviews-step-panel <?php echo $open_step === 2 ? '' : 'fi-hidden'; ?>" id="fi-rv-step-2">
                <div class="fi-reviews-step-panel-inner">
                    <p class="fi-reviews-step-context">
                        Choose what the snippet renders on <strong><?php echo esc_html( $record->domain ?: 'the client site' ); ?></strong>.
                        Each toggle is independent. Changes take effect on the next page load with no reinstall.
                    </p>

                    <div class="fi-reviews-feature-group">
                        <div class="fi-reviews-feature-group-label">Review collection</div>
                        <?php self::toggle_row( $record, 'feature_review_button', 'Review button',
                            'A styled button on the site that takes customers directly to the Google review form. One tap, no searching.' ); ?>
                        <?php self::toggle_row( $record, 'feature_qr_display', 'QR code on site',
                            'Embeds a scannable QR code on the page. Customers scan it and land on the review form.' ); ?>
                        <?php self::toggle_row( $record, 'feature_multi_location', 'Multiple locations',
                            'Adds a dropdown so customers choose the right location before reviewing. Use for businesses with more than one site.' ); ?>
                    </div>

                    <div class="fi-reviews-feature-group" style="margin-top:20px;">
                        <div class="fi-reviews-feature-group-label">Review display</div>
                        <?php self::toggle_row( $record, 'feature_display_widget', 'Show existing reviews',
                            'Displays stored Google reviews on the client site. Social proof for new visitors before they decide to engage.' ); ?>

                        <div class="fi-reviews-display-config <?php echo $record->feature_display_widget ? '' : 'fi-hidden'; ?>"
                             id="fi-reviews-display-config">
                            <div class="fi-reviews-subsettings">
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_count">Reviews to show</label>
                                    <input type="number" id="fi_rv_count" class="fi-input fi-reviews-autosave"
                                           data-field="display_count" data-record-id="<?php echo (int) $record->id; ?>"
                                           value="<?php echo (int) $record->display_count; ?>"
                                           min="1" max="50" style="width:70px;">
                                    <span class="fi-hint" style="margin-left:8px;">Default 5.</span>
                                </div>
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_layout">Layout</label>
                                    <select id="fi_rv_layout" class="fi-select fi-reviews-autosave"
                                            data-field="display_layout" data-record-id="<?php echo (int) $record->id; ?>"
                                            style="width:140px;">
                                        <option value="list" <?php selected( $record->display_layout, 'list' ); ?>>List</option>
                                        <option value="grid" <?php selected( $record->display_layout, 'grid' ); ?>>Grid</option>
                                    </select>
                                </div>
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_stars">Minimum stars</label>
                                    <select id="fi_rv_stars" class="fi-select fi-reviews-autosave"
                                            data-field="display_min_stars" data-record-id="<?php echo (int) $record->id; ?>"
                                            style="width:140px;">
                                        <?php for ( $s = 1; $s <= 5; $s++ ) : ?>
                                        <option value="<?php echo $s; ?>" <?php selected( (int) $record->display_min_stars, $s ); ?>><?php echo $s; ?>+ stars</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_sort">Sort order</label>
                                    <select id="fi_rv_sort" class="fi-select fi-reviews-autosave"
                                            data-field="display_sort" data-record-id="<?php echo (int) $record->id; ?>"
                                            style="width:160px;">
                                        <option value="newest"   <?php selected( $record->display_sort, 'newest' ); ?>>Newest first</option>
                                        <option value="highest"  <?php selected( $record->display_sort, 'highest' ); ?>>Highest rated first</option>
                                        <option value="relevant" <?php selected( $record->display_sort, 'relevant' ); ?>>Most relevant</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fi-reviews-feature-group" style="margin-top:20px;">
                        <div class="fi-reviews-feature-group-label">Attribution</div>
                        <?php self::toggle_row( $record, 'feature_attribution', 'Show credit line',
                            'Displays a small branded link below the widget. Turn off for clients on a paid white-label arrangement.' ); ?>
                        <div class="fi-reviews-attribution-config <?php echo $record->feature_attribution ? '' : 'fi-hidden'; ?>"
                             id="fi-reviews-attribution-config">
                            <div class="fi-reviews-subsettings">
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_attr_text">Credit text</label>
                                    <input type="text" id="fi_rv_attr_text" class="fi-input fi-reviews-autosave"
                                           data-field="attribution_text" data-record-id="<?php echo (int) $record->id; ?>"
                                           value="<?php echo esc_attr( $record->attribution_text ?? '' ); ?>"
                                           placeholder="<?php echo esc_attr( 'Review prompt by ' . $default_attr_text ); ?>"
                                           style="max-width:300px;">
                                </div>
                                <div class="fi-reviews-subsetting-row">
                                    <label class="fi-label fi-label-sm" for="fi_rv_attr_url">Credit link</label>
                                    <input type="url" id="fi_rv_attr_url" class="fi-input fi-reviews-autosave"
                                           data-field="attribution_url" data-record-id="<?php echo (int) $record->id; ?>"
                                           value="<?php echo esc_attr( $record->attribution_url ?? '' ); ?>"
                                           placeholder="<?php echo esc_attr( $default_attr_url ); ?>"
                                           style="max-width:360px;">
                                </div>
                                <p class="fi-hint" style="margin:6px 0 0;">Defaults to your White Label brand name and URL. Override here for this client only.</p>
                            </div>
                        </div>
                    </div>

                    <div class="fi-reviews-step-footer">
                        <button type="button" class="button fi-reviews-step-prev" data-prev="1">&larr; Back</button>
                        <button type="button" class="button button-primary fi-reviews-step-next" data-next="3"
                                <?php echo ! self::step2_done( $record ) ? 'disabled' : ''; ?>>
                            Ready to deploy &rarr;
                        </button>
                        <?php if ( ! self::step2_done( $record ) ) : ?>
                        <span class="fi-hint" style="margin-left:12px;">Enable at least one feature to continue.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Step 3: Deploy -->
            <div class="fi-reviews-step-panel <?php echo $open_step === 3 ? '' : 'fi-hidden'; ?>" id="fi-rv-step-3">
                <div class="fi-reviews-step-panel-inner">

                    <?php if ( ! $s3 ) : ?>
                    <div class="fi-reviews-deploy-callout">
                        <strong>Ready to go.</strong>
                        Copy the snippet and paste it anywhere on
                        <strong><?php echo esc_html( $record->domain ?: 'the client site' ); ?></strong>
                        — a header, footer, or page template. Once it loads for the first time, this record shows as live.
                    </div>
                    <?php else : ?>
                    <div class="fi-reviews-deploy-callout fi-reviews-deploy-callout--live">
                        <span class="fi-reviews-live-dot"></span>
                        <strong>Live</strong> &mdash; last seen
                        <?php echo esc_html( human_time_diff( strtotime( $record->last_seen_at ) ) ); ?> ago
                        on <strong><?php echo esc_html( $record->domain ); ?></strong>.
                        Toggle features in Step 2 — changes take effect on next page load, no reinstall.
                    </div>
                    <?php endif; ?>

                    <!-- Deliverables grid -->
                    <div class="fi-rv-deliverables">

                        <!-- Card 1: Snippet -->
                        <div class="fi-rv-card fi-rv-card--snippet">
                            <div class="fi-rv-card-header">
                                <span class="fi-rv-card-icon">&#9001;/&#9002;</span>
                                <div>
                                    <strong>Website snippet</strong>
                                    <span class="fi-rv-card-sub">Paste once on the client site</span>
                                </div>
                            </div>
                            <p class="fi-rv-card-desc">
                                One script tag. Paste it in the site header, footer, or directly on a page.
                                All enabled features render automatically — no reinstall needed when you change settings here.
                            </p>
                            <div class="fi-rv-snippet-box">
                                <code id="fi-reviews-snippet-code"><?php echo esc_html( $snippet ); ?></code>
                            </div>
                            <button type="button" class="button fi-rv-copy-btn"
                                    data-copy-from="fi-reviews-snippet-code">
                                Copy snippet
                            </button>
                        </div>

                        <!-- Card 2: QR -->
                        <div class="fi-rv-card fi-rv-card--qr">
                            <div class="fi-rv-card-header">
                                <span class="fi-rv-card-icon">&#9638;</span>
                                <div>
                                    <strong>QR code</strong>
                                    <span class="fi-rv-card-sub">Print for counter, receipt, signage</span>
                                </div>
                            </div>
                            <p class="fi-rv-card-desc">
                                Download and hand to the client. They stick it on a counter card, receipt,
                                or window — customers scan and land directly on the review form.
                            </p>
                            <?php if ( $record->review_url ) : ?>
                            <div class="fi-rv-qr-wrap">
                                <div id="fi-rv-qr-canvas"
                                     data-url="<?php echo esc_attr( $record->review_url ); ?>"
                                     data-name="<?php echo esc_attr( $record->business_name ); ?>">
                                </div>
                            </div>
                            <div class="fi-rv-qr-actions">
                                <button type="button" class="button fi-rv-qr-download-btn">
                                    Download PNG
                                </button>
                                <span class="fi-hint" style="margin-left:8px;">300 &times; 300px</span>
                            </div>
                            <?php else : ?>
                            <p class="fi-hint">Set a Review URL in Step 1 to generate the QR.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Card 3: Email template -->
                        <div class="fi-rv-card fi-rv-card--email">
                            <div class="fi-rv-card-header">
                                <span class="fi-rv-card-icon">&#9993;</span>
                                <div>
                                    <strong>Email template</strong>
                                    <span class="fi-rv-card-sub">Send after a purchase or visit</span>
                                </div>
                            </div>
                            <p class="fi-rv-card-desc">
                                Copy and paste into any email tool. Business name and review link
                                are pre-filled — edit the greeting or tone if needed before sending.
                            </p>
                            <div class="fi-rv-email-box">
                                <pre id="fi-reviews-email-tpl"><?php echo esc_html( $email_tpl ); ?></pre>
                            </div>
                            <button type="button" class="button fi-rv-copy-btn"
                                    data-copy-from="fi-reviews-email-tpl">
                                Copy template
                            </button>
                        </div>

                    </div><!-- /.fi-rv-deliverables -->

                    <!-- Tracking surfaces -->
                    <div class="fi-rv-tracking-section">
                        <div class="fi-rv-tracking-header">
                            <div>
                                <strong>Link tracking</strong>
                                <span class="fi-reviews-optional-tag">optional</span>
                            </div>
                            <p class="fi-hint" style="margin:4px 0 0;">
                                Each surface gets a tagged URL. Use it instead of the plain link in a specific
                                placement — views and clicks count per surface so you can see what's working.
                                Your IP is excluded.
                            </p>
                        </div>

                        <?php if ( $surfaces ) : ?>
                        <div class="fi-rv-surfaces">
                            <?php foreach ( $surfaces as $surf ) :
                                $tagged     = FI_Reviews::build_tracked_url( $record, $surf->param );
                                $has_data   = $surf->views > 0 || $surf->clicks > 0;
                            ?>
                            <div class="fi-rv-surface-card" data-surface-id="<?php echo (int) $surf->id; ?>">
                                <div class="fi-rv-surface-top">
                                    <div class="fi-rv-surface-name"><?php echo esc_html( $surf->label ); ?></div>
                                    <button type="button" class="fi-reviews-surface-delete"
                                            data-surface-id="<?php echo (int) $surf->id; ?>"
                                            data-record-id="<?php echo (int) $record->id; ?>"
                                            title="Remove">&times;</button>
                                </div>
                                <div class="fi-rv-surface-stats">
                                    <span class="fi-rv-stat">
                                        <span class="fi-rv-stat-num"><?php echo number_format( $surf->views ); ?></span>
                                        <span class="fi-rv-stat-label">views</span>
                                    </span>
                                    <span class="fi-rv-stat-sep">/</span>
                                    <span class="fi-rv-stat">
                                        <span class="fi-rv-stat-num"><?php echo number_format( $surf->clicks ); ?></span>
                                        <span class="fi-rv-stat-label">clicks</span>
                                    </span>
                                    <?php if ( $has_data && $surf->views > 0 ) :
                                        $ctr = round( ( $surf->clicks / $surf->views ) * 100 );
                                    ?>
                                    <span class="fi-rv-stat-ctr"><?php echo $ctr; ?>% CTR</span>
                                    <?php endif; ?>
                                </div>
                                <div class="fi-rv-surface-param"><code><?php echo esc_html( $surf->param ); ?></code></div>
                                <?php if ( $tagged ) : ?>
                                <button type="button" class="button button-small fi-rv-surface-copy-btn"
                                        data-copy="<?php echo esc_attr( $tagged ); ?>"
                                        onclick="navigator.clipboard.writeText(this.dataset.copy);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy tagged URL',1500)">
                                    Copy tagged URL
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Add surface -->
                        <div class="fi-rv-add-surface" id="fi-reviews-add-surface">
                            <input type="text" id="fi-reviews-surface-label"
                                   class="fi-input" placeholder="Label  (e.g. Counter card QR)" style="width:210px;">
                            <input type="text" id="fi-reviews-surface-param"
                                   class="fi-input" placeholder="Param  (e.g. counter_qr)" style="width:180px;">
                            <button type="button" class="button fi-reviews-surface-add-btn"
                                    data-record-id="<?php echo (int) $record->id; ?>">Add surface</button>
                            <p class="fi-hint" style="margin-top:5px;">Param: lowercase letters, numbers, and underscores only.</p>
                        </div>
                    </div><!-- /.fi-rv-tracking-section -->

                    <div class="fi-reviews-step-footer">
                        <button type="button" class="button fi-reviews-step-prev" data-prev="2">&larr; Back to features</button>
                    </div>

                </div>
            </div>

        </div>
        <?php
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    private static function toggle_row( object $record, string $field, string $label, string $hint ): void {
        $checked = ! empty( $record->$field );
        ?>
        <div class="fi-reviews-toggle-row">
            <label class="fi-reviews-toggle-label">
                <input type="checkbox"
                       class="fi-reviews-toggle"
                       data-field="<?php echo esc_attr( $field ); ?>"
                       data-record-id="<?php echo (int) $record->id; ?>"
                       <?php checked( $checked ); ?>>
                <span class="fi-reviews-toggle-text">
                    <strong><?php echo esc_html( $label ); ?></strong>
                    <span class="fi-hint"><?php echo esc_html( $hint ); ?></span>
                </span>
            </label>
        </div>
        <?php
    }
}
