<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Shortcode
 * Registers [f_insights].
 * Also intercepts ?fi_report=TOKEN on any page and renders the shared report.
 */
class FI_Shortcode {

    public static function init() {
        add_shortcode( 'f_insights', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue() {
        if ( ! self::should_load() ) return;

        $api_key = get_option( 'fi_google_api_key', '' );

        wp_enqueue_style( 'fi-frontend', FI_ASSETS_URL . 'css/frontend.css', [], FI_VERSION );

        // Inject saved brand color tokens as CSS custom property overrides.
        // Only outputs a <style> block if at least one option differs from the
        // hardcoded defaults — keeping the DOM clean for unstyled installs.
        $inline = self::build_brand_css();
        if ( $inline ) {
            wp_add_inline_style( 'fi-frontend', $inline );
        }

        wp_enqueue_script( 'fi-frontend', FI_ASSETS_URL . 'js/frontend.js', [ 'jquery' ], FI_VERSION, true );

        // wp_localize_script outputs an inline <script> block each time it is
        // called. wp_enqueue_script is idempotent (WP de-dupes by handle), but
        // wp_localize_script is not — calling it twice produces duplicate inline
        // data. Guard with wp_script_is so calling enqueue() from render() when
        // the hook has already fired does not double-output the FI config object.
        if ( ! wp_script_is( 'fi-frontend', 'done' ) ) {
            wp_localize_script( 'fi-frontend', 'FI', [
            'ajax_url'             => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( 'fi_frontend_nonce' ),
            'google_key'           => $api_key,
            'autocomplete_radius'  => (int) get_option( 'fi_autocomplete_radius', 10 ),
            'scan_btn_text'        => get_option( 'fi_scan_btn_text', 'Scan Business' ),
            'search_placeholder'   => get_option( 'fi_search_placeholder', 'Type a business name to scan...' ),
            'premium_active'       => FI_Premium::is_active(),
            'hide_credit'          => (bool) get_option( 'fi_hide_credit', 0 ),
            'cta_enabled'          => (bool) get_option( 'fi_cta_enabled', 0 ),
            'cta_text'             => get_option( 'fi_cta_text', '' ),
            'cta_url'              => get_option( 'fi_cta_url', '' ),
            // Lead form config (premium only — safe to pass, gated in JS)
            'form_headline'        => get_option( 'fi_form_headline',    'Want this report in your inbox?' ),
            'form_subtext'         => get_option( 'fi_form_subtext',     'Get your full business insights report, free, no obligation.' ),
            'email_placeholder'    => get_option( 'fi_email_placeholder','Your email address' ),
            'email_btn_text'       => get_option( 'fi_email_btn_text',   'Get My Free Report' ),
            'form_thankyou'        => get_option( 'fi_form_thankyou',    'Your report is on its way! Check your inbox (and spam folder) in the next few minutes.' ),
            'form_fields'          => [
                'firstname'  => [ 'enabled' => (bool) get_option('fi_field_firstname_enabled',0),  'required' => (bool) get_option('fi_field_firstname_required',0)  ],
                'lastname'   => [ 'enabled' => (bool) get_option('fi_field_lastname_enabled',0),   'required' => (bool) get_option('fi_field_lastname_required',0)   ],
                'phone'      => [ 'enabled' => (bool) get_option('fi_field_phone_enabled',0),      'required' => (bool) get_option('fi_field_phone_required',0)      ],
                'role'       => [ 'enabled' => (bool) get_option('fi_field_role_enabled',0),       'required' => (bool) get_option('fi_field_role_required',0)       ],
                'employees'  => [ 'enabled' => (bool) get_option('fi_field_employees_enabled',0),  'required' => (bool) get_option('fi_field_employees_required',0)  ],
                'custom'     => [
                    'enabled'     => (bool) get_option('fi_field_custom_enabled',0),
                    'required'    => (bool) get_option('fi_field_custom_required',0),
                    'label'       => get_option('fi_field_custom_label',''),
                ],
            ],
            // Consent / GDPR
            'consent_enabled'      => (bool) get_option( 'fi_consent_enabled', 0 ),
            'consent_text'         => get_option( 'fi_consent_text', 'I agree to receive this report and understand my data will be used to follow up with relevant business advice.' ),
            'consent_privacy_url'  => get_option( 'fi_consent_privacy_url', '' ),
        ] );
        } // end wp_script_is guard
    }

    public static function render( $atts ) {
        // If the shortcode is being rendered, we definitely need the assets.
        // This handles cases where should_load() failed (e.g. widgets, page builders).
        self::enqueue();

        // Shared report token in URL — tokens are 32 hex chars; reject anything malformed
        $token = isset( $_GET['fi_report'] ) ? sanitize_text_field( $_GET['fi_report'] ) : '';
        if ( $token ) {
            if ( strlen( $token ) !== 32 || ! ctype_xdigit( $token ) ) {
                $token = '';
            }
        }
        if ( $token ) {
            return self::render_shared_report( $token );
        }

        $placeholder = esc_attr( get_option( 'fi_search_placeholder', 'Type a business name to scan...' ) );
        $scan_text   = esc_html( get_option( 'fi_scan_btn_text', 'Scan Business' ) );

        ob_start();
        ?>
        <div id="fi-scanner" class="fi-scanner-wrap">
            <div class="fi-search-bar">
                <div class="fi-search-input-wrap">
                    <input type="text"
                           id="fi-search-input"
                           placeholder="<?php echo esc_attr( $placeholder ); ?>"
                           autocomplete="off"
                           spellcheck="false">
                    <button type="button" id="fi-clear-btn" class="fi-clear-btn" aria-label="Clear search" style="display:none;">×</button>
                </div>
                <button type="button" id="fi-scan-btn" class="fi-btn-primary">
                    <?php echo esc_html( $scan_text ); ?>
                </button>
            </div>
            <?php /* Honeypot: hidden from real users via CSS, bots fill it, AJAX handler rejects non-empty value */ ?>
            <input type="text"
                   id="fi-hp-field"
                   name="fi_website"
                   value=""
                   autocomplete="off"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
            <div id="fi-report-area" class="fi-report-area" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─── Shared report render ─────────────────────────────────────────────────

    private static function render_shared_report( string $token ): string {
        $result = FI_Share::resolve( $token );

        if ( $result['expired'] ) {
            $reason = $result['reason'] ?? 'expired';

            if ( $reason === 'not_found' ) {
                $msg = 'This report link is invalid.';
                $cta = '<a href="' . esc_url( remove_query_arg( 'fi_report' ) ) . '" class="fi-btn-secondary">Run a New Scan</a>';
            } elseif ( $reason === 'scan_deleted' && ! empty( $result['place_id'] ) ) {
                // Scan data was purged but the share link is still valid — offer
                // a direct re-scan link pre-filled with the original place_id so
                // the recipient lands on a fresh report, not a dead end.
                $rescan_url = add_query_arg( 'fi_rescan', esc_attr( $result['place_id'] ), remove_query_arg( 'fi_report' ) );
                $msg = 'This report has expired but you can generate a fresh one.';
                $cta = '<a href="' . esc_url( $rescan_url ) . '" class="fi-btn-primary">Get a Fresh Report</a>'
                     . ' &nbsp; <a href="' . esc_url( remove_query_arg( 'fi_report' ) ) . '" class="fi-btn-secondary">Search a Different Business</a>';
            } else {
                $msg = 'This report link has expired.';
                $cta = '<a href="' . esc_url( remove_query_arg( 'fi_report' ) ) . '" class="fi-btn-secondary">Run a New Scan</a>';
            }

            return '<div class="fi-scanner-wrap">'
                 . '<div class="fi-expired">'
                 . '<p>' . esc_html( $msg ) . '</p>'
                 . '<p>' . $cta . '</p>'
                 . '</div></div>';
        }

        $report = $result['report'];
        $share  = $report['_share'] ?? [];
        $expiry = isset( $share['expires_at'] ) ? FI_Share::expiry_display( $share['expires_at'] ) : '';

        // Build a scan-like object from embedded report data
        $scan_meta = [
            'id'            => $report['_scan_id'] ?? 0,
            'business_name' => $report['name']        ?? '',
            'address'       => $report['address']     ?? '',
            'phone'         => $report['phone']       ?? '',
            'website'       => $report['website']     ?? '',
            'description'   => $report['description'] ?? '',
            'overall_score' => $report['overall_score'] ?? 0,
            'category'      => $report['category']    ?? '',
            'vague_match'   => $report['vague_match'] ?? false,
            'search_type'   => $report['search_type'] ?? '',
            'photos'        => $report['photos']      ?? [],
            'hours'         => $report['hours']       ?? [],
            'price_level'   => $report['price_level'] ?? null,
            // These come from _meta via FI_Share::resolve() — needed by the reviews and PageSpeed tabs
            'pagespeed'     => $report['pagespeed']   ?? null,
            'reviews_top'   => $report['reviews_top'] ?? [],
            'reviews_low'   => $report['reviews_low'] ?? [],
        ];

        // Encode as JSON and let the frontend render it
        // This keeps the render logic in one place (JS)
        ob_start();
        ?>
        <div id="fi-scanner" class="fi-scanner-wrap">
            <div id="fi-report-area" class="fi-report-area"></div>
            <?php if ( $expiry ) : ?>
            <p class="fi-hint" style="text-align:center;margin-top:8px;"><?php echo esc_html( $expiry ); ?> · <a href="<?php echo esc_url( remove_query_arg( 'fi_report' ) ); ?>">Run a new scan</a></p>
            <?php endif; ?>
        </div>
        <script>
        // Set shared report data before frontend.js loads so there's no race condition.
        // frontend.js reads window.FI_sharedReport on DOMContentLoaded.
        window.FI_sharedReport = <?php echo wp_json_encode( [
            'report'    => $report,
            'scan'      => $scan_meta,
            'share_url' => FI_Share::build_url( $token ),
        ] ); ?>;
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Build a :root { } CSS block overriding brand tokens with saved options.
     * Returns an empty string when no options have been customised, so we add
     * zero bytes to the page for default-colour installs.
     *
     * Hover variants (--fi-primary-dark, --fi-cta-dark) are derived here in PHP
     * using a simple HSL lightness reduction so they never need to be stored
     * separately. The JS WCAG checker only ever touches the base tokens.
     */
    private static function build_brand_css(): string {
        // White-label color defaults live in FI_Admin_Tab_White_Label, which is a
        // premium-only file. On the free version it won't exist — return early so
        // no inline style block is injected (the CSS defaults in frontend.css apply).
        if ( ! class_exists( 'FI_Admin_Tab_White_Label' ) ) {
            $path = FI_DIR . 'includes/admin/class-fi-admin-tab-white-label.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            } else {
                return '';
            }
        }

        $defaults = FI_Admin_Tab_White_Label::color_defaults();
        $vars     = [];

        $map = [
            'fi_color_primary'     => [ '--fi-primary',     '--fi-primary-dark' ],
            'fi_color_cta'         => [ '--fi-cta',         '--fi-cta-dark'     ],
            'fi_color_header_bg'   => [ '--fi-header-bg',   null                ],
            'fi_color_header_text' => [ '--fi-header-text', null                ],
            'fi_color_tab_bg'      => [ '--fi-tab-bg',      null                ],
            'fi_color_surface'     => [ '--fi-surface',     null                ],
            'fi_color_body_text'   => [ '--fi-body-text',   null                ],
            'fi_color_link'        => [ '--fi-link',        null                ],
        ];

        $any_custom = false;

        foreach ( $map as $option => [ $token, $dark_token ] ) {
            $saved = get_option( $option, '' );
            if ( '' === $saved || $saved === $defaults[ $option ] ) {
                continue; // No override needed — CSS default applies.
            }
            $any_custom = true;
            $vars[] = $token . ':' . esc_attr( $saved );
            if ( $dark_token ) {
                $vars[] = $dark_token . ':' . esc_attr( self::darken_hex( $saved, 10 ) );
            }
        }

        if ( ! $any_custom ) {
            return '';
        }

        return '.fi-scanner-wrap{' . implode( ';', $vars ) . '}';
    }

    /**
     * Darken a hex color by reducing its HSL lightness by $amount percent.
     * Used to auto-derive hover states from the base brand color.
     */
    private static function darken_hex( string $hex, int $amount ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec( substr( $hex, 0, 2 ) ) / 255;
        $g = hexdec( substr( $hex, 2, 2 ) ) / 255;
        $b = hexdec( substr( $hex, 4, 2 ) ) / 255;

        $max = max( $r, $g, $b );
        $min = min( $r, $g, $b );
        $l   = ( $max + $min ) / 2;
        $d   = $max - $min;

        if ( $d == 0 ) {
            $h = $s = 0;
        } else {
            $s = $d / ( 1 - abs( 2 * $l - 1 ) );
            switch ( $max ) {
                case $r: $h = fmod( ( $g - $b ) / $d, 6 ); break;
                case $g: $h = ( $b - $r ) / $d + 2;        break;
                default: $h = ( $r - $g ) / $d + 4;        break;
            }
            $h /= 6;
            if ( $h < 0 ) $h += 1;
        }

        $l = max( 0, $l - $amount / 100 );
        $c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
        $x = $c * ( 1 - abs( fmod( $h * 6, 2 ) - 1 ) );
        $m = $l - $c / 2;

        $h6 = $h * 6;
        if      ( $h6 < 1 ) { [ $r, $g, $b ] = [ $c, $x, 0 ]; }
        elseif  ( $h6 < 2 ) { [ $r, $g, $b ] = [ $x, $c, 0 ]; }
        elseif  ( $h6 < 3 ) { [ $r, $g, $b ] = [ 0, $c, $x ]; }
        elseif  ( $h6 < 4 ) { [ $r, $g, $b ] = [ 0, $x, $c ]; }
        elseif  ( $h6 < 5 ) { [ $r, $g, $b ] = [ $x, 0, $c ]; }
        else                 { [ $r, $g, $b ] = [ $c, 0, $x ]; }

        return sprintf( '#%02x%02x%02x',
            round( ( $r + $m ) * 255 ),
            round( ( $g + $m ) * 255 ),
            round( ( $b + $m ) * 255 )
        );
    }

    private static function should_load(): bool {
        global $post;

        // Never load inside page builder editors — they run as frontend pages
        // but our fixed-position dropdown and high z-index break their UI.
        if ( self::is_builder_context() ) return false;

        // Always load on shared report pages
        if ( isset( $_GET['fi_report'] ) ) return true;

        // Load if the shortcode is present in the post content
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'f_insights' ) ) return true;

        // Fallback: If we can't reliably detect the shortcode (e.g. in widgets or page builders),
        // we allow the user to force-load the assets via a filter or just check if the shortcode
        // was already rendered in this request (though that might be too late for enqueuing).
        // For now, let's add a filter so users can force it, and also check a global flag.
        if ( apply_filters( 'fi_force_load_assets', false ) ) return true;

        return false;
    }

    /**
     * Returns true when running inside a visual page builder editor.
     * Covers Divi, Elementor, Beaver Builder, and the WP block editor REST context.
     * All of these run wp_enqueue_scripts on the frontend URL — we must not
     * inject our styles/scripts into their editing environments.
     */
    private static function is_builder_context(): bool {
        // Divi Visual Builder (?et_fb=1) and Divi BFB (?et_bfb=1)
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_bfb'] ) ) return true;

        // Elementor editor
        if ( isset( $_GET['elementor-preview'] ) ) return true;

        // Beaver Builder
        if ( isset( $_GET['fl_builder'] ) ) return true;

        // WP block editor REST / preview context
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;

        return false;
    }
}
