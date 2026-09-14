<?php

use ExternalModules\ExternalModules;

ExternalModules::requireDesignRights();

?>
<div class="legacy-url-fixer" style="max-width: 960px">
    <h4><i class="fas fa-link"></i> Fix legacy image/file URLs</h4>

    <p>
        This scans authored project configuration for old static <code>DataEntry/image_view.php</code>
        and <code>DataEntry/file_download.php</code> URLs, including survey passthru URLs. The scan is
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
        </div>
    </div>
    <div id="legacy-url-apply-result" class="alert" style="display:none" role="status"></div>
</div>

<?=$module->initializeJavascriptModuleObject()?>
<script>
    (function ($) {
        'use strict';

        const module = <?=$module->getJavascriptModuleObjectName()?>;
        let currentScan = null;
        const $scan = $('#legacy-url-scan');
        const $apply = $('#legacy-url-apply');
        const $progress = $('#legacy-url-progress');
        const $error = $('#legacy-url-error');
        const $summary = $('#legacy-url-summary');
        const $stats = $('#legacy-url-stats');
        const $surfaces = $('#legacy-url-surface-results');
        const $skippedSurfaces = $('#legacy-url-skipped-surfaces');
        const $applyResult = $('#legacy-url-apply-result');

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        function errorMessage(error) {
            if (typeof error === 'string') return error;
            if (error && typeof error.message === 'string') return error.message;
            return 'The request could not be completed. See the REDCap logs for details.';
        }

        function setBusy(message) {
            $scan.prop('disabled', true);
            $apply.prop('disabled', true);
            $progress.text(message);
            $error.hide();
        }

        function setIdle() {
            $scan.prop('disabled', false);
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
            const stats = scan.stats;
            $stats.html(
                stat('Cells to update', stats.changed_cells)
                + stat('URLs to update', stats.changed_urls)
                + stat('Already current', stats.current_urls)
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

        function runScan() {
            setBusy('Scanning project configuration…');
            $applyResult.hide();
            return module.ajax('scan', {}).then(renderScan).catch(function (error) {
                currentScan = null;
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        }

        $scan.on('click', runScan);
        $apply.on('click', function () {
            if (!currentScan || currentScan.stats.changed_cells === 0) return;
            if (!window.confirm('Fix all URLs from this scan? Cells changed since scanning will be skipped. A CSV audit is saved to the project File Repository.')) return;

            setBusy('Applying URL repairs…');
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
