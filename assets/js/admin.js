/* F! Insights — Admin JS v2.0.0 */
(function ($) {
    'use strict';

    if (typeof FI_Admin === 'undefined') return;

    // ── Nonce auto-refresh ────────────────────────────────────────────────
    // WordPress nonces expire after 12–24 hours. An admin leaving a tab open
    // overnight would get silent failures on every AJAX action. This intercepts
    // the -1 response WordPress sends for an invalid nonce, fetches a fresh one,
    // and replays the original request exactly once before giving up.
    var _nonceRefreshing = false;
    var _nonceQueue      = [];

    $.ajaxPrefilter(function (options, originalOptions) {
        // Only intercept requests to our admin-ajax endpoint
        if (!options.url || options.url.indexOf('admin-ajax.php') === -1) return;
        // Don't intercept the refresh request itself
        if (originalOptions.data && originalOptions.data.action === 'fi_refresh_nonce') return;

        var originalSuccess = options.success;
        options.success = function (res) {
            // WordPress returns the string "-1" (or JSON {success:false,data:"-1"})
            // when a nonce check fails
            var isNonceError = (res === '-1') ||
                (res && res.success === false && (res.data === '-1' || res.data === -1));

            if (isNonceError && !originalOptions._nonceRetried) {
                // Queue this request for replay after refresh
                _nonceQueue.push({ options: originalOptions });

                if (!_nonceRefreshing) {
                    _nonceRefreshing = true;
                    $.post(FI_Admin.ajax_url, { action: 'fi_refresh_nonce' }, function (refreshRes) {
                        _nonceRefreshing = false;
                        if (refreshRes && refreshRes.success) {
                            FI_Admin.nonce = refreshRes.data.nonce;
                        }
                        // Replay all queued requests with fresh nonce
                        var queue = _nonceQueue.splice(0);
                        queue.forEach(function (item) {
                            var replayData = $.extend({}, item.options.data, { nonce: FI_Admin.nonce });
                            $.post(FI_Admin.ajax_url, replayData, item.options.success)
                                .fail(item.options.error || $.noop);
                            item.options._nonceRetried = true;
                        });
                    }).fail(function () {
                        _nonceRefreshing = false;
                        _nonceQueue.length = 0; // discard on refresh failure
                    });
                }
                return; // don't call original success with the -1 response
            }

            if (originalSuccess) originalSuccess.apply(this, arguments);
        };
    });

    $(document).ready(function () {
        initAPIKeys();
        initLicense();
        initRateLimiting();
        initIPExclusions();
        initWhiteLabel();
        initMarketIntel();
        initDebugLogs();
        initLeadPipeline();
        initReportPopup();
        initPipelineSort();
        initLeadForm();
        initReviews();

        initCache();
        initBarCharts();
    });

    // =========================================================================
    // API Keys — Show/Hide + Test Connection
    // =========================================================================

    function initAPIKeys() {
        $(document).on('click', '.fi-show-key', function () {
            var $btn   = $(this);
            var $input = $btn.closest('.fi-key-row').find('input');
            var hidden = $input.attr('type') === 'password';
            $input.attr('type', hidden ? 'text' : 'password');
            $btn.text(hidden ? 'Hide' : 'Show');
        });

        $(document).on('click', '#fi-test-google', function () {
            testKey('google', $('#fi_google_api_key').val());
        });

        $(document).on('click', '#fi-test-claude', function () {
            testKey('claude', $('#fi_claude_api_key').val());
        });
    }

    function testKey(type, key) {
        var $btn    = $('#fi-test-' + type);
        var $status = $('#fi-test-' + type + '-status');

        $btn.prop('disabled', true).text('Testing…');
        $status.text('').removeClass('fi-ok fi-fail');

        $.post(FI_Admin.ajax_url, {
            action: 'fi_test_' + type,
            nonce:  FI_Admin.nonce,
            key:    key,
        }, function (res) {
            $btn.prop('disabled', false).text('Test Connection');
            if (res.ok) {
                $status.text('✓ Connected').addClass('fi-ok');
            } else {
                $status.text('✗ ' + (res.message || 'Failed')).addClass('fi-fail');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Test Connection');
            $status.text('✗ Request failed').addClass('fi-fail');
        });
    }

    // =========================================================================
    // License — Activate / Deactivate
    // =========================================================================

    function initLicense() {
        $(document).on('click', '#fi-activate-license', function () {
            var $btn    = $(this);
            var $status = $('#fi-license-status');
            var key     = $('#fi_license_key').val();

            if (!key) {
                $status.text('Please enter a license key.').addClass('fi-fail');
                return;
            }

            $btn.prop('disabled', true).text('Activating…');
            $status.text('').removeClass('fi-ok fi-fail');

            $.post(FI_Admin.ajax_url, {
                action: 'fi_activate_license',
                nonce:  FI_Admin.nonce,
                key:    key,
            }, function (res) {
                if (res.success) {
                    $status.text('✓ ' + (res.data.message || 'Activated')).addClass('fi-ok');
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    $status.text('✗ ' + (res.data || 'Activation failed')).addClass('fi-fail');
                    $btn.prop('disabled', false).text('Activate');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Activate');
                $status.text('✗ Request failed').addClass('fi-fail');
            });
        });

        $(document).on('click', '#fi-deactivate-license', function () {
            var $btn    = $(this);
            var $status = $('#fi-license-status');

            if (!confirm('Are you sure you want to deactivate your license?')) return;

            $btn.prop('disabled', true).text('Deactivating…');

            $.post(FI_Admin.ajax_url, {
                action: 'fi_deactivate_license',
                nonce:  FI_Admin.nonce,
            }, function (res) {
                if (res.success) {
                    $status.text('✓ Deactivated').addClass('fi-ok');
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    $status.text('✗ ' + (res.data || 'Deactivation failed')).addClass('fi-fail');
                    $btn.prop('disabled', false).text('Deactivate');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Deactivate');
                $status.text('✗ Request failed').addClass('fi-fail');
            });
        });
    }

    // =========================================================================
    // Rate Limiting
    // =========================================================================

    function initRateLimiting() {
        var $cb = $('#fi_rate_limit_enabled');
        if (!$cb.length) return;

        function toggle() {
            var enabled = $cb.is(':checked');
            $('#fi_rate_limit_max, #fi_rate_limit_window').prop('disabled', !enabled)
                .closest('.fi-field-row').css('opacity', enabled ? 1 : 0.5);
        }

        $cb.on('change', toggle);
        toggle();
    }

    // =========================================================================
    // IP Exclusions
    // =========================================================================

    function initIPExclusions() {
        $(document).on('click', '#fi-add-my-ip', function () {
            var $btn = $(this);
            var ip   = $btn.data('ip');
            if (!ip) return;

            var $textarea = $('#fi_excluded_ips');
            var current   = $textarea.val().trim();
            var lines     = current ? current.split('\n').map(function(l){ return l.trim(); }) : [];

            if (lines.indexOf(ip) === -1) {
                lines.push(ip);
                $textarea.val(lines.join('\n'));
            }
        });
    }

    // =========================================================================
    // White-Label
    // =========================================================================

    function initWhiteLabel() {
        var wcagPairs = [
            { badge: 'fi_color_primary_badge', fix: 'fi_color_primary_fix', fg: '#ffffff', bgSrc: 'fi_color_primary', fixTarget: 'fi_color_primary', fixMode: 'bg' },
            { badge: 'fi_color_cta_badge', fix: 'fi_color_cta_fix', fg: '#ffffff', bgSrc: 'fi_color_cta', fixTarget: 'fi_color_cta', fixMode: 'bg' },
            { badge: 'fi_color_header_pair_badge', fix: 'fi_color_header_text_fix', fgSrc: 'fi_color_header_text', bgSrc: 'fi_color_header_bg', fixTarget: 'fi_color_header_text', fixMode: 'fg' },
            { badge: 'fi_color_body_pair_badge', fix: 'fi_color_body_text_fix', fgSrc: 'fi_color_body_text', bgSrc: 'fi_color_surface', fixTarget: 'fi_color_body_text', fixMode: 'fg' },
            { badge: 'fi_color_link_pair_badge', fix: 'fi_color_link_fix', fgSrc: 'fi_color_link', bgSrc: 'fi_color_surface', fixTarget: 'fi_color_link', fixMode: 'fg' },
        ];

        $(document).on('input change', '.fi-color-input', function () {
            var id  = this.id;
            var hex = this.value.toUpperCase();
            $('#' + id + '_hex').val(hex);
            refreshAllBadges();
        });

        $(document).on('input', '.fi-color-hex', function () {
            var raw = this.value.trim();
            var hex = raw.startsWith('#') ? raw : '#' + raw;
            if ( /^#[0-9A-Fa-f]{6}$/.test(hex) ) {
                var inputId = this.id.replace('_hex', '');
                $('#' + inputId).val(hex);
                refreshAllBadges();
            }
        });

        function refreshAllBadges() {
            wcagPairs.forEach(function (pair) {
                var fg = pair.fg || getColorVal(pair.fgSrc);
                var bg = getColorVal(pair.bgSrc);
                if (!fg || !bg) return;
                updateBadge(pair.badge, pair.fix, fg, bg);
            });
        }

        function getColorVal(inputId) {
            // Read from the hex text field (type="text") — always has its value on load.
            // The type="color" input can return "" on some browsers before user interaction.
            var $hex = $('#' + inputId + '_hex');
            if ( $hex.length ) {
                var v = $hex.val().trim();
                return /^#[0-9A-Fa-f]{6}$/.test(v) ? v : null;
            }
            var $input = $('#' + inputId);
            return $input.length ? $input.val() : null;
        }

        function hexToRgb(hex) {
            hex = hex.replace(/^#/, '');
            if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
            return { r: parseInt(hex.slice(0,2), 16), g: parseInt(hex.slice(2,4), 16), b: parseInt(hex.slice(4,6), 16) };
        }

        function linearize(val) {
            var s = val / 255;
            return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        }

        function relativeLuminance(hex) {
            var c = hexToRgb(hex);
            return 0.2126 * linearize(c.r) + 0.7152 * linearize(c.g) + 0.0722 * linearize(c.b);
        }

        function contrastRatio(hex1, hex2) {
            var l1 = relativeLuminance(hex1);
            var l2 = relativeLuminance(hex2);
            var lighter = Math.max(l1, l2);
            var darker  = Math.min(l1, l2);
            return (lighter + 0.05) / (darker + 0.05);
        }

        function updateBadge(badgeId, fixId, fg, bg) {
            var $badge = $('#' + badgeId);
            var $fix   = $('#' + fixId);
            if (!$badge.length) return;
            var ratio = contrastRatio(fg, bg);
            var ratioStr = ratio.toFixed(2) + ':1';
            $badge.removeClass('fi-wcag-badge--pass fi-wcag-badge--warn fi-wcag-badge--fail');
            $fix.removeClass('fi-autofix-visible');
            if (ratio >= 4.5) {
                $badge.addClass('fi-wcag-badge--pass').text('✓ ' + ratioStr + ' AA');
            } else if (ratio >= 3.0) {
                $badge.addClass('fi-wcag-badge--warn').text('⚠ ' + ratioStr + ' Large text only');
                $fix.addClass('fi-autofix-visible');
            } else {
                $badge.addClass('fi-wcag-badge--fail').text('✗ ' + ratioStr + ' Fails AA');
                $fix.addClass('fi-autofix-visible');
            }
        }

        // ── Reset all colors to defaults ───────────────────────────────
        $(document).on('click', '#fi-colors-reset-all', function (e) {
            e.preventDefault();
            var defaults = FI_Admin.colorDefaults || {};
            $.each(defaults, function (key, val) {
                var hex = val.toUpperCase();
                $('#' + key).val(val);
                $('#' + key + '_hex').val(hex);
            });
            refreshAllBadges();
        });
        $(document).on('click', '.fi-autofix-btn', function () {
            var $btn    = $(this);
            var target  = $btn.data('target');
            var fixMode = $btn.data('fix-mode') || 'bg';
            var $input  = $('#' + target);
            if ( ! $input.length ) return;

            // Find the pair config for this target
            var pair = null;
            wcagPairs.forEach(function(p) { if (p.fixTarget === target) pair = p; });
            if ( ! pair ) return;

            var fg = pair.fg || getColorVal(pair.fgSrc);
            var bg = getColorVal(pair.bgSrc);
            if ( !fg || !bg ) return;

            // Nudge the adjustable color until ratio >= 4.5
            var adjustHex = fixMode === 'bg' ? bg : fg;
            var otherHex  = fixMode === 'bg' ? fg : bg;
            var rgb = hexToRgb(adjustHex);
            var steps = 0;

            while ( contrastRatio(fixMode === 'bg' ? otherHex : adjustHex,
                                   fixMode === 'bg' ? adjustHex : otherHex) < 4.5 && steps < 255 ) {
                if ( fixMode === 'bg' ) {
                    rgb.r = Math.max(0, rgb.r - 3);
                    rgb.g = Math.max(0, rgb.g - 3);
                    rgb.b = Math.max(0, rgb.b - 3);
                } else {
                    rgb.r = Math.min(255, rgb.r + 3);
                    rgb.g = Math.min(255, rgb.g + 3);
                    rgb.b = Math.min(255, rgb.b + 3);
                }
                adjustHex = '#' + [rgb.r, rgb.g, rgb.b].map(function(v) {
                    return ('0' + v.toString(16)).slice(-2);
                }).join('');
                steps++;
            }

            $input.val(adjustHex);
            $('#' + target + '_hex').val(adjustHex.toUpperCase());
            refreshAllBadges();
        });

        refreshAllBadges();
    }

    // =========================================================================
    // Market Intel
    // =========================================================================

    function initMarketIntel() {
        var filterState  = { category: 'all', date_range: 'all' };
        var assetIndex   = {};
        var currentCount = 0;

        loadAssetIndex();

        function loadAssetIndex() {
            $.post(FI_Admin.ajax_url, {
                action:   'fi_get_intel_index',
                nonce:    FI_Admin.nonce,
                industry: filterState.category,
            }, function(res) {
                if (!res.success) return;
                assetIndex   = res.data.index   || {};
                currentCount = res.data.current_count || 0;
                $('#fi-filtered-count').text(currentCount);
                updateCardLockStates(currentCount);
                updateCardSavedStates();
            });
        }

        $('#fi-filter-category').on('change', function() {
            filterState.category = $(this).val();
            refreshFilteredCount();
            loadAssetIndex();
        });

        $('#fi-filter-date-range').on('change', function() {
            filterState.date_range = $(this).val();
            refreshFilteredCount();
        });

        function refreshFilteredCount() {
            $.post(FI_Admin.ajax_url, {
                action:            'fi_get_filtered_scan_count',
                nonce:             FI_Admin.nonce,
                filter_category:   filterState.category,
                filter_date_range: filterState.date_range,
            }, function(res) {
                if (res.success) {
                    currentCount = parseInt(res.data.count, 10) || 0;
                    $('#fi-filtered-count').text(currentCount);
                    updateCardLockStates(currentCount);
                }
            });
        }

        function updateCardLockStates(count) {
            $('.fi-intel-action-card').each(function() {
                var $card     = $(this);
                var threshold = parseInt($card.data('threshold'), 10) || 0;
                var unlocked  = count >= threshold;

                $card.toggleClass('fi-intel-action-card--locked', !unlocked);
                $card.find('.fi-intel-run-btn').prop('disabled', !unlocked);
                $card.find('.fi-intel-action-locked').toggle(!unlocked);
                $card.find('.fi-card-scan-progress').text(count + '/' + threshold);
                $card.find('.fi-intel-action-run').toggle(unlocked);
            });

            $('.fi-intel-tier').each(function() {
                var $tier     = $(this);
                var threshold = parseInt($tier.data('tier-threshold'), 10) || 0;
                var $badge    = $tier.find('.fi-intel-tier-badge');
                var unlocked  = count >= threshold;

                $badge
                    .toggleClass('fi-intel-tier-badge--unlocked', unlocked)
                    .toggleClass('fi-intel-tier-badge--locked', !unlocked);

                if (unlocked) {
                    $badge.text('Unlocked');
                } else {
                    $badge.html('Locked: ' + threshold + '+ scans <span class="fi-intel-tier-progress">' + count + '/' + threshold + '</span>');
                }
            });
        }

        function updateCardSavedStates() {
            $('.fi-intel-action-card').each(function() {
                var $card = $(this);
                var slug  = $card.data('action');
                var saved = assetIndex[slug];

                $card.find('.fi-intel-saved-bar').remove();
                $card.removeClass('fi-intel-action-card--saved');

                if (!saved) return;

                $card.addClass('fi-intel-action-card--saved');

                var delta     = currentCount - saved.scan_count;
                var pct       = saved.scan_count > 0 ? delta / saved.scan_count : 0;
                var stale     = pct >= 0.25 && delta >= 5;
                var dateStr   = formatRelativeDate(saved.generated_at);
                var diffStr   = delta > 0 ? ' &middot; +' + delta + ' new scans' : '';
                var staleHtml = stale ? '<span class="fi-intel-stale-badge">More data available</span>' : '';
                var cardTitle = escAttr($card.find('.fi-intel-action-title').text());

                var $bar = $(
                    '<div class="fi-intel-saved-bar">' +
                        '<span class="fi-intel-saved-meta">Saved ' + dateStr + ' &middot; ' + saved.scan_count + ' scans' + diffStr + '</span>' +
                        staleHtml +
                        '<div class="fi-intel-saved-actions">' +
                            '<button type="button" class="fi-intel-view-btn"' +
                                ' data-asset-id="' + saved.id + '"' +
                                ' data-action="' + escAttr(slug) + '"' +
                                ' data-title="' + cardTitle + '">View</button>' +
                            '<button type="button" class="fi-intel-regen-btn fi-intel-run-btn"' +
                                ' data-action="' + escAttr(slug) + '"' +
                                ' data-title="' + cardTitle + '">Regenerate</button>' +
                            '<button type="button" class="fi-intel-delete-btn"' +
                                ' data-asset-id="' + saved.id + '"' +
                                ' data-action="' + escAttr(slug) + '">Delete</button>' +
                        '</div>' +
                    '</div>'
                );

                $card.append($bar);
            });
        }

        $(document).on('click', '.fi-intel-view-btn', function() {
            var $btn    = $(this);
            var assetId = $btn.data('asset-id');
            var title   = $btn.data('title');
            var slug    = $btn.data('action');
            var saved   = assetIndex[slug] || {};

            openOutputPanel(title, saved.scan_count, saved.generated_at);

            $.post(FI_Admin.ajax_url, {
                action:   'fi_load_intel_asset',
                nonce:    FI_Admin.nonce,
                asset_id: assetId,
            }, function(res) {
                $('#fi-intel-loading').hide();
                if (res.success) {
                    $('#fi-intel-output').html(formatIntelOutput(res.data.content_md));
                } else {
                    $('#fi-intel-output').html('<p class="fi-error-text">Could not load saved asset.</p>');
                }
            });
        });

        $(document).on('click', '.fi-intel-delete-btn', function() {
            if (!confirm('Delete this saved asset? You will need to regenerate it.')) return;

            var $btn    = $(this);
            var assetId = $btn.data('asset-id');
            var slug    = $btn.data('action');
            var $card   = $btn.closest('.fi-intel-action-card');

            $btn.prop('disabled', true).text('Deleting...');

            $.post(FI_Admin.ajax_url, {
                action:   'fi_delete_intel_asset',
                nonce:    FI_Admin.nonce,
                asset_id: assetId,
            }, function(res) {
                if (res.success) {
                    delete assetIndex[slug];
                    updateCardSavedStates();
                    var panelTitle = $('#fi-intel-output-title').text();
                    if (panelTitle === $card.find('.fi-intel-action-title').text()) {
                        $('#fi-intel-output-wrap').hide();
                        $('#fi-intel-output').html('');
                    }
                } else {
                    $btn.prop('disabled', false).text('Delete');
                }
            });
        });

        $(document).on('click', '.fi-intel-run-btn', function() {
            var $btn        = $(this);
            var $card       = $btn.closest('.fi-intel-action-card');
            var actionKey   = $btn.data('action');
            var actionTitle = $btn.data('title') || $card.find('.fi-intel-action-title').text();
            var needsPlat   = $btn.data('needs-platform');
            var platform    = '';

            if (needsPlat) {
                platform = $card.find('.fi-intel-platform-select').val() || 'facebook';
            }

            $card.find('button').prop('disabled', true);
            $btn.text('Generating...');

            openOutputPanel(actionTitle, null, null);

            $.post(FI_Admin.ajax_url, {
                action:            'fi_run_market_intel',
                nonce:             FI_Admin.nonce,
                action_type:       actionKey,
                platform:          platform,
                filter_category:   filterState.category,
                filter_date_range: filterState.date_range,
            }, function(res) {
                $card.find('button').prop('disabled', false);
                $btn.text($btn.hasClass('fi-intel-regen-btn') ? 'Regenerate' : 'Generate');
                $('#fi-intel-loading').hide();

                if (res.success && res.data.analysis) {
                    assetIndex[actionKey] = {
                        id:           res.data.asset_id,
                        scan_count:   res.data.scan_count,
                        generated_at: res.data.generated_at,
                    };
                    updateCardSavedStates();
                    $('#fi-intel-output').html(formatIntelOutput(res.data.analysis));
                    updateOutputMeta(res.data.scan_count, res.data.generated_at);
                    $('#fi-intel-output-wrap').addClass('fi-intel-output-flash');
                    setTimeout(function() {
                        $('#fi-intel-output-wrap').removeClass('fi-intel-output-flash');
                    }, 800);
                } else {
                    var msg = (res.data && typeof res.data === 'string') ? res.data : 'Generation failed.';
                    $('#fi-intel-output').html('<p class="fi-error-text">' + msg + '</p>');
                }
            });
        });

        function openOutputPanel(title, scanCount, generatedAt) {
            var $wrap = $('#fi-intel-output-wrap');
            $('#fi-intel-output-title').text(title);
            $('#fi-intel-output').html('');
            $('#fi-intel-loading').show();
            $wrap.show();
            updateOutputMeta(scanCount, generatedAt);
            $wrap[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function updateOutputMeta(scanCount, generatedAt) {
            var $ctx = $('#fi-intel-output-context');
            if (scanCount && generatedAt) {
                $ctx.text(scanCount + ' scans · Saved ' + formatRelativeDate(generatedAt));
            } else {
                $ctx.text('');
            }
        }

        $('#fi-intel-copy-btn').on('click', function() {
            var $btn = $(this);
            var text = $('#fi-intel-output').text().trim();
            if (!text) return;
            navigator.clipboard.writeText(text).then(function() {
                $btn.text('Copied');
                setTimeout(function() { $btn.text('Copy'); }, 2000);
            }).catch(function() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity  = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                $btn.text('Copied');
                setTimeout(function() { $btn.text('Copy'); }, 2000);
            });
        });

        $('#fi-intel-close-btn').on('click', function() {
            $('#fi-intel-output-wrap').hide();
            $('#fi-intel-output').html('');
        });

        function escAttr(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function formatRelativeDate(datetimeStr) {
            if (!datetimeStr) return '';
            var d    = new Date(datetimeStr.replace(' ', 'T'));
            var now  = new Date();
            var diff = Math.floor((now - d) / 1000);
            if (diff < 60)     return 'just now';
            if (diff < 3600)   return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400)  return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }

        function formatIntelOutput(text) {
            var html = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/^### (.+)$/gm, '<h4 class="fi-intel-h4">$1</h4>')
                .replace(/^## (.+)$/gm,  '<h3 class="fi-intel-h3">$1</h3>')
                .replace(/^# (.+)$/gm,   '<h2 class="fi-intel-h2">$1</h2>')
                .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
                .replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g,         '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code class="fi-intel-code">$1</code>')
                .replace(/^---+$/gm, '<hr class="fi-intel-hr">')
                .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
                .replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

            html = html.replace(/(<li>[\s\S]+?<\/li>)(?=\s*(?!<li>))/g, function(match) {
                return '<ul class="fi-intel-list">' + match + '</ul>';
            });

            var blocks = html.split(/\n{2,}/);
            html = blocks.map(function(block) {
                block = block.trim();
                if (!block) return '';
                if (/^<(h[2-4]|hr|ul|ol)/.test(block)) return block;
                return '<p class="fi-intel-para">' + block.replace(/\n/g, '<br>') + '</p>';
            }).join('');

            return html;
        }
    }

    // Debug Logs
    // =========================================================================

    function initDebugLogs() {
        $('#fi-clear-logs').on('click', function() {
            if (!confirm('Clear all logs?')) return;
            $.post(FI_Admin.ajax_url, { action: 'fi_clear_logs', nonce: FI_Admin.nonce }, function() { window.location.reload(); });
        });
    }


    // =========================================================================
    // Cron respawn (Bulk Scan health banner)
    // =========================================================================

    $(document).on('click', '#fi-cron-respawn', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Spawning…');
        $.post(FI_Admin.ajax_url, {
            action: 'fi_bulk_respawn_cron',
            nonce:  FI_Admin.nonce,
        }, function(res) {
            if (res && res.success) {
                $btn.closest('.fi-bulk-cron-banner')
                    .removeClass('fi-bulk-cron-banner--error')
                    .addClass('fi-bulk-cron-banner--warn')
                    .html('⟳ Cron spawn attempted. Refresh the page in 30 seconds to check if the job is progressing.');
            } else {
                $btn.prop('disabled', false).text('Try spawning cron now');
                alert('Could not spawn cron. You may need to set up a real system cron job.');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Try spawning cron now');
        });
    });


    // =========================================================================
    // Cache
    // =========================================================================

    function initCache() {
        $('#fi-clear-cache').on('click', function() {
            $.post(FI_Admin.ajax_url, { action: 'fi_clear_cache', nonce: FI_Admin.nonce }, function() { window.location.reload(); });
        });
    }

    // =========================================================================
    // Lead Pipeline — Status, Follow-up Date, Notes, Pitch, CSV Export
    // =========================================================================

    function initLeadPipeline() {

        // ── Status dropdown ───────────────────────────────────────────────
        $(document).on('change', '.fi-status-select', function () {
            var $sel    = $(this);
            var leadId  = $sel.data('lead-id');
            var status  = $sel.val();
            var $row    = $sel.closest('tr');

            $sel.prop('disabled', true);

            $.post(FI_Admin.ajax_url, {
                action:  'fi_update_lead_status',
                nonce:   FI_Admin.nonce,
                lead_id: leadId,
                status:  status,
            }, function (res) {
                $sel.prop('disabled', false);
                if (res.success) {
                    $row.addClass('fi-saved-flash');
                    setTimeout(function () { $row.removeClass('fi-saved-flash'); }, 800);

                    // Reveal Set Up Reviews button when status becomes Closed,
                    // but only if no Reviews record already exists for this row.
                    var $actionsCell = $row.find('.fi-pipeline-cell-actions');
                    var alreadyHasReviews = $actionsCell.find('.fi-reviews-setup-btn, .fi-reviews-manage-btn').length > 0;
                    if ( status === 'closed' && ! alreadyHasReviews ) {
                        var $setupBtn = $('<button type="button" class="button button-small fi-reviews-setup-btn" title="Create a Reviews record for this client">&#11088; Set Up Reviews</button>');
                        $setupBtn.data('lead-id', leadId);
                        $actionsCell.append(' ').append($setupBtn);
                    } else if ( status !== 'closed' ) {
                        // Remove the button if status moves away from Closed
                        // Only remove if it hasn't been used yet (no manage link)
                        $actionsCell.find('.fi-reviews-setup-btn').remove();
                    }
                } else {
                    alert('Could not update status.');
                }
            }).fail(function () {
                $sel.prop('disabled', false);
                alert('Request failed.');
            });
        });

        // ── Follow-up date ────────────────────────────────────────────────
        $(document).on('change', '.fi-followup-date', function () {
            var $inp   = $(this);
            var leadId = $inp.data('lead-id');
            var date   = $inp.val();
            var $row   = $inp.closest('tr');

            $.post(FI_Admin.ajax_url, {
                action:  'fi_set_followup_date',
                nonce:   FI_Admin.nonce,
                lead_id: leadId,
                date:    date,
            }, function (res) {
                if (res.success) {
                    // Remove overdue highlight if date was cleared or changed
                    $inp.removeClass('fi-overdue');
                    if (date) {
                        var today = new Date().toISOString().split('T')[0];
                        if (date < today) $inp.addClass('fi-overdue');
                    }
                    $row.addClass('fi-saved-flash');
                    setTimeout(function () { $row.removeClass('fi-saved-flash'); }, 800);
                }
            });
        });

        // ── Notes — debounced autosave ────────────────────────────────────
        var notesTimers = {};
        $(document).on('input', '.fi-notes-field', function () {
            var $ta    = $(this);
            var leadId = $ta.data('lead-id');

            clearTimeout(notesTimers[leadId]);
            notesTimers[leadId] = setTimeout(function () {
                $.post(FI_Admin.ajax_url, {
                    action:  'fi_save_lead_notes',
                    nonce:   FI_Admin.nonce,
                    lead_id: leadId,
                    notes:   $ta.val(),
                }, function (res) {
                    if (res.success) {
                        var $row = $ta.closest('tr');
                        $row.addClass('fi-saved-flash');
                        setTimeout(function () { $row.removeClass('fi-saved-flash'); }, 800);
                    }
                });
            }, 800);
        });

        // ── Pitch generation ──────────────────────────────────────────────
        $(document).on('click', '.fi-gen-pitch-btn', function () {
            var $btn   = $(this);
            var leadId = $btn.data('lead-id');

            $btn.prop('disabled', true).text('Generating…');

            $.post(FI_Admin.ajax_url, {
                action:  'fi_generate_pitch',
                nonce:   FI_Admin.nonce,
                lead_id: leadId,
            }, function (res) {
                $btn.prop('disabled', false).text('✉ Pitch');
                if (res.success && res.data) {
                    var pitch   = res.data.body    || '';
                    var subject = res.data.subject || '';
                    var copyText = subject ? 'Subject: ' + subject + '\n\n' + pitch : pitch;
                    // Show in a modal-style overlay
                    var $modal = $('<div class="fi-pitch-modal">'
                        + '<div class="fi-pitch-modal-inner">'
                        + '<div class="fi-pitch-modal-header">'
                        + '<strong>Generated Cold Outreach Email</strong>'
                        + '<button type="button" class="fi-pitch-modal-close">✕</button>'
                        + '</div>'
                        + (subject ? '<div class="fi-pitch-modal-subject"><strong>Subject:</strong> ' + escHtml(subject) + '</div>' : '')
                        + '<textarea class="fi-pitch-modal-body fi-input" rows="12" readonly>' + escHtml(pitch) + '</textarea>'
                        + '<div class="fi-pitch-modal-footer">'
                        + '<button type="button" class="button button-primary fi-pitch-copy-btn">Copy to Clipboard</button>'
                        + '</div>'
                        + '</div></div>');
                    $('body').append($modal);
                    $modal.find('.fi-pitch-modal-close').on('click', function () { $modal.remove(); });
                    $modal.on('click', function (e) { if ($(e.target).is($modal)) $modal.remove(); });
                    $modal.find('.fi-pitch-copy-btn').on('click', function () {
                        navigator.clipboard.writeText(copyText).then(function () {
                            $modal.find('.fi-pitch-copy-btn').text('Copied!');
                            setTimeout(function () { $modal.find('.fi-pitch-copy-btn').text('Copy to Clipboard'); }, 2000);
                        });
                    });
                } else {
                    alert('Failed to generate pitch: ' + (res.data || 'Unknown error'));
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('✉ Pitch');
                alert('Request failed.');
            });
        });

        // ── Reply draft (Leads table) ─────────────────────────────────────
        $(document).on('click', '.fi-gen-reply-btn', function () {
            var $btn   = $(this);
            var leadId = $btn.data('lead-id');

            $btn.prop('disabled', true).text('Drafting…');

            $.post(FI_Admin.ajax_url, {
                action:  'fi_generate_reply',
                nonce:   FI_Admin.nonce,
                lead_id: leadId,
            }, function (res) {
                $btn.prop('disabled', false).text('✉ Reply Draft');
                if (res.success && res.data) {
                    var body    = res.data.body    || '';
                    var subject = res.data.subject || '';
                    var copyText = subject ? 'Subject: ' + subject + '\n\n' + body : body;
                    var $modal = $('<div class="fi-pitch-modal">'
                        + '<div class="fi-pitch-modal-inner">'
                        + '<div class="fi-pitch-modal-header">'
                        + '<strong>Warm Follow-Up Draft</strong>'
                        + '<button type="button" class="fi-pitch-modal-close">✕</button>'
                        + '</div>'
                        + (subject ? '<div class="fi-pitch-modal-subject"><strong>Subject:</strong> ' + escHtml(subject) + '</div>' : '')
                        + '<textarea class="fi-pitch-modal-body fi-input" rows="12" readonly>' + escHtml(body) + '</textarea>'
                        + '<div class="fi-pitch-modal-footer">'
                        + '<button type="button" class="button button-primary fi-pitch-copy-btn">Copy to Clipboard</button>'
                        + '</div>'
                        + '</div></div>');
                    $('body').append($modal);
                    $modal.find('.fi-pitch-modal-close').on('click', function () { $modal.remove(); });
                    $modal.on('click', function (e) { if ($(e.target).is($modal)) $modal.remove(); });
                    $modal.find('.fi-pitch-copy-btn').on('click', function () {
                        navigator.clipboard.writeText(copyText).then(function () {
                            $modal.find('.fi-pitch-copy-btn').text('Copied!');
                            setTimeout(function () { $modal.find('.fi-pitch-copy-btn').text('Copy to Clipboard'); }, 2000);
                        });
                    });
                } else {
                    alert('Failed to generate reply: ' + (res.data || 'Unknown error'));
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('✉ Reply Draft');
                alert('Request failed.');
            });
        });

        // ── CSV Export — trigger download ─────────────────────────────────
        $('#fi-export-csv').on('click', function () {
            // Build a form POST to the AJAX handler which now streams a real CSV download
            var $form = $('<form method="post" action="' + FI_Admin.ajax_url + '" style="display:none;">'
                + '<input name="action" value="fi_export_leads">'
                + '<input name="nonce" value="' + FI_Admin.nonce + '">'
                + '</form>');
            $('body').append($form);
            $form.submit();
            setTimeout(function () { $form.remove(); }, 3000);
        });
    }

    // =========================================================================
    // Bar Charts
    // =========================================================================

    function initBarCharts() {
        $('.fi-bar-chart').each(function () {
            var $chart = $(this);
            var raw    = $chart.data('values');
            if (!raw) return;
            var data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            var max = Math.max.apply(null, data.map(function (d) { return parseInt(d.count, 10) || 0; }));
            if (max === 0) return;
            var html = '';
            data.forEach(function (d) {
                var count = parseInt(d.count, 10) || 0;
                var pct   = Math.max(4, Math.round((count / max) * 100));
                html += '<div class="fi-bar-wrap"><div class="fi-bar" style="height:' + pct + '%"></div></div>';
            });
            $chart.html(html);
        });
    }

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Report popup modal ────────────────────────────────────────────────────
    function initReportPopup() {
        $(document).on('click', '.fi-report-popup', function (e) {
            e.preventDefault();
            var url   = $(this).data('url');
            var title = $(this).data('title') || 'Report';

            var $modal = $(
                '<div class="fi-report-modal">' +
                  '<div class="fi-report-modal-inner">' +
                    '<div class="fi-report-modal-bar">' +
                      '<span>' + escHtml(title) + '</span>' +
                      '<div>' +
                        '<a href="' + escHtml(url) + '" target="_blank" rel="noopener">Open in new tab ↗</a>' +
                        '<button type="button" class="fi-report-modal-close" title="Close">✕</button>' +
                      '</div>' +
                    '</div>' +
                    '<iframe class="fi-report-modal-iframe" src="' + escHtml(url) + '" allowfullscreen></iframe>' +
                  '</div>' +
                '</div>'
            );

            $('body').append($modal);

            $modal.find('.fi-report-modal-close').on('click', function () { $modal.remove(); });
            $modal.on('click', function (e) { if ($(e.target).is($modal)) $modal.remove(); });
            $(document).one('keydown.reportmodal', function (e) {
                if (e.key === 'Escape') { $modal.remove(); $(document).off('keydown.reportmodal'); }
            });
        });
    }

    // ── Pipeline column sort ──────────────────────────────────────────────────
    function initPipelineSort() {
        $(document).on('click', 'th[data-sort]', function () {
            var $th      = $(this);
            var sortKey  = $th.data('sort');
            var tableId  = $th.data('table');
            var $table   = $('#' + tableId);
            var $tbody   = $table.find('tbody');
            var $rows    = $tbody.find('tr').toArray();
            var isAsc    = $th.hasClass('sort-asc');
            var dir      = isAsc ? -1 : 1;  // toggle

            // Reset all th in this table
            $table.find('th[data-sort]').removeClass('sort-asc sort-desc');
            $th.addClass( isAsc ? 'sort-desc' : 'sort-asc' );

            $rows.sort(function (a, b) {
                var aVal = getCellVal(a, sortKey);
                var bVal = getCellVal(b, sortKey);
                if (sortKey === 'score') {
                    return dir * (parseFloat(aVal) - parseFloat(bVal));
                }
                return dir * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });

            $tbody.append($rows);
        });

        function getCellVal(row, key) {
            var $row = $(row);
            switch (key) {
                case 'name':
                    return $row.find('.fi-pipeline-cell-name').attr('data-sort-name') || '';
                case 'email':
                    return $row.find('.fi-pipeline-cell-email').text().trim();
                case 'score':
                    return $row.find('.fi-pipeline-cell-score').text().trim();
                case 'pain':
                    return $row.find('.fi-pipeline-cell-pain').text().trim();
                case 'status':
                    return $row.find('.fi-status-select').val() || '';
                default:
                    return '';
            }
        }
    }


    // ── Lead Form settings ────────────────────────────────────────────────────
    function initLeadForm() {
        // ── Toggle Show → enable/disable Required + update preview ───────
        $(document).on('change', '.fi-field-enable-cb', function () {
            var key      = $(this).data('field');
            var enabled  = this.checked;
            var $reqCb   = $('.fi-field-required-cb[data-field="' + key + '"]');
            var $reqLbl  = $reqCb.closest('label');

            // Enable / disable the Required toggle
            $reqCb.prop('disabled', !enabled);
            $reqLbl.toggleClass('fi-toggle--disabled', !enabled);
            if (!enabled) {
                $reqCb.prop('checked', false);
            }

            // Show / hide custom label input
            if (key === 'custom') {
                $('#fi-custom-field-meta').toggleClass('fi-custom-field-meta--hidden', !enabled);
            }

            // Update live preview
            updatePreviewField(key, enabled, $reqCb.is(':checked'));
        });

        // ── Required toggle → update preview asterisk ────────────────────
        $(document).on('change', '.fi-field-required-cb', function () {
            var key     = $(this).data('field');
            var enabled = $('.fi-field-enable-cb[data-field="' + key + '"]').is(':checked');
            updatePreviewField(key, enabled, this.checked);
        });

        function updatePreviewField(key, enabled, required) {
            if (key === 'firstname' || key === 'lastname') {
                // Name row: show if either first or last is enabled
                var fnOn = $('.fi-field-enable-cb[data-field="firstname"]').is(':checked');
                var lnOn = $('.fi-field-enable-cb[data-field="lastname"]').is(':checked');
                $('#fi-preview-name-row').toggle(fnOn || lnOn);
                // Individual half-width slots inside the row
                updateNameSlot('firstname', fnOn, $('.fi-field-required-cb[data-field="firstname"]').is(':checked'));
                updateNameSlot('lastname',  lnOn, $('.fi-field-required-cb[data-field="lastname"]').is(':checked'));
            } else {
                var $field = $('#fi-preview-field-' + key);
                $field.toggle(enabled);
                $field.toggleClass('fi-preview-field--required', required);
            }
        }

        function updateNameSlot(key, enabled, required) {
            // Name slots live inside the row — find by data attr if present, else by index
            var $row   = $('#fi-preview-name-row');
            var $slots = $row.find('.fi-preview-field');
            // Rebuild the row dynamically based on current state
            $row.empty();
            ['firstname', 'lastname'].forEach(function (k) {
                var isOn  = $('.fi-field-enable-cb[data-field="' + k + '"]').is(':checked');
                var isReq = $('.fi-field-required-cb[data-field="' + k + '"]').is(':checked');
                if (isOn) {
                    var label = k === 'firstname' ? 'First name' : 'Last name';
                    var cls   = 'fi-preview-field' + (isReq ? ' fi-preview-field--required' : '');
                    $row.append(
                        '<div class="' + cls + '">' +
                        '<div class="fi-preview-input-fake"></div>' +
                        '<span class="fi-preview-label">' + label + '</span>' +
                        '</div>'
                    );
                }
            });
        }

        // ── Custom label live preview + debounced AJAX save ───────────────
        var customLabelTimer;
        $(document).on('input', '#fi_field_custom_label', function () {
            var $input = $(this);
            var val    = $input.val();

            // Live preview update
            $('#fi-preview-custom-label').text(val || 'Custom field');

            // Debounced save
            clearTimeout(customLabelTimer);
            customLabelTimer = setTimeout(function () {
                $.post(FI_Admin.ajax_url, {
                    action: 'fi_save_custom_label',
                    nonce:  FI_Admin.nonce,
                    label:  val,
                }, function (res) {
                    if (res.success) {
                        // Reuse animation by forcing reflow
                        $input.removeClass('fi-input-saved');
                        void $input[0].offsetWidth;
                        $input.addClass('fi-input-saved');
                        setTimeout(function () { $input.removeClass('fi-input-saved'); }, 800);
                    }
                });
            }, 800);
        });

        // ── Consent toggle show/hide ──────────────────────────────────────
        // (Already wired in PHP inline script but replicate here cleanly)
        $('#fi-consent-enabled-cb').on('change', function () {
            $('#fi-consent-fields').toggle(this.checked);
        });
    }

    // =========================================================================
    // F! Reviews — detail screen autosave, toggles, surfaces, archive
    // =========================================================================

    function initReviews() {

        // ── Step rail navigation ──────────────────────────────────────────
        // Clicking a step node or Next/Back buttons switches the visible panel.
        function showStep(num) {
            $('.fi-reviews-step-panel').addClass('fi-hidden');
            $('#fi-rv-step-' + num).removeClass('fi-hidden');
            $('.fi-reviews-step-node').each(function () {
                var $n = $(this);
                var n  = parseInt( $n.data('step'), 10 );
                $n.toggleClass('fi-reviews-step-node--active', n === num);
            });
            // Scroll to rail top
            var $rail = $('.fi-reviews-step-rail');
            if ( $rail.length ) {
                $('html,body').animate({ scrollTop: $rail.offset().top - 40 }, 150);
            }
        }

        // Step rail button click
        $(document).on('click', '.fi-reviews-step-node', function () {
            showStep( parseInt( $(this).data('step'), 10 ) );
        });

        // Next button
        $(document).on('click', '.fi-reviews-step-next', function () {
            var next = parseInt( $(this).data('next'), 10 );
            showStep( next );
        });

        // Back button
        $(document).on('click', '.fi-reviews-step-prev', function () {
            var prev = parseInt( $(this).data('prev'), 10 );
            showStep( prev );
        });

        // Enable Step 1 Next button live when domain + review_url are both filled
        $(document).on('input change', '#fi_rv_domain, #fi_rv_review_url', function () {
            var domain = $.trim( $('#fi_rv_domain').val() );
            var url    = $.trim( $('#fi_rv_review_url').val() );
            var $btn   = $('#fi-rv-step-1').find('.fi-reviews-step-next');
            $btn.prop( 'disabled', ! domain || ! url );
        });

        // Enable Step 2 Next button live when at least one collection/display toggle is on
        $(document).on('change', '.fi-reviews-toggle', function () {
            var $panel = $('#fi-rv-step-2');
            var anyOn  = $panel.find(
                '[data-field="feature_review_button"],[data-field="feature_qr_display"],[data-field="feature_display_widget"]'
            ).filter(':checked').length > 0;
            $panel.find('.fi-reviews-step-next').prop('disabled', ! anyOn);
        });

        // ── "Set Up Reviews" button — create record + redirect ────────────
        $(document).on('click', '.fi-reviews-setup-btn', function () {
            var $btn   = $(this);
            var leadId = $btn.data('lead-id');

            $btn.prop('disabled', true).text('Creating…');

            $.post(FI_Admin.ajax_url, {
                action:  'fi_reviews_create',
                nonce:   FI_Admin.nonce,
                lead_id: leadId,
            }, function (res) {
                if (res.success && res.data.redirect_url) {
                    window.location.href = res.data.redirect_url;
                } else {
                    $btn.prop('disabled', false).text('⭐ Set Up Reviews');
                    alert(res.data || 'Could not create Reviews record.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('⭐ Set Up Reviews');
                alert('Request failed.');
            });
        });

        // ── Autosave — text inputs and selects on the detail screen ───────
        // Debounced for text; immediate for selects and numbers.
        var rvAutosaveTimers = {};

        function rvSaveField(recordId, field, value, $el) {
            $.post(FI_Admin.ajax_url, {
                action:    'fi_reviews_update_field',
                nonce:     FI_Admin.nonce,
                record_id: recordId,
                field:     field,
                value:     value,
            }, function (res) {
                if (res.success) {
                    if ($el) {
                        $el.addClass('fi-input-saved');
                        setTimeout(function () { $el.removeClass('fi-input-saved'); }, 800);
                    }
                } else {
                    if ($el) $el.addClass('fi-input-error');
                    setTimeout(function () { if ($el) $el.removeClass('fi-input-error'); }, 2000);
                }
            });
        }

        // Text inputs — debounced 700ms
        $(document).on('input', '.fi-reviews-autosave[type=text], .fi-reviews-autosave[type=url], .fi-reviews-autosave.fi-textarea', function () {
            var $el       = $(this);
            var recordId  = $el.data('record-id');
            var field     = $el.data('field');
            var value     = $el.val();
            var timerKey  = recordId + '_' + field;

            clearTimeout(rvAutosaveTimers[timerKey]);
            rvAutosaveTimers[timerKey] = setTimeout(function () {
                rvSaveField(recordId, field, value, $el);
            }, 700);
        });

        // Textarea (notes) — also debounced via input event above
        // Select and number inputs — immediate on change
        $(document).on('change', 'select.fi-reviews-autosave, input[type=number].fi-reviews-autosave', function () {
            var $el      = $(this);
            var recordId = $el.data('record-id');
            var field    = $el.data('field');
            var value    = $el.val();
            rvSaveField(recordId, field, value, $el);
        });

        // ── Feature toggles — immediate save + show/hide dependent panels ─
        $(document).on('change', '.fi-reviews-toggle', function () {
            var $cb       = $(this);
            var recordId  = $cb.data('record-id');
            var field     = $cb.data('field');
            var value     = $cb.is(':checked') ? 1 : 0;

            rvSaveField(recordId, field, value, null);

            // Show/hide dependent config panels
            if (field === 'feature_display_widget') {
                $('#fi-reviews-display-config').toggle($cb.is(':checked'));
            }
            if (field === 'feature_attribution') {
                $('#fi-reviews-attribution-config').toggle($cb.is(':checked'));
            }
        });

        // ── Archive button ────────────────────────────────────────────────
        $(document).on('click', '.fi-reviews-archive-btn', function () {
            var $btn      = $(this);
            var recordId  = $btn.data('record-id');
            var confirmed = confirm($btn.data('confirm') || 'Archive this record?');
            if (!confirmed) return;

            $btn.prop('disabled', true).text('Archiving…');

            $.post(FI_Admin.ajax_url, {
                action:    'fi_reviews_archive',
                nonce:     FI_Admin.nonce,
                record_id: recordId,
            }, function (res) {
                if (res.success) {
                    window.location.href = window.location.href.split('&review_id')[0];
                } else {
                    $btn.prop('disabled', false).text('Archive');
                    alert(res.data || 'Could not archive record.');
                }
            });
        });

        // ── Restore button ────────────────────────────────────────────────
        $(document).on('click', '.fi-reviews-restore-btn', function () {
            var $btn     = $(this);
            var recordId = $btn.data('record-id');

            $btn.prop('disabled', true).text('Restoring…');

            $.post(FI_Admin.ajax_url, {
                action:    'fi_reviews_restore',
                nonce:     FI_Admin.nonce,
                record_id: recordId,
            }, function (res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    $btn.prop('disabled', false).text('Restore');
                    alert(res.data || 'Could not restore record.');
                }
            });
        });

        // ── Add tracking surface ──────────────────────────────────────────
        $(document).on('click', '.fi-reviews-surface-add-btn', function () {
            var $btn      = $(this);
            var recordId  = $btn.data('record-id');
            var $label    = $('#fi-reviews-surface-label');
            var $param    = $('#fi-reviews-surface-param');
            var labelVal  = $.trim($label.val());
            var paramVal  = $.trim($param.val()).toLowerCase().replace(/[^a-z0-9_]/g, '_');

            if (!labelVal || !paramVal) {
                alert('Both a label and a param are required.');
                return;
            }

            $btn.prop('disabled', true).text('Adding…');

            $.post(FI_Admin.ajax_url, {
                action:    'fi_reviews_add_surface',
                nonce:     FI_Admin.nonce,
                record_id: recordId,
                label:     labelVal,
                param:     paramVal,
            }, function (res) {
                $btn.prop('disabled', false).text('Add Surface');
                if (res.success) {
                    var d = res.data;
                    var copyBtn = d.tagged_url
                        ? '<button type="button" class="fi-copy-btn fi-copy-btn--small" data-copy="' + $('<div/>').text(d.tagged_url).html() + '" onclick="navigator.clipboard.writeText(this.dataset.copy);this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy URL\',1500)">Copy URL</button>'
                        : '';
                    var $newRow = $(
                        '<tr data-surface-id="' + d.surface_id + '">' +
                        '<td>' + $('<div/>').text(d.label).html() + '</td>' +
                        '<td><code>' + $('<div/>').text(d.param).html() + '</code></td>' +
                        '<td>0</td>' +
                        '<td>0</td>' +
                        '<td>' + copyBtn + '</td>' +
                        '<td><button type="button" class="fi-reviews-surface-delete" data-surface-id="' + d.surface_id + '" data-record-id="' + recordId + '" title="Remove surface">&times;</button></td>' +
                        '</tr>'
                    );
                    $('#fi-reviews-surfaces-tbody').append($newRow);
                    $label.val('');
                    $param.val('');
                } else {
                    alert(res.data || 'Could not add surface.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Add Surface');
                alert('Request failed.');
            });
        });

        // Auto-sanitise param field as user types
        $(document).on('input', '#fi-reviews-surface-param', function () {
            var cleaned = $(this).val().toLowerCase().replace(/[^a-z0-9_]/g, '_');
            $(this).val(cleaned);
        });

        // ── Delete tracking surface (card DOM) ────────────────────────────
        $(document).on('click', '.fi-reviews-surface-delete', function () {
            var $btn      = $(this);
            var surfaceId = $btn.data('surface-id');

            if (!confirm('Remove this surface? Its view and click counts will be lost.')) return;

            $btn.prop('disabled', true);

            $.post(FI_Admin.ajax_url, {
                action:     'fi_reviews_delete_surface',
                nonce:      FI_Admin.nonce,
                surface_id: surfaceId,
            }, function (res) {
                if (res.success) {
                    // Support both old <tr> rows and new card divs
                    var $card = $btn.closest('.fi-rv-surface-card, tr');
                    $card.fadeOut(200, function () { $(this).remove(); });
                } else {
                    $btn.prop('disabled', false);
                    alert(res.data || 'Could not remove surface.');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                alert('Request failed.');
            });
        });

        // ── Add tracking surface — inject card DOM on success ─────────────
        // Override the existing handler to build a card instead of a table row
        $(document).off('click', '.fi-reviews-surface-add-btn');
        $(document).on('click', '.fi-reviews-surface-add-btn', function () {
            var $btn      = $(this);
            var recordId  = $btn.data('record-id');
            var $label    = $('#fi-reviews-surface-label');
            var $param    = $('#fi-reviews-surface-param');
            var labelVal  = $.trim($label.val());
            var paramVal  = $.trim($param.val()).toLowerCase().replace(/[^a-z0-9_]/g, '_');

            if (!labelVal || !paramVal) {
                alert('Both a label and a param are required.');
                return;
            }

            $btn.prop('disabled', true).text('Adding…');

            $.post(FI_Admin.ajax_url, {
                action:    'fi_reviews_add_surface',
                nonce:     FI_Admin.nonce,
                record_id: recordId,
                label:     labelVal,
                param:     paramVal,
            }, function (res) {
                $btn.prop('disabled', false).text('Add surface');
                if (res.success) {
                    var d = res.data;
                    var copyBtnHtml = d.tagged_url
                        ? '<button type="button" class="button button-small fi-rv-surface-copy-btn" data-copy="' + $('<div/>').text(d.tagged_url).html() + '" onclick="navigator.clipboard.writeText(this.dataset.copy);this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy tagged URL\',1500)">Copy tagged URL</button>'
                        : '';

                    var $card = $(
                        '<div class="fi-rv-surface-card" data-surface-id="' + d.surface_id + '">' +
                        '  <div class="fi-rv-surface-top">' +
                        '    <div class="fi-rv-surface-name">' + $('<div/>').text(d.label).html() + '</div>' +
                        '    <button type="button" class="fi-reviews-surface-delete" data-surface-id="' + d.surface_id + '" data-record-id="' + recordId + '" title="Remove">&times;</button>' +
                        '  </div>' +
                        '  <div class="fi-rv-surface-stats">' +
                        '    <span class="fi-rv-stat"><span class="fi-rv-stat-num">0</span><span class="fi-rv-stat-label">views</span></span>' +
                        '    <span class="fi-rv-stat-sep">/</span>' +
                        '    <span class="fi-rv-stat"><span class="fi-rv-stat-num">0</span><span class="fi-rv-stat-label">clicks</span></span>' +
                        '  </div>' +
                        '  <div class="fi-rv-surface-param"><code>' + $('<div/>').text(d.param).html() + '</code></div>' +
                        copyBtnHtml +
                        '</div>'
                    );

                    // If surfaces container exists, append; otherwise create it before add-surface
                    var $surfaces = $('.fi-rv-surfaces');
                    if ( $surfaces.length ) {
                        $surfaces.append($card);
                    } else {
                        $('<div class="fi-rv-surfaces"></div>').insertBefore('#fi-reviews-add-surface').append($card);
                    }

                    $label.val('');
                    $param.val('');
                } else {
                    alert(res.data || 'Could not add surface.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Add surface');
                alert('Request failed.');
            });
        });

        // ── QR code generation via qrcode.js ─────────────────────────────
        function initQR() {
            var $container = $('#fi-rv-qr-canvas');
            if ( ! $container.length ) return;
            var url  = $container.data('url');
            var name = $container.data('name') || 'review';
            if ( ! url ) return;

            function tryRender(attempts) {
                if ( typeof QRCode === 'undefined' ) {
                    if ( attempts > 30 ) {
                        $container.html('<p style="color:#9ca3af;font-size:12px;">QR library not loaded.</p>');
                        return;
                    }
                    setTimeout(function () { tryRender(attempts + 1); }, 200);
                    return;
                }
                $container.empty();
                new QRCode($container[0], {
                    text:         url,
                    width:        200,
                    height:       200,
                    colorDark:    '#111827',
                    colorLight:   '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            }
            tryRender(0);

            // Download: scale canvas to 600px for print quality
            $(document).on('click', '.fi-rv-qr-download-btn', function () {
                var canvas = $container.find('canvas')[0];
                if ( ! canvas ) {
                    alert('QR code is still generating — try again in a moment.');
                    return;
                }
                var out = document.createElement('canvas');
                out.width = 600; out.height = 600;
                var ctx = out.getContext('2d');
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(canvas, 0, 0, 600, 600);

                var filename = name.replace(/[^a-z0-9]/gi, '-').toLowerCase() + '-review-qr.png';
                var link = document.createElement('a');
                link.download = filename;
                link.href = out.toDataURL('image/png');
                link.click();
            });
        }
        initQR();

        // ── Generic copy buttons (data-copy-from) ─────────────────────────
        $(document).on('click', '.fi-rv-copy-btn', function () {
            var $btn   = $(this);
            var fromId = $btn.data('copy-from');
            var text   = fromId
                ? ( $('#' + fromId).text() || $('#' + fromId).val() )
                : $btn.data('copy');
            if ( ! text ) return;
            navigator.clipboard.writeText( $.trim(text) ).then(function () {
                var orig = $btn.text();
                $btn.text('Copied!');
                setTimeout(function () { $btn.text(orig); }, 1500);
            });
        });
    }


})(jQuery);