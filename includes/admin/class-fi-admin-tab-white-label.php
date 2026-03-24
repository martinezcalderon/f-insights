<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_White_Label {

    /**
     * Default brand color values — single source of truth.
     * Must stay in sync with the :root block in frontend.css.
     */
    public static function color_defaults(): array {
        return [
            'fi_color_primary'     => '#1d4ed8',
            'fi_color_cta'         => '#059669',
            'fi_color_header_bg'   => '#1a1a2e',
            'fi_color_header_text' => '#ffffff',
            'fi_color_tab_bg'      => '#f3f4f6',
            'fi_color_surface'     => '#ffffff',
            'fi_color_body_text'   => '#374151',
            'fi_color_link'        => '#1d4ed8',
        ];
    }

    public static function render(): void {
        if ( ! FI_Premium::is_active() ) {
            echo FI_Premium::upgrade_prompt( 'White-Label' );
            return;
        }

        $logo_url    = get_option( 'fi_logo_url', '' );
        $brand_name  = get_option( 'fi_brand_name', '' );
        $reply_to    = get_option( 'fi_reply_to_email', '' );
        $search_ph   = get_option( 'fi_search_placeholder', 'Type a business name to scan...' );
        $scan_btn    = get_option( 'fi_scan_btn_text', 'Scan Business' );
        $email_ph    = get_option( 'fi_email_placeholder', 'Where should we send the report?' );
        $email_btn   = get_option( 'fi_email_btn_text', 'Get My Free Report' );
        $cta_enabled = get_option( 'fi_cta_enabled', 0 );
        $cta_text    = get_option( 'fi_cta_text', 'Book a Free Consultation' );
        $cta_url     = get_option( 'fi_cta_url', '' );
        $hide_credit = get_option( 'fi_hide_credit', 0 );
        $rpt_title   = get_option( 'fi_report_title', 'Your Business Insights Report' );
        $footer_cta  = get_option( 'fi_email_footer_cta', 'Want help putting these into action? Reply to this email.' );
        $defaults = self::color_defaults();
        $colors   = [];
        foreach ( $defaults as $key => $default ) {
            $colors[ $key ] = get_option( $key, $default );
        }
        ?>
        <div class="fi-settings-form">

            <!-- ── Brand Colors ─────────────────────────────────────────── -->
            <div class="fi-section-title">Brand Colors</div>

            <p class="fi-hint" style="margin-bottom:16px;">
                Customize the UI chrome of your scanner widget. Score indicator colors (green / amber / red) are
                system-defined and cannot be changed, they carry universally understood meaning.
                The WCAG checker below flags any combination that would fail AA contrast (4.5:1 ratio), and
                can auto-suggest the nearest color that passes.
            </p>

            <!-- Buttons -->
            <p class="fi-label" style="margin-bottom:10px;">Buttons</p>
            <div class="fi-color-group">
                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_primary">Primary Button</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_primary"
                               name="fi_color_primary"
                               value="<?php echo esc_attr( $colors['fi_color_primary'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_primary_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_primary'] ) ); ?>"
                               maxlength="7"
                               aria-label="Primary button hex value">
                    </div>
                    <div class="fi-wcag-row">
                        <span class="fi-wcag-badge"
                              id="fi_color_primary_badge"
                              data-fg="#ffffff"
                              data-bg-source="fi_color_primary"
                              title="White text on primary button background">
                            Checking…
                        </span>
                        <button type="button"
                                class="fi-autofix-btn"
                                id="fi_color_primary_fix"
                                data-target="fi_color_primary"
                                data-fix-fg="#ffffff">
                            ↺ Auto-fix
                        </button>
                    </div>
                    <p class="fi-hint">Scan button, active tab underline, focus rings, loading spinner.</p>
                </div>

                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_cta">CTA Button</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_cta"
                               name="fi_color_cta"
                               value="<?php echo esc_attr( $colors['fi_color_cta'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_cta_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_cta'] ) ); ?>"
                               maxlength="7"
                               aria-label="CTA button hex value">
                    </div>
                    <div class="fi-wcag-row">
                        <span class="fi-wcag-badge"
                              id="fi_color_cta_badge"
                              data-fg="#ffffff"
                              data-bg-source="fi_color_cta"
                              title="White text on CTA button background">
                            Checking…
                        </span>
                        <button type="button"
                                class="fi-autofix-btn"
                                id="fi_color_cta_fix"
                                data-target="fi_color_cta"
                                data-fix-fg="#ffffff">
                            ↺ Auto-fix
                        </button>
                    </div>
                    <p class="fi-hint">"Book a consultation" or custom CTA button at report bottom.</p>
                </div>
            </div>

            <!-- Report Header -->
            <p class="fi-label" style="margin-bottom:10px;">Report Header</p>
            <div class="fi-color-group">
                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_header_bg">Header Background</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_header_bg"
                               name="fi_color_header_bg"
                               value="<?php echo esc_attr( $colors['fi_color_header_bg'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_header_bg_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_header_bg'] ) ); ?>"
                               maxlength="7"
                               aria-label="Header background hex value">
                    </div>
                    <p class="fi-hint">The dark band showing the business name and overall score.</p>
                </div>

                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_header_text">Header Text</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_header_text"
                               name="fi_color_header_text"
                               value="<?php echo esc_attr( $colors['fi_color_header_text'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_header_text_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_header_text'] ) ); ?>"
                               maxlength="7"
                               aria-label="Header text hex value">
                    </div>
                    <div class="fi-wcag-row">
                        <span class="fi-wcag-badge"
                              id="fi_color_header_pair_badge"
                              data-fg-source="fi_color_header_text"
                              data-bg-source="fi_color_header_bg"
                              title="Header text on header background">
                            Checking…
                        </span>
                        <button type="button"
                                class="fi-autofix-btn"
                                id="fi_color_header_text_fix"
                                data-target="fi_color_header_text"
                                data-fix-bg-source="fi_color_header_bg">
                            ↺ Auto-fix
                        </button>
                    </div>
                    <p class="fi-hint">Business name, address, and meta info inside the header.</p>
                </div>
            </div>

            <!-- Content Area -->
            <p class="fi-label" style="margin-bottom:10px;">Content Area</p>
            <div class="fi-color-group">
                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_tab_bg">Tab Bar Background</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_tab_bg"
                               name="fi_color_tab_bg"
                               value="<?php echo esc_attr( $colors['fi_color_tab_bg'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_tab_bg_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_tab_bg'] ) ); ?>"
                               maxlength="7"
                               aria-label="Tab bar background hex value">
                    </div>
                    <p class="fi-hint">Background of the tab row above the report panels.</p>
                </div>

                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_surface">Card Surface</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_surface"
                               name="fi_color_surface"
                               value="<?php echo esc_attr( $colors['fi_color_surface'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_surface_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_surface'] ) ); ?>"
                               maxlength="7"
                               aria-label="Card surface hex value">
                    </div>
                    <p class="fi-hint">Background of report panels, category cards, and sections.</p>
                </div>

                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_body_text">Body Text</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_body_text"
                               name="fi_color_body_text"
                               value="<?php echo esc_attr( $colors['fi_color_body_text'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_body_text_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_body_text'] ) ); ?>"
                               maxlength="7"
                               aria-label="Body text hex value">
                    </div>
                    <div class="fi-wcag-row">
                        <span class="fi-wcag-badge"
                              id="fi_color_body_pair_badge"
                              data-fg-source="fi_color_body_text"
                              data-bg-source="fi_color_surface"
                              title="Body text on card surface">
                            Checking…
                        </span>
                        <button type="button"
                                class="fi-autofix-btn"
                                id="fi_color_body_text_fix"
                                data-target="fi_color_body_text"
                                data-fix-bg-source="fi_color_surface">
                            ↺ Auto-fix
                        </button>
                    </div>
                    <p class="fi-hint">Analysis paragraphs, recommendation lists, action descriptions.</p>
                </div>

                <div class="fi-color-pair">
                    <label class="fi-label" for="fi_color_link">Link Color</label>
                    <div class="fi-color-row">
                        <input type="color"
                               class="fi-color-input"
                               id="fi_color_link"
                               name="fi_color_link"
                               value="<?php echo esc_attr( $colors['fi_color_link'] ); ?>">
                        <input type="text"
                               class="fi-color-hex"
                               id="fi_color_link_hex"
                               value="<?php echo esc_attr( strtoupper( $colors['fi_color_link'] ) ); ?>"
                               maxlength="7"
                               aria-label="Link color hex value">
                    </div>
                    <div class="fi-wcag-row">
                        <span class="fi-wcag-badge"
                              id="fi_color_link_pair_badge"
                              data-fg-source="fi_color_link"
                              data-bg-source="fi_color_surface"
                              title="Link color on card surface">
                            Checking…
                        </span>
                        <button type="button"
                                class="fi-autofix-btn"
                                id="fi_color_link_fix"
                                data-target="fi_color_link"
                                data-fix-bg-source="fi_color_surface">
                            ↺ Auto-fix
                        </button>
                    </div>
                    <p class="fi-hint">Active tab text, website links, and navigation links.</p>
                </div>
            </div>

            <a href="#" class="fi-color-reset" id="fi-colors-reset-all">↺ Reset all colors to defaults</a>

            <hr style="margin: 28px 0; border: none; border-top: 1px solid #e5e7eb;">

            <!-- ── Brand Identity ────────────────────────────────────────── -->
            <div class="fi-section-title">Brand Identity</div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_brand_name">Brand Name</label>
                <input type="text" name="fi_brand_name" id="fi_brand_name"
                       value="<?php echo esc_attr( $brand_name ); ?>"
                       class="fi-input" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                <p class="fi-hint">Used as the email "From" name. Defaults to your site name.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_reply_to_email">Reply-To Email</label>
                <input type="email" name="fi_reply_to_email" id="fi_reply_to_email"
                       value="<?php echo esc_attr( $reply_to ); ?>"
                       class="fi-input" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_logo_url">Logo URL</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="fi_logo_url" id="fi_logo_url"
                           value="<?php echo esc_url( $logo_url ); ?>"
                           class="fi-input" placeholder="https://...">
                    <button type="button" id="fi-media-library" class="button">Choose from Media Library</button>
                </div>
                <p class="fi-hint">PNG or SVG. Displays at max 200×60px in email header.</p>
                <img id="fi-logo-preview" src="<?php echo $logo_url ? esc_url( $logo_url ) : ''; ?>"
                     alt="Logo preview" <?php echo $logo_url ? '' : 'style="display:none;"'; ?>>
            </div>

            <div class="fi-section-title">Search Form</div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_search_placeholder">Search Placeholder</label>
                <input type="text" name="fi_search_placeholder" id="fi_search_placeholder"
                       value="<?php echo esc_attr( $search_ph ); ?>" class="fi-input">
                <div class="fi-chips">
                    <?php foreach ( [ 'Type a business name to scan...', 'Search your business...', 'Enter a business name' ] as $chip ) : ?>
                        <span class="fi-chip" data-target="fi_search_placeholder" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_scan_btn_text">Scan Button Text</label>
                <input type="text" name="fi_scan_btn_text" id="fi_scan_btn_text"
                       value="<?php echo esc_attr( $scan_btn ); ?>" class="fi-input">
                <div class="fi-chips">
                    <?php foreach ( [ 'Scan Business', 'Get Free Report', 'Analyze Now', 'Check My Score' ] as $chip ) : ?>
                        <span class="fi-chip" data-target="fi_scan_btn_text" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="fi-section-title">Call-to-Action Button</div>

            <div class="fi-field-row">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <input type="checkbox" name="fi_cta_enabled" id="fi_cta_enabled" value="1" <?php checked( $cta_enabled, 1 ); ?>>
                    Show CTA button on reports
                </label>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_cta_text">Button Text</label>
                <input type="text" name="fi_cta_text" id="fi_cta_text"
                       value="<?php echo esc_attr( $cta_text ); ?>" class="fi-input">
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_cta_url">Button URL</label>
                <input type="url" name="fi_cta_url" id="fi_cta_url"
                       value="<?php echo esc_url( $cta_url ); ?>" class="fi-input" placeholder="https://...">
                <p class="fi-hint">Opens in a new tab.</p>
            </div>

            <div class="fi-section-title">Email Report Copy</div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_report_title">Report Title</label>
                <input type="text" name="fi_report_title" id="fi_report_title"
                       value="<?php echo esc_attr( $rpt_title ); ?>" class="fi-input">
                <p class="fi-hint">Appears as the email subject headline.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_email_footer_cta">Footer Call-to-Action</label>
                <textarea name="fi_email_footer_cta" id="fi_email_footer_cta"
                          class="fi-textarea" rows="3"><?php echo esc_textarea( $footer_cta ); ?></textarea>
                <p class="fi-hint">Closing paragraph in the email, your pitch to reply/book/connect.</p>
            </div>

            <div class="fi-section-title">Credit Link</div>

            <div class="fi-field-row">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <input type="checkbox" name="fi_hide_credit" id="fi_hide_credit" value="1" <?php checked( $hide_credit, 1 ); ?>>
                    Hide mention of F! Insights in emails and reports
                </label>
            </div>

            <?php FI_Admin::save_bar(); ?>
        </div>
        <?php
    }
}