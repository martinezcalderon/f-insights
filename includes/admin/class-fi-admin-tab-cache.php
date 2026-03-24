<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_Cache {

    public static function render(): void {
        $cache     = get_option( 'fi_cache_duration', 24 );
        $comp_r    = get_option( 'fi_competitor_radius', 5 );
        $auto_r    = get_option( 'fi_autocomplete_radius', 10 );
        $share_exp = get_option( 'fi_share_expiry_days', 7 );

        $cache_opts = [
            0   => 'Disabled (always fetch fresh)',
            1   => '1 hour',
            6   => '6 hours',
            12  => '12 hours',
            24  => '24 hours (recommended)',
            72  => '3 days',
            168 => '7 days',
        ];

        $share_opts = [
            1  => '1 day',
            3  => '3 days',
            7  => '7 days (recommended)',
            14 => '14 days',
            30 => '30 days',
        ];
        ?>
        <div class="fi-settings-form">

            <div class="fi-field-row">
                <label class="fi-label" for="fi_cache_duration">Cache Duration</label>
                <select name="fi_cache_duration" id="fi_cache_duration" class="fi-select">
                    <?php foreach ( $cache_opts as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cache, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="fi-hint">How long to reuse a scan result before hitting the API again. Saves cost on repeat searches for the same business.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_competitor_radius">Competitor Search Radius (miles)</label>
                <input type="number" name="fi_competitor_radius" id="fi_competitor_radius"
                       value="<?php echo esc_attr( $comp_r ); ?>"
                       class="fi-input fi-input-sm" min="1" max="60">
                <p class="fi-hint">How far to look for competitor businesses in the same category.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_autocomplete_radius">Autocomplete Search Radius (miles)</label>
                <input type="number" name="fi_autocomplete_radius" id="fi_autocomplete_radius"
                       value="<?php echo esc_attr( $auto_r ); ?>"
                       class="fi-input fi-input-sm" min="1" max="60">
                <p class="fi-hint">Biases search suggestions toward this radius from the visitor's location.</p>
            </div>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_share_expiry_days">Share Link Expiry</label>
                <select name="fi_share_expiry_days" id="fi_share_expiry_days" class="fi-select">
                    <?php foreach ( $share_opts as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $share_exp, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="fi-hint">How long a shareable report link stays active. A clear deadline below the report creates soft urgency to relevant stakeholders. Do not coerce your prospects, but do create urgency around their pain point of what they stand to gain.</p>
            </div>

            <div class="fi-field-row">
                <button type="button" id="fi-clear-cache" class="button">Clear All Cached Data</button>
                <p class="fi-hint">Forces all next scans to fetch fresh data from Google and Claude.</p>
            </div>

            <?php FI_Admin::save_bar(); ?>
        </div>
        <?php
    }
}