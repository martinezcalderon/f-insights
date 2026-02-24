<?php
/**
 * Shortcode handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Shortcode {
    
    public function __construct() {
        add_shortcode('f_insights', array($this, 'render'));
    }
    
    /**
     * Render the shortcode
     */
    public function render($atts) {
        // Shortcode attribute 'placeholder' overrides the admin setting if explicitly provided.
        // White-label options (custom text/placeholder/icon) are premium-only.
        // If the license is no longer active, always fall back to the defaults so
        // previously saved premium values don't linger after a downgrade.
        $is_premium        = FI_Premium::is_active();
        $default_placeholder = __( 'Search a business', 'f-insights' );
        $default_btn_text    = __( 'Search Business', 'f-insights' );
        $admin_placeholder = $is_premium
            ? get_option( 'fi_scan_placeholder', $default_placeholder )
            : $default_placeholder;

        $atts = shortcode_atts( array(
            'placeholder' => $admin_placeholder,
        ), $atts );

        $scan_btn_text = $is_premium
            ? get_option( 'fi_scan_btn_text', $default_btn_text )
            : $default_btn_text;


        // ── Shared report: detect ?fi_report=TOKEN in the URL ────────────────
        // When present, render the pre-stored report instead of the search form.
        $shared_token = sanitize_text_field( wp_unslash( $_GET['fi_report'] ?? '' ) );
        if ( ! empty( $shared_token ) ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT report_json, expires_at, created_at FROM {$wpdb->prefix}fi_shared_reports WHERE token = %s LIMIT 1",
                $shared_token
            ) );

            if ( ! $row ) {
                // Token not found — show generic expired notice with no dates
                return $this->render_expired_notice(
                    esc_html__( 'an earlier date', 'f-insights' ),
                    esc_html__( 'a previous date', 'f-insights' )
                );
            }

            if ( strtotime( $row->expires_at ) < time() ) {
                return $this->render_expired_notice(
                    date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ),
                    date_i18n( get_option( 'date_format' ), strtotime( $row->expires_at ) )
                );
            }

            // Valid token — pass the report JSON to the frontend via a data attribute.
            // The JS picks this up on DOMContentLoaded and calls displayResults() directly.
            $report_json = esc_attr( $row->report_json );
            ob_start();
            ?>
            <div class="fi-scanner-container">
                <div id="fi-loading" class="fi-loading" style="display:none;"></div>
                <div id="fi-results" class="fi-results"
                     data-shared-report="<?php echo $report_json; ?>"
                     style="display:none;"></div>
            </div>
            <?php
            return ob_get_clean();
        }
        // ── End shared report detection ────────────────────────────────────

        ob_start();
        ?>
        <div class="fi-scanner-container">
            <div class="fi-scanner-form">
                <div class="fi-search-box">
                    <input 
                        type="text" 
                        id="fi-business-search" 
                        class="fi-search-input" 
                        placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                        autocomplete="off"
                    />
                    <button type="button" class="fi-search-button" id="fi-scan-button">
                        <span><?php echo esc_html( $scan_btn_text ); ?></span>
                    </button>
                </div>
                
                <div id="fi-business-suggestions" class="fi-suggestions"></div>
            </div>
            
            <div id="fi-loading" class="fi-loading" style="display: none;">
                <div class="fi-loading-header">
                    <div class="fi-loading-spinner"></div>
                    <p class="fi-loading-text"></p>
                </div>

                <div class="fi-skeleton-container">
                    <!-- Header Skeleton -->
                    <div class="fi-skeleton-header">
                        <div class="fi-skeleton-info">
                            <div class="fi-skeleton-line title"></div>
                            <div class="fi-skeleton-line address"></div>
                            <div class="fi-skeleton-meta">
                                <div class="fi-skeleton-pill"></div>
                                <div class="fi-skeleton-pill"></div>
                            </div>
                        </div>
                        <div class="fi-skeleton-score"></div>
                    </div>

                    <!-- Grid Skeleton -->
                    <div class="fi-skeleton-grid">
                        <?php for($i=0; $i<6; $i++): ?>
                        <div class="fi-skeleton-card">
                            <div class="fi-skeleton-card-header">
                                <div class="fi-skeleton-line card-title"></div>
                                <div class="fi-skeleton-circle"></div>
                            </div>
                            <div class="fi-skeleton-line headline"></div>
                            <div class="fi-skeleton-line summary"></div>
                            <div class="fi-skeleton-line summary short"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="fi-loading-checklist">
                    <div class="fi-checklist-item" data-step="1">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Analyzing Online Presence</span>
                    </div>
                    <div class="fi-checklist-item" data-step="2">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Evaluating Customer Reviews</span>
                    </div>
                    <div class="fi-checklist-item" data-step="3">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Inspecting Photos & Media</span>
                    </div>
                    <div class="fi-checklist-item" data-step="4">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Checking Business Information</span>
                    </div>
                    <div class="fi-checklist-item" data-step="5">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Mapping Competitive Landscape</span>
                    </div>
                    <div class="fi-checklist-item" data-step="6">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Auditing Website Performance</span>
                    </div>
                     <div class="fi-checklist-item" data-step="7">
                        <span class="fi-check-icon">○</span>
                        <span class="fi-check-text">Finalizing your custom report...</span>
                    </div>
                </div>
            </div>
            
            <div id="fi-results" class="fi-results" style="display: none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render an expired-report notice with a link back to run a fresh scan.
     * Shown when a shared report token is present but past its expiry date.
     *
     * @param string $created  Human-readable creation date.
     * @param string $expired  Human-readable expiry date.
     */
    private function render_expired_notice( string $created, string $expired ): string {
        ob_start();
        ?>
        <div class="fi-scanner-container">
            <div class="fi-email-report-section" style="border-color:#ccc; background:#fafafa; text-align:center;">
                <p style="font-size:28px; margin-bottom:14px; color:#ccc; line-height:1;">&#8987;</p>
                <h3 style="color:#555; font-size:20px; font-weight:700; margin:0 0 10px;"><?php esc_html_e( 'This report has expired', 'f-insights' ); ?></h3>
                <p style="font-size:14px; color:#666; margin:0 0 20px;">
                    <?php printf(
                        /* translators: 1: creation date, 2: expiry date */
                        esc_html__( 'Reports are available for a limited time after they\'re generated. This one was created on %1$s and expired on %2$s.', 'f-insights' ),
                        '<strong>' . esc_html( $created ) . '</strong>',
                        '<strong>' . esc_html( $expired ) . '</strong>'
                    ); ?>
                </p>
                <a href="<?php echo esc_url( strtok( home_url( add_query_arg( null, null ) ), '?' ) ); ?>"
                   style="display:inline-block; max-width:380px; width:100%; padding:12px 20px; background:#fff; color:#000; border:2px solid #000; font-size:14px; font-weight:600; text-decoration:none; text-align:center;">
                    <?php esc_html_e( 'Run a fresh scan →', 'f-insights' ); ?>
                </a>
                <p class="fi-powered-by" style="margin-top:16px;">
                    <a href="https://fricking.website/f-insights" target="_blank" rel="noopener noreferrer">get this tool for your wordpress site</a>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
