<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_Shortcode {

    public static function render(): void {
        $google_ok  = ! empty( get_option( 'fi_google_api_key' ) );
        $claude_ok  = ! empty( get_option( 'fi_claude_api_key' ) );
        $cache_hrs  = (int) get_option( 'fi_cache_duration', 24 );
        $rl_enabled = get_option( 'fi_rate_limit_enabled', 0 );
        $page_id    = (int) get_option( 'fi_shortcode_page_id', 0 );
        $site_origin = rtrim( home_url(), '/' );

        global $wpdb;
        $pages = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ('page','post')
             AND post_content LIKE '%[f_insights]%'
             ORDER BY post_title"
        );
        ?>
        <div class="fi-settings-form">

            <!-- Shortcode -->
            <p>Drop this shortcode anywhere on your site to embed the business scanner.</p>

            <div class="fi-shortcode-box">
                <code>[f_insights]</code>
                <button type="button" class="fi-copy-btn"
                        onclick="navigator.clipboard.writeText('[f_insights]');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
            </div>

            <!-- Page Assignment -->
            <div class="fi-section-title" style="margin-top:28px;">Page Assignment</div>
            <p class="fi-hint" style="margin-bottom:12px;">
                Shared report links are built as
                <code><?php echo esc_html( $site_origin ); ?><strong>/your-slug</strong>?fi_report=TOKEN</code>.
                Your domain is set automatically. Set the page where your scanner shortcode lives so share links and resume links always point to the right place.
            </p>

            <?php if ( $pages ) : ?>
            <div style="margin-bottom:12px;">
                <p class="fi-hint">The page<?php echo count( $pages ) > 1 ? 's' : ''; ?> below <?php echo count( $pages ) > 1 ? 'were' : 'was'; ?> detected automatically &mdash; click to assign:</p>
                <div class="fi-chips">
                    <?php foreach ( $pages as $page ) : ?>
                    <span class="fi-chip" data-page-id="<?php echo (int) $page->ID; ?>"
                          onclick="document.getElementById('fi_page_id').value='<?php echo (int) $page->ID; ?>'">
                        <?php echo esc_html( $page->post_title ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_page_id">Scanner Page</label>
                <select name="fi_shortcode_page_id" id="fi_page_id" class="fi-select" style="max-width:320px;">
                    <option value="0">-- Select a page --</option>
                    <?php foreach ( get_pages() as $p ) : ?>
                    <option value="<?php echo (int) $p->ID; ?>" <?php selected( $page_id, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $page_id ) : ?>
                <a href="<?php echo get_permalink( $page_id ); ?>" target="_blank" class="button button-small" style="margin-left:8px;">Preview</a>
                <?php endif; ?>
            </div>

            <?php FI_Admin::save_bar(); ?>

            <!-- System Status -->
            <div class="fi-section-title" style="margin-top:28px;">System Status</div>
            <div class="fi-system-status">
                <?php self::status_item( 'Google API',     $google_ok,            $google_ok ? 'Configured' : 'Not set; go to API Config' ); ?>
                <?php self::status_item( 'Claude API',     $claude_ok,            $claude_ok ? 'Configured' : 'Not set; go to API Config' ); ?>
                <?php self::status_item( 'Cache',          true,                  $cache_hrs === 0 ? 'Disabled' : $cache_hrs . ' hours', 'ok' ); ?>
                <?php self::status_item( 'Rate Limiting',  true,                  $rl_enabled ? 'Enabled' : 'Disabled', 'ok' ); ?>
                <?php self::status_item( 'Shortcode page', (bool) $page_id,       $page_id ? 'Assigned' : 'Not assigned', $page_id ? 'ok' : 'warn' ); ?>
                <?php self::status_item( 'Premium',        FI_Premium::is_active(), FI_Premium::is_active() ? 'Active' : 'Free; email/leads locked' ); ?>
            </div>

            <!-- Quick Start -->
            <div class="fi-section-title">Quick Start</div>
            <ol style="font-size:13px;color:#374151;line-height:2;max-width:480px;">
                <li>Add your <strong>Google API key</strong> in the API Config tab (Maps + Places enabled)</li>
                <li>Add your <strong>Claude API key</strong> in the API Config tab</li>
                <li>Create a page and add the <code>[f_insights]</code> shortcode</li>
                <li>The page will be detected automatically above; click it to assign</li>
                <li>Visit the page and scan a business to test</li>
            </ol>

        </div>
        <?php
    }

    private static function status_item( string $label, bool $ok, string $text, string $force = '' ): void {
        $state = $force ?: ( $ok ? 'ok' : 'error' );
        echo '<div class="fi-status-item">'
           . '<span class="fi-status-dot fi-status-dot--' . esc_attr( $state ) . '"></span>'
           . '<span><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $text ) . '</span>'
           . '</div>';
    }

    public static function save(): void {
        update_option( 'fi_shortcode_page_id', (int) ( $_POST['fi_shortcode_page_id'] ?? 0 ) );
    }
}
