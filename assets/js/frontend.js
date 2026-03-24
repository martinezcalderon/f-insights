/* F! Insights — Frontend JS v2.0.1 */
(function ($) {
    'use strict';

    if (typeof FI === 'undefined') return;

    // =========================================================================
    // State
    // =========================================================================

    var state = {
        selectedPlace: null,   // { place_id, name, address }
        currentScan:   null,   // full scan response from server
        autocompleteResults: [],
        searchTimeout: null,
        userLat: 0,
        userLng: 0,
    };

    // =========================================================================
    // Init
    // =========================================================================

    $(document).ready(function () {
        if ($('#fi-scanner').length) {
            initScanner();
        }
        // Handle shared report token in URL
        var params = new URLSearchParams(window.location.search);
        if (params.get('fi_report') && $('#fi-report-area').length) {
            loadSharedReport(params.get('fi_report'));
        }
    });

    function initScanner() {
        // Request geolocation for location-biased autocomplete
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                state.userLat = pos.coords.latitude;
                state.userLng = pos.coords.longitude;
            });
        }

        // Search input — debounced autocomplete + show/hide clear button
        $('#fi-search-input').on('input', function () {
            var query = $(this).val().trim();
            $('#fi-clear-btn').toggle(query.length > 0);
            clearTimeout(state.searchTimeout);
            if (query.length < 2) {
                hideDropdown();
                return;
            }
            state.searchTimeout = setTimeout(function () {
                fetchAutocomplete(query);
            }, 300);
        });

        // Clear button — resets input, selection, and dropdown
        $('#fi-clear-btn').on('click', function () {
            $('#fi-search-input').val('').trigger('focus');
            $(this).hide();
            state.selectedPlace = null;
            hideDropdown();
        });

        // Hide dropdown when clicking outside the scanner or the dropdown itself
        $(document).on('click.fi', function (e) {
            if (!$(e.target).closest('#fi-scanner').length &&
                !$(e.target).closest('.fi-suggestions--portal').length) {
                hideDropdown();
            }
        });

        // Scan button
        $('#fi-scan-btn').on('click', function () {
            runScan();
        });

        // Enter key in search
        $('#fi-search-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // If first dropdown result exists, select it
                var first = $('.fi-suggestion').first();
                if (first.length) {
                    first.trigger('click');
                } else {
                    runScan();
                }
            }
        });
    }

    // =========================================================================
    // Autocomplete
    // =========================================================================

    function fetchAutocomplete(query) {
        $.post(FI.ajax_url, {
            action:   'fi_autocomplete',
            nonce:    FI.nonce,
            query:    query,
            lat:      state.userLat,
            lng:      state.userLng,
        }, function (res) {
            if (res.success && res.data.length) {
                state.autocompleteResults = res.data;
                renderDropdown(res.data);
            } else {
                hideDropdown();
            }
        });
    }

    function renderDropdown(results) {
        hideDropdown();

        var $input   = $('#fi-search-input');
        var rect     = $input[0].getBoundingClientRect();

        var $list = $('<ul class="fi-suggestions fi-suggestions--portal"></ul>').css({
            position: 'fixed',
            top:      rect.bottom + 4,
            left:     rect.left,
            width:    rect.width,
            zIndex:   2147483647,  // max z-index — escapes all stacking contexts
        });

        results.forEach(function (r) {
            var $item = $('<li class="fi-suggestion"></li>')
                .html('<strong>' + escHtml(r.name) + '</strong><span>' + escHtml(r.address) + '</span>')
                .data('place', r)
                .on('click', function () {
                    selectPlace($(this).data('place'));
                });
            $list.append($item);
        });

        // Append to body so overflow:hidden on any ancestor (Divi modals,
        // page builders, etc.) cannot clip the dropdown.
        $('body').append($list);

        // Reposition on scroll or resize in case the input moves
        function reposition() {
            var r = $input[0].getBoundingClientRect();
            $list.css({ top: r.bottom + 4, left: r.left, width: r.width });
        }
        $(window).on('scroll.fidrop resize.fidrop', reposition);
    }

    function hideDropdown() {
        $('.fi-suggestions--portal').remove();
        $(window).off('scroll.fidrop resize.fidrop');
    }

    function selectPlace(place) {
        state.selectedPlace = place;
        $('#fi-search-input').val(place.name);
        $('#fi-clear-btn').show();
        hideDropdown();
    }

    // =========================================================================
    // Scan
    // =========================================================================

    // Track page load time for submission timing check
    var _pageLoadTime = Date.now();

    function runScan() {
        var place    = state.selectedPlace;
        var inputVal = $('#fi-search-input').val().trim();

        if (!place && !inputVal) {
            showError('Please enter a business name.');
            return;
        }

        var postData = {
            action:     'fi_scan',
            nonce:      FI.nonce,
            place_name: place ? place.name : inputVal,
            place_id:   place ? place.place_id : '',
            fi_hp:      $('#fi-hp-field').val() || '',
            fi_ts:      Math.round((Date.now() - _pageLoadTime) / 1000),
        };

        showLoading();

        $.post(FI.ajax_url, postData, function (res) {
            // Clear any pending loading step timers
            var timers = $('#fi-report-area').data('fi-loading-timers') || [];
            timers.forEach(clearTimeout);

            if (res.success) {
                state.currentScan = res.data;
                renderReport(res.data);
            } else {
                var msg = res.data && res.data.message ? res.data.message : 'Scan failed. Please try again.';
                showError(msg);
            }
        }).fail(function () {
            showError('Network error. Please try again.');
        });
    }

    // =========================================================================
    // Report rendering
    // =========================================================================

    function renderReport(data) {
        var report  = data.report;
        var scan    = data.scan;
        var overall = report.overall_score || 0;

        var $area = $('#fi-report-area');
        $area.html('').show();

        // ── Header ──────────────────────────────────────────────────────────
        var $header = $('<div class="fi-report-header"></div>').html(
            '<div class="fi-report-header-inner">' +
            '<div class="fi-report-biz">' +
            '<h2>' + escHtml(scan.business_name) + '</h2>' +
            (scan.description ? '<p class="fi-biz-description">' + escHtml(scan.description) + '</p>' : '') +
            (scan.address ? '<p class="fi-biz-meta">' + escHtml(scan.address) + '</p>' : '') +
            (scan.phone   ? '<p class="fi-biz-meta">📞 <a href="tel:' + escHtml(scan.phone) + '">' + escHtml(scan.phone) + '</a></p>' : '') +
            (scan.website ? '<p class="fi-biz-meta">🌐 <a href="' + escHtml(scan.website) + '" target="_blank" rel="noopener">' + truncateUrl(scan.website) + '</a></p>' : '') +
            '</div>' +
            '<div class="fi-overall-score" style="background:' + scoreColor(overall) + '">' +
            '<div class="fi-score-num">' + overall + '</div>' +
            '<div class="fi-score-label">OVERALL SCORE</div>' +
            '</div>' +
            '</div>'
        );
        $area.append($header);

        // ── Photos ───────────────────────────────────────────────────────────
        if (scan.photos && scan.photos.length) {
            var $photos = $('<div class="fi-photos-grid"></div>');
            scan.photos.forEach(function (photo) {
                var ref = photo.photo_reference || '';
                if (!ref) return;
                var url;
                // New Places API returns resource paths like "places/ChIJ.../photos/Aaw..."
                // These must be proxied server-side — the new photo endpoint
                // blocks direct browser requests with CORS errors.
                if (ref.indexOf('places/') === 0 || ref.indexOf('/') !== -1) {
                    url = FI.ajax_url + '?action=fi_photo_proxy&ref=' + encodeURIComponent(ref) + '&nonce=' + FI.nonce;
                } else {
                    // Legacy photo_reference — keep working for any cached data
                    url = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photo_reference='
                        + encodeURIComponent(ref) + '&key=' + encodeURIComponent(FI.google_key || '');
                }
                $photos.append('<div class="fi-photo"><img src="' + url + '" alt="' + escHtml(scan.business_name) + ' photo" loading="lazy" onerror="this.closest(&quot;.fi-photo&quot;).style.display=&quot;none&quot;"></div>');
            });
            if ($photos.children().length) {
                $area.append($photos);
            }
        }

        // ── Tabs ─────────────────────────────────────────────────────────────
        var catLabels = {
            online_presence:      'Online Presence',
            customer_reviews:     'Customer Reviews',
            photos_media:         'Photos & Media',
            business_information: 'Business Info',
            competitive_position: 'Competition',
            website_performance:  'Website',
            local_seo:            'Local SEO',
        };

        var tabDefs = [];

        // PageSpeed tab first (if we have data) — it's the showpiece
        var psData = scan.pagespeed;
        var psCat  = report.categories && report.categories['pagespeed_insights'];
        if ( psData || psCat ) {
            tabDefs.push({ id: 'pagespeed', label: 'Page Speed', type: 'pagespeed' });
        }

        // Build one tab per standard category
        if (report.categories) {
            Object.keys(catLabels).forEach(function (key) {
                var cat = report.categories[key];
                if (!cat) return;
                var dotColor = cat.score >= 80 ? '#16a34a' : cat.score >= 60 ? '#d97706' : '#dc2626';
                var dot = '<span class="fi-tab-dot" style="background:' + dotColor + '"></span>';
                tabDefs.push({ id: 'cat-' + key, label: catLabels[key] + dot, type: 'cat', key: key, cat: cat });
            });
        }

        // Competitive context tab
        if (report.competitive_narrative) {
            tabDefs.push({ id: 'competitive', label: 'Context', type: 'narrative' });
        }

        // Priority actions tab
        if (report.priority_actions && report.priority_actions.length) {
            tabDefs.push({ id: 'priorities', label: 'Actions', type: 'priorities' });
        }

        if (tabDefs.length > 0) {
            var $tabsWrap  = $('<div class="fi-tabs-wrap"></div>');
            var $tabBar    = $('<div class="fi-report-tabs" role="tablist"></div>');
            var $tabPanels = $('<div class="fi-tab-panels"></div>');
            // Left scroll indicator elements (right side uses CSS ::before/::after)
            $tabsWrap.append('<span class="fi-tabs-left-fade" aria-hidden="true"></span>');
            $tabsWrap.append('<span class="fi-tabs-left-arrow" aria-hidden="true">‹</span>');
            $tabsWrap.append($tabBar);

            tabDefs.forEach(function (tab, i) {
                var isFirst = i === 0;

                // label may contain HTML (score dot span) — set via innerHTML
                var $btn = $('<button class="fi-report-tab' + (isFirst ? ' fi-tab-active' : '') + '" role="tab" data-tab="' + tab.id + '"></button>');
                $btn[0].innerHTML = tab.label;
                $tabBar.append($btn);

                var panelHtml = '';

                if (tab.type === 'pagespeed') {
                    panelHtml = buildPageSpeedPanel(psData, psCat);

                } else if (tab.type === 'cat' && tab.key === 'customer_reviews') {
                    panelHtml = buildReviewsPanel(tab.cat, scan.reviews_top || [], scan.reviews_low || []);

                } else if (tab.type === 'cat' && tab.key === 'competitive_position') {
                    panelHtml = buildCompetitionPanel(tab.cat, scan);

                } else if (tab.type === 'cat' && tab.key === 'business_information') {
                    panelHtml = buildBusinessInfoPanel(tab.cat, scan);

                } else if (tab.type === 'cat') {
                    var cat   = tab.cat;
                    var color = scoreColor(cat.score);
                    var recs  = (cat.recommendations || []).map(function (r) {
                        return '<li>→ ' + escHtml(r) + '</li>';
                    }).join('');
                    panelHtml =
                        '<div class="fi-category-card" style="border-left-color:' + color + '">' +
                        '<div class="fi-cat-header">' +
                        '<h3>' + catLabels[tab.key] + '</h3>' +
                        '<span class="fi-cat-score" style="color:' + color + ';border-color:' + color + '">' + cat.score + '<small>/100</small></span>' +
                        '</div>' +
                        '<p class="fi-cat-headline">' + escHtml(cat.headline || '') + '</p>' +
                        '<p class="fi-cat-analysis">' + escHtml(cat.analysis || '') + '</p>' +
                        (recs ? '<ul class="fi-recommendations">' + recs + '</ul>' : '') +
                        '</div>';

                } else if (tab.type === 'narrative') {
                    panelHtml =
                        '<div class="fi-section fi-competitive-context">' +
                        '<p>' + escHtml(report.competitive_narrative) + '</p>' +
                        '</div>';

                } else if (tab.type === 'priorities') {
                    var scanId = scan.id || 'scan';
                    var actionsHtml = '';
                    report.priority_actions.forEach(function (action, idx) {
                        var ic = action.impact === 'high' ? 'green' : (action.impact === 'medium' ? 'orange' : 'gray');
                        var ec = action.effort === 'low'  ? 'green' : (action.effort === 'medium' ? 'orange' : 'red');
                        var lsKey = 'fi_action_' + scanId + '_' + idx;
                        var checked = localStorage.getItem(lsKey) === '1';
                        actionsHtml +=
                            '<div class="fi-action-card' + (checked ? ' fi-action-done' : '') + '" data-ls-key="' + lsKey + '">' +
                            '<label class="fi-action-check-label">' +
                            '<input type="checkbox" class="fi-action-check"' + (checked ? ' checked' : '') + '>' +
                            '<span class="fi-action-check-box"></span>' +
                            '<h4>' + escHtml(action.title || '') + '</h4>' +
                            '</label>' +
                            '<p>' + escHtml(action.description || '') + '</p>' +
                            '<span class="fi-tag fi-tag--impact-' + ic + '">Impact: ' + ucFirst(action.impact || '') + '</span>' +
                            '<span class="fi-tag fi-tag--effort-' + ec + '">Effort: ' + ucFirst(action.effort || '') + '</span>' +
                            '</div>';
                    });
                    panelHtml = '<div class="fi-section fi-priority-actions">' +
                        '<p class="fi-actions-hint">Check off actions as you complete them. Progress is saved on this device.</p>' +
                        actionsHtml + '</div>';
                }

                $tabPanels.append(
                    '<div class="fi-tab-panel' + (isFirst ? ' fi-tab-active' : '') + '" ' +
                    'role="tabpanel" id="fi-panel-' + tab.id + '">' + panelHtml + '</div>'
                );
            });

            $area.append($tabsWrap).append($tabPanels);

            // ── Tab scroll indicator ─────────────────────────────────────────
            initTabScrollIndicator($tabBar);

            $tabBar.on('click', '.fi-report-tab', function () {
                var target = $(this).data('tab');
                $tabBar.find('.fi-report-tab').removeClass('fi-tab-active');
                $tabPanels.find('.fi-tab-panel').removeClass('fi-tab-active');
                $(this).addClass('fi-tab-active');
                $tabPanels.find('#fi-panel-' + target).addClass('fi-tab-active');

                // Scroll clicked tab fully into view within the tab bar
                // 52px offset accounts for the fade overlay padding on each side
                var el  = $tabBar[0];
                var btn = this;
                var btnLeft  = btn.offsetLeft;
                var btnRight = btnLeft + btn.offsetWidth;
                var barLeft  = el.scrollLeft;
                var barRight = barLeft + el.clientWidth;
                if (btnLeft < barLeft + 52) {
                    el.scrollTo({ left: btnLeft - 52, behavior: 'smooth' });
                } else if (btnRight > barRight - 52) {
                    el.scrollTo({ left: btnRight - el.clientWidth + 52, behavior: 'smooth' });
                }
            });

            // ── Priority action checkboxes ────────────────────────────────────
            $tabPanels.on('change', '.fi-action-check', function () {
                var $card = $(this).closest('.fi-action-card');
                var lsKey = $card.data('ls-key');
                var done  = this.checked;
                localStorage.setItem(lsKey, done ? '1' : '0');
                $card.toggleClass('fi-action-done', done);
            });
        }

        // ── Always-visible bottom section ────────────────────────────────────
        var $capture = $('<div class="fi-capture-section"></div>');

        // Lead capture form — premium only
        if (FI.premium_active) {
            var ff = FI.form_fields || {};

            // Helper: wrap a field in a floating label wrapper
            function floatField(inputHtml, label, required) {
                var req = required ? ' fi-field--required' : '';
                return '<div class="fi-field' + req + '">' + inputHtml + '<label>' + escHtml(label) + '</label></div>';
            }

            var formHtml = '<div class="fi-email-capture">';

            if (FI.form_headline) {
                formHtml += '<p class="fi-capture-headline">' + escHtml(FI.form_headline) + '</p>';
            }
            if (FI.form_subtext) {
                formHtml += '<p class="fi-capture-subtext">' + escHtml(FI.form_subtext) + '</p>';
            }

            formHtml += '<div class="fi-capture-fields">';

            // Name row
            var hasFirst = ff.firstname && ff.firstname.enabled;
            var hasLast  = ff.lastname  && ff.lastname.enabled;
            if (hasFirst || hasLast) {
                formHtml += '<div class="fi-capture-name-row">';
                if (hasFirst) formHtml += floatField('<input type="text" id="fi-field-firstname" class="fi-capture-input"' + (ff.firstname.required ? ' required' : '') + '>', 'First name', ff.firstname.required);
                if (hasLast)  formHtml += floatField('<input type="text" id="fi-field-lastname"  class="fi-capture-input"' + (ff.lastname.required  ? ' required' : '') + '>', 'Last name',  ff.lastname.required);
                formHtml += '</div>';
            }

            // Email — always required
            formHtml += floatField('<input type="email" id="fi-email-input" class="fi-capture-input" required>', 'Email address', true);

            // Phone
            if (ff.phone && ff.phone.enabled) {
                formHtml += floatField('<input type="tel" id="fi-field-phone" class="fi-capture-input"' + (ff.phone.required ? ' required' : '') + '>', 'Phone number', ff.phone.required);
            }

            // Business Role
            if (ff.role && ff.role.enabled) {
                var roleOpts = '<option value=""></option>' + ['Owner','Manager','Marketing','Other'].map(function(r){ return '<option value="' + r + '">' + r + '</option>'; }).join('');
                formHtml += floatField('<select id="fi-field-role" class="fi-capture-input fi-capture-select"' + (ff.role.required ? ' required' : '') + '>' + roleOpts + '</select>', 'Business role', ff.role.required);
            }

            // Employees
            if (ff.employees && ff.employees.enabled) {
                var empOpts = '<option value=""></option>' + ['Just me','2–5','6–15','16–50','51–200','200+'].map(function(e){ return '<option value="' + e + '">' + e + '</option>'; }).join('');
                formHtml += floatField('<select id="fi-field-employees" class="fi-capture-input fi-capture-select"' + (ff.employees.required ? ' required' : '') + '>' + empOpts + '</select>', 'No. of employees', ff.employees.required);
            }

            // Custom field
            if (ff.custom && ff.custom.enabled && ff.custom.label) {
                formHtml += floatField('<input type="text" id="fi-field-custom" class="fi-capture-input"' + (ff.custom.required ? ' required' : '') + '>', ff.custom.label, ff.custom.required);
            }

            formHtml += '</div>'; // .fi-capture-fields

            // Consent checkbox — shown only when enabled in settings
            if (FI.consent_enabled) {
                var consentText = FI.consent_text || '';
                // If a privacy policy URL is set, auto-link the phrase "Privacy Policy"
                if (FI.consent_privacy_url) {
                    consentText = consentText.replace(
                        /Privacy Policy/gi,
                        '<a href="' + escHtml(FI.consent_privacy_url) + '" target="_blank" rel="noopener noreferrer">$&</a>'
                    );
                } else {
                    consentText = escHtml(consentText);
                }
                formHtml += '<label class="fi-consent-label">'
                          + '<input type="checkbox" id="fi-consent-checkbox" class="fi-consent-checkbox">'
                          + '<span class="fi-consent-text">' + consentText + '</span>'
                          + '</label>';
            }

            formHtml += '<button id="fi-email-btn" class="fi-btn-primary fi-capture-btn">' + escHtml(FI.email_btn_text) + '</button>';
            formHtml += '<div id="fi-email-status"></div>';
            formHtml += '</div>'; // .fi-email-capture

            var $form = $(formHtml);
            $capture.append($form);

            // Float label on input/change
            $capture.on('input change', '.fi-capture-input', function () {
                var val = $(this).val();
                $(this).closest('.fi-field').toggleClass('is-filled', val !== '' && val !== null);
            });

            $capture.find('#fi-email-btn').on('click', function () { submitEmail(); });
            $capture.find('#fi-email-input').on('keydown', function (e) { if (e.key === 'Enter') submitEmail(); });
        }

        // Share row — always shown (free and premium)
        $capture.append(
            '<div class="fi-share-section">' +
            '<p class="fi-share-label">Share this report</p>' +
            '<div class="fi-share-row">' +
            '<input type="text" id="fi-share-url-input" placeholder="Generating link…" readonly>' +
            '<button id="fi-share-btn" class="fi-btn-secondary">Copy</button>' +
            '</div>' +
            '<span id="fi-share-status"></span>' +
            '<span id="fi-share-expiry"></span>' +
            '</div>'
        );

        $capture.find('#fi-share-btn').on('click', function () { copyShareLink(); });

        $area.append($capture);

        // CTA button — premium only
        if (FI.premium_active && FI.cta_enabled && FI.cta_url) {
            $area.append(
                '<div class="fi-cta-wrap">' +
                '<a href="' + escAttr(FI.cta_url) + '" target="_blank" rel="noopener" class="fi-btn-cta">' +
                escHtml(FI.cta_text) + '</a>' +
                '</div>'
            );
        }

        // Credit line — always shown for free; premium can hide via fi_hide_credit
        if (!FI.premium_active || !FI.hide_credit) {
            $area.append('<p class="fi-credit">Want <a href="https://fricking.website/f-insights" target="_blank" rel="noopener">F! Insights</a> in your wordpress website</p>');
        }

        // Re-enable scan button and scroll
        $('#fi-scan-btn').prop('disabled', false).text(FI.scan_btn_text || 'Scan Business');
        $('html, body').animate({ scrollTop: $area.offset().top - 40 }, 400);

        // Auto-generate and populate the share link — no click required
        autoPopulateShareLink();
    }

    // =========================================================================
    // Email capture
    // =========================================================================

    function submitEmail() {
        var email = $('#fi-email-input').val().trim();
        if (!email || !validateEmail(email)) {
            $('#fi-email-status').text('Valid email only, homie.').addClass('fi-status--error');
            return;
        }

        // Validate required optional fields
        var ff = FI.form_fields || {};
        var missing = false;
        if (ff.firstname && ff.firstname.enabled && ff.firstname.required && !$('#fi-field-firstname').val().trim()) missing = true;
        if (ff.lastname  && ff.lastname.enabled  && ff.lastname.required  && !$('#fi-field-lastname').val().trim())  missing = true;
        if (ff.phone     && ff.phone.enabled      && ff.phone.required     && !$('#fi-field-phone').val().trim())     missing = true;
        if (ff.role      && ff.role.enabled       && ff.role.required      && !$('#fi-field-role').val())             missing = true;
        if (ff.employees && ff.employees.enabled  && ff.employees.required && !$('#fi-field-employees').val())        missing = true;
        if (ff.custom    && ff.custom.enabled     && ff.custom.required    && !$('#fi-field-custom').val().trim())    missing = true;

        if (missing) {
            $('#fi-email-status').text('All required fields are...required.').addClass('fi-status--error');
            return;
        }

        // Consent gate — must be ticked when enabled
        if (FI.consent_enabled && !$('#fi-consent-checkbox').is(':checked')) {
            $('#fi-email-status').text('Consent is sexy. Tick the checkbox to keep going.').addClass('fi-status--error');
            return;
        }

        var scanId = state.currentScan && state.currentScan.scan && state.currentScan.scan.id;
        if (!scanId) {
            $('#fi-email-status').text('Damn. The analysis failed for this report. You can try scanning again, but remember it\'s also the definition of insanity. Go all Karen on the admin.').addClass('fi-status--error');
            return;
        }

        $('#fi-email-btn').prop('disabled', true).text('Sending…');
        $('#fi-email-status').text('').removeClass('fi-status--error fi-status--success');

        $.post(FI.ajax_url, {
            action:     'fi_email_report',
            nonce:      FI.nonce,
            email:      email,
            scan_id:    scanId,
            firstname:  $('#fi-field-firstname').val()  || '',
            lastname:   $('#fi-field-lastname').val()   || '',
            phone:      $('#fi-field-phone').val()      || '',
            role:       $('#fi-field-role').val()       || '',
            employees:  $('#fi-field-employees').val()  || '',
            custom:     $('#fi-field-custom').val()     || '',
        }, function (res) {
            if (res.success) {
                if (res.data && res.data.sent === false) {
                    // Email failed after retries but lead was captured — show a
                    // soft follow-up message rather than a hard error. The user's
                    // intent was recorded; the admin will follow up manually.
                    var fallbackMsg = (res.data.message) || 'Your report had a lit-tle accident. The details were saved and we will get in touch.';
                    $('.fi-email-capture').fadeOut(200, function () {
                        $(this).replaceWith(
                            '<div class="fi-capture-thankyou fi-capture-thankyou--followup">'
                            + '<p>' + escHtml(fallbackMsg) + '</p>'
                            + '</div>'
                        );
                    });
                } else {
                    // Replace form with thank-you message, showing the email it was sent to
                    var thankyou = FI.form_thankyou || 'Your report is on its way!';
                    var sentTo   = '<div class="fi-capture-thankyou-email">Sent to <strong>' + escHtml(email) + '</strong></div>';
                    $('.fi-email-capture').fadeOut(200, function () {
                        $(this).replaceWith(
                            '<div class="fi-capture-thankyou">'
                            + '<p>' + escHtml(thankyou) + '</p>'
                            + sentTo
                            + '</div>'
                        );
                    });
                }
            } else {
                $('#fi-email-status').text('Failed to send. Please try again.').addClass('fi-status--error');
                $('#fi-email-btn').prop('disabled', false).text(FI.email_btn_text);
            }
        });
    }

    // =========================================================================
    // Share link
    // =========================================================================

    // Called automatically at end of renderReport — fetches/creates the link
    // and populates the input field without requiring a user click.
    function autoPopulateShareLink() {
        var scanId = state.currentScan && state.currentScan.scan && state.currentScan.scan.id;
        if (!scanId) {
            // Report wasn't saved (AI failed) — share link can't be generated
            $('#fi-share-url-input').val('').attr('placeholder', 'Share link unavailable; AI analysis incomplete');
            return;
        }

        // Send the current page URL (minus any fi_report param) so the share link
        // points back to THIS page, regardless of what's configured in the admin slugs.
        var sourceUrl = window.location.href.split('?')[0];
        var qs = window.location.search;
        if (qs) {
            // Re-append any query params except fi_report
            var params = new URLSearchParams(qs);
            params.delete('fi_report');
            var remaining = params.toString();
            if (remaining) sourceUrl += '?' + remaining;
        }

        $.post(FI.ajax_url, {
            action:     'fi_create_share',
            nonce:      FI.nonce,
            scan_id:    scanId,
            source_url: sourceUrl,
        }, function (res) {
            if (!res.success) return;
            var url       = res.data.url;
            var expiresAt = res.data.expires_at || '';
            $('#fi-share-url-input').val(url);
            if (expiresAt) {
                $('#fi-share-expiry').text(formatExpiryDate(expiresAt));
            }
        });
    }

    // Copies whatever is in the input — called on button click only.
    function copyShareLink() {
        var url = $('#fi-share-url-input').val();
        if (!url) return;
        copyToClipboard(url);
        var $btn = $('#fi-share-btn');
        $btn.text('✓ Copied');
        setTimeout(function () { $btn.text('Copy'); }, 2000);
    }

    // Format a MySQL datetime string (UTC) as "Expires Wednesday, March 5"
    function formatExpiryDate(mysqlDate) {
        if (!mysqlDate) return '';
        // MySQL format: "2026-03-05 14:23:00" — parse as local by replacing space with T
        var d = new Date(mysqlDate.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var diff = Math.floor((d - Date.now()) / 86400000);
        var dayStr = diff === 0 ? 'today'
                   : diff === 1 ? 'tomorrow'
                   : d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        return 'Expires ' + dayStr;
    }

    // =========================================================================
    // Shared report loader
    // =========================================================================

    function loadSharedReport(token) {
        // PHP shortcode injects FI_sharedReport if present.
        // We pick it up here and render it with the same pipeline.
        if (window.FI_sharedReport) {
            state.currentScan = window.FI_sharedReport;
            renderReport(window.FI_sharedReport);
        }
    }

    // Exposed so the inline <script> in shortcode can call it directly
    window.FI_renderSharedReport = function(data) {
        state.currentScan = data;
        var $area = $('#fi-report-area');
        if ($area.length) {
            $area.show();
            renderReport(data);
        }
    };

    // =========================================================================
    // Tab scroll indicator
    // =========================================================================

    function initTabScrollIndicator($tabBar) {
        var el    = $tabBar[0];
        var $wrap = $tabBar.closest('.fi-tabs-wrap');
        if (!el) return;

        function update() {
            var overflowing = el.scrollWidth > el.clientWidth + 4;
            var atStart     = el.scrollLeft <= 8;
            var atEnd       = el.scrollLeft + el.clientWidth >= el.scrollWidth - 8;
            $wrap.toggleClass('fi-tabs-has-more-right', overflowing && !atEnd);
            $wrap.toggleClass('fi-tabs-has-more-left',  overflowing && !atStart);
        }

        update();
        $tabBar.on('scroll.tabind', update);
        $(window).on('resize.tabind', update);

        // Mouse drag to scroll (desktop)
        // Uses a moved flag to distinguish drag from click — prevents swallowing tab clicks.
        var dragStart  = null;
        var scrollStart = 0;
        var didDrag    = false;
        $tabBar.on('mousedown.tabdrag', function(e) {
            if (e.button !== 0) return; // left button only
            dragStart   = e.clientX;
            scrollStart = el.scrollLeft;
            didDrag     = false;
        });
        $(document).on('mousemove.tabdrag', function(e) {
            if (dragStart === null) return;
            var dist = Math.abs(e.clientX - dragStart);
            if (dist > 4) {
                didDrag = true;
                $tabBar.addClass('fi-tabs-dragging');
                e.preventDefault();
                el.scrollLeft = scrollStart - (e.clientX - dragStart);
            }
        });
        $(document).on('mouseup.tabdrag', function() {
            dragStart = null;
            // Small delay so the click event that follows mouseup sees didDrag=true
            setTimeout(function() {
                didDrag = false;
                $tabBar.removeClass('fi-tabs-dragging');
            }, 0);
        });
        // Suppress click on tab buttons if we just dragged
        $tabBar.on('click.tabdrag', '.fi-report-tab', function(e) {
            if (didDrag) e.stopImmediatePropagation();
        });
    }

    // =========================================================================
    // Customer Reviews panel
    // =========================================================================

    function buildReviewsPanel(cat, topReviews, lowReviews) {
        var color = scoreColor(cat.score);

        var html =
            '<div class="fi-category-card" style="border-left-color:' + color + '">' +
            '<div class="fi-cat-header">' +
            '<h3>Customer Reviews</h3>' +
            '<span class="fi-cat-score" style="color:' + color + ';border-color:' + color + '">' +
            cat.score + '<small>/100</small></span>' +
            '</div>' +
            '<p class="fi-cat-headline">' + escHtml(cat.headline || '') + '</p>' +
            '<p class="fi-cat-analysis">' + escHtml(cat.analysis || '') + '</p>';

        if (cat.sentiment_summary) {
            html += '<p class="fi-cat-sentiment">' + escHtml(cat.sentiment_summary) + '</p>';
        }

        // ── Review cards ─────────────────────────────────────────────────────
        var hasTop = topReviews.length > 0;
        var hasLow = lowReviews.length > 0;

        if (hasTop) {
            html += '<div class="fi-reviews-section">';
            html += '<h4 class="fi-reviews-heading fi-reviews-heading--good">⭐ Highest-rated feedback</h4>';
            topReviews.forEach(function (r) {
                html += buildReviewCard(r, 'good');
            });
            html += '</div>';
        } else {
            html += '<p class="fi-reviews-empty">No highly-rated reviews were available in the sample Google returned; this doesn\'t mean they don\'t exist, just that Google\'s algorithm didn\'t surface them here.</p>';
        }

        if (hasLow) {
            html += '<div class="fi-reviews-section">';
            html += '<h4 class="fi-reviews-heading fi-reviews-heading--warn">⚠️ Lowest-rated feedback</h4>';
            lowReviews.forEach(function (r) {
                html += buildReviewCard(r, 'warn');
            });
            html += '</div>';
        } else {
            html += '<p class="fi-reviews-empty fi-reviews-empty--good">✓ No low-rated reviews in this sample; either customers are consistently happy, or the review volume is still small.</p>';
        }

        // Recommendations
        var recs = (cat.recommendations || []).map(function (r) {
            return '<li>→ ' + escHtml(r) + '</li>';
        }).join('');
        if (recs) {
            html += '<ul class="fi-recommendations">' + recs + '</ul>';
        }

        html += '</div>';
        return html;
    }

    function buildReviewCard(review, tone) {
        var stars = '';
        var rating = parseInt(review.rating, 10) || 0;
        for (var i = 1; i <= 5; i++) {
            stars += '<span class="fi-star' + (i <= rating ? ' fi-star--on' : '') + '">★</span>';
        }
        return (
            '<div class="fi-review-card fi-review-card--' + tone + '">' +
            '<div class="fi-review-meta">' +
            '<span class="fi-review-stars">' + stars + '</span>' +
            '<span class="fi-review-author">' + escHtml(review.author || 'Anonymous') + '</span>' +
            '<span class="fi-review-time">' + escHtml(review.time_ago || '') + '</span>' +
            '</div>' +
            '<p class="fi-review-text">' + escHtml(review.text || '') + '</p>' +
            '</div>'
        );
    }

    // =========================================================================
    // Competition panel
    // =========================================================================

    function buildCompetitionPanel(cat, scan) {
        var color = scoreColor(cat.score);

        var html =
            '<div class="fi-category-card" style="border-left-color:' + color + '">' +
            '<div class="fi-cat-header">' +
            '<h3>Competition</h3>' +
            '<span class="fi-cat-score" style="color:' + color + ';border-color:' + color + '">' +
            cat.score + '<small>/100</small></span>' +
            '</div>' +
            '<p class="fi-cat-headline">' + escHtml(cat.headline || '') + '</p>' +
            '<p class="fi-cat-analysis">' + escHtml(cat.analysis || '') + '</p>';

        // Category context line — what type was searched
        if (cat.category_context) {
            html += '<p class="fi-comp-context">' + escHtml(cat.category_context) + '</p>';
        }

        // Vague match warning
        if (scan.vague_match) {
            html +=
                '<div class="fi-alert fi-alert--warn">' +
                '<strong>⚠️ Category optimization opportunity</strong>' +
                '<p>The competitors shown were found using the category <em>' + escHtml(scan.search_type || 'establishment') + '</em>: ' +
                'which is fairly generic. If this business is a ' + escHtml(scan.category || 'business') + ', ' +
                'setting a more specific primary category in Google Business Profile would pull in more relevant competitors ' +
                'and improve how this business appears in category-specific searches.</p>' +
                '</div>';
        }

        var recs = (cat.recommendations || []).map(function (r) {
            return '<li>→ ' + escHtml(r) + '</li>';
        }).join('');
        if (recs) {
            html += '<ul class="fi-recommendations">' + recs + '</ul>';
        }

        html += '</div>';
        return html;
    }

    // =========================================================================
    // Business Info panel — hours of operation with contextual commentary
    // =========================================================================

    function buildBusinessInfoPanel(cat, scan) {
        var color = scoreColor(cat.score);
        var recs  = (cat.recommendations || []).map(function (r) {
            return '<li>→ ' + escHtml(r) + '</li>';
        }).join('');

        var html =
            '<div class="fi-category-card" style="border-left-color:' + color + '">' +
            '<div class="fi-cat-header">' +
            '<h3>Business Info</h3>' +
            '<span class="fi-cat-score" style="color:' + color + ';border-color:' + color + '">' + cat.score + '<small>/100</small></span>' +
            '</div>' +
            '<p class="fi-cat-headline">' + escHtml(cat.headline || '') + '</p>' +
            '<p class="fi-cat-analysis">' + escHtml(cat.analysis || '') + '</p>' +
            (recs ? '<ul class="fi-recommendations">' + recs + '</ul>' : '') +
            '</div>';

        // ── Hours of operation ────────────────────────────────────────────────
        var hours = scan.hours || [];
        if (hours.length) {
            html += '<div class="fi-hours-section">';
            html += '<h4 class="fi-hours-title">Hours of Operation</h4>';
            html += '<ul class="fi-hours-list">';
            hours.forEach(function (line) {
                // Google format: "Monday: 9:00 AM – 5:00 PM" or "Monday: Closed"
                var parts = line.split(': ');
                var day   = parts[0] || '';
                var time  = parts.slice(1).join(': ') || '';
                html += '<li class="fi-hours-row">' +
                    '<span class="fi-hours-day">' + escHtml(day) + '</span>' +
                    '<span class="fi-hours-time' + (time === 'Closed' ? ' fi-hours-closed' : '') + '">' + escHtml(time) + '</span>' +
                    '</li>';
            });
            html += '</ul>';

            // ── Contextual commentary ─────────────────────────────────────────
            html += '<div class="fi-hours-insights">';
            html += buildHoursInsights(hours);
            html += '</div>';

            html += '</div>'; // .fi-hours-section
        }

        return html;
    }

    // Parse hours strings and generate plain-language commentary about
    // peak times, missed commuter windows, holiday considerations, etc.
    function buildHoursInsights(hoursLines) {
        // Parse each line into { day, opens, closes, closed }
        var parsed = [];
        var dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

        hoursLines.forEach(function (line) {
            var match = line.match(/^(\w+):\s*(.+)$/);
            if (!match) return;
            var day  = match[1];
            var time = match[2].trim();
            if (time === 'Closed') {
                parsed.push({ day: day, closed: true });
                return;
            }
            // "9:00 AM – 9:00 PM" or "Open 24 hours"
            if (time === 'Open 24 hours') {
                parsed.push({ day: day, closed: false, opens: 0, closes: 1440, all_day: true });
                return;
            }
            var rangeMatch = time.match(/(\d+:\d+\s*[AP]M)\s*[–\-]\s*(\d+:\d+\s*[AP]M)/i);
            if (rangeMatch) {
                parsed.push({ day: day, closed: false, opens: parseTime(rangeMatch[1]), closes: parseTime(rangeMatch[2]) });
            }
        });

        var insights = [];

        // Weekend availability
        var sat = parsed.find(function(p){ return p.day === 'Saturday'; });
        var sun = parsed.find(function(p){ return p.day === 'Sunday'; });
        if (sat && sat.closed && sun && sun.closed) {
            insights.push('⚠️ <strong>Closed weekends</strong>: this cuts off a significant portion of foot traffic. Weekend hours, even limited, can meaningfully increase visibility and sales. Unless you are in a shady part of the neighborhood.');
        } else if (sat && !sat.closed && sun && sun.closed) {
            insights.push('📅 Open Saturdays but closed Sundays. Sunday can be a high-traffic day for local businesses; consider even limited hours. Unless you-know-who-is-your-demographic.');
        }

        // Evening coverage — does it catch the after-work crowd?
        var weekdays = parsed.filter(function(p) {
            return ['Monday','Tuesday','Wednesday','Thursday','Friday'].includes(p.day) && !p.closed;
        });
        var lateEnough = weekdays.filter(function(p){ return p.closes >= 18 * 60; }); // 6 PM+
        if (weekdays.length && lateEnough.length === 0) {
            insights.push('🕔 <strong>No evening hours on weekdays</strong>: closing before 6 PM means missing the after-work commuter window (5–7 PM), one of the highest-traffic periods for local businesses. Have you explored ways to make up for this opportunity?');
        } else if (weekdays.length && lateEnough.length < weekdays.length) {
            insights.push('🕔 Some weekdays close before 6 PM. Extending hours on even one or two days to capture the 5–7 PM commuter window could increase weekday walk-ins, if applicable.');
        }

        // Early morning — breakfast/commute opportunity
        var earlyDays = weekdays.filter(function(p){ return p.opens <= 8 * 60; }); // 8 AM or earlier
        if (earlyDays.length) {
            insights.push('☕ Early opening on some days captures the morning commute window. Make sure your Google profile lists breakfast or morning offerings if applicable.');
        }

        // Federal holidays note — static, since we don't know live holiday hours
        var now      = new Date();
        var month    = now.getMonth() + 1; // 1-12
        var day      = now.getDate();
        var upcoming = null;
        var holidays = [
            { month: 1,  day: 1,  name: "New Year's Day" },
            { month: 1,  day: 20, name: 'MLK Day' },
            { month: 2,  day: 17, name: "Presidents' Day" },
            { month: 5,  day: 26, name: 'Memorial Day' },
            { month: 7,  day: 4,  name: 'July 4th' },
            { month: 9,  day: 1,  name: 'Labor Day' },
            { month: 11, day: 11, name: "Veterans Day" },
            { month: 11, day: 27, name: 'Thanksgiving' },
            { month: 12, day: 25, name: 'Christmas' },
        ];
        for (var h = 0; h < holidays.length; h++) {
            var hol  = holidays[h];
            var diff = (hol.month - month) * 30 + (hol.day - day);
            if (diff >= 0 && diff <= 30) { upcoming = hol; break; }
        }
        if (upcoming) {
            insights.push('🗓️ <strong>' + upcoming.name + '</strong> is within the next 30 days. Make sure your Google profile reflects any special holiday hours; outdated hours are a top reason customers leave negative reviews.');
        }

        if (!insights.length) {
            insights.push('✅ Hours look solid. Make sure your Google Business Profile hours stay up to date; Google may prompt customers to confirm your hours, and stale listings hurt trust.');
        }

        var out = '<h5 class="fi-hours-insights-title">What these hours mean for your business</h5>';
        insights.forEach(function (ins) {
            out += '<p class="fi-hours-insight">' + ins + '</p>';
        });
        return out;
    }

    // Parse "9:00 AM" or "12:30 PM" → minutes since midnight
    function parseTime(str) {
        var m = str.trim().match(/(\d+):(\d+)\s*([AP]M)/i);
        if (!m) return 0;
        var h = parseInt(m[1], 10);
        var min = parseInt(m[2], 10);
        var ampm = m[3].toUpperCase();
        if (ampm === 'AM' && h === 12) h = 0;
        if (ampm === 'PM' && h !== 12) h += 12;
        return h * 60 + min;
    }

    // =========================================================================
    // PageSpeed panel builder
    // =========================================================================

    // CWV thresholds mirror Google's own PageSpeed Insights definitions.
    // Each metric has: label, unit, thresholds (good/warn), and a
    // plain-English description so non-technical users understand the value.
    var cwvMeta = [
        {
            key: 'fcp', label: 'First Contentful Paint',
            good: 1.8, warn: 3.0, unit: 's',
            describe: function(v, cls) {
                var s = parseFloat(v);
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Fast. Text and images appear quickly for visitors.';
                if (cls === 'fi-cwv-warn') return 'Moderate. Visitors see a blank screen for ' + v + ' before content loads.';
                return 'Slow; visitors wait ' + v + ' before anything appears. Most leave after 3s. Slowpoke is nicest way I can put it.';
            },
            cls: function (v) {
                var s = parseFloat(v);
                return isNaN(s) ? '' : s <= 1.8 ? 'fi-cwv-good' : s <= 3.0 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
        {
            key: 'lcp', label: 'Largest Contentful Paint',
            good: 2.5, warn: 4.0, unit: 's',
            describe: function(v, cls) {
                var s = parseFloat(v);
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Fast. Main hero image or headline loads quickly.';
                if (cls === 'fi-cwv-warn') return 'Slow. Main content takes ' + v + ' to appear. Google recommends under 2.5s.';
                return 'Very slow; the main image/headline takes ' + v + ' to load. This is a top Google ranking factor.';
            },
            cls: function (v) {
                var s = parseFloat(v);
                return isNaN(s) ? '' : s <= 2.5 ? 'fi-cwv-good' : s <= 4.0 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
        {
            key: 'tbt', label: 'Total Blocking Time',
            good: 200, warn: 600, unit: 'ms',
            describe: function(v, cls) {
                var s = parseFloat(v.replace(/,/g, ''));
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Good. The page stays responsive while loading.';
                if (cls === 'fi-cwv-warn') return 'Fair. The browser is blocked for ' + v + ', making clicks feel sluggish.';
                return 'Poor; the browser is frozen for ' + v + '. Buttons and links won\'t respond during this time.';
            },
            cls: function (v) {
                var s = parseFloat(v.replace(/,/g, ''));
                return isNaN(s) ? '' : s <= 200 ? 'fi-cwv-good' : s <= 600 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
        {
            key: 'cls', label: 'Layout Shift (CLS)',
            good: 0.1, warn: 0.25, unit: '',
            describe: function(v, cls) {
                var s = parseFloat(v);
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Stable. Content doesn\'t jump around while the page loads.';
                if (cls === 'fi-cwv-warn') return 'Meh. Elements shift as the page loads, which can cause accidental taps.';
                return 'Ah fack! Content jumps significantly while loading. Users may tap the wrong thing.';
            },
            cls: function (v) {
                var s = parseFloat(v);
                return isNaN(s) ? '' : s <= 0.1 ? 'fi-cwv-good' : s <= 0.25 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
        {
            key: 'si', label: 'Speed Index',
            good: 3.4, warn: 5.8, unit: 's',
            describe: function(v, cls) {
                var s = parseFloat(v);
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Fast. The page fills in quickly and feels responsive.';
                if (cls === 'fi-cwv-warn') return 'Moderate. The page loads gradually over ' + v + '. Some visitors may leave early.';
                return 'Boooooo! The page loads in pieces over ' + v + ', giving a poor first impression. Do you even parse, bro?';
            },
            cls: function (v) {
                var s = parseFloat(v);
                return isNaN(s) ? '' : s <= 3.4 ? 'fi-cwv-good' : s <= 5.8 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
        {
            key: 'tti', label: 'Time to Interactive',
            good: 3.8, warn: 7.3, unit: 's',
            describe: function(v, cls) {
                var s = parseFloat(v);
                if (isNaN(s)) return '';
                if (cls === 'fi-cwv-good') return 'Fast. Buttons and menus work almost immediately.';
                if (cls === 'fi-cwv-warn') return 'Slow. Visitors wait ' + v + ' before they can tap or click anything.';
                return 'Very slow, and not in a good way! The page looks loaded but nothing works for ' + v + '. High abandonment risk. They ain\'t calling back, boo.';
            },
            cls: function (v) {
                var s = parseFloat(v);
                return isNaN(s) ? '' : s <= 3.8 ? 'fi-cwv-good' : s <= 7.3 ? 'fi-cwv-warn' : 'fi-cwv-poor';
            }
        },
    ];

    function buildPageSpeedPanel(psData, psCat) {
        var html = '<div class="fi-ps-panel">';

        var metricOrder  = ['performance', 'accessibility', 'best_practices', 'seo'];
        var metricLabels = {
            performance:    'Performance',
            accessibility:  'Accessibility',
            best_practices: 'Best Practices',
            seo:            'SEO',
        };
        var strategies     = ['mobile', 'desktop'];
        var strategyLabels = { mobile: '📱 Mobile', desktop: '🖥 Desktop' };

        if (psData) {
            html += '<div class="fi-ps-strategies">';

            strategies.forEach(function (s) {
                var d = psData[s];
                if (!d) return;

                html += '<div class="fi-ps-strategy">';
                html += '<h4 class="fi-ps-strategy-label">' + strategyLabels[s] + '</h4>';

                // ── 4 gauges ──────────────────────────────────────────────
                html += '<div class="fi-ps-gauges">';
                metricOrder.forEach(function (metric) {
                    var score = d[metric];
                    if (score === null || score === undefined) return;
                    var color = psScoreColor(score);
                    var r = 28, circ = 2 * Math.PI * r;
                    var dash = (score / 100) * circ;
                    html +=
                        '<div class="fi-ps-gauge">' +
                        '<svg width="72" height="72" viewBox="0 0 72 72">' +
                        '<circle cx="36" cy="36" r="' + r + '" fill="none" stroke="#e5e7eb" stroke-width="5"/>' +
                        '<circle cx="36" cy="36" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="5"' +
                        ' stroke-dasharray="' + dash.toFixed(1) + ' ' + circ.toFixed(1) + '"' +
                        ' stroke-linecap="round" transform="rotate(-90 36 36)"/>' +
                        '<text x="36" y="40" text-anchor="middle" font-size="16" font-weight="700" fill="' + color + '">' + score + '</text>' +
                        '</svg>' +
                        '<span class="fi-ps-gauge-label">' + escHtml(metricLabels[metric]) + '</span>' +
                        '</div>';
                });
                html += '</div>'; // .fi-ps-gauges

                // ── CWV table — color-coded rows with interpretation line ──
                var cwv     = d.cwv || {};
                var cwvHtml = '';
                cwvMeta.forEach(function (item) {
                    var val = cwv[item.key];
                    if (!val) return;
                    var cls  = item.cls(String(val));
                    var desc = item.describe ? item.describe(String(val), cls) : '';
                    // Map CWV class to border color token
                    var borderColor = cls === 'fi-cwv-good' ? '#0cce6b' : cls === 'fi-cwv-warn' ? '#ffa400' : '#ff4e42';
                    cwvHtml +=
                        '<div class="fi-cwv-row fi-cwv-row--detailed" style="border-left-color:' + borderColor + '">' +
                        '<div class="fi-cwv-main">' +
                        '<span class="fi-cwv-label">' + item.label + '</span>' +
                        (desc ? '<span class="fi-cwv-desc">' + escHtml(desc) + '</span>' : '') +
                        '</div>' +
                        '<span class="fi-cwv-val ' + cls + '">' + escHtml(val) + '</span>' +
                        '</div>';
                });
                if (cwvHtml) {
                    html += '<div class="fi-cwv-table">' + cwvHtml + '</div>';
                }

                html += '</div>'; // .fi-ps-strategy
            });

            html += '</div>'; // .fi-ps-strategies
        }

        // ── Claude's human interpretation ─────────────────────────────────
        // Only show this block if we have real AI analysis (not a fallback stub).
        // The raw gauges and CWV table above are always useful on their own.
        var hasRealAnalysis = psCat && psCat.analysis &&
            psCat.analysis.indexOf('incomplete') === -1 &&
            psCat.analysis.indexOf('did not complete') === -1 &&
            psCat.analysis.indexOf('unavailable') === -1;

        if (hasRealAnalysis) {
            html += '<div class="fi-ps-interpretation">';
            if (psCat.headline) {
                html += '<p class="fi-cat-headline">' + escHtml(psCat.headline) + '</p>';
            }
            if (psCat.analysis) {
                html += '<p class="fi-cat-analysis">' + escHtml(psCat.analysis) + '</p>';
            }
            var doing     = psCat.doing_well        || [];
            var improving = psCat.needs_improvement  || [];
            if (doing.length || improving.length) {
                html += '<div class="fi-ps-split">';
                if (doing.length) {
                    html += '<div class="fi-ps-col fi-ps-col--good"><h5>✅ Keep doing this</h5><ul>';
                    doing.forEach(function (item) { html += '<li>' + escHtml(item) + '</li>'; });
                    html += '</ul></div>';
                }
                if (improving.length) {
                    html += '<div class="fi-ps-col fi-ps-col--fix"><h5>🔧 Fix these first</h5><ul>';
                    improving.forEach(function (item) { html += '<li>' + escHtml(item) + '</li>'; });
                    html += '</ul></div>';
                }
                html += '</div>';
            }
            var recs = psCat.recommendations || [];
            if (recs.length) {
                html += '<ul class="fi-recommendations" style="margin-top:12px;">';
                recs.forEach(function (r) { html += '<li>→ ' + escHtml(r) + '</li>'; });
                html += '</ul>';
            }
            html += '</div>'; // .fi-ps-interpretation
        }

        if (!psData && !psCat) {
            html += '<p class="fi-cat-analysis" style="padding:20px 0;">No website URL found for this business; PageSpeed data unavailable. Don\'t fret. Their website might just go to another school that\s why I don\t know it.</p>';
        }

        html += '</div>'; // .fi-ps-panel
        return html;
    }

    function psScoreColor(score) {
        if (score >= 90) return '#0cce6b';
        if (score >= 50) return '#ffa400';
        return '#ff4e42';
    }



    function showLoading() {
        var $area = $('#fi-report-area');

        // Steps timed to reflect the actual pipeline
        var steps = [
            'Finding business on Google Maps…',
            'Fetching business profile…',
            'Loading photos and hours…',
            'Pulling customer reviews…',
            'Scanning competitor landscape…',
            'Checking website health…',
            'Running PageSpeed analysis…',
            'Running mobile performance test…',
            'Running desktop performance test…',
            'Sending data to AI for analysis…',
            'Watering my plants…',
            'Scoring online presence…',
            'Scoring customer reviews…',
            'Scoring photos and media…',
            'Scoring local SEO…',
            'Scoring competitive position…',
            'Scoring website performance…',
            'Generating priority actions…',
            'Building your report…',
        ];

        $area.html(
            '<div class="fi-loading">' +
            '<div class="fi-skeleton-header">' +
            '<div class="fi-skeleton-biz">' +
            '<div class="fi-skeleton-line fi-skeleton-line--title"></div>' +
            '<div class="fi-skeleton-line fi-skeleton-line--sub"></div>' +
            '<div class="fi-skeleton-line fi-skeleton-line--meta"></div>' +
            '</div>' +
            '<div class="fi-skeleton-score"></div>' +
            '</div>' +
            '<div class="fi-loading-inner">' +
            '<p class="fi-loading-eta">Depending on how much data the scanner finds, it\'s done under 90 seconds.</p>' +
            '<div class="fi-loading-steps-wrap">' +
            '<ul class="fi-loading-steps" id="fi-loading-steps"></ul>' +
            '</div>' +
            '</div>' +
            '</div>'
        ).show();

        var $list  = $('#fi-loading-steps');
        var timers = [];

        // Add all steps as pending immediately
        steps.forEach(function (text, i) {
            $list.append(
                '<li class="fi-step fi-step--pending" id="fi-step-' + i + '">' +
                '<span class="fi-step-icon"></span>' +
                '<span class="fi-step-text">' + escHtml(text) + '</span>' +
                '</li>'
            );
        });

        // Average ~5s per step across 90s, with early steps faster
        var delays = steps.map(function (_, i) {
            return i < 4  ? 1200 :
                   i < 9  ? 4500 :
                   i < 14 ? 5500 : 6000;
        });

        function activateStep(i) {
            if (i >= steps.length) return;
            var $step = $('#fi-step-' + i);

            // Mark previous as done
            if (i > 0) {
                $('#fi-step-' + (i - 1))
                    .removeClass('fi-step--active')
                    .addClass('fi-step--done');
            }

            $step.removeClass('fi-step--pending').addClass('fi-step--active');

            // Scroll so active step is centered in the viewport window
            var $wrap = $step.closest('.fi-loading-steps-wrap');
            var itemH = $step.outerHeight(true) || 36;
            // Show 3 items: scroll so active is the middle one
            var scrollTarget = Math.max(0, (i - 1) * itemH);
            $wrap.stop(true).animate({ scrollTop: scrollTarget }, 300);

            var t = setTimeout(function () { activateStep(i + 1); }, delays[i]);
            timers.push(t);
        }

        activateStep(0);

        // Store cleanup fn on the area so runScan can clear timers when done
        $area.data('fi-loading-timers', timers);

        $('#fi-scan-btn').prop('disabled', true).text('Scanning…');
    }

    function showError(msg) {
        $('#fi-report-area').html(
            '<div class="fi-error"><p>⚠️ ' + escHtml(msg) + '</p></div>'
        ).show();
        $('#fi-scan-btn').prop('disabled', false).text(FI.scan_btn_text || 'Scan Business');
    }

    function scoreColor(score) {
        if (score >= 80) return '#16a34a';
        if (score >= 60) return '#d97706';
        return '#dc2626';
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escAttr(str) { return escHtml(str); }

    function ucFirst(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    function validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    /**
     * Truncate a URL to just the scheme + hostname (no path, query, or fragment).
     * The full URL is still used as the href — this is display-only.
     * e.g. https://okigeorgetown.com/?utm_source=google → okigeorgetown.com
     */
    function truncateUrl(url) {
        if (!url) return '';
        try {
            var parsed = new URL(url);
            // Remove www. prefix for cleaner display
            return parsed.hostname.replace(/^www\./, '');
        } catch (e) {
            // Fallback: strip everything after the first slash after the domain
            return url.replace(/^https?:\/\//, '').replace(/^www\./, '').split('/')[0].split('?')[0];
        }
    }

    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
        } else {
            var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
            $t[0].select();
            document.execCommand('copy');
            $t.remove();
        }
    }

})(jQuery);