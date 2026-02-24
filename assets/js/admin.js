/**
 * Admin JavaScript for F Insights
 * v1.8.0 — Tab navigation refactor
 */

jQuery(function($) {
    'use strict';

    // ── IP blacklist — auto-detect current IP ─────────────────────────────
    // Runs on Settings > IP Exclusions tab (textarea is present in DOM).
    if ($('#fi_analytics_ip_blacklist').length) {
        const $detectedDisplay = $('#fi-detected-ip');
        const $addBtn          = $('#fi-add-my-ip');
        const $addedNotice     = $('#fi-ip-added-notice');
        const $textarea        = $('#fi_analytics_ip_blacklist');

        // Use a public IP-echo service. ipify is GDPR-friendly and
        // returns just the raw IP as JSON — no tracking.
        $.getJSON('https://api.ipify.org?format=json', function(data) {
            const ip = data.ip || '';
            if (!ip) {
                $detectedDisplay.text('Could not detect IP');
                return;
            }
            $detectedDisplay.text(ip);
            $addBtn.prop('disabled', false);

            $addBtn.on('click', function() {
                const current = $textarea.val().trim();
                // Only add if not already listed.
                const lines = current.split(/\n/).map(function(l) { return l.trim(); });
                if (lines.indexOf(ip) !== -1) {
                    $addedNotice.text('Already listed').fadeIn().delay(2000).fadeOut();
                    return;
                }
                const newVal = current ? current + '\n' + ip : ip;
                $textarea.val(newVal);
                $addBtn.prop('disabled', true).text('✓ Added');
                $addedNotice.fadeIn().delay(2000).fadeOut();
            });
        }).fail(function() {
            $detectedDisplay.text('Could not detect IP');
        });
    }

        // ── Shortcode copy ───────────────────────────────────────────────────
        $('.fi-copy-shortcode').on('click', function() {
            const button = $(this);
            const text   = button.data('clipboard-text');

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(function() { showCopySuccess(button); })
                    .catch(function() { fallbackCopy(text, button); });
            } else {
                fallbackCopy(text, button);
            }
        });

        // ── Page link helper ─────────────────────────────────────────────────
        $('#fi-page-link-select').on('change', function() {
            var url        = $(this).val();
            var $copyBtn   = $('#fi-copy-page-link');
            var $openLink  = $('#fi-open-page-link');
            if ( url ) {
                $copyBtn.prop('disabled', false).data('clipboard-text', url);
                $openLink.attr('href', url).show();
            } else {
                $copyBtn.prop('disabled', true).removeData('clipboard-text');
                $openLink.attr('href', '#').hide();
            }
        });

        $('#fi-copy-page-link').on('click', function() {
            var url = $(this).data('clipboard-text');
            if ( ! url ) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url)
                    .then(function() { showCopySuccess($('#fi-copy-page-link')); })
                    .catch(function() { fallbackCopy(url, $('#fi-copy-page-link')); });
            } else {
                fallbackCopy(url, $(this));
            }
        });

        function fallbackCopy(text, button) {
            // navigator.clipboard is unavailable (non-HTTPS context or permissions denied).
            // The deprecated document.execCommand('copy') has been intentionally removed —
            // the WordPress admin always runs over HTTPS, so this path should never be hit
            // in production. Showing a clear error is more honest than silent degradation.
            showCopySuccess(button, fiAdmin.strings.copyFailed, false);
        }

        function showCopySuccess(button, label, success) {
            if ( typeof success === 'undefined' ) { success = true; }
            const orig = button.text();
            const msg  = label || fiAdmin.strings.copied;
            button.text(msg);
            if ( success ) {
                button.addClass('button-primary');
            }
            setTimeout(function() {
                button.text(orig).removeClass('button-primary');
            }, 2000);
        }

        // ── Brand color preview ──────────────────────────────────────────────
        // NOTE: Color branding was removed in v2.1.0. Font preview and type
        // scale sliders are handled by the typography block below.

        // Typography controls removed — font/size settings no longer in use.

    // ── All Leads: search, filter, pagination (AJAX table) ───────────────
    (function() {
        var $tbody      = $('#fi-leads-tbody');
        if ( ! $tbody.length ) return;

        var $search     = $('#fi-leads-search');
        var $filter     = $('#fi-leads-status-filter');
        var $countLabel = $('#fi-leads-count-label');
        var $pageInfo   = $('#fi-leads-page-info');
        var $pageButtons= $('#fi-leads-page-buttons');

        var currentPage  = 1;
        var totalPages   = 1;
        var searchTimer  = null;
        var perPage      = 20;
        var currentOrderBy = 'request_date';
        var currentOrder   = 'DESC';

        // ── Row builder ───────────────────────────────────────────────────
        function buildRow( lead ) {
            var isPremium    = !! ( fiAdmin && fiAdmin.isPremium );
            var statusOptions = ['new','contacted','qualified','closed','lost'];
            var statusLabels  = {new:'New',contacted:'Contacted',qualified:'Qualified',closed:'Closed',lost:'Lost'};

            var selectHtml = '<select class="fi-status-select" data-lead-id="' + lead.id + '">';
            statusOptions.forEach(function(s) {
                selectHtml += '<option value="' + s + '"' + (lead.follow_up_status === s ? ' selected' : '') + '>' + statusLabels[s] + '</option>';
            });
            selectHtml += '</select>';

            // Business column: name + report viewer only (no Rescan button)
            var businessHtml = '<strong>' + escHtml(lead.business_name) + '</strong>';
            if ( lead.has_report ) {
                businessHtml += '<br><button type="button" class="button button-small fi-view-report" data-lead-id="' + lead.id + '" style="margin-top:5px;">📄 View Report</button>';
            }
            if ( lead.business_website ) {
                businessHtml += '<br><a href="' + escHtml(lead.business_website) + '" target="_blank" style="font-size:0.9em;">' + escHtml(lead.business_website) + '</a>';
            }
            if ( lead.business_phone ) {
                businessHtml += '<br><a href="tel:' + escHtml(lead.business_phone) + '" style="font-size:0.9em;">📞 ' + escHtml(lead.business_phone) + '</a>';
            }

            // Notes column: autosave on blur for all plans.
            // Premium: no Save button — just a "✓ Updated" flash (same as Status dropdown).
            // Free:    same autosave UX (no Save button either).
            var notes     = lead.follow_up_notes || '';
            var charsLeft = 2000 - notes.length;
            var notesHtml = '<textarea class="fi-notes-field" data-lead-id="' + lead.id + '" rows="2" maxlength="2000" placeholder="Add notes...">' + escHtml(notes) + '</textarea>'
                          + '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:3px;">'
                          + '<span class="fi-notes-saved-msg fi-inline-success" data-lead-id="' + lead.id + '" style="font-size:11px;display:none;">✓ Updated</span>'
                          + '<span class="fi-notes-counter" style="font-size:11px;color:#888;margin-left:auto;">' + charsLeft + ' chars left</span>'
                          + '</div>';

            var deleteHtml = '<button type="button" class="button button-small fi-delete-lead-btn" '
                           + 'data-lead-id="' + lead.id + '" '
                           + 'style="color:#b32d2e;border-color:#b32d2e;margin-top:4px;" '
                           + 'title="Delete this lead">'
                           + '🗑 Delete</button>';

            return '<tr data-lead-id="' + lead.id + '">'
                 + '<td class="check-column"><input type="checkbox" class="fi-lead-checkbox" value="' + lead.id + '" /></td>'
                 + '<td>' + businessHtml + '</td>'
                 + '<td>' + escHtml( lead.business_category || 'Unknown' ) + '</td>'
                 + '<td class="fi-score-cell"><span class="fi-score-badge fi-score-' + lead.score_class + '" data-lead-id="' + lead.id + '">' + lead.overall_score + '</span></td>'
                 + '<td><a href="mailto:' + escHtml(lead.visitor_email) + '">' + escHtml(lead.visitor_email) + '</a>' + deleteHtml + '</td>'
                 + '<td>' + escHtml(lead.date_formatted) + '</td>'
                 + '<td>' + selectHtml + '</td>'
                 + '<td>' + notesHtml + '</td>'
                 + '</tr>';
        }

        function escHtml( str ) {
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Pagination controls ───────────────────────────────────────────
        function renderPagination( page, pages, total ) {
            var start = ( page - 1 ) * perPage + 1;
            var end   = Math.min( page * perPage, total );
            $pageInfo.text( total > 0 ? 'Showing ' + start + '–' + end + ' of ' + total + ' leads' : '' );

            $pageButtons.empty();
            if ( pages <= 1 ) return;

            // Prev
            var $prev = $('<button class="button button-small">‹ Prev</button>').prop('disabled', page <= 1);
            $prev.on('click', function() { loadPage( page - 1 ); });
            $pageButtons.append($prev);

            // Page numbers — show up to 7 around current
            var range = [];
            for ( var i = 1; i <= pages; i++ ) {
                if ( i === 1 || i === pages || ( i >= page - 2 && i <= page + 2 ) ) {
                    range.push(i);
                } else if ( range[range.length - 1] !== '…' ) {
                    range.push('…');
                }
            }
            range.forEach(function(p) {
                if ( p === '…' ) {
                    $pageButtons.append('<span style="padding:4px 6px;color:#888;">…</span>');
                } else {
                    var $btn = $('<button class="button button-small">' + p + '</button>');
                    if ( p === page ) $btn.addClass('button-primary');
                    $btn.on('click', (function(pg){ return function(){ loadPage(pg); }; })(p));
                    $pageButtons.append($btn);
                }
            });

            // Next
            var $next = $('<button class="button button-small">Next ›</button>').prop('disabled', page >= pages);
            $next.on('click', function() { loadPage( page + 1 ); });
            $pageButtons.append($next);
        }

        // ── AJAX fetch ────────────────────────────────────────────────────
        function loadPage( page ) {
            currentPage = page;
            // Reset bulk selection state on every page load
            if ( typeof $selectAll !== 'undefined' ) {
                $selectAll.prop('checked', false).prop('indeterminate', false);
            }
            if ( typeof $bulkBar !== 'undefined' ) { $bulkBar.hide(); }
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#888;">Loading…</td></tr>');

            $.ajax({
                url: fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action:   'fi_get_leads',
                    nonce:    fiAdmin.nonce,
                    search:   $search.val(),
                    status:   $filter.val(),
                    page:     page,
                    per_page: perPage,
                    orderby:  currentOrderBy,
                    order:    currentOrder
                },
                success: function( response ) {
                    if ( ! response.success ) {
                        $tbody.html('<tr><td colspan="8" style="color:#dc2626;padding:20px;">Error loading leads.</td></tr>');
                        return;
                    }
                    var data = response.data;
                    totalPages = data.pages;

                    $countLabel.text( data.total + ' lead' + ( data.total !== 1 ? 's' : '' ) );

                    if ( data.leads.length === 0 ) {
                        var msg = $search.val() || $filter.val() !== 'all'
                            ? 'No leads match your search.'
                            : 'No leads yet. Leads appear when visitors request email reports.';
                        $tbody.html('<tr><td colspan="8" style="text-align:center;padding:40px;color:#888;">' + msg + '</td></tr>');
                        $pageInfo.text('');
                        $pageButtons.empty();
                        return;
                    }

                    var html = '';
                    data.leads.forEach(function(lead) { html += buildRow(lead); });
                    $tbody.html(html);

                    // Re-initialise notes original-value tracking for new rows
                    $tbody.find('.fi-notes-field').each(function() {
                        $(this).data('original-value', $(this).val());
                    });

                    renderPagination( page, data.pages, data.total );
                },
                error: function() {
                    $tbody.html('<tr><td colspan="8" style="color:#dc2626;padding:20px;">Request failed. Please reload the page.</td></tr>');
                }
            });
        }

        // ── Event bindings ────────────────────────────────────────────────
        $search.on('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { loadPage(1); }, 350);
        });

        $filter.on('change', function() { loadPage(1); });

        // ── Sortable column headers ────────────────────────────────────────
        // Clicking a sortable <th> sets or toggles the sort, then reloads page 1.
        $(document).on('click', '.fi-sortable-col', function() {
            var col = $(this).data('col');
            if ( col === currentOrderBy ) {
                // Same column — flip direction
                currentOrder = ( currentOrder === 'DESC' ) ? 'ASC' : 'DESC';
            } else {
                // New column — default to DESC (highest first for score/date)
                currentOrderBy = col;
                currentOrder   = 'DESC';
            }
            // Update visual indicators
            $('.fi-sortable-col').removeClass('fi-sort-active fi-sort-asc fi-sort-desc');
            $(this).addClass('fi-sort-active')
                   .addClass( currentOrder === 'ASC' ? 'fi-sort-asc' : 'fi-sort-desc' );
            loadPage(1);
        });

        // Initial load
        loadPage(1);
    })();

    // ── Lead management (v1.6.0) ─────────────────────────────────────────

        // ══ Modal state management ═══════════════════════════════════════════
        var modalState = {
            originalBodyOverflow: '',
            originalBodyPosition: '',
            originalHtmlOverflow: ''
        };

        function closeReportModal() {
            var $modal = $('#fi-report-modal');
            $modal.fadeOut(200, function() {
                $('body').css({
                    'overflow': modalState.originalBodyOverflow,
                    'position': modalState.originalBodyPosition
                });
                $('html').css({ 'overflow': modalState.originalHtmlOverflow });
                $('#fi-report-body').html('');
            });
        }

        // Status dropdown change
        $(document).on('change', '.fi-status-select', function() {
            const $select = $(this);
            const leadId  = $select.data('lead-id');
            const newStatus = $select.val();
            const $row = $select.closest('tr');

            $select.prop('disabled', true);

            $.ajax({
                url: fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fi_update_lead_status',
                    nonce: fiAdmin.nonce,
                    lead_id: leadId,
                    status: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        $row.addClass('fi-status-updated').delay(300).queue(function(next) {
                            $(this).removeClass('fi-status-updated');
                            next();
                        });
                        const $message = $('<span class="fi-inline-success">✓ Updated</span>');
                        $select.after($message);
                        setTimeout(function() {
                            $message.fadeOut(function() { $(this).remove(); });
                        }, 2000);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                        location.reload();
                    }
                },
                error: function() {
                    alert('Failed to update status. Please try again.');
                    location.reload();
                },
                complete: function() {
                    $select.prop('disabled', false);
                }
            });
        });

        // ── Bulk actions ──────────────────────────────────────────────────────
        var $selectAll    = $('#fi-select-all');
        var $bulkBar      = $('#fi-bulk-bar');
        var $bulkCount    = $('#fi-bulk-count');
        var $bulkStatus   = $('#fi-bulk-status');
        var $bulkApply    = $('#fi-bulk-apply');
        var $bulkDeselect = $('#fi-bulk-deselect');

        function getCheckedIds() {
            return $('#fi-leads-tbody').find('.fi-lead-checkbox:checked').map(function() {
                return parseInt($(this).val(), 10);
            }).get();
        }

        function updateBulkBar() {
            var $leadsTable = $('#fi-leads-tbody');
            var ids = getCheckedIds();
            if ( ids.length > 0 ) {
                $bulkCount.text( ids.length + ' lead' + (ids.length > 1 ? 's' : '') + ' selected' );
                $bulkBar.css('display', 'flex');
            } else {
                $bulkBar.hide();
                $bulkStatus.val('');
            }
            // Sync select-all checkbox state
            var total = $leadsTable.find('.fi-lead-checkbox').length;
            $selectAll.prop('indeterminate', ids.length > 0 && ids.length < total);
            $selectAll.prop('checked', total > 0 && ids.length === total);
        }

        // Select-all toggle
        $selectAll.on('change', function() {
            $('#fi-leads-tbody').find('.fi-lead-checkbox').prop('checked', this.checked);
            updateBulkBar();
        });

        // Individual checkbox
        $(document).on('change', '.fi-lead-checkbox', function() {
            updateBulkBar();
        });

        // Deselect all
        $bulkDeselect.on('click', function() {
            $('#fi-leads-tbody').find('.fi-lead-checkbox').prop('checked', false);
            $selectAll.prop('checked', false).prop('indeterminate', false);
            updateBulkBar();
        });

        // Apply bulk status
        $bulkApply.on('click', function() {
            var ids    = getCheckedIds();
            var status = $bulkStatus.val();
            if ( ids.length === 0 || ! status ) {
                if ( ! status ) { alert('Please choose a status to apply.'); }
                return;
            }

            $bulkApply.prop('disabled', true).text('Applying…');

            $.ajax({
                url: fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action:   'fi_bulk_update_leads',
                    nonce:    fiAdmin.nonce,
                    lead_ids: ids,
                    status:   status
                },
                success: function(response) {
                    if ( response.success ) {
                        // Update the dropdowns in-place for each updated row
                        var $leadsBody = $('#fi-leads-tbody');
                        ids.forEach(function(id) {
                            $leadsBody.find('.fi-status-select[data-lead-id="' + id + '"]').val(status);
                            $leadsBody.find('.fi-lead-checkbox[value="' + id + '"]').prop('checked', false);
                        });
                        updateBulkBar();
                        $bulkStatus.val('');
                        // Flash a brief confirmation in the toolbar
                        var $msg = $('<span style="color:#00a32a;font-size:13px;font-weight:600;">✓ ' + response.data.message + '</span>');
                        $bulkBar.append($msg);
                        setTimeout(function() { $msg.fadeOut(function() { $(this).remove(); }); }, 3000);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() { alert('Bulk update failed. Please try again.'); },
                complete: function() { $bulkApply.prop('disabled', false).text('Apply'); }
            });
        });

        // Note: loadPage lives inside its own IIFE (the All Leads table block above).
        // Bulk-bar reset on page change is already handled inside that IIFE's loadPage
        // via the typeof $bulkBar / $selectAll guards.

        // ── Delete lead ───────────────────────────────────────────────────────
        $(document).on('click', '.fi-delete-lead-btn', function() {
            var leadId = $(this).data('lead-id');
            if ( !confirm('Delete this lead? This cannot be undone.') ) { return; }
            var $btn = $(this).prop('disabled', true).text('Deleting…');
            $.post(fiAdmin.ajaxUrl, {
                action:  'fi_delete_lead',
                nonce:   fiAdmin.nonce,
                lead_id: leadId
            }, function(response) {
                if (response.success) {
                    $('tr[data-lead-id="' + leadId + '"]').fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert('Error: ' + (response.data.message || 'Unknown error'));
                    $btn.prop('disabled', false).text('🗑 Delete');
                }
            }).fail(function() {
                alert('Delete failed. Please try again.');
                $btn.prop('disabled', false).text('🗑 Delete');
            });
        });

        // ── Bulk delete leads ──────────────────────────────────────────────────
        var $bulkDeleteBtn = $('#fi-bulk-delete-leads');
        if ($bulkDeleteBtn.length) {
            $bulkDeleteBtn.on('click', function() {
                var ids = getCheckedIds();
                if (!ids.length) { return; }
                if (!confirm('Delete ' + ids.length + ' selected lead(s)? This cannot be undone.')) { return; }
                $bulkDeleteBtn.prop('disabled', true).text('Deleting…');
                $.post(fiAdmin.ajaxUrl, {
                    action:   'fi_bulk_delete_leads',
                    nonce:    fiAdmin.nonce,
                    lead_ids: ids
                }, function(response) {
                    if (response.success) {
                        ids.forEach(function(id) {
                            $('tr[data-lead-id="' + id + '"]').fadeOut(300, function() { $(this).remove(); });
                        });
                        updateBulkBar();
                        var $msg = $('<span style="color:#00a32a;font-size:13px;font-weight:600;">✓ ' + response.data.message + '</span>');
                        $bulkBar.append($msg);
                        setTimeout(function() { $msg.fadeOut(function() { $(this).remove(); }); }, 3000);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                }).always(function() { $bulkDeleteBtn.prop('disabled', false).text('Delete Selected'); });
            });
        }

        // ── Notes save — autosave on blur, mirrors status-dropdown flash UX ──
        // Track in-flight AJAX per lead to prevent concurrent saves.
        var notesSaving = {};

        function saveNotes(leadId, notes) {
            if (notesSaving[leadId]) {
                return; // already in flight for this lead
            }
            notesSaving[leadId] = true;

            $.ajax({
                url: fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fi_update_lead_notes',
                    nonce: fiAdmin.nonce,
                    lead_id: leadId,
                    notes: notes
                },
                success: function(response) {
                    if (response.success) {
                        // Same pattern as status dropdown: brief inline flash, then fade
                        var $msg = $('.fi-notes-saved-msg[data-lead-id="' + leadId + '"]');
                        $msg.stop(true, true).show();
                        setTimeout(function() { $msg.fadeOut(400); }, 2000);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to save notes. Please try again.');
                },
                complete: function() {
                    notesSaving[leadId] = false;
                }
            });
        }

        // Autosave on blur — only when content changed, only if not already saving.
        $(document).on('blur', '.fi-notes-field', function() {
            const $textarea     = $(this);
            const leadId        = $textarea.data('lead-id');
            const originalValue = $textarea.data('original-value') || '';
            const currentValue  = $textarea.val();
            if (currentValue !== originalValue && !notesSaving[leadId]) {
                $textarea.data('original-value', currentValue);
                saveNotes(leadId, currentValue);
            }
        });

        // ── Report Viewer Modal (v2.0.2) ─────────────────────────────────────
        // The stored snapshot is a fully self-contained HTML document — it needs
        // no style injection. We just write it into a sandboxed iframe directly.

        $(document).on('click', '.fi-view-report', function() {
            const $button = $(this);
            const leadId  = $button.data('lead-id');
            const $modal  = $('#fi-report-modal');
            const $body   = $('#fi-report-body');

            modalState.originalBodyOverflow = $('body').css('overflow');
            modalState.originalBodyPosition = $('body').css('position');
            modalState.originalHtmlOverflow = $('html').css('overflow');

            $('body').css('overflow', 'hidden');
            $('html').css('overflow', 'hidden');

            $modal.fadeIn(200);
            $body.html('<div class="fi-loading">Loading report…</div>');

            $.ajax({
                url: fiAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'fi_view_report', nonce: fiAdmin.nonce, lead_id: leadId },
                success: function(response) {
                    if (!response.success) {
                        $body.html('<div style="color:#dc2626;text-align:center;padding:40px;">Error: ' +
                            (response.data.message || 'Failed to load report') + '</div>');
                        return;
                    }

                    $('#fi-report-title').text(response.data.business_name + ' — Report');

                    const generatedDate = response.data.generated_at
                        ? new Date(response.data.generated_at).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' })
                        : 'Unknown date';

                    // ── Build plain-text for clipboard ────────────────────────
                    // Parse the snapshot so we can extract clean text without HTML noise
                    const parser  = new DOMParser();
                    const snapDoc = parser.parseFromString(response.data.html, 'text/html');

                    function buildPlainText() {
                        const lines = [];
                        const name  = (snapDoc.querySelector('h1') || {}).textContent || response.data.business_name;
                        lines.push(name.trim().toUpperCase());
                        lines.push('Business Insights Report — ' + generatedDate);
                        lines.push('='.repeat(62));
                        lines.push('');

                        // Score
                        const scoreNum = snapDoc.querySelector('.rpt-score-circle-num, .score-number');
                        const scoreLabel = snapDoc.querySelector('.rpt-score-label, .score-label');
                        if (scoreNum) {
                            lines.push('OVERALL SCORE: ' + scoreNum.textContent.trim() + '/100' +
                                (scoreLabel ? ' — ' + scoreLabel.textContent.trim().replace(/[^\w\s—-]/g,'').trim() : ''));
                            lines.push('');
                        }

                        // Walk sections
                        const sections = snapDoc.querySelectorAll('.rpt-section, .section');
                        sections.forEach(function(section) {
                            const title = section.querySelector('.rpt-section-title, .section-title, h2');
                            if (title) {
                                const t = title.textContent.trim().replace(/[^\w\s:!?—-]/g,'').trim();
                                lines.push(t.toUpperCase());
                                lines.push('-'.repeat(Math.min(t.length, 50)));
                            }
                            // Strengths
                            section.querySelectorAll('.rpt-strength-item, .strength-item').forEach(function(el) {
                                lines.push('  ✓ ' + el.textContent.trim());
                            });
                            // Actions
                            section.querySelectorAll('.rpt-action-item, .action-item').forEach(function(el) {
                                const at = el.querySelector('.rpt-action-title, .action-title');
                                const ad = el.querySelector('.rpt-action-desc');
                                if (at) lines.push('  • ' + at.textContent.replace(/^\d+/,'').trim());
                                if (ad) lines.push('    ' + ad.textContent.trim());
                            });
                            // Insight items
                            section.querySelectorAll('.rpt-insight-item, .insight-item').forEach(function(el) {
                                const iname  = el.querySelector('.rpt-insight-name, .insight-name');
                                const iscore = el.querySelector('.rpt-insight-score, .insight-score');
                                const isum   = el.querySelector('.rpt-insight-summary, .insight-summary');
                                if (iname) {
                                    lines.push('');
                                    lines.push('  ' + (iname.textContent.trim()) +
                                        (iscore ? '  [' + iscore.textContent.trim() + ']' : ''));
                                }
                                if (isum) lines.push('  ' + isum.textContent.trim());
                                el.querySelectorAll('.rpt-insight-recs li, ul li').forEach(function(li) {
                                    lines.push('    → ' + li.textContent.trim());
                                });
                            });
                            lines.push('');
                        });

                        lines.push('='.repeat(62));
                        lines.push('Report generated by F! Insights');
                        return lines.join('\n');
                    }

                    const plainText = buildPlainText();

                    // ── Toolbar ───────────────────────────────────────────────
                    const $toolbar = $('<div class="fi-report-toolbar">'
                        + '<span class="fi-report-meta">Generated: <strong>' + generatedDate + '</strong></span>'
                        + '<button type="button" class="button button-primary fi-copy-report-btn">📋 Copy to Clipboard</button>'
                        + '</div>');

                    $toolbar.find('.fi-copy-report-btn').on('click', function() {
                        const $btn = $(this);
                        if ( ! ( navigator.clipboard && navigator.clipboard.writeText ) ) {
                            $btn.text('⚠ Copy unavailable (HTTPS required)');
                            setTimeout(function() { $btn.text('📋 Copy to Clipboard'); }, 2500);
                            return;
                        }
                        navigator.clipboard.writeText(plainText)
                            .then(function() {
                                $btn.text('✓ Copied!').addClass('fi-copy-success');
                                setTimeout(function() { $btn.text('📋 Copy to Clipboard').removeClass('fi-copy-success'); }, 2500);
                            })
                            .catch(function() {
                                $btn.text('⚠ Copy failed — check browser permissions');
                                setTimeout(function() { $btn.text('📋 Copy to Clipboard'); }, 2500);
                            });
                    });

                    // ── Sandboxed iframe — write snapshot HTML directly ────────
                    // sandbox="allow-same-origin" lets the snapshot's embedded CSS
                    // render correctly while blocking all script execution.
                    const iframe = document.createElement('iframe');
                    iframe.setAttribute('sandbox', 'allow-same-origin');
                    iframe.className = 'fi-report-iframe';

                    $body.empty().append($toolbar).append(iframe);

                    const iDoc = iframe.contentDocument || iframe.contentWindow.document;
                    iDoc.open();
                    iDoc.write(response.data.html);
                    iDoc.close();

                    iframe.onload = function() {
                        try {
                            const h = iframe.contentDocument.body.scrollHeight;
                            if (h > 0) iframe.style.height = (h + 20) + 'px';
                        } catch(e) {}
                    };
                },
                error: function() {
                    $body.html('<div style="color:#dc2626;text-align:center;padding:40px;">Failed to load report. Please try again.</div>');
                }
            });
        });

        $('.fi-modal-close, .fi-modal-overlay').on('click', function() { closeReportModal(); });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#fi-report-modal').is(':visible')) { closeReportModal(); }
        });

        // ── API key show/hide toggle ──────────────────────────────────────
        $(document).on('click', '.fi-toggle-key', function() {
            var $btn    = $(this);
            var target  = $btn.data('target');
            var $input  = $('#' + target);
            var val     = $input.val();
            var isHidden = $input.attr('type') === 'password';
            var newType  = isHidden ? 'text' : 'password';
            $input.replaceWith(
                $('<input>')
                    .attr({ type: newType, id: target, name: target, class: $input.attr('class'), autocomplete: 'off' })
                    .val(val)
            );
            $btn.text( isHidden ? 'Hide' : 'Show' );
        });

        // ── API key test connection ───────────────────────────────────────
        $(document).on('click', '.fi-test-key', function() {
            var $btn    = $(this);
            var $result = $btn.siblings('.fi-test-result');
            var field   = $btn.data('key-field');
            var action  = $btn.data('action');
            var key     = $('#' + field).val().trim();

            if ( ! key ) {
                $result.css('color', '#b32d2e').text('⚠ Enter a key first.');
                return;
            }

            $btn.prop('disabled', true).text('Testing…');
            $result.text('');

            $.ajax({
                url:  fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce:  fiAdmin.nonce,
                    key:    key,
                    model:  $('#fi_claude_model').val() || 'claude-haiku-4-5-20251001',
                },
                success: function( response ) {
                    if ( response.success ) {
                        $result.css('color', '#00a32a').text('✓ ' + response.data.message);
                    } else {
                        $result.css('color', '#b32d2e').text('✗ ' + response.data.message);
                    }
                },
                error: function() {
                    $result.css('color', '#b32d2e').text('✗ Request failed.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Test Connection');
                }
            });
        });


        // ── Reset tab to defaults ─────────────────────────────────────────────
        $(document).on('click', '.fi-reset-tab-defaults', function() {
            var $btn  = $(this);
            var $msg  = $btn.siblings('.fi-reset-tab-msg');
            var tab   = $btn.data('tab');
            var nonce = $btn.data('nonce');

            if ( ! confirm( 'Reset all settings on this tab to their defaults? This cannot be undone.' ) ) {
                return;
            }

            $btn.prop('disabled', true).text('Resetting...');
            $msg.hide().text('');

            $.ajax({
                url:  fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fi_reset_tab_defaults',
                    nonce:  nonce,
                    tab:    tab,
                },
                success: function( response ) {
                    if ( response.success ) {
                        $msg.css('color', '#00a32a').text('Done! ' + response.data.message).show();
                        setTimeout(function() { window.location.reload(); }, 1200);
                    } else {
                        $msg.css('color', '#b32d2e').text('Error: ' + response.data.message).show();
                        $btn.prop('disabled', false).text('Reset to Defaults');
                    }
                },
                error: function() {
                    $msg.css('color', '#b32d2e').text('Request failed.').show();
                    $btn.prop('disabled', false).text('Reset to Defaults');
                }
            });
        });

        // ── Send Test Email ──────────────────────────────────────────────────
        $(document).on('click', '#fi-send-test-email', function() {
            var $btn = $(this);
            var $msg = $('#fi-test-email-msg');

            $btn.prop('disabled', true).text('Sending…');
            $msg.hide().text('');

            $.ajax({
                url:  fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fi_send_test_email',
                    nonce:  $btn.data('nonce'),
                },
                success: function( response ) {
                    if ( response.success ) {
                        $msg.css('color', '#00a32a').text('✓ ' + response.data.message).show();
                    } else {
                        $msg.css('color', '#b32d2e').text('✗ ' + response.data.message).show();
                    }
                },
                error: function() {
                    $msg.css('color', '#b32d2e').text('✗ Request failed. Check your internet connection.').show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text('✉️ Send Test Email');
                }
            });
        });

        // ── Clear cache: confirm before wiping (item 4) ──────────────────────
        $(document).on('click', '.fi-clear-cache-link', function(e) {
            if ( ! confirm('This will delete all cached scan data and the next scans will make fresh API calls. Continue?') ) {
                e.preventDefault();
            }
        });

        // ── Reset All Analytics: enable submit only when field contains RESET ────
        function checkResetConfirm() {
            var typed = $('#fi-reset-confirm-input').val();
            $('#fi-reset-analytics-btn').prop('disabled', typed !== 'RESET');
        }
        // 'input' covers typed characters; 'keyup' + 'change' cover edge cases;
        // paste value isn't in the DOM yet when the paste event fires, so defer.
        $(document).on('input keyup change', '#fi-reset-confirm-input', checkResetConfirm);
        $(document).on('paste', '#fi-reset-confirm-input', function() {
            setTimeout(checkResetConfirm, 0);
        });
        // Run on load in case browser session-restores the field value.
        checkResetConfirm();

        // ── Rate limiting: disable sub-fields when the master checkbox is off ──
        function updateRateLimitFields() {
            var enabled = $('#fi_rate_limit_enabled').is(':checked');
            $('#fi_rate_limit_per_ip, #fi_rate_limit_window').prop('disabled', !enabled)
                .closest('tr').css('opacity', enabled ? '' : '0.45');
        }
        if ( $('#fi_rate_limit_enabled').length ) {
            updateRateLimitFields();
            $('#fi_rate_limit_enabled').on('change', updateRateLimitFields);
        }

        // ── Cache duration: live human-readable label ─────────────────────────
        function updateCacheLabel() {
            var secs = parseInt( $('#fi_cache_duration').val(), 10 ) || 0;
            var label = '';
            if ( secs === 0 )              { label = '— caching disabled'; }
            else if ( secs < 3600 )        { label = '= ' + Math.round(secs / 60) + ' minutes'; }
            else if ( secs % 86400 === 0 ) { label = '= ' + (secs / 86400) + ' day' + (secs / 86400 !== 1 ? 's' : ''); }
            else                           { label = '= ' + (secs / 3600).toFixed(1).replace(/\.0$/, '') + ' hours'; }
            $('#fi-cache-duration-label').text(label);
        }
        if ( $('#fi_cache_duration').length ) {
            $('#fi_cache_duration').after('<span id="fi-cache-duration-label" style="margin-left:8px;font-weight:600;color:#646970;font-size:13px;"></span>');
            updateCacheLabel();
            $('#fi_cache_duration').on('input change', updateCacheLabel);
        }

        // ── Notification email inline validation (item 11) ───────────────────
        $('#fi_lead_notification_email').on('blur', function() {
            var $input = $(this);
            var val    = $input.val().trim();
            var $msg   = $input.siblings('.fi-email-validation-msg');
            if ( ! $msg.length ) {
                $msg = $('<span class="fi-email-validation-msg" style="display:block;margin-top:4px;font-size:12px;"></span>');
                $input.after($msg);
            }
            if ( val === '' ) {
                $msg.text('').hide();
                return;
            }
            var valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            if (valid) {
                $msg.css('color', '#065f46').text('✓ Looks good').show();
            } else {
                $msg.css('color', '#991b1b').text('⚠ Please enter a valid email address — notifications will not send with an invalid address.').show();
            }
        });

        // ── Cache duration: friendly preset selector ──────────────────────────
        var $cachePreset  = $('#fi_cache_duration_preset');
        var $cacheCustom  = $('#fi-cache-custom-wrap');
        var $cacheInput   = $('#fi_cache_duration');
        var $cacheHidden  = $('#fi_cache_duration_hidden');

        if ( $cachePreset.length ) {
            $cachePreset.on('change', function() {
                var val = $(this).val();
                if ( val === 'custom' ) {
                    $cacheCustom.show();
                } else {
                    $cacheCustom.hide();
                    $cacheHidden.val(val);
                }
            });
            $cacheInput.on('input change', function() {
                if ( $cachePreset.val() === 'custom' ) {
                    $cacheHidden.val( $(this).val() );
                }
            });
        }

        // ── Rate limit window: live human-readable label ──────────────────────
        var $rateWindow = $('#fi_rate_limit_window');
        var $rateLabel  = $('#fi-rate-window-label');

        function updateRateWindowLabel() {
            var secs = parseInt( $rateWindow.val(), 10 );
            if ( isNaN(secs) || secs <= 0 ) { $rateLabel.text(''); return; }
            var label;
            if ( secs >= 86400 && secs % 86400 === 0 ) {
                var d = secs / 86400;
                label = '= ' + d + ' ' + (d === 1 ? 'day' : 'days');
            } else if ( secs >= 3600 && secs % 3600 === 0 ) {
                var h = secs / 3600;
                label = '= ' + h + ' ' + (h === 1 ? 'hour' : 'hours');
            } else if ( secs >= 60 && secs % 60 === 0 ) {
                var m = secs / 60;
                label = '= ' + m + ' ' + (m === 1 ? 'minute' : 'minutes');
            } else {
                label = '= ' + secs + ' seconds';
            }
            $rateLabel.text( label );
        }

        if ( $rateWindow.length ) {
            $rateWindow.on( 'input change', updateRateWindowLabel );
        }

        // ── Logo URL: WordPress media library picker ──────────────────────────
        $('#fi-logo-media-btn').on('click', function(e) {
            e.preventDefault();
            if ( typeof wp === 'undefined' || ! wp.media ) {
                alert('WordPress media library not available. Enter a URL manually.');
                return;
            }
            var frame = wp.media({
                title: 'Select Logo',
                button: { text: 'Use this logo' },
                library: { type: ['image'] },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#fi_wl_logo_url').val(attachment.url);
                var $wrap = $('#fi-logo-preview-wrap');
                var $img  = $('#fi-logo-preview');
                $img.attr('src', attachment.url);
                $wrap.show();
            });
            frame.open();
        });

        // Update logo preview when URL is typed manually
        $('#fi_wl_logo_url').on('blur', function() {
            var url = $(this).val().trim();
            var $wrap = $('#fi-logo-preview-wrap');
            var $img  = $('#fi-logo-preview');
            if ( url ) {
                $img.attr('src', url);
                $wrap.show();
            } else {
                $wrap.hide();
            }
        });

        // ── Notes textarea: live character counter ────────────────────────────
        $(document).on('input', '.fi-notes-field', function() {
            var remaining = 2000 - $(this).val().length;
            $(this).closest('td').find('.fi-notes-counter').text( remaining + ' chars left' )
                .css('color', remaining < 100 ? '#dc2626' : '#888');
        });
        var $logBox = $('.fi-log-viewer');
        if ( $logBox.length ) {
            // Auto-scroll is ON by default — jump to the newest (bottom) entry.
            var autoScroll = true;
            $logBox[0].scrollTop = $logBox[0].scrollHeight;

            // Toggle button
            $(document).on('click', '#fi-log-scroll-toggle', function() {
                autoScroll = ! autoScroll;
                var $btn = $(this);
                if ( autoScroll ) {
                    $btn.addClass('fi-log-scroll-active').html('⬇ Scroll to bottom');
                    $logBox[0].scrollTop = $logBox[0].scrollHeight;
                } else {
                    $btn.removeClass('fi-log-scroll-active').html('⬆ Scroll to top');
                    $logBox[0].scrollTop = 0;
                }
            });

            // Level filter buttons
            $(document).on('click', '.fi-log-filter', function() {
                var level = $(this).data('level');
                $('.fi-log-filter').removeClass('fi-log-filter-active');
                $(this).addClass('fi-log-filter-active');

                var $lines = $logBox.find('.fi-log-line');
                if (level === 'all') {
                    $lines.show();
                } else {
                    $lines.each(function() {
                        var lineLevel = $(this).data('level');
                        // API filter matches both API_REQUEST and API_RESPONSE
                        var match = (level === 'API') ? lineLevel === 'API' : lineLevel === level;
                        $(this).toggle(match);
                    });
                }
                // After filtering, re-apply scroll position based on toggle state
                if ( autoScroll ) {
                    $logBox[0].scrollTop = $logBox[0].scrollHeight;
                } else {
                    $logBox[0].scrollTop = 0;
                }
            });
        }

        // ── Chart.js initialization ───────────────────────────────────────────
        // Charts are initialised here (in the footer, after Chart.js has loaded)
        // rather than in an inline PHP <script> block which fired mid-page before
        // Chart.js was available — that was the root cause of the blank charts.
        if ( typeof Chart !== 'undefined' && typeof window.fiChartData !== 'undefined' ) {
            var cd         = window.fiChartData;
            var gridColor  = 'rgba(0,0,0,0.06)';
            var tickColor  = '#646970';

            // 30-day trend
            var trendCtx = document.getElementById('fi-trend-chart');
            if ( trendCtx && cd.trend ) {
                new Chart( trendCtx, {
                    type: 'bar',
                    data: {
                        labels: cd.trend.labels,
                        datasets: [{ label: 'Scans', data: cd.trend.values, backgroundColor: 'rgba(34,113,177,0.7)', borderRadius: 3, borderSkipped: false }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { color: tickColor, maxTicksLimit: 10, maxRotation: 0 }, grid: { color: gridColor } },
                            y: { ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor }, beginAtZero: true }
                        }
                    }
                });
            }

            // Score trend (monthly, 6 months)
            var scoreTrendCtx = document.getElementById('fi-score-trend-chart');
            if ( scoreTrendCtx && cd.scoreTrend && cd.scoreTrend.labels.length > 1 ) {
                new Chart( scoreTrendCtx, {
                    type: 'line',
                    data: {
                        labels: cd.scoreTrend.labels,
                        datasets: [{ label: 'Avg Score', data: cd.scoreTrend.values, borderColor: '#2271b1', backgroundColor: 'rgba(34,113,177,0.1)', tension: 0.3, fill: true, pointRadius: 4, pointHoverRadius: 6 }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { color: tickColor }, grid: { color: gridColor } },
                            y: { min: 0, max: 100, ticks: { color: tickColor }, grid: { color: gridColor } }
                        }
                    }
                });
            }

            // Score distribution
            var distCtx = document.getElementById('fi-dist-chart');
            if ( distCtx && cd.dist ) {
                new Chart( distCtx, {
                    type: 'bar',
                    data: {
                        labels: cd.dist.labels,
                        datasets: [{ label: 'Scans', data: cd.dist.values, backgroundColor: ['#dc3232','#e65c00','#f0b429','#2271b1','#00a32a'], borderRadius: 3, borderSkipped: false }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { color: tickColor }, grid: { display: false } },
                            y: { ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor }, beginAtZero: true }
                        }
                    }
                });
            }
        }

        // ── AI Market Intelligence ────────────────────────────────────────────
        var intelCostMap = {
            'claude-haiku-4-5-20251001': '~$0.01–$0.02',
            'claude-sonnet-4-20250514':  '~$0.03–$0.06',
            'claude-opus-4-20250514':    '~$0.12–$0.25'
        };
        var intelModelLabels = {
            'claude-haiku-4-5-20251001': 'Haiku',
            'claude-sonnet-4-20250514':  'Sonnet',
            'claude-opus-4-20250514':    'Opus'
        };

        // Update cost note whenever model changes, and persist preference to DB
        $('#fi-intel-model').on('change', function() {
            var model = $(this).val();
            var cost  = intelCostMap[ model ] || '~$0.03–$0.06';
            var label = intelModelLabels[ model ] || model;
            $('#fi-intel-cost-note').html(
                'Est. cost: <strong>' + cost + '</strong> using ' + label + '. Uses your Claude API credits — not a fricking.website charge.'
            );
            // Persist to DB so Settings > API Configuration stays in sync
            $.post( fiAdmin.ajaxUrl, {
                action: 'fi_save_intel_model',
                nonce:  fiAdmin.nonce,
                model:  model
            } );
        });

        $('#fi-run-market-intel').on('click', function() {
            var $btn      = $(this);
            var $output   = $('#fi-intel-output');
            var $text     = $('#fi-intel-text');
            var $meta     = $('#fi-intel-meta');
            var $loading  = $('#fi-intel-loading');
            var focus     = $('#fi-intel-focus').val();
            var industry  = $('#fi-intel-industry').val();
            var score     = $('#fi-intel-score').val();
            var window_   = $('#fi-intel-window').val();
            var model     = $('#fi-intel-model').val();

            $btn.prop('disabled', true).text('Thinking…');
            $output.hide();
            $loading.show();
            $text.text('');
            $meta.text('');

            $.ajax({
                url:  fiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action:   'fi_market_intel',
                    nonce:    fiAdmin.nonce,
                    focus:    focus,
                    industry: industry,
                    score:    score,
                    window:   window_,
                    model:    model,
                },
                timeout: 60000,
                success: function(response) {
                    $loading.hide();
                    if ( response.success ) {
                        $text.text( response.data.intel );
                        $meta.html(
                            'Based on <strong>' + response.data.scans + '</strong> scan' + ( response.data.scans !== 1 ? 's' : '' ) +
                            ' · ' + response.data.filters +
                            ' · model: ' + response.data.model +
                            ' · ' + new Date().toLocaleTimeString()
                        );
                        $output.slideDown(200);
                    } else {
                        $text.text( '⚠ ' + ( response.data.message || 'Analysis failed.' ) );
                        $output.slideDown(200);
                    }
                },
                error: function() {
                    $loading.hide();
                    $text.text('⚠ Request timed out or failed. Try again.');
                    $output.slideDown(200);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('▶ Run Analysis');
                }
            });
        });


    // ── Divi-style icon picker ───────────────────────────────────────────────

    function fiIconPickerSelect( $wrap, iconClass, label ) {
        var field    = $wrap.data( 'field' );
        var $trigger = $wrap.find( '.fi-icon-trigger' );

        $( '#' + field ).val( iconClass );

        if ( iconClass ) {
            $trigger.find( '.fi-icon-trigger-icon' ).html( '<i class="' + iconClass + '"></i>' );
            $trigger.find( '.fi-icon-trigger-label' ).text( label );
        } else {
            $trigger.find( '.fi-icon-trigger-icon' ).html( '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v8M8 12h8"/></svg>' );
            $trigger.find( '.fi-icon-trigger-label' ).text( 'Choose Icon' );
        }

        $wrap.find( '.fi-icon-clear' ).remove();
        if ( iconClass ) {
            $trigger.after( '<button type="button" class="fi-icon-clear" title="Clear icon"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>' );
        }

        $wrap.find( '.fi-icon-item' ).removeClass( 'fi-icon-item-selected' );
        if ( iconClass ) {
            $wrap.find( '.fi-icon-item[data-icon="' + CSS.escape( iconClass ) + '"]' ).addClass( 'fi-icon-item-selected' );
        }
    }

    function fiIconPickerOpen( $wrap ) {
        $( '.fi-icon-picker-wrap.fi-picker-open' ).not( $wrap ).each( function() {
            fiIconPickerClose( $( this ) );
        } );
        $wrap.addClass( 'fi-picker-open' );
        $wrap.find( '.fi-icon-popover' ).removeAttr( 'hidden' );
        $wrap.find( '.fi-icon-search-input' ).val( '' ).trigger( 'input' ).focus();
    }

    function fiIconPickerClose( $wrap ) {
        $wrap.removeClass( 'fi-picker-open' );
        $wrap.find( '.fi-icon-popover' ).attr( 'hidden', '' );
    }

    // Trigger toggle
    $( document ).on( 'mousedown', '.fi-icon-trigger', function ( e ) {
        e.preventDefault();
        var $wrap = $( this ).closest( '.fi-icon-picker-wrap' );
        if ( $wrap.hasClass( 'fi-picker-open' ) ) {
            fiIconPickerClose( $wrap );
        } else {
            fiIconPickerOpen( $wrap );
        }
    } );

    // Clear button
    $( document ).on( 'mousedown', '.fi-icon-clear', function ( e ) {
        e.preventDefault();
        var $wrap = $( this ).closest( '.fi-icon-picker-wrap' );
        fiIconPickerSelect( $wrap, '', '' );
    } );

    // Select icon — mousedown fires before document click, so popover stays open long enough
    $( document ).on( 'mousedown', '.fi-icon-item', function ( e ) {
        e.preventDefault();
        var $item = $( this );
        var $wrap = $item.closest( '.fi-icon-picker-wrap' );
        fiIconPickerSelect( $wrap, $item.data( 'icon' ), $item.attr( 'title' ) );
        fiIconPickerClose( $wrap );
    } );

    // Live search filter
    $( document ).on( 'input', '.fi-icon-search-input', function () {
        var q      = $( this ).val().toLowerCase().trim();
        var $grid  = $( this ).closest( '.fi-icon-popover' ).find( '.fi-icon-grid' );
        var $items = $grid.find( '.fi-icon-item' );
        var $none  = $grid.find( '.fi-icon-no-results' );
        var visible = 0;

        $items.each( function () {
            var match = ! q || $( this ).data( 'label' ).indexOf( q ) !== -1;
            $( this ).prop( 'hidden', ! match );
            if ( match ) visible++;
        } );

        if ( visible === 0 ) {
            if ( ! $none.length ) {
                $grid.append( '<div class="fi-icon-no-results">No icons match \"' + $( '<span>' ).text( q ).html() + '\"</div>' );
            }
        } else {
            $none.remove();
        }
    } );

    // Close when clicking outside the picker entirely
    $( document ).on( 'click', function ( e ) {
        if ( ! $( e.target ).closest( '.fi-icon-picker-wrap' ).length ) {
            $( '.fi-icon-picker-wrap.fi-picker-open' ).each( function () {
                fiIconPickerClose( $( this ) );
            } );
        }
    } );

    // Escape closes
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) {
            $( '.fi-icon-picker-wrap.fi-picker-open' ).each( function () {
                fiIconPickerClose( $( this ) );
            } );
        }
    } );

}); // end jQuery wrapper