<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Admin_Tab_Lead_Form
 * Premium-only tab for configuring the lead capture form.
 * Lives under Market Leads (fi-market-intel), not the Settings page.
 *
 * Covers:
 *  - Which optional fields are enabled and whether each is required
 *  - Form copy: headline, subtext, email placeholder, submit button, thank-you message
 *  - Live preview that inherits brand tokens from White Label
 */
class FI_Admin_Tab_Lead_Form {

    /**
     * Default values — single source of truth, referenced by save handler and frontend.
     */
    public static function defaults(): array {
        return [
            // Form copy
            'fi_form_headline'              => 'Want this report in your inbox?',
            'fi_form_subtext'               => 'Get your full business insights report; free, no obligation.',
            'fi_email_placeholder'          => 'Your email address',
            'fi_email_btn_text'             => 'Get My Free Report',
            'fi_form_thankyou'              => 'Your report is on its way! Check your spam folder first in the next few minutes.',

            // Field toggles and required flags
            'fi_field_firstname_enabled'    => 0,
            'fi_field_firstname_required'   => 0,
            'fi_field_lastname_enabled'     => 0,
            'fi_field_lastname_required'    => 0,
            'fi_field_phone_enabled'        => 0,
            'fi_field_phone_required'       => 0,
            'fi_field_role_enabled'         => 0,
            'fi_field_role_required'        => 0,
            'fi_field_employees_enabled'    => 0,
            'fi_field_employees_required'   => 0,
            'fi_field_custom_enabled'       => 0,
            'fi_field_custom_required'      => 0,
            'fi_field_custom_label'         => '',

            // GDPR / consent
            'fi_consent_enabled'            => 0,
            'fi_consent_text'               => 'I agree to receive this report and understand my data will be used to follow up with relevant business advice.',
            'fi_consent_privacy_url'        => '',
        ];
    }

    public static function render(): void {
        if ( ! FI_Premium::is_active() ) {
            echo FI_Premium::upgrade_prompt( 'Lead Form' );
            return;
        }

        $d = self::defaults();

        // Load saved values, falling back to defaults
        $headline    = get_option( 'fi_form_headline',    $d['fi_form_headline'] );
        $subtext     = get_option( 'fi_form_subtext',     $d['fi_form_subtext'] );
        $email_ph    = get_option( 'fi_email_placeholder',$d['fi_email_placeholder'] );
        $email_btn   = get_option( 'fi_email_btn_text',   $d['fi_email_btn_text'] );
        $thankyou    = get_option( 'fi_form_thankyou',    $d['fi_form_thankyou'] );

        $fields = [
            'firstname'  => [ 'label' => 'First Name',     'type' => 'text' ],
            'lastname'   => [ 'label' => 'Last Name',       'type' => 'text' ],
            'phone'      => [ 'label' => 'Phone Number',    'type' => 'tel'  ],
            'role'       => [ 'label' => 'Business Role',   'type' => 'select' ],
            'employees'  => [ 'label' => 'No. of Employees','type' => 'select' ],
            'custom'     => [ 'label' => 'Custom Field',    'type' => 'text' ],
        ];

        // Brand colors for live preview — inherit from White Label
        $primary = get_option( 'fi_color_primary',     '#1d4ed8' );
        $cta     = get_option( 'fi_color_cta',         '#059669' );
        $surface = get_option( 'fi_color_surface',     '#ffffff' );
        $body_tx = get_option( 'fi_color_body_text',   '#374151' );
        ?>
        <div class="fi-settings-form fi-lead-form-wrap">

            <div class="fi-lead-form-cols">

                <!-- ── Left: settings ──────────────────────────────────── -->
                <div class="fi-lead-form-settings">

                    <div class="fi-section-title">Form Copy</div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_form_headline">Headline</label>
                        <input type="text" name="fi_form_headline" id="fi_form_headline"
                               value="<?php echo esc_attr( $headline ); ?>" class="fi-input"
                               data-preview="fi-preview-headline">
                        <div class="fi-chips">
                            <?php foreach ( [
                                'Want this report in your inbox?',
                                'Get your full report, free',
                                'See the complete breakdown',
                                'Ready to fix these issues?',
                            ] as $chip ) : ?>
                            <span class="fi-chip" data-target="fi_form_headline" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_form_subtext">Subtext</label>
                        <input type="text" name="fi_form_subtext" id="fi_form_subtext"
                               value="<?php echo esc_attr( $subtext ); ?>" class="fi-input"
                               data-preview="fi-preview-subtext">
                        <div class="fi-chips">
                            <?php foreach ( [
                                'Get your full business insights report; free, no obligation.',
                                'We\'ll email it straight to you. No spam, ever.',
                                'Free report. No credit card. No catch.',
                            ] as $chip ) : ?>
                            <span class="fi-chip" data-target="fi_form_subtext" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_email_placeholder">Email Placeholder</label>
                        <input type="text" name="fi_email_placeholder" id="fi_email_placeholder"
                               value="<?php echo esc_attr( $email_ph ); ?>" class="fi-input"
                               data-preview="fi-preview-email-ph">
                        <div class="fi-chips">
                            <?php foreach ( [
                                'Your email address',
                                'Where should we send the report?',
                                'Enter your email',
                            ] as $chip ) : ?>
                            <span class="fi-chip" data-target="fi_email_placeholder" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_email_btn_text">Submit Button Text</label>
                        <input type="text" name="fi_email_btn_text" id="fi_email_btn_text"
                               value="<?php echo esc_attr( $email_btn ); ?>" class="fi-input"
                               data-preview="fi-preview-btn">
                        <div class="fi-chips">
                            <?php foreach ( [
                                'Get My Free Report',
                                'Email Me the Report',
                                'Send It to Me',
                                'Yes, Email Me This',
                            ] as $chip ) : ?>
                            <span class="fi-chip" data-target="fi_email_btn_text" data-value="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="fi-field-row">
                        <label class="fi-label" for="fi_form_thankyou">Thank-You Message</label>
                        <textarea name="fi_form_thankyou" id="fi_form_thankyou"
                                  class="fi-textarea" rows="3"
                                  data-preview="fi-preview-thankyou"><?php echo esc_textarea( $thankyou ); ?></textarea>
                        <p class="fi-hint">Replaces the form after the visitor submits.</p>
                    </div>

                    <div class="fi-section-title">Optional Fields</div>
                    <p class="fi-hint" style="margin-bottom:16px;">
                        Email is always required. Enable optional fields to capture richer lead data.
                        The more you ask, the higher the signal, but the lower the conversion. Choose wisely.
                    </p>

                    <div class="fi-field-rows-table">
                        <div class="fi-field-rows-header">
                            <span>Field</span>
                            <span>Show</span>
                            <span>Required</span>
                        </div>

                        <!-- Email — locked -->
                        <div class="fi-field-row-item fi-field-row-item--locked">
                            <span class="fi-field-row-label">Email Address</span>
                            <span class="fi-field-locked-tag">Always on</span>
                            <span class="fi-field-locked-tag">Always required</span>
                        </div>

                        <?php foreach ( $fields as $key => $field ) :
                            $enabled  = (int) get_option( "fi_field_{$key}_enabled",  $d["fi_field_{$key}_enabled"]  ?? 0 );
                            $required = (int) get_option( "fi_field_{$key}_required", $d["fi_field_{$key}_required"] ?? 0 );
                        ?>
                        <div class="fi-field-row-item">
                            <span class="fi-field-row-label"><?php echo esc_html( $field['label'] ); ?></span>
                            <label class="fi-toggle">
                                <input type="checkbox"
                                       name="fi_field_<?php echo esc_attr( $key ); ?>_enabled"
                                       value="1"
                                       class="fi-field-enable-cb"
                                       data-field="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( $enabled, 1 ); ?>>
                                <span class="fi-toggle-track"></span>
                            </label>
                            <label class="fi-toggle<?php echo ! $enabled ? ' fi-toggle--disabled' : ''; ?>">
                                <input type="checkbox"
                                       name="fi_field_<?php echo esc_attr( $key ); ?>_required"
                                       value="1"
                                       class="fi-field-required-cb"
                                       data-field="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( $required, 1 ); ?>
                                       <?php disabled( ! $enabled ); ?>>
                                <span class="fi-toggle-track"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $custom_label = get_option( 'fi_field_custom_label', $d['fi_field_custom_label'] );
                    $custom_on    = (int) get_option( 'fi_field_custom_enabled', 0 );
                    ?>
                    <div class="fi-field-row fi-custom-field-meta<?php echo ! $custom_on ? ' fi-custom-field-meta--hidden' : ''; ?>" id="fi-custom-field-meta">
                        <label class="fi-label" for="fi_field_custom_label">Custom Field Label</label>
                        <input type="text" name="fi_field_custom_label" id="fi_field_custom_label"
                               value="<?php echo esc_attr( $custom_label ); ?>" class="fi-input"
                               placeholder="e.g. What's your biggest challenge?">
                        <p class="fi-hint">This becomes the floating label on the field.</p>
                    </div>

                    <?php
                    $consent_enabled     = (int) get_option( 'fi_consent_enabled', $d['fi_consent_enabled'] );
                    $consent_text        = get_option( 'fi_consent_text',        $d['fi_consent_text'] );
                    $consent_privacy_url = get_option( 'fi_consent_privacy_url', $d['fi_consent_privacy_url'] );
                    ?>
                    <div class="fi-section-title" style="margin-top:28px;">Consent &amp; Privacy</div>
                    <p class="fi-hint" style="margin-bottom:16px;">
                        Show a consent checkbox above the submit button. When enabled, the visitor must tick it before submitting.
                        Recommended if you collect personal data from EU/UK users (GDPR/UK GDPR).
                    </p>

                    <div class="fi-field-row">
                        <label class="fi-label">Consent Checkbox</label>
                        <label class="fi-toggle">
                            <input type="checkbox" name="fi_consent_enabled" value="1"
                                   id="fi-consent-enabled-cb"
                                   <?php checked( $consent_enabled, 1 ); ?>>
                            <span class="fi-toggle-track"></span>
                        </label>
                        <p class="fi-hint">Show a required consent checkbox on the lead capture form.</p>
                    </div>

                    <div id="fi-consent-fields" style="<?php echo $consent_enabled ? '' : 'display:none;'; ?>">
                        <div class="fi-field-row">
                            <label class="fi-label" for="fi_consent_text">Consent Text</label>
                            <textarea name="fi_consent_text" id="fi_consent_text"
                                      class="fi-textarea" rows="3"><?php echo esc_textarea( $consent_text ); ?></textarea>
                            <p class="fi-hint">
                                What the visitor agrees to. If you set a Privacy Policy URL below, the phrase
                                "Privacy Policy" inside this text will automatically become a link.
                            </p>
                        </div>

                        <div class="fi-field-row">
                            <label class="fi-label" for="fi_consent_privacy_url">Privacy Policy URL</label>
                            <input type="url" name="fi_consent_privacy_url" id="fi_consent_privacy_url"
                                   value="<?php echo esc_attr( $consent_privacy_url ); ?>"
                                   class="fi-input"
                                   placeholder="https://yoursite.com/privacy-policy">
                            <p class="fi-hint">
                                Optional. When set, "Privacy Policy" in the consent text becomes a link that
                                opens in a new tab.
                            </p>
                        </div>
                    </div>

                    <?php FI_Admin::save_bar(); ?>
                </div>

                <!-- ── Right: live preview ──────────────────────────────── -->
                <div class="fi-lead-form-preview-wrap">
                    <div class="fi-section-title">Live Preview</div>
                    <p class="fi-hint" style="margin-bottom:16px;">Reflects your brand colors and current field settings.</p>
                    <div class="fi-lead-form-preview" id="fi-form-preview"
                         style="--fi-primary:<?php echo esc_attr( $primary ); ?>;--fi-cta:<?php echo esc_attr( $cta ); ?>;--fi-surface:<?php echo esc_attr( $surface ); ?>;--fi-body-text:<?php echo esc_attr( $body_tx ); ?>;">

                        <div class="fi-preview-capture">
                            <p class="fi-preview-headline" id="fi-preview-headline"><?php echo esc_html( $headline ); ?></p>
                            <p class="fi-preview-subtext"  id="fi-preview-subtext"><?php echo esc_html( $subtext ); ?></p>

                            <!-- Name row -->
                            <div class="fi-preview-name-row" id="fi-preview-name-row"
                                 style="<?php echo ( get_option('fi_field_firstname_enabled',0) || get_option('fi_field_lastname_enabled',0) ) ? '' : 'display:none;'; ?>">
                                <?php if ( get_option('fi_field_firstname_enabled',0) ) : ?>
                                <div class="fi-preview-field<?php echo get_option('fi_field_firstname_required',0) ? ' fi-preview-field--required' : ''; ?>">
                                    <div class="fi-preview-input-fake"></div>
                                    <span class="fi-preview-label">First name</span>
                                </div>
                                <?php endif; ?>
                                <?php if ( get_option('fi_field_lastname_enabled',0) ) : ?>
                                <div class="fi-preview-field<?php echo get_option('fi_field_lastname_required',0) ? ' fi-preview-field--required' : ''; ?>">
                                    <div class="fi-preview-input-fake"></div>
                                    <span class="fi-preview-label">Last name</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Email — always shown -->
                            <div class="fi-preview-field fi-preview-field--required">
                                <div class="fi-preview-input-fake"></div>
                                <span class="fi-preview-label">Email address</span>
                            </div>

                            <!-- Optional fields -->
                            <?php foreach ( [
                                'phone'     => 'Phone number',
                                'role'      => 'Business role',
                                'employees' => 'No. of employees',
                            ] as $fkey => $flabel ) :
                                $fen  = (int) get_option( "fi_field_{$fkey}_enabled", 0 );
                                $freq = (int) get_option( "fi_field_{$fkey}_required", 0 );
                            ?>
                            <div class="fi-preview-field<?php echo $freq ? ' fi-preview-field--required' : ''; ?>"
                                 id="fi-preview-field-<?php echo esc_attr( $fkey ); ?>"
                                 style="<?php echo $fen ? '' : 'display:none;'; ?>">
                                <div class="fi-preview-input-fake"></div>
                                <span class="fi-preview-label"><?php echo esc_html( $flabel ); ?></span>
                            </div>
                            <?php endforeach; ?>

                            <!-- Custom field -->
                            <div class="fi-preview-field<?php echo get_option('fi_field_custom_required',0) ? ' fi-preview-field--required' : ''; ?>"
                                 id="fi-preview-field-custom"
                                 style="<?php echo $custom_on ? '' : 'display:none;'; ?>">
                                <div class="fi-preview-input-fake"></div>
                                <span class="fi-preview-label" id="fi-preview-custom-label"><?php echo esc_html( $custom_label ?: 'Custom field' ); ?></span>
                            </div>

                            <button class="fi-preview-btn" id="fi-preview-btn" disabled><?php echo esc_html( $email_btn ); ?></button>

                            <p class="fi-preview-thankyou" id="fi-preview-thankyou" style="display:none;"><?php echo esc_html( $thankyou ); ?></p>
                        </div>
                    </div>
                </div>

            </div><!-- .fi-lead-form-cols -->
        </div>
        <?php
    }
}