<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Admin_Tab_Api {

    public static function render(): void {
        $google_key  = get_option( 'fi_google_api_key', '' );
        $claude_key  = get_option( 'fi_claude_api_key', '' );
        $premium     = FI_Premium::is_active();
        $masked_key  = FI_Premium::get_masked_key();
        $upgrade_url = FI_Premium::upgrade_url();

        // ── Polar settings ────────────────────────────────────────────────
        $polar_org_id         = get_option( 'fi_polar_organization_id', '' );
        $polar_webhook_secret = get_option( 'fi_polar_webhook_secret',  '' );
        $polar_access_token   = get_option( 'fi_polar_access_token',    '' );
        $polar_checkout_url   = get_option( 'fi_polar_checkout_url',    '' );
        $webhook_endpoint_url = FI_Polar::webhook_url();

        // ── Claude models ─────────────────────────────────────────────────
        $model_report = get_option( 'fi_claude_model_report', get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' ) );
        $model_admin  = get_option( 'fi_claude_model_admin',  get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' ) );

        $models = [
            'claude-haiku-4-5-20251001'   => [ 'name' => 'Claude Haiku 4.5',  'desc' => 'Fastest, lowest cost.',                    'cost' => '~$0.02/scan' ],
            'claude-sonnet-4-5-20251022'  => [ 'name' => 'Claude Sonnet 4.5', 'desc' => 'Deeper analysis, richer recommendations.', 'cost' => '~$0.08/scan' ],
            'claude-opus-4-20250514'      => [ 'name' => 'Claude Opus 4',     'desc' => 'Maximum quality for high-stakes clients.',  'cost' => '~$0.30/scan' ],
        ];
        ?>
        <div class="fi-settings-form">

            <!-- ══ Google Places API ══════════════════════════════════════ -->
            <div class="fi-section-title">Google Places API</div>
            <div class="fi-field-row">
                <label class="fi-label" for="fi_google_api_key">API Key</label>
                <div class="fi-key-row">
                    <input type="password" name="fi_google_api_key" id="fi_google_api_key"
                           value="<?php echo esc_attr( $google_key ); ?>"
                           class="fi-input" autocomplete="off" spellcheck="false">
                    <button type="button" class="fi-show-key">Show</button>
                    <button type="button" class="fi-test-btn" id="fi-test-google">Test Connection</button>
                </div>
                <p class="fi-hint">Requires: <strong>Places API (New)</strong> &middot; <strong>PageSpeed Insights API</strong> enabled in Google Cloud Console. Get your key at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">console.cloud.google.com</a></p>
                <div class="fi-test-result" id="fi-test-google-status"></div>
            </div>

            <!-- ══ Claude API ══════════════════════════════════════════════ -->
            <div class="fi-section-title">Claude API</div>
            <div class="fi-field-row">
                <label class="fi-label" for="fi_claude_api_key">API Key</label>
                <div class="fi-key-row">
                    <input type="password" name="fi_claude_api_key" id="fi_claude_api_key"
                           value="<?php echo esc_attr( $claude_key ); ?>"
                           class="fi-input" autocomplete="off" spellcheck="false">
                    <button type="button" class="fi-show-key">Show</button>
                    <button type="button" class="fi-test-btn" id="fi-test-claude">Test Connection</button>
                </div>
                <p class="fi-hint">Get your key at <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a></p>
                <div class="fi-test-result" id="fi-test-claude-status"></div>
            </div>

            <!-- ══ Report Model ════════════════════════════════════════════ -->
            <div class="fi-section-title">Report Model</div>
            <p class="fi-hint" style="margin-bottom:14px;">Used for all external-facing business scans. Haiku is recommended for speed and cost.</p>
            <div class="fi-field-row">
                <div class="fi-model-grid">
                    <?php foreach ( $models as $value => $info ) : ?>
                    <label class="fi-model-option">
                        <input type="radio" name="fi_claude_model_report" value="<?php echo esc_attr( $value ); ?>"
                               <?php checked( $model_report, $value ); ?>>
                        <div class="fi-model-name"><?php echo esc_html( $info['name'] ); ?></div>
                        <div class="fi-model-desc"><?php echo esc_html( $info['desc'] ); ?></div>
                        <div class="fi-model-cost"><?php echo esc_html( $info['cost'] ); ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ Admin Model ════════════════════════════════════════════ -->
            <div class="fi-section-title">Admin Intelligence Model
                <?php if ( ! $premium ) : ?>
                <span style="display:inline-block;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;margin-left:8px;vertical-align:middle;">Premium</span>
                <?php endif; ?>
            </div>
            <p class="fi-hint" style="margin-bottom:14px;">Used for Market Leads analytics, competitor intel, and AI pitch generation. Admin-only features.</p>

            <?php if ( $premium ) : ?>
            <div class="fi-field-row">
                <div class="fi-model-grid">
                    <?php foreach ( $models as $value => $info ) : ?>
                    <label class="fi-model-option">
                        <input type="radio" name="fi_claude_model_admin" value="<?php echo esc_attr( $value ); ?>"
                               <?php checked( $model_admin, $value ); ?>>
                        <div class="fi-model-name"><?php echo esc_html( $info['name'] ); ?></div>
                        <div class="fi-model-desc"><?php echo esc_html( $info['desc'] ); ?></div>
                        <div class="fi-model-cost"><?php echo esc_html( $info['cost'] ); ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else : ?>
            <div style="position:relative;border-radius:10px;overflow:hidden;">
                <div class="fi-model-grid" style="opacity:.35;pointer-events:none;user-select:none;filter:blur(1px);">
                    <?php foreach ( $models as $value => $info ) : ?>
                    <label class="fi-model-option">
                        <input type="radio" disabled>
                        <div class="fi-model-name"><?php echo esc_html( $info['name'] ); ?></div>
                        <div class="fi-model-desc"><?php echo esc_html( $info['desc'] ); ?></div>
                        <div class="fi-model-cost"><?php echo esc_html( $info['cost'] ); ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;background:rgba(255,255,255,.7);backdrop-filter:blur(2px);border-radius:10px;border:1px dashed #d1d5db;">
                    <p style="margin:0;font-size:13px;font-weight:700;color:#1e3a5f;">Upgrade to unlock admin model selection.</p>
                    <?php if ( $upgrade_url !== '#' ) : ?>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"
                       style="display:inline-block;padding:8px 20px;background:#059669;color:#fff;font-size:13px;font-weight:700;border-radius:6px;text-decoration:none;">
                        Buy Premium on Polar &rarr;
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══ Premium License ════════════════════════════════════════ -->
            <div class="fi-section-title" style="margin-top:36px;">
                Premium License
                <span style="display:inline-block;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:99px;margin-left:8px;vertical-align:middle;">Powered by Polar.sh</span>
            </div>

            <?php if ( $premium ) : ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
                <p style="margin:0;font-size:14px;font-weight:700;color:#15803d;">&#10003; Premium Active</p>
                <p style="margin:6px 0 0;font-size:13px;color:#166534;">Lead capture, email reports, white-label branding, market intel, and analytics are all unlocked.</p>
            </div>
            <?php else : ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
                <p style="margin:0;font-size:13px;color:#9a3412;">Premium is not active. Purchase a license on Polar, then enter your license key below.</p>
                <?php if ( $upgrade_url !== '#' ) : ?>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"
                   style="display:inline-block;margin-top:10px;padding:10px 22px;background:#059669;color:#fff;font-size:13px;font-weight:700;border-radius:6px;text-decoration:none;">
                    Buy Premium on Polar &rarr;
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="fi-field-row">
                <label class="fi-label" for="fi_license_key">License Key</label>
                <div class="fi-key-row">
                    <input type="text" name="fi_license_key" id="fi_license_key"
                           value="<?php echo esc_attr( $masked_key ); ?>"
                           class="fi-input" autocomplete="off" spellcheck="false"
                           placeholder="<?php echo $premium ? 'License Active' : 'Paste your Polar license key here'; ?>">
                    <?php if ( $premium ) : ?>
                        <button type="button" class="fi-test-btn" id="fi-deactivate-license">Deactivate</button>
                    <?php else : ?>
                        <button type="button" class="fi-test-btn" id="fi-activate-license">Activate</button>
                    <?php endif; ?>
                </div>
                <p class="fi-hint">
                    After purchasing, your license key is automatically delivered via your
                    <a href="https://polar.sh" target="_blank" rel="noopener">Polar customer portal</a>
                    under &ldquo;Benefits&rdquo; &rarr; &ldquo;License Keys&rdquo;.
                </p>
                <div class="fi-test-result" id="fi-license-status"></div>
                <?php if ( defined( 'FI_DEV_MODE' ) && FI_DEV_MODE ) : ?>
                    <p class="fi-hint" style="color:#6b7280;font-style:italic;margin-top:8px;">Dev mode is ON; all premium features are active without a license. Set <code>FI_DEV_MODE = false</code> in <code>f-insights.php</code> before releasing to customers.</p>
                <?php endif; ?>
            </div>

            <!-- ══ Polar.sh Integration Settings ════════════════════════ -->
            <div class="fi-section-title" style="margin-top:36px;">Polar.sh Integration Settings</div>
            <p class="fi-hint" style="margin-bottom:18px;">
                Connect this plugin to your <a href="https://polar.sh" target="_blank" rel="noopener">Polar.sh</a> organization.
                Polar acts as your Merchant of Record, handling checkout, international tax compliance,
                automatic license key delivery, and subscription lifecycle management.
            </p>

            <!-- Organization ID -->
            <div class="fi-field-row">
                <label class="fi-label" for="fi_polar_organization_id">Organization ID <span style="color:#dc2626;">*</span></label>
                <input type="text" name="fi_polar_organization_id" id="fi_polar_organization_id"
                       value="<?php echo esc_attr( $polar_org_id ); ?>"
                       class="fi-input" autocomplete="off" spellcheck="false"
                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                <p class="fi-hint">
                    <strong>Required.</strong> Your Polar organization's UUID.
                    Find it at <strong>Polar Dashboard &rarr; Settings &rarr; General</strong> &rarr; &ldquo;Organization ID&rdquo;.
                    This scopes all license key API calls to your organization, preventing cross-org key abuse.
                </p>
            </div>

            <!-- Checkout URL -->
            <div class="fi-field-row">
                <label class="fi-label" for="fi_polar_checkout_url">Checkout URL</label>
                <input type="url" name="fi_polar_checkout_url" id="fi_polar_checkout_url"
                       value="<?php echo esc_attr( $polar_checkout_url ); ?>"
                       class="fi-input" placeholder="https://buy.polar.sh/polar_xxxxxxxx">
                <p class="fi-hint">
                    Your product&rsquo;s Polar checkout link. Found at <strong>Polar Dashboard &rarr; Products &rarr; your product &rarr; Checkout Links</strong>.
                    All &ldquo;Buy Premium&rdquo; buttons in this plugin link here.
                </p>
            </div>

            <!-- Webhook Endpoint URL (read-only display) -->
            <div class="fi-field-row">
                <label class="fi-label">Webhook Endpoint URL</label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="text" readonly value="<?php echo esc_attr( $webhook_endpoint_url ); ?>"
                           class="fi-input" style="background:#f9fafb;color:#374151;cursor:default;"
                           id="fi-webhook-url-display">
                    <button type="button" class="fi-test-btn"
                            onclick="(function(b){navigator.clipboard.writeText(document.getElementById('fi-webhook-url-display').value).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy'},2000)})})(this)">
                        Copy
                    </button>
                </div>
                <p class="fi-hint">
                    Paste this URL into <strong>Polar Dashboard &rarr; Settings &rarr; Webhooks &rarr; Add Endpoint</strong>.
                    Subscribe to: <code>order.paid</code>, <code>order.refunded</code>,
                    <code>subscription.active</code>, <code>subscription.revoked</code>,
                    <code>subscription.canceled</code>, <code>benefit_grant.created</code>.
                    After saving, copy the generated <strong>Webhook Secret</strong> into the field below.
                </p>
            </div>

            <!-- Webhook Secret -->
            <div class="fi-field-row">
                <label class="fi-label" for="fi_polar_webhook_secret">Webhook Secret <span style="color:#dc2626;">*</span></label>
                <div class="fi-key-row">
                    <input type="password" name="fi_polar_webhook_secret" id="fi_polar_webhook_secret"
                           value="<?php echo esc_attr( $polar_webhook_secret ); ?>"
                           class="fi-input" autocomplete="off" spellcheck="false"
                           placeholder="whsec_xxxxxxxxxxxxxxxxxxxxxxxx">
                    <button type="button" class="fi-show-key">Show</button>
                </div>
                <p class="fi-hint">
                    <strong>Required.</strong> The cryptographic signing secret Polar generates for your webhook endpoint.
                    Found at <strong>Polar Dashboard &rarr; Settings &rarr; Webhooks &rarr; your endpoint &rarr; Secret</strong>.
                    Every incoming webhook is verified against this secret using HMAC-SHA256.
                </p>
            </div>

            <!-- Organization Access Token (optional) -->
            <div class="fi-field-row">
                <label class="fi-label" for="fi_polar_access_token">Organization Access Token <span style="color:#6b7280;font-weight:400;">(optional)</span></label>
                <div class="fi-key-row">
                    <input type="password" name="fi_polar_access_token" id="fi_polar_access_token"
                           value="<?php echo esc_attr( $polar_access_token ); ?>"
                           class="fi-input" autocomplete="off" spellcheck="false"
                           placeholder="polar_oat_xxxxxxxxxxxxxxxxxxxxxxxx">
                    <button type="button" class="fi-show-key">Show</button>
                </div>
                <p class="fi-hint">
                    Optional. An Organization Access Token for making admin-level Polar API calls (e.g. listing orders, customers).
                    <strong>Not required</strong> for license key validation or webhook verification; those endpoints are public.
                    Create one at <strong>Polar Dashboard &rarr; Settings &rarr; API Tokens</strong>.
                    <strong>Keep this private; never commit or expose it.</strong>
                </p>
            </div>

            <?php FI_Admin::save_bar(); ?>
        </div>
        <?php
    }
}
