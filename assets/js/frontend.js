/**
 * Frontend JavaScript for F Insights
 */

(function($) {
    'use strict';

    let searchTimeout     = null;
    let selectedPlaceId   = null;
    let currentReport     = null;
    let currentShareToken = null; // server-issued token for the current report
    let scanInProgress    = false;         // prevents double-submit
    let checklistTimers   = [];            // stored so they can be cancelled on early completion

    // ── Google Maps lazy loader ───────────────────────────────────────────────
    // The Maps SDK is no longer enqueued on page load; instead it is injected
    // on-demand the first time the competitor map panel is about to render.
    // This avoids loading ~120 KB of SDK on every page view when many visitors
    // never open the competition tab.
    var _mapsLoaded    = typeof google !== 'undefined' && typeof google.maps !== 'undefined';
    var _mapsLoading   = false;
    var _mapsCallbacks = [];

    function loadMapsSdk( callback ) {
        if ( _mapsLoaded ) {
            callback();
            return;
        }
        _mapsCallbacks.push( callback );
        if ( _mapsLoading ) {
            return; // already injecting — callback will fire when ready
        }
        if ( ! ( fInsights && fInsights.mapsSdkUrl ) ) {
            return; // no API key configured — callers fall back to OSM
        }
        _mapsLoading = true;
        var script  = document.createElement( 'script' );
        script.src  = fInsights.mapsSdkUrl;
        script.async = true;
        script.onload = function() {
            // The async loader may still be initialising after script.onload.
            // Poll until google.maps is fully available.
            var check = function() {
                if ( typeof google !== 'undefined' && typeof google.maps !== 'undefined' ) {
                    _mapsLoaded  = true;
                    _mapsLoading = false;
                    var cbs = _mapsCallbacks.splice( 0 );
                    cbs.forEach( function( cb ) { cb(); } );
                } else {
                    setTimeout( check, 50 );
                }
            };
            check();
        };
        script.onerror = function() {
            _mapsLoading = false;
            _mapsCallbacks.splice( 0 ); // clear — callers will receive no callback
        };
        document.head.appendChild( script );
    }

    // Geolocation state — populated as soon as the user grants permission.
    let userLat = null;
    let userLng = null;
    let geoRequested = false; // track whether we've already shown the prompt

    $(document).ready(function() {
        // Shared report: if the shortcode rendered a data-shared-report attribute,
        // load and display it immediately without requiring a new scan.
        var $sharedEl = $('#fi-results[data-shared-report]');
        if ($sharedEl.length) {
            try {
                var sharedReport = JSON.parse($sharedEl.attr('data-shared-report'));
                displayResults(sharedReport, null);
            } catch(e) {
                $sharedEl.html('<p style="color:#c00;padding:20px;">Unable to load shared report.</p>').show();
            }
            return;
        }
        initializeScanner();
        requestGeolocation();
    });

    // ---------------------------------------------------------------------------
    // Geolocation — request on page load with a friendly inline explanation.
    // We show our own prompt first so the user understands *why* before the
    // browser's permission dialog appears. If they decline (either our prompt or
    // the browser's), searches still work — just without location bias.
    // ---------------------------------------------------------------------------
    function requestGeolocation() {
        if (!navigator.geolocation || geoRequested) return;
        geoRequested = true;

        // Insert a subtle, dismissible banner above the search box.
        const $banner = $(
            '<div class="fi-geo-banner" role="status">' +
            '  <span class="fi-geo-icon">📍</span>' +
            '  <span class="fi-geo-text">Allow location access so we can show you nearby businesses first.</span>' +
            '  <button type="button" class="fi-geo-allow">Allow</button>' +
            '  <button type="button" class="fi-geo-dismiss" aria-label="Dismiss">✕</button>' +
            '</div>'
        );

        $('.fi-search-box').before($banner);

        $banner.find('.fi-geo-allow').on('click', function() {
            $banner.remove();
            doGeoLookup();
        });

        $banner.find('.fi-geo-dismiss').on('click', function() {
            $banner.remove();
        });
    }

    function doGeoLookup() {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
            },
            function() {
                // User denied or geolocation unavailable — no-op, searches work fine.
                userLat = null;
                userLng = null;
                
                // Show helpful tip about enabling location
                const $tip = $(
                    '<div class="fi-geo-tip" role="status">' +
                    '💡 <strong>Tip:</strong> Enable location for more accurate nearby results. ' +
                    'You can still search by including your city name.' +
                    '</div>'
                );
                $('.fi-search-box').before($tip);
                setTimeout(function() { 
                    $tip.fadeOut(400, function() { $(this).remove(); }); 
                }, 8000);
            },
            { timeout: 8000, maximumAge: 300000 } // 5-min cache on device
        );
    }

    // ---------------------------------------------------------------------------
    // Inline error helper — replaces all native alert() calls.
    // ---------------------------------------------------------------------------
    function showInlineError(message) {
        const $target = $('#fi-business-suggestions');
        const $err = $(
            '<div class="fi-inline-error" role="alert">' +
            '  <span class="fi-error-icon">⚠</span> ' +
            escapeHtml(message) +
            '</div>'
        );
        $target.html($err);
    }

    // ---------------------------------------------------------------------------
    // Scanner initialisation
    // ---------------------------------------------------------------------------
    function initializeScanner() {
        const $searchInput = $('#fi-business-search');
        const $scanButton  = $('#fi-scan-button');
        const $suggestions = $('#fi-business-suggestions');

        // Search as user types
        $searchInput.on('input', function() {
            const query = $(this).val().trim();

            clearTimeout(searchTimeout);
            $suggestions.find('.fi-inline-error').remove();

            if (query.length < 3) {
                $suggestions.empty();
                return;
            }

            searchTimeout = setTimeout(function() {
                searchBusinesses(query);
            }, 500);
        });

        // Scan button click — guard against double-submit while a scan is running
        $scanButton.on('click', function() {
            if (scanInProgress) return;
            if (selectedPlaceId) {
                scanBusiness(selectedPlaceId);
            } else {
                const query = $searchInput.val().trim();
                if (query) {
                    searchAndScan(query);
                }
            }
        });

        // Enter key to search
        $searchInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $scanButton.click();
            }
        });
    }

    function searchBusinesses(query) {
        $.ajax({
            url:  fInsights.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fi_search_business',
                nonce:  fInsights.nonce,
                query:  query,
                lat:    userLat || '',
                lng:    userLng || '',
            },
            success: function(response) {
                if (response.success) {
                    displaySuggestions(response.data.businesses);
                } else {
                    showInlineError(response.data.message || fInsights.strings.error);
                }
            },
            error: function() {
                showInlineError(fInsights.strings.error);
            }
        });
    }

    function displaySuggestions(businesses) {
        const $suggestions = $('#fi-business-suggestions');

        if (!businesses || businesses.length === 0) {
            showInlineError(
                'No businesses found nearby. Try:\n' +
                '• Adding your city name (e.g. "Joe\'s Pizza Arlington VA")\n' +
                '• Using a more specific business name\n' +
                '• Allowing location access for better results'
            );
            return;
        }

        let html = '<div class="fi-suggestion-list">';

        businesses.forEach(function(business) {
            html += '<div class="fi-suggestion-item" data-place-id="' + escapeHtml(business.place_id) + '">';
            html += '<div class="fi-suggestion-name">' + escapeHtml(business.name) + '</div>';
            html += '<div class="fi-suggestion-address">' + escapeHtml(business.address) + '</div>';

            if (business.rating > 0) {
                html += '<div class="fi-suggestion-rating">';
                html += '⭐ ' + business.rating + ' (' + business.user_ratings_total + ' reviews)';
                html += '</div>';
            }
            
            if (business.distance_miles !== null && business.distance_miles !== undefined) {
                html += '<div class="fi-suggestion-distance">';
                html += '📍 ' + parseFloat(business.distance_miles).toFixed(1) + ' miles away';
                html += '</div>';
            }

            html += '</div>';
        });

        html += '</div>';
        $suggestions.html(html);

        // Click handler for suggestions
        $('.fi-suggestion-item').on('click', function() {
            selectedPlaceId = $(this).data('place-id');
            const name = $(this).find('.fi-suggestion-name').text();
            $('#fi-business-search').val(name);
            $suggestions.empty();
        });
    }

    function searchAndScan(query) {
        showLoading(fInsights.strings.searching);

        $.ajax({
            url:  fInsights.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fi_search_business',
                nonce:  fInsights.nonce,
                query:  query,
                lat:    userLat || '',
                lng:    userLng || '',
            },
            success: function(response) {
                if (response.success && response.data.businesses.length > 0) {
                    selectedPlaceId = response.data.businesses[0].place_id;
                    scanBusiness(selectedPlaceId);
                } else {
                    hideLoading();
                    showInlineError('No businesses found — try adding your city (e.g. "Starbucks Silver Spring").');
                }
            },
            error: function() {
                hideLoading();
                showInlineError(fInsights.strings.error);
            }
        });
    }

    function scanBusiness(placeId) {
        const $email = $('#fi-user-email');
        const email  = $email.val();

        // Validate required email if enabled
        if ($email.attr('required') && !email) {
            showInlineError('Please enter your email address to continue.');
            $email.focus();
            return;
        }

        // Lock the button for the duration of the scan
        scanInProgress = true;
        const $scanButton = $('#fi-scan-button');
        $scanButton.prop('disabled', true);

        showLoading(fInsights.strings.analyzing);
        $('#fi-business-suggestions').empty();
        $('#fi-results').hide();

        $.ajax({
            url:  fInsights.ajaxUrl,
            type: 'POST',
            data: {
                action:   'fi_scan_business',
                nonce:    fInsights.nonce,
                place_id: placeId,
                email:    email,
            },
            success: function(response) {
                hideLoading();
                scanInProgress = false;
                $scanButton.prop('disabled', false);

                if (response.success) {
                    currentReport     = response.data.report;
                    currentShareToken = response.data.share ? response.data.share.token : null;
                    displayResults(response.data.report, response.data.share || null);
                    // Reset so the scan button can be used for a new search
                    selectedPlaceId = null;
                } else {
                    showInlineError(response.data.message || fInsights.strings.error);
                }
            },
            error: function() {
                hideLoading();
                scanInProgress = false;
                $scanButton.prop('disabled', false);
                showInlineError(fInsights.strings.error);
            }
        });
    }

    function displayResults(report, shareData) {
        const business = report.business_data;
        const analysis = report.analysis;
        const insights = analysis.insights || {};
        const overallScore = analysis.overall_score || 0;

        // ── Always-visible: Business Header + Score (3-column) ────────────────
        let headerHtml = '';
        headerHtml += '<div class="fi-business-header-combined">';

        // Col 1: Name, address, description, meta
        headerHtml += '<div class="fi-business-info-block">';
        headerHtml += '<h2 class="fi-business-name">' + escapeHtml(business.name) + '</h2>';
        if (business.editorial_summary) {
            headerHtml += '<p class="fi-business-description">' + escapeHtml(business.editorial_summary) + '</p>';
        }
        headerHtml += '<p class="fi-business-address">' + escapeHtml(business.address) + '</p>';
        headerHtml += '<div class="fi-business-meta">';
        if (business.rating > 0) {
            headerHtml += '<div class="fi-meta-item"><span>⭐</span><span>' + business.rating + ' (' + business.user_ratings_total + ' reviews)</span></div>';
        }
        if (business.phone) {
            headerHtml += '<div class="fi-meta-item"><span>📞</span><span>' + escapeHtml(business.phone) + '</span></div>';
        }
        if (business.website) {
            headerHtml += '<div class="fi-meta-item"><span>🌐</span><span><a href="' + business.website + '" target="_blank" style="color: white;">Website</a></span></div>';
        }
        headerHtml += '</div>';
        headerHtml += '</div>'; // fi-business-info-block

        // Col 2: Score
        const scoreStatus = getScoreStatus(overallScore);
        headerHtml += '<div class="fi-score-block">';
        headerHtml += '<div class="fi-score-circle ' + scoreStatus + '">' + overallScore + '</div>';
        headerHtml += '<div class="fi-score-label">Overall Score</div>';
        headerHtml += '</div>';

        headerHtml += '</div>'; // fi-business-header-combined

        // ── Tab nav ──────────────────────────────────────────────────────────
        const tabs = [
            { id: 'overview',    label: 'Overview' },
            { id: 'insights',    label: 'Insights' },
            { id: 'website',     label: 'Website' },
            { id: 'competition', label: 'Competition' },
            { id: 'sentiment',   label: 'Sentiment' },
        ];

        let tabNavHtml = '<div class="fi-tab-nav" role="tablist">';
        tabs.forEach(function(tab, i) {
            tabNavHtml += '<button class="fi-tab-btn' + (i === 0 ? ' active' : '') + '" role="tab" data-tab="' + tab.id + '" aria-selected="' + (i === 0 ? 'true' : 'false') + '">' + tab.label + '</button>';
        });
        tabNavHtml += '</div>';

        // ── Tab panels ───────────────────────────────────────────────────────

        // OVERVIEW: Priority Actions + Key Strengths (2-column grid)
        let overviewHtml = '<div class="fi-tab-panel" data-panel="overview">';

        if (analysis.executive_summary) {
            overviewHtml += '<div class="fi-executive-summary">' + escapeHtml(analysis.executive_summary) + '</div>';
        }

        overviewHtml += '<div class="fi-overview-grid">';

        if (analysis.priority_actions && analysis.priority_actions.length > 0) {
            overviewHtml += '<div class="fi-priority-actions"><h2>Priority Actions</h2>';
            overviewHtml += '<div class="fi-actions-grid">';
            analysis.priority_actions.forEach(function(action) {
                overviewHtml += '<div class="fi-action-item">';
                overviewHtml += '<div class="fi-action-title">' + escapeHtml(action.title) + '</div>';
                overviewHtml += '<div class="fi-action-description">' + escapeHtml(action.description) + '</div>';
                overviewHtml += '<div class="fi-action-meta">';
                overviewHtml += '<div class="fi-action-impact ' + (action.impact || 'medium') + '">Impact: ' + (action.impact || 'Medium') + '</div>';
                overviewHtml += '<div class="fi-action-effort ' + (action.effort || 'medium') + '">Effort: ' + (action.effort || 'Medium') + '</div>';
                overviewHtml += '</div></div>';
            });
            overviewHtml += '</div>'; // fi-actions-grid
            overviewHtml += '</div>'; // fi-priority-actions
        }

        if (analysis.strengths && analysis.strengths.length > 0) {
            overviewHtml += '<div class="fi-strengths-card"><h3>Key Strengths</h3>';
            overviewHtml += '<div class="fi-strengths-grid">';
            analysis.strengths.forEach(function(strength) {
                overviewHtml += '<div class="fi-strength-item">' + escapeHtml(strength) + '</div>';
            });
            overviewHtml += '</div>'; // fi-strengths-grid
            overviewHtml += '</div>'; // fi-strengths-card
        }

        overviewHtml += '</div>'; // fi-overview-grid
        overviewHtml += '</div>'; // overview panel

        // INSIGHTS: Category cards grid — 3 per row
        let insightsHtml = '<div class="fi-tab-panel" data-panel="insights" style="display:none;">';
        insightsHtml += '<div class="fi-insights-grid">';
        Object.keys(insights).forEach(function(key) {
            const insight = insights[key];
            const status  = insight.status || 'good';
            const isPhotos = (key === 'photos_media' || key === 'photos');
            const photos   = isPhotos ? (business.photos || []) : [];

            insightsHtml += '<div class="fi-insight-card ' + status + '">';

            // Card header: title + score badge
            insightsHtml += '<div class="fi-insight-header">';
            insightsHtml += '<h3 class="fi-insight-title">' + formatTitle(key) + '</h3>';
            insightsHtml += '<div class="fi-insight-score ' + status + '">' + (insight.score || 0) + '<span class="fi-score-denom">/100</span></div>';
            insightsHtml += '</div>';

            if (insight.headline) {
                insightsHtml += '<div class="fi-insight-headline">' + escapeHtml(insight.headline) + '</div>';
            }

            // ── Photo gallery (photos_media card only) ──────────────────────
            if (isPhotos && photos.length > 0 && fInsights.googleApiKey) {
                const displayPhotos = photos.slice(0, 10);
                insightsHtml += '<div class="fi-photo-gallery">';
                displayPhotos.forEach(function(photo) {
                    if (!photo.name) return;
                    // Encode each path segment to prevent injection via a crafted photo.name value
                    const encodedPhotoName = photo.name.split('/').map(encodeURIComponent).join('/');
                    const src = 'https://places.googleapis.com/v1/' + encodedPhotoName
                        + '/media?key=' + encodeURIComponent(fInsights.googleApiKey)
                        + '&maxWidthPx=400';
                    insightsHtml += '<div class="fi-photo-thumb">';
                    insightsHtml += '<img src="' + src + '" alt="Business photo" loading="lazy" />';
                    insightsHtml += '</div>';
                });
                insightsHtml += '</div>';
            }
            // ────────────────────────────────────────────────────────────────

            if (insight.summary) {
                insightsHtml += '<p class="fi-insight-summary">' + escapeHtml(insight.summary) + '</p>';
            }
            if (insight.recommendations && insight.recommendations.length > 0) {
                insightsHtml += '<div class="fi-recommendations"><h4>Recommendations</h4>';
                insight.recommendations.forEach(function(rec) {
                    insightsHtml += '<div class="fi-recommendation-item">' + escapeHtml(rec) + '</div>';
                });
                insightsHtml += '</div>';
            }
            insightsHtml += '</div>'; // fi-insight-card
        });
        insightsHtml += '</div>'; // fi-insights-grid
        insightsHtml += '</div>'; // insights panel

        // WEBSITE: Audit
        let websiteHtml = '<div class="fi-tab-panel" data-panel="website" style="display:none;">';
        const audit = report.website_analysis && report.website_analysis.audit;
        if (audit) {
            websiteHtml += renderWebsiteAudit(audit, business.website);
        } else {
            websiteHtml += '<div class="fi-tab-empty"><p>No website detected for this business.</p></div>';
        }
        websiteHtml += '</div>'; // website panel

        // COMPETITION: Competitive wins + map
        let competitionHtml = '<div class="fi-tab-panel" data-panel="competition" style="display:none;">';
        const competitors = business.competitors;
        if (competitors && competitors.length > 0) {
            competitionHtml += renderCompetitiveWins(business, competitors);
            competitionHtml += renderCompetitorMap(business, competitors, analysis.competitive_narrative || null);
        } else if (business.location) {
            competitionHtml += '<div class="fi-competitor-section"><div class="fi-competitor-header"><h2>Local Competitive Landscape</h2><span class="fi-competitor-subtitle">No nearby competitors found within 5 miles</span></div>';
            competitionHtml += '<div class="fi-no-competitors-message"><p>No similar businesses were found in the immediate vicinity.</p></div></div>';
        } else {
            competitionHtml += '<div class="fi-tab-empty"><p>Competitive data unavailable for this business.</p></div>';
        }
        competitionHtml += '</div>'; // competition panel

        // SENTIMENT: Themes + pain points (2-column grid)
        let sentimentHtml = '<div class="fi-tab-panel" data-panel="sentiment" style="display:none;">';
        if (analysis.sentiment_analysis) {
            const sentiment = analysis.sentiment_analysis;
            sentimentHtml += '<div class="fi-sentiment-card">';
            sentimentHtml += '<div class="fi-sentiment-header">';
            sentimentHtml += '<h3>Customer Sentiment</h3>';
            if (sentiment.overall_sentiment) {
                sentimentHtml += '<span class="fi-sentiment-overall">' + escapeHtml(sentiment.overall_sentiment) + '</span>';
            }
            sentimentHtml += '</div>'; // fi-sentiment-header
            sentimentHtml += '<div class="fi-sentiment-grid">';

            // Themes column
            if (sentiment.common_themes && sentiment.common_themes.length > 0) {
                sentimentHtml += '<div class="fi-sentiment-col">';
                sentimentHtml += '<h4 class="fi-sentiment-col-label">Common Themes</h4>';
                sentiment.common_themes.forEach(function(theme) {
                    sentimentHtml += '<div class="fi-theme-item">' + escapeHtml(theme) + '</div>';
                });
                sentimentHtml += '</div>';
            }

            // Pain points column
            if (sentiment.customer_pain_points && sentiment.customer_pain_points.length > 0) {
                sentimentHtml += '<div class="fi-sentiment-col">';
                sentimentHtml += '<h4 class="fi-sentiment-col-label">Pain Points</h4>';
                sentiment.customer_pain_points.forEach(function(point) {
                    sentimentHtml += '<div class="fi-pain-point-item">' + escapeHtml(point) + '</div>';
                });
                sentimentHtml += '</div>';
            }

            sentimentHtml += '</div>'; // fi-sentiment-grid

            // Customer review quotes: Good | Bad two-column grid
            const reviews = business.reviews || [];
            if (reviews.length > 0) {
                const goodReviews = reviews.filter(function(r) { return r.rating >= 4 && r.text; });
                const badReviews  = reviews.filter(function(r) { return r.rating <= 3 && r.text; });

                if (goodReviews.length > 0 || badReviews.length > 0) {
                    sentimentHtml += '<div class="fi-review-quotes-section">';
                    sentimentHtml += '<div class="fi-review-quotes-header"><h4>What Customers Are Saying</h4></div>';
                    sentimentHtml += '<div class="fi-review-quotes-grid">';

                    // Good reviews column
                    sentimentHtml += '<div class="fi-review-col fi-review-col--good">';
                    sentimentHtml += '<div class="fi-review-col-label">Positive</div>';
                    if (goodReviews.length > 0) {
                        goodReviews.slice(0, 3).forEach(function(r) {
                            const stars = '\u2605'.repeat(r.rating) + '\u2606'.repeat(5 - r.rating);
                            const excerpt = r.text.length > 220 ? r.text.substring(0, 220).trimEnd() + '\u2026' : r.text;
                            sentimentHtml += '<div class="fi-review-card fi-review-card--good">';
                            sentimentHtml += '<div class="fi-review-meta">';
                            sentimentHtml += '<span class="fi-review-stars">' + stars + '</span>';
                            sentimentHtml += '<span class="fi-review-author">' + escapeHtml(r.author) + '</span>';
                            if (r.relative_time) sentimentHtml += '<span class="fi-review-time">' + escapeHtml(r.relative_time) + '</span>';
                            sentimentHtml += '</div>';
                            sentimentHtml += '<p class="fi-review-text">&ldquo;' + escapeHtml(excerpt) + '&rdquo;</p>';
                            sentimentHtml += '</div>';
                        });
                    } else {
                        sentimentHtml += '<p class="fi-review-empty">No positive reviews available.</p>';
                    }
                    sentimentHtml += '</div>';

                    // Critical reviews column
                    sentimentHtml += '<div class="fi-review-col fi-review-col--bad">';
                    sentimentHtml += '<div class="fi-review-col-label">Critical</div>';
                    if (badReviews.length > 0) {
                        badReviews.slice(0, 3).forEach(function(r) {
                            const stars = '\u2605'.repeat(r.rating) + '\u2606'.repeat(5 - r.rating);
                            const excerpt = r.text.length > 220 ? r.text.substring(0, 220).trimEnd() + '\u2026' : r.text;
                            sentimentHtml += '<div class="fi-review-card fi-review-card--bad">';
                            sentimentHtml += '<div class="fi-review-meta">';
                            sentimentHtml += '<span class="fi-review-stars">' + stars + '</span>';
                            sentimentHtml += '<span class="fi-review-author">' + escapeHtml(r.author) + '</span>';
                            if (r.relative_time) sentimentHtml += '<span class="fi-review-time">' + escapeHtml(r.relative_time) + '</span>';
                            sentimentHtml += '</div>';
                            sentimentHtml += '<p class="fi-review-text">&ldquo;' + escapeHtml(excerpt) + '&rdquo;</p>';
                            sentimentHtml += '</div>';
                        });
                    } else {
                        sentimentHtml += '<p class="fi-review-empty">No critical reviews available.</p>';
                    }
                    sentimentHtml += '</div>';

                    sentimentHtml += '</div>'; // fi-review-quotes-grid
                    sentimentHtml += '</div>'; // fi-review-quotes-section
                }
            }

            sentimentHtml += '</div>'; // fi-sentiment-card
        } else {
            sentimentHtml += '<div class="fi-tab-empty"><p>No sentiment data available for this business.</p></div>';
        }
        sentimentHtml += '</div>'; // sentiment panel

        // Always-visible: Email / CTA section
        let ctaHtml = '<div class="fi-email-report-section">';
        var emailEnabled = fInsights.emailEnabled || false;
        if (emailEnabled) {
            ctaHtml += '<h3>Want this report in your back pocket?</h3>';
            ctaHtml += '<p style="text-align:center;margin-bottom:12px;">Email it to yourself or copy a link to share with anyone.</p>';
            var emailPlaceholder = fInsights.emailPlaceholder || 'Enter your email';
            var emailBtnText     = fInsights.emailBtnText     || 'Email Report';
            var emailBtnIcon     = fInsights.emailBtnIcon     || '';
            ctaHtml += '<div class="fi-email-box">';
            ctaHtml += '<input type="email" id="fi-report-email" class="fi-email-input" placeholder="' + escapeHtml(emailPlaceholder) + '" />';
            ctaHtml += '<button class="fi-email-button" id="fi-send-report">';
            if (emailBtnIcon) {
                ctaHtml += '<i class="' + escapeHtml(emailBtnIcon) + '" aria-hidden="true"></i>';
            } else {
                ctaHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
            }
            ctaHtml += '<span>' + escapeHtml(emailBtnText) + '</span></button></div>';
            ctaHtml += '<div id="fi-email-feedback"></div>';
        } else {
            // Free tier: share takes the headline
            ctaHtml += '<h3>Share this report</h3>';
            ctaHtml += '<p style="text-align:center;margin-bottom:12px;">Send this link to the business owner or anyone who\'d find it useful.</p>';
        }

        // Share strip (always shown when the server returned a token)
        if (shareData && shareData.url) {
            if (emailEnabled) {
                ctaHtml += '<div class="fi-share-divider"><span>or share a link</span></div>';
            }
            ctaHtml += '<div class="fi-share-row">';
            ctaHtml += '<div class="fi-share-url">' + escapeHtml(shareData.url) + '</div>';
            ctaHtml += '<button class="fi-share-copy-btn" id="fi-copy-share-url" data-url="' + escapeHtml(shareData.url) + '">';
            ctaHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
            ctaHtml += '<span>Copy Link</span></button>';
            ctaHtml += '</div>';
            ctaHtml += '<div class="fi-share-meta">Generated <strong>' + escapeHtml(shareData.generated) + '</strong> &nbsp;&middot;&nbsp; Link expires <strong>' + escapeHtml(shareData.expires) + '</strong></div>';
        }

        if (fInsights.ctaButton && fInsights.ctaButton.enabled && fInsights.ctaButton.url) {
            var ctaText = fInsights.ctaButton.text || 'Book a Free Consultation';
            var ctaUrl  = fInsights.ctaButton.url;
            var ctaIcon = fInsights.ctaButton.icon || '';
            ctaHtml += '<a href="' + escapeHtml(ctaUrl) + '" target="_blank" rel="noopener noreferrer" class="fi-cta-button-secondary">';

            ctaHtml += escapeHtml(ctaText) + '</a>';
        }
        if (!fInsights.hideBranding) {
            ctaHtml += '<p class="fi-powered-by"><a href="https://fricking.website/f-insights" target="_blank" rel="noopener noreferrer">get this tool for your wordpress site</a></p>';
        }
        ctaHtml += '</div>';

        // ── Assemble & render ────────────────────────────────────────────────
        const fullHtml = headerHtml
            + tabNavHtml
            + '<div class="fi-tab-content">'
            + overviewHtml
            + insightsHtml
            + websiteHtml
            + competitionHtml
            + sentimentHtml
            + '</div>'
            + ctaHtml;

        $('#fi-results').html(fullHtml).fadeIn();

        // Scroll to results
        $('html, body').animate({ scrollTop: $('#fi-results').offset().top - 50 }, 500);

        // Tab switching
        $(document).on('click', '.fi-tab-btn', function() {
            const target = $(this).data('tab');
            $('.fi-tab-btn').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            $('.fi-tab-panel').hide();
            $('.fi-tab-panel[data-panel="' + target + '"]').show();
        });

        // Email report handler
        if (fInsights.emailEnabled) {
            $('#fi-send-report').on('click', function() {
                sendEmailReport();
            });
        }

        // Copy share link handler
        $(document).on('click', '#fi-copy-share-url', function() {
            var url = $(this).data('url');
            var $btn = $(this);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    $btn.find('span').text('Copied!');
                    setTimeout(function() { $btn.find('span').text('Copy Link'); }, 2000);
                });
            } else {
                // Fallback for older browsers
                var $tmp = $('<input>').val(url).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                $btn.find('span').text('Copied!');
                setTimeout(function() { $btn.find('span').text('Copy Link'); }, 2000);
            }
        });
    }

        function sendEmailReport() {
        const email = $('#fi-report-email').val();

        if (!email || !isValidEmail(email)) {
            showInlineError('Please enter a valid email address.');
            $('#fi-report-email').focus();
            return;
        }

        const $button    = $('#fi-send-report');
        const $feedback  = $('#fi-email-feedback');
        const originalHtml = $button.html();
        $button.prop('disabled', true).html('Sending, check spam folder...');
        $feedback.empty();

        // UNIFIED REPORT FIX: Capture the EXACT HTML the client is seeing
        const frontendHtml = $('#fi-results').html();

        $.ajax({
            url:  fInsights.ajaxUrl,
            type: 'POST',
            data: {
                action:      'fi_email_report',
                nonce:       fInsights.nonce,
                email:       email,
                share_token: currentShareToken,  // server-side token; report fetched from DB
                frontend_html: frontendHtml  // Send the actual frontend HTML
            },
            success: function(response) {
                $button.prop('disabled', false).html(originalHtml);

                if (response.success) {
                    $feedback.html(
                        '<div class="fi-email-success" role="status">' +
                        '<span style="flex-shrink:0; font-weight:700; font-size:16px;">✓</span>' +
                        '<span>This report has been sent to <strong>' + escapeHtml(email) + '</strong>. Please check your spam folder first.</span>' +
                        '</div>'
                    );
                    // Don't clear email field - allows sending to multiple recipients
                } else {
                    $feedback.html(
                        '<div class="fi-inline-error"><span class="fi-error-icon">⚠</span> ' +
                        escapeHtml(response.data.message || 'Failed to send email — please try again.') +
                        '</div>'
                    );
                }
            },
            error: function() {
                $button.prop('disabled', false).html(originalHtml);
                $feedback.html(
                    '<div class="fi-inline-error"><span class="fi-error-icon">⚠</span> ' +
                    'Failed to send email — please try again.' +
                    '</div>'
                );
            }
        });
    }

    function renderCompetitiveWins(business, competitors) {
        // Calculate competitive advantages - find SOMETHING positive to highlight
        const wins = [];
        
        // 1. Rating comparison
        if (business.rating && business.rating > 0) {
            const betterRating = competitors.filter(c => c.rating && c.rating < business.rating).length;
            if (betterRating > 0) {
                const percentage = Math.round((betterRating / competitors.length) * 100);
                wins.push({
                    icon: '⭐',
                    text: 'Higher rating than ' + betterRating + ' of ' + competitors.length + ' nearby competitors (' + percentage + '%)',
                    type: 'positive'
                });
            } else if (business.rating >= 4.0) {
                // Even if not beating competitors, 4.0+ is still good
                wins.push({
                    icon: '⭐',
                    text: 'Strong ' + business.rating + ' star rating demonstrates customer satisfaction',
                    type: 'neutral'
                });
            }
        }
        
        // 2. Review volume comparison
        if (business.user_ratings_total && business.user_ratings_total > 0) {
            const moreReviews = competitors.filter(c => 
                c.user_ratings_total && c.user_ratings_total < business.user_ratings_total
            ).length;
            
            if (moreReviews > 0) {
                const percentage = Math.round((moreReviews / competitors.length) * 100);
                wins.push({
                    icon: '💬',
                    text: 'More customer reviews than ' + percentage + '% of nearby competitors',
                    type: 'positive'
                });
            } else if (business.user_ratings_total >= 50) {
                wins.push({
                    icon: '💬',
                    text: business.user_ratings_total.toLocaleString() + ' reviews show strong customer engagement',
                    type: 'neutral'
                });
            }
        }
        
        // 3. Rating velocity (high rating + high volume = credibility)
        if (business.rating >= 4.5 && business.user_ratings_total >= 100) {
            wins.push({
                icon: '🏆',
                text: 'Top-tier rating with substantial review volume builds trust',
                type: 'positive'
            });
        }
        
        // 4. Website presence
        if (business.website) {
            const competitorsWithWebsite = competitors.filter(c => c.website).length;
            if (competitorsWithWebsite < competitors.length) {
                const withoutWebsite = competitors.length - competitorsWithWebsite;
                wins.push({
                    icon: '🌐',
                    text: 'Has a website (unlike ' + withoutWebsite + ' nearby competitor' + (withoutWebsite !== 1 ? 's' : '') + ')',
                    type: 'positive'
                });
            }
        }
        
        // 5. If no wins found yet, create neutral positioning statements
        if (wins.length === 0) {
            if (business.rating > 0) {
                wins.push({
                    icon: '📍',
                    text: 'Operating in a competitive market with ' + competitors.length + ' similar businesses nearby',
                    type: 'neutral'
                });
            }
            
            if (business.user_ratings_total > 0) {
                wins.push({
                    icon: '👥',
                    text: business.user_ratings_total.toLocaleString() + ' customers have shared their experience',
                    type: 'neutral'
                });
            }
        }
        
        // Only render if we found something to highlight
        if (wins.length === 0) {
            return '';
        }
        
        let html = '<div class="fi-competitive-wins">';
        html += '<h2>Your Competitive Edge</h2>';
        html += '<p class="fi-wins-intro">Here\'s what sets you apart from nearby competitors:</p>';
        html += '<div class="fi-wins-grid">';
        
        wins.forEach(function(win) {
            const winClass = win.type === 'positive' ? 'fi-win-positive' : 'fi-win-neutral';
            html += '<div class="fi-win-card ' + winClass + '">';
            html += '<span class="fi-win-icon">' + win.icon + '</span>';
            html += '<span class="fi-win-text">' + escapeHtml(win.text) + '</span>';
            html += '</div>';
        });
        
        html += '</div>';
        html += '</div>';
        
        return html;
    }

    function renderCompetitorMap(business, competitors, competitiveNarrative) {
        const lat = business.location && business.location.latitude  ? business.location.latitude  : null;
        const lng = business.location && business.location.longitude ? business.location.longitude : null;
        
        // Calculate max distance to show in subtitle
        let maxDistance = 0;
        if (competitors && competitors.length > 0) {
            competitors.forEach(function(comp) {
                if (comp.distance_miles && comp.distance_miles > maxDistance) {
                    maxDistance = comp.distance_miles;
                }
            });
        }
        
        // Format distance for display
        let distanceText = 'nearby';
        if (maxDistance > 0) {
            if (maxDistance < 1) {
                distanceText = 'within ' + Math.round(maxDistance * 10) / 10 + ' mi';
            } else {
                distanceText = 'within ' + Math.round(maxDistance) + ' mi';
            }
        }

        let html = '<div class="fi-competitor-section">';
        html += '<div class="fi-competitor-header">';
        html += '<h2>Local Competitive Landscape</h2>';
        html += '<span class="fi-competitor-subtitle">Based on how your Google Business Profile is currently set up</span>';
        html += '</div>';
        
        html += '<div class="fi-competitor-explainer">';
        html += '<p>Google groups you with these ' + competitors.length + ' businesses. If they feel like accurate competitors, you\'re well-positioned in search. If they don\'t match your actual market, it\'s a signal your profile needs refinement — which directly affects who finds you when searching for what you offer.</p>';
        html += '</div>';

        if (competitiveNarrative) {
            html += '<div class="fi-competitive-narrative">' + escapeHtml(competitiveNarrative) + '</div>';
        }

        html += '<div class="fi-competitor-body">';

        // Map panel
        html += '<div class="fi-competitor-map-panel">';
        
        // Generate unique map ID
        const mapId = 'fi-map-' + Math.random().toString(36).substr(2, 9);
        
        // ── Lazy Maps SDK loading ─────────────────────────────────────────────
        // loadMapsSdk() injects the Google Maps SDK on-demand and fires its
        // callback once google.maps is fully initialised. If no SDK URL is
        // configured (no API key), the call is a no-op and we fall through to
        // the OpenStreetMap iframe fallback.
        const hasMapsKey = !! ( fInsights && fInsights.mapsSdkUrl );

        if ( hasMapsKey && lat !== null && lng !== null ) {
            // Render a placeholder div immediately so the DOM is ready for the
            // map instance. The SDK is injected lazily and initialises the map.
            html += '<div id="' + mapId + '" class="fi-map-canvas fi-map-loading"></div>';

            setTimeout( function() {
                loadMapsSdk( function() {
                    if ( typeof google.maps.importLibrary === 'function' ) {
                        google.maps.importLibrary( 'maps' ).then( function() {
                            renderMapWithMarkers( mapId, business, competitors, lat, lng );
                        } ).catch( function() {
                            renderMapWithMarkers( mapId, business, competitors, lat, lng );
                        } );
                    } else {
                        renderMapWithMarkers( mapId, business, competitors, lat, lng );
                    }
                } );
            }, 100 );

        } else if (lat !== null && lng !== null) {
            // Fallback: static OpenStreetMap tile (no key required)
            const osmSrc = 'https://www.openstreetmap.org/export/embed.html'
                + '?bbox=' + (lng - 0.005) + ',' + (lat - 0.004) + ',' + (lng + 0.005) + ',' + (lat + 0.004)
                + '&layer=mapnik'
                + '&marker=' + lat + ',' + lng;
            html += '<iframe class="fi-map-iframe" src="' + osmSrc + '" allowfullscreen loading="lazy"></iframe>';
        } else {
            html += '<div class="fi-map-unavailable">Map unavailable — location data not returned by API</div>';
        }
        html += '</div>'; // fi-competitor-map-panel

        // Competitor list panel
        html += '<div class="fi-competitor-list-panel">';
        html += '<div class="fi-competitor-subject">';
        html += '<div class="fi-competitor-row subject">';
        html += '<div class="fi-competitor-rank">★</div>';
        html += '<div class="fi-competitor-info">';
        html += '<div class="fi-competitor-name subject-name">' + escapeHtml(business.name) + ' <span class="fi-you-badge">YOU</span></div>';
        html += '<div class="fi-competitor-rating">';
        if (business.rating) {
            html += renderStars(business.rating) + ' <strong>' + business.rating + '</strong>';
            if (business.user_ratings_total) {
                html += ' <span class="fi-rating-count">(' + Number(business.user_ratings_total).toLocaleString() + ' reviews)</span>';
            }
        } else {
            html += '<span class="fi-no-rating">No rating yet</span>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>'; // fi-competitor-subject

        html += '<div class="fi-competitor-divider">vs. Nearby</div>';

        competitors.forEach(function(comp, idx) {
            const ratingDiff  = business.rating && comp.rating ? (business.rating - comp.rating).toFixed(1) : null;
            const diffClass   = ratingDiff === null ? '' : (parseFloat(ratingDiff) > 0 ? 'ahead' : parseFloat(ratingDiff) < 0 ? 'behind' : 'tied');
            const diffLabel   = ratingDiff === null ? '' : (parseFloat(ratingDiff) > 0 ? '+' + ratingDiff + ' ahead' : parseFloat(ratingDiff) < 0 ? ratingDiff + ' behind' : 'Tied');

            html += '<div class="fi-competitor-row">';
            html += '<div class="fi-competitor-rank">' + (idx + 1) + '</div>';
            html += '<div class="fi-competitor-info">';
            
            // Add website link if available
            if (comp.website) {
                html += '<div class="fi-competitor-name">';
                html += '<a href="' + escapeHtml(comp.website) + '" target="_blank" rel="noopener noreferrer">';
                html += escapeHtml(comp.name || 'Unknown');
                html += '</a>';
                html += '</div>';
            } else {
                html += '<div class="fi-competitor-name">' + escapeHtml(comp.name || 'Unknown') + '</div>';
            }
            html += '<div class="fi-competitor-rating">';
            if (comp.rating) {
                html += renderStars(comp.rating) + ' <strong>' + comp.rating + '</strong>';
                if (comp.user_ratings_total) {
                    html += ' <span class="fi-rating-count">(' + Number(comp.user_ratings_total).toLocaleString() + ')</span>';
                }
            } else {
                html += '<span class="fi-no-rating">No rating</span>';
            }
            html += '</div>';
            if (comp.address) {
                html += '<div class="fi-competitor-address">' + escapeHtml(comp.address) + '</div>';
            }
            if (comp.competitor_reason) {
                html += '<div class="fi-competitor-reason">' + escapeHtml(comp.competitor_reason) + '</div>';
            }
            html += '</div>';
            if (diffLabel) {
                html += '<div class="fi-competitor-diff ' + diffClass + '">' + diffLabel + '</div>';
            }
            html += '</div>'; // fi-competitor-row
        });

        html += '</div>'; // fi-competitor-list-panel
        html += '</div>'; // fi-competitor-body
        html += '</div>'; // fi-competitor-section

        return html;
    }

    function renderMapWithMarkers(mapId, business, competitors, centerLat, centerLng) {
        const mapElement = document.getElementById(mapId);
        if (!mapElement) return;

        const map = new google.maps.Map(mapElement, {
            center: { lat: centerLat, lng: centerLng },
            zoom: 15,
            mapTypeControl: true,
            streetViewControl: false,
            fullscreenControl: true,
        });

        const bounds = new google.maps.LatLngBounds();

        // Add marker for the main business (black/highlighted)
        const businessMarker = new google.maps.Marker({
            position: { lat: centerLat, lng: centerLng },
            map: map,
            title: business.name,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 12,
                fillColor: '#000',
                fillOpacity: 1,
                strokeColor: '#fff',
                strokeWeight: 3,
            },
            zIndex: 1000,
        });

        const businessInfoWindow = new google.maps.InfoWindow({
            content: '<div style="padding: 8px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;"><strong>' + escapeHtml(business.name) + '</strong><br>' +
                     (business.rating ? '⭐ ' + business.rating + ' (' + business.user_ratings_total + ' reviews)' : '') +
                     '<br><em style="color: #10b981; font-weight: bold;">YOUR BUSINESS</em></div>'
        });

        businessMarker.addListener('click', function() {
            businessInfoWindow.open(map, businessMarker);
        });

        bounds.extend(businessMarker.getPosition());

        // Add markers for competitors (red)
        competitors.forEach(function(comp, idx) {
            if (comp.location && comp.location.latitude && comp.location.longitude) {
                const compLat = comp.location.latitude;
                const compLng = comp.location.longitude;

                const competitorMarker = new google.maps.Marker({
                    position: { lat: compLat, lng: compLng },
                    map: map,
                    title: comp.name,
                    label: {
                        text: String(idx + 1),
                        color: '#fff',
                        fontSize: '12px',
                        fontWeight: 'bold',
                    },
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 10,
                        fillColor: '#ef4444',
                        fillOpacity: 0.9,
                        strokeColor: '#fff',
                        strokeWeight: 2,
                    },
                });

                const compInfoWindow = new google.maps.InfoWindow({
                    content: '<div style="padding: 8px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;"><strong>' + escapeHtml(comp.name) + '</strong><br>' +
                             (comp.rating ? '⭐ ' + comp.rating + ' (' + comp.user_ratings_total + ' reviews)' : 'No rating') +
                             (comp.address ? '<br><small>' + escapeHtml(comp.address) + '</small>' : '') +
                             '</div>'
                });

                competitorMarker.addListener('click', function() {
                    compInfoWindow.open(map, competitorMarker);
                });

                bounds.extend(competitorMarker.getPosition());
            }
        });

        // Fit map to show all markers
        if (competitors.length > 0 && competitors.some(c => c.location)) {
            map.fitBounds(bounds);
            // Ensure minimum zoom level after fitting bounds
            google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                if (map.getZoom() > 16) {
                    map.setZoom(16);
                }
            });
        }
    }

    function renderStars(rating) {
        const full  = Math.floor(rating);
        const half  = (rating % 1) >= 0.5;
        let stars   = '';
        for (let i = 0; i < 5; i++) {
            if (i < full)           stars += '<span class="fi-star full">★</span>';
            else if (i === full && half) stars += '<span class="fi-star half">½</span>';
            else                    stars += '<span class="fi-star empty">☆</span>';
        }
        return '<span class="fi-stars">' + stars + '</span>';
    }

    function renderWebsiteAudit(audit, websiteUrl) {
        const categories = [
            { key: 'performance',     label: 'Performance' },
            { key: 'seo',             label: 'SEO' },
            { key: 'best_practices',  label: 'Best Practices' },
            { key: 'accessibility',   label: 'Accessibility' },
        ];

        function auditScoreStatus(s) {
            if (s >= 80) return 'good';
            if (s >= 60) return 'warning';
            return 'alert';
        }

        function renderDevice(deviceKey, deviceLabel) {
            const deviceData = audit[deviceKey];
            if (!deviceData) return '';

            let h = '<div class="fi-audit-device">';
            h += '<div class="fi-audit-device-header">';
            h += '<span class="fi-audit-device-icon">' + (deviceKey === 'mobile' ? '📱' : '🖥') + '</span>';
            h += '<span class="fi-audit-device-label">' + deviceLabel + '</span>';
            h += '</div>';
            h += '<div class="fi-audit-categories">';

            categories.forEach(function(cat) {
                const catData = deviceData[cat.key];
                if (!catData) return;
                const score  = catData.score || 0;
                const status = auditScoreStatus(score);

                h += '<div class="fi-audit-cat">';
                // Category header row
                h += '<div class="fi-audit-cat-header">';
                h += '<span class="fi-audit-cat-label">' + cat.label + '</span>';
                h += '<span class="fi-audit-cat-score ' + status + '">' + score + '<span class="fi-score-denom">/100</span></span>';
                h += '</div>';

                // Metric rows
                if (catData.metrics && catData.metrics.length) {
                    h += '<div class="fi-audit-metrics">';
                    catData.metrics.forEach(function(m) {
                        const rowClass = m.pass ? (m.keep ? 'pass-keep' : 'pass') : 'fail';
                        h += '<div class="fi-audit-metric ' + rowClass + '">';
                        h += '<div class="fi-audit-metric-top">';
                        h += '<span class="fi-audit-metric-icon">' + (m.pass ? (m.keep ? '✓' : '✓') : '✗') + '</span>';
                        h += '<span class="fi-audit-metric-name">' + escapeHtml(m.name) + '</span>';
                        h += '<span class="fi-audit-metric-value">' + escapeHtml(m.value) + '</span>';
                        h += '</div>';
                        h += '<div class="fi-audit-metric-desc">';
                        if (m.pass && m.keep) {
                            h += '<span class="fi-audit-note keep">Keep it up — ' + escapeHtml(m.description) + '</span>';
                        } else if (m.pass && !m.keep) {
                            h += '<span class="fi-audit-note improve">Good, but could be better — ' + escapeHtml(m.description) + '</span>';
                        } else {
                            h += '<span class="fi-audit-note fix">Needs attention — ' + escapeHtml(m.description) + '</span>';
                        }
                        h += '</div>';
                        h += '</div>';
                    });
                    h += '</div>';
                }

                h += '</div>'; // fi-audit-cat
            });

            h += '</div>'; // fi-audit-categories
            h += '</div>'; // fi-audit-device
            return h;
        }

        let html = '<div class="fi-website-audit">';
        html += '<div class="fi-website-audit-header">';
        html += '<h2>Website Performance Audit</h2>';
        if (websiteUrl) {
            html += '<a href="' + websiteUrl + '" target="_blank" class="fi-audit-url">' + escapeHtml(websiteUrl) + '</a>';
        }
        html += '</div>';
        html += '<div class="fi-audit-devices">';
        html += renderDevice('mobile', 'Mobile');
        html += renderDevice('desktop', 'Desktop');
        html += '</div>';
        html += '</div>';
        return html;
    }

    function showLoading(text) {
        $('#fi-loading .fi-loading-text').text(text);
        $('#fi-loading').fadeIn();
        
        // Reset checklist
        $('.fi-checklist-item').removeClass('active completed');
        $('.fi-check-icon').text('○');

        // Cancel any leftover timers from a previous scan
        checklistTimers.forEach(function(id) { clearTimeout(id); });
        checklistTimers = [];
        
        // Animate checklist items progressively with variable timing
        // Total time should feel like it's actually doing work (approx 45-60s total)
        let currentStep = 0;
        const totalSteps = 7;
        
        // Define durations for each step to make it feel more realistic
        // Steps that involve external APIs or AI take longer
        const stepDurations = [
            2000,  // Step 1: Analyzing Online Presence (Quick check)
            8000,  // Step 2: Evaluating Customer Reviews (Fetching reviews)
            6000,  // Step 3: Inspecting Photos & Media (Fetching photos)
            4000,  // Step 4: Checking Business Information (Details)
            12000, // Step 5: Mapping Competitive Landscape (Heavy processing)
            10000, // Step 6: Auditing Website Performance (Scraping/Lighthouse)
            15000  // Step 7: Finalizing (AI Analysis)
        ];
        
        function animateStep() {
            if (currentStep > 0) {
                // Mark previous step as completed
                $('.fi-checklist-item[data-step="' + currentStep + '"]')
                    .removeClass('active')
                    .addClass('completed')
                    .find('.fi-check-icon')
                    .text('✓');
            }
            
            currentStep++;
            
            if (currentStep <= totalSteps) {
                // Mark current step as active
                $('.fi-checklist-item[data-step="' + currentStep + '"]')
                    .addClass('active')
                    .find('.fi-check-icon')
                    .text('●');
                
                // Store timer ID so hideLoading() can cancel it if scan finishes early
                const duration = stepDurations[currentStep - 1] || 5000;
                checklistTimers.push(setTimeout(animateStep, duration));
            }
        }
        
        // Start animation after a brief delay
        checklistTimers.push(setTimeout(animateStep, 500));
    }
    
    function hideLoading() {
        // Cancel any in-progress checklist animation timers
        checklistTimers.forEach(function(id) { clearTimeout(id); });
        checklistTimers = [];
        $('#fi-loading').fadeOut();
    }
    
    function getScoreStatus(score) {
        if (score >= 80) return 'good';
        if (score >= 60) return 'warning';
        return 'alert';
    }
    
    function formatTitle(key) {
        return key.split('_').map(function(word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
})(jQuery);