<?php

use ExternalModules\ExternalModules;

ExternalModules::requireDesignRights();

?>
<div class="legacy-url-fixer" style="max-width: 960px">
    <h4><i class="fas fa-link"></i> Fix legacy image/file URLs</h4>

    <p>
        This scans authored project configuration for static <code>DataEntry/image_view.php</code>
        and <code>DataEntry/file_download.php</code> URLs, including survey passthru URLs. It classifies
        both legacy and current document hashes; only verified legacy URLs can be repaired. The scan is
        cached only as row locators and content fingerprints; it does not cache the text being scanned.
    </p>

    <div class="alert alert-warning">
        <strong>Scope:</strong> This does not inspect record data or historical/sent messages. In a production
        project, data-dictionary changes are made only to <code>redcap_metadata_temp</code> while the project
        is in Draft Mode; <code>redcap_metadata</code> is never updated by this module for a production project.
    </div>

    <p>
        <button type="button" id="legacy-url-scan" class="btn btn-primaryrc">
            <i class="fas fa-search"></i> Scan project
        </button>
        <button type="button" id="legacy-url-details" class="btn btn-secondary" disabled>
            <i class="fas fa-list"></i> Show scan details
        </button>
        <button type="button" id="legacy-url-apply" class="btn btn-danger" disabled>
            <i class="fas fa-wrench"></i> Fix all scanned URLs
        </button>
        <span id="legacy-url-progress" class="ms-2 text-muted" aria-live="polite"></span>
    </p>

    <div id="legacy-url-error" class="alert alert-danger" style="display:none" role="alert"></div>
    <div id="legacy-url-summary" class="card" style="display:none">
        <div class="card-header"><strong>Scan result</strong></div>
        <div class="card-body">
            <div id="legacy-url-stats" class="row"></div>
            <div id="legacy-url-surface-results" class="mt-3"></div>
            <div id="legacy-url-skipped-surfaces" class="mt-3 text-muted"></div>
            <details class="mt-3 small text-muted">
                <summary>Scanned tables and columns</summary>
                <div id="legacy-url-scanned-columns" class="mt-2"></div>
            </details>
        </div>
    </div>
    <div id="legacy-url-details-result" class="card mt-3" style="display:none">
        <div class="card-header"><strong>Scan details</strong></div>
        <div class="card-body">
            <div id="legacy-url-details-note" class="small text-muted mb-2"></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Location</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="legacy-url-details-rows"></tbody>
                </table>
            </div>
            <p class="mb-0 mt-3">
                <button type="button" id="legacy-url-details-more" class="btn btn-secondary btn-sm" style="display:none">
                    Show more
                </button>
            </p>
        </div>
    </div>
    <div id="legacy-url-apply-result" class="alert mt-2" style="display:none" role="status"></div>
</div>

<?=$module->initializeJavascriptModuleObject()?>
<script>
    (function ($) {
        'use strict';

        const module = <?=$module->getJavascriptModuleObjectName()?>;
        let currentScan = null;
        let detailOffset = 0;
        const $scan = $('#legacy-url-scan');
        const $details = $('#legacy-url-details');
        const $apply = $('#legacy-url-apply');
        const $progress = $('#legacy-url-progress');
        const $error = $('#legacy-url-error');
        const $summary = $('#legacy-url-summary');
        const $stats = $('#legacy-url-stats');
        const $surfaces = $('#legacy-url-surface-results');
        const $skippedSurfaces = $('#legacy-url-skipped-surfaces');
        const $scannedColumns = $('#legacy-url-scanned-columns');
        const $detailsResult = $('#legacy-url-details-result');
        const $detailsNote = $('#legacy-url-details-note');
        const $detailsRows = $('#legacy-url-details-rows');
        const $detailsMore = $('#legacy-url-details-more');
        const $applyResult = $('#legacy-url-apply-result');

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        function errorMessage(error) {
            if (typeof error === 'string') return error;
            if (error && typeof error.message === 'string') return error.message;
            return 'The request could not be completed. See the REDCap logs for details.';
        }

        function confirmWithSimpleDialog(message, title, confirmLabel) {
            return new Promise(function (resolve) {
                let settled = false;
                const settle = function (confirmed) {
                    if (settled) return;
                    settled = true;
                    resolve(confirmed);
                };
                simpleDialog(
                    '<p class="mb-0">' + escapeHtml(message) + '</p>',
                    title,
                    null,
                    500,
                    function () { settle(false); },
                    'Cancel',
                    function () { settle(true); },
                    confirmLabel
                );
            });
        }

        function setBusy(message) {
            $scan.prop('disabled', true);
            $details.prop('disabled', true);
            $detailsMore.prop('disabled', true);
            $apply.prop('disabled', true);
            $progress.text(message);
            $error.hide();
        }

        function setIdle() {
            $scan.prop('disabled', false);
            $details.prop('disabled', !currentScan || (currentScan.stats.changed_cells === 0 && currentScan.stats.issues === 0));
            $detailsMore.prop('disabled', false);
            $apply.prop('disabled', !currentScan || currentScan.stats.changed_cells === 0);
            $progress.text('');
        }

        function stat(label, value) {
            return '<div class="col-sm-4 col-lg-3 mb-2"><div class="border rounded p-2">'
                + '<div class="text-muted small">' + escapeHtml(label) + '</div>'
                + '<strong>' + escapeHtml(value) + '</strong></div></div>';
        }

        function renderScan(scan) {
            currentScan = scan;
            detailOffset = 0;
            $detailsRows.empty();
            $detailsResult.hide();
            $detailsMore.hide();
            const stats = scan.stats;
            $stats.html(
                stat('Cells to update', stats.changed_cells)
                + stat('URLs to update', stats.changed_urls)
                + stat('Valid current URLs', stats.current_urls)
                + stat('URLs needing review', stats.issues)
            );

            const rows = Object.keys(stats.surfaces).map(function (key) {
                const surface = stats.surfaces[key];
                return '<tr><td>' + escapeHtml(surface.label) + '</td>'
                    + '<td class="text-end">' + escapeHtml(surface.changed_cells) + '</td>'
                    + '<td class="text-end">' + escapeHtml(surface.changed_urls) + '</td>'
                    + '<td class="text-end">' + escapeHtml(surface.issues) + '</td></tr>';
            });
            $surfaces.html(rows.length === 0 ? '<span class="text-muted">No candidate URLs were found.</span>' :
                '<table class="table table-sm mb-0"><thead><tr><th>Surface</th><th class="text-end">Cells</th>'
                + '<th class="text-end">URLs</th><th class="text-end">Review</th></tr></thead><tbody>'
                + rows.join('') + '</tbody></table>');

            const scannedColumns = stats.scanned_columns || [];
            const scannedTableRows = scannedColumns.map(function (surface) {
                const columns = (surface.columns || []).map(function (column) {
                    return '<code>' + escapeHtml(column) + '</code>';
                }).join(', ');
                return '<li><strong>' + escapeHtml(surface.label) + '</strong> '
                    + '(<code>' + escapeHtml(surface.table) + '</code>): ' + columns + '</li>';
            });
            $scannedColumns.html(scannedTableRows.length
                ? '<ul class="mb-0 ps-3">' + scannedTableRows.join('') + '</ul>'
                : '<span>No tables were available to scan.</span>');

            const skipped = stats.skipped_surfaces || [];
            const issueReasons = stats.issues_by_reason || {};
            const issueSummary = Object.keys(issueReasons).map(function (reason) {
                return escapeHtml(issueReasons[reason] + ' — ' + reason);
            });
            let diagnostics = '';
            if (issueSummary.length) {
                diagnostics += '<strong>Review reasons:</strong> ' + issueSummary.join('; ') + '.';
            }
            if (skipped.length) {
                diagnostics += (diagnostics ? '<br>' : '') + '<strong>Skipped:</strong> ' + escapeHtml(skipped.join('; '));
            }
            $skippedSurfaces.html(diagnostics);
            $summary.show();
            setIdle();
        }

        function formatKeys(keys) {
            const pairs = Object.keys(keys || {}).map(function (key) {
                return key + '=' + keys[key];
            });
            return pairs.length ? pairs.join(', ') : 'No row identifier available';
        }

        function urlPreview(label, url) {
            if (!url) return '';
            return '<div class="mb-1"><span class="text-muted small">' + escapeHtml(label) + '</span><br>'
                + '<code style="white-space:normal;overflow-wrap:anywhere">' + escapeHtml(url) + '</code></div>';
        }

        function renderDetails(result, append) {
            if (!append) $detailsRows.empty();

            const rows = (result.details || []).map(function (detail) {
                let action;
                let contents = '';
                if (detail.state === 'repair') {
                    action = '<span class="badge bg-success">Will update</span>';
                    contents = urlPreview('Current URL', detail.url) + urlPreview('Replacement URL', detail.replacement);
                } else if (detail.state === 'review') {
                    action = '<span class="badge bg-warning text-dark">Review</span>';
                    contents = '<div class="mb-1">' + escapeHtml(detail.reason || 'Unsupported URL format') + '</div>'
                        + urlPreview('Current URL', detail.url);
                } else {
                    action = '<span class="badge bg-secondary">Refresh needed</span>';
                    contents = escapeHtml(detail.reason || 'The scanned item is no longer available.');
                }
                const location = '<strong>' + escapeHtml(detail.surface || 'Unknown surface') + '</strong><br>'
                    + '<span class="small text-muted">' + escapeHtml(detail.table || '') + ' · '
                    + escapeHtml(detail.column || '') + '<br>' + escapeHtml(formatKeys(detail.keys)) + '</span>';
                return '<tr><td>' + action + '</td><td>' + location + '</td><td>' + contents + '</td></tr>';
            });
            if (rows.length) {
                $detailsRows.append(rows.join(''));
            } else if (!append) {
                $detailsRows.html('<tr><td colspan="3" class="text-muted">No URL details are available for this scan.</td></tr>');
            }

            detailOffset = result.next_offset || 0;
            let note = result.total_cells === 0 ? 'No URL details are available for this scan.'
                : 'Showing details for scanned cells ' + (result.offset + 1) + '–'
                    + Math.min(detailOffset, result.total_cells) + ' of ' + result.total_cells + '.';
            if (result.stale_cells) {
                note += ' ' + result.stale_cells + ' cell(s) changed or became unavailable after the scan.';
            }
            $detailsNote.text(note);
            $detailsMore.toggle(!!result.has_more);
            $detailsResult.show();
        }

        function loadDetails(append) {
            if (!currentScan) return;
            const offset = append ? detailOffset : 0;
            setBusy(append ? 'Loading more scan details…' : 'Loading scan details…');
            module.ajax('details', {scan_id: currentScan.scan_id, offset: offset}).then(function (result) {
                if (!currentScan || result.scan_id !== currentScan.scan_id) return;
                renderDetails(result, append);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        }

        function runScan() {
            setBusy('Scanning project configuration…');
            $applyResult.hide();
            $detailsResult.hide();
            $detailsRows.empty();
            $detailsMore.hide();
            return module.ajax('scan', {}).then(renderScan).catch(function (error) {
                currentScan = null;
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        }

        $scan.on('click', runScan);
        $details.on('click', function () {
            loadDetails(false);
        });
        $detailsMore.on('click', function () {
            loadDetails(true);
        });
        $apply.on('click', async function () {
            if (!currentScan || currentScan.stats.changed_cells === 0) return;
            const confirmed = await confirmWithSimpleDialog(
                'Fix all URLs from this scan? Cells changed since scanning will be skipped. A CSV audit is saved to the project File Repository.',
                'Confirm URL repairs',
                'Fix URLs'
            );
            if (!confirmed) return;

            setBusy('Applying URL repairs…');
            $detailsResult.hide();
            module.ajax('apply', {scan_id: currentScan.scan_id}).then(function (result) {
                currentScan = null;
                const counts = result.counts;
                let message = 'Updated ' + counts.updated_urls + ' URL(s) in ' + counts.updated_cells + ' cell(s).';
                if (counts.skipped_changed || counts.skipped_no_longer_needed || counts.errors) {
                    message += ' Skipped changed: ' + counts.skipped_changed + '; already resolved: '
                        + counts.skipped_no_longer_needed + '; errors: ' + counts.errors + '.';
                }
                if (result.audit_doc_id) {
                    message += ' Audit CSV saved to the File Repository (document ID ' + result.audit_doc_id + ').';
                } else if (result.audit_error) {
                    message += ' Audit CSV warning: ' + result.audit_error;
                }
                $applyResult.removeClass('alert-danger').addClass('alert-success').text(message).show();
                setIdle();
            }).catch(function (error) {
                $applyResult.removeClass('alert-success').addClass('alert-danger').text(errorMessage(error)).show();
                setIdle();
            });
        });

        runScan();
    })(jQuery);
</script>
