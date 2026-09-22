<?php

if (!$module->isSuperUser()) {
    ?>
    <div class="alert alert-danger" role="alert">Only a REDCap super user may use this page.</div>
    <?php
    return;
}

$projectPluginUrl = $module->getUrl('legacy-url-fixer.php');

?>
<div class="legacy-url-fixer-control-center" style="max-width: 1100px">
    <h4><i class="fas fa-search"></i> Scan legacy image/file URLs</h4>

    <p>
        <button type="button" id="legacy-url-cc-refresh" class="btn btn-secondary">
            <i class="fas fa-sync"></i> Refresh cached results
        </button>
        <span id="legacy-url-cc-progress" class="ms-2 text-muted" aria-live="polite"></span>
    </p>

    <div id="legacy-url-cc-error" class="alert alert-danger" style="display:none" role="alert"></div>

    <ul class="nav nav-tabs" id="legacy-url-cc-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="legacy-url-cc-project-tab" data-bs-toggle="tab"
                    data-bs-target="#legacy-url-cc-project-pane" type="button" role="tab"
                    aria-controls="legacy-url-cc-project-pane" aria-selected="true">
                Project configuration
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="legacy-url-cc-settings-tab" data-bs-toggle="tab"
                    data-bs-target="#legacy-url-cc-settings-pane" type="button" role="tab"
                    aria-controls="legacy-url-cc-settings-pane" aria-selected="false">
                Control Center settings
            </button>
        </li>
    </ul>

    <div class="tab-content pt-3" id="legacy-url-cc-tab-content">
        <div class="tab-pane fade show active" id="legacy-url-cc-project-pane" role="tabpanel"
             aria-labelledby="legacy-url-cc-project-tab" tabindex="0">
            <p>
                Scan one project configuration surface at a time across non-deleted projects. Each completed scan is
                cached system-wide as the affected project IDs only. Affected projects have either a repairable
                legacy URL or a URL requiring review. Use a linked project ID to review and fix project content.
                Mark a project done in its module settings to omit it from Control Center results.
            </p>
            <div class="mb-3">
                <label><input type="checkbox" id="legacy-url-cc-ignore-completed"> Ignore completed projects</label>
                <div class="small text-muted">This choice applies to the next scan of each surface. Cached results show the choice used when scanned.</div>
            </div>
            <div class="alert alert-info">
                <strong>Performance:</strong> each button scans one physical table/surface. This avoids a single
                long-running scan across all project configuration. For a production data-dictionary finding, enter
                Draft Mode before using the project page; repairs there are limited to <code>redcap_metadata_temp</code>.
            </div>
            <div class="table-responsive">
                <table class="table table-sm" id="legacy-url-cc-results">
                    <thead>
                        <tr>
                            <th>Surface</th>
                            <th>Cached result</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="text-muted">Loading cached scan results…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="legacy-url-cc-settings-pane" role="tabpanel"
             aria-labelledby="legacy-url-cc-settings-tab" tabindex="0">
            <div id="legacy-url-cc-settings-summary" class="card" style="display:none">
                <div class="card-header"><strong>Control Center settings</strong></div>
                <div class="card-body">
                    <p>
                        Scan the fixed set of authored system settings stored in <code>redcap_config</code>. These
                        global settings may be reviewed and fixed here. Their URLs must reference system eDocs
                        (<code>redcap_edocs_metadata.project_id IS NULL</code>); a project-owned eDoc is review-only.
                    </p>
                    <p>
                        <button type="button" id="legacy-url-cc-settings-scan" class="btn btn-primaryrc">
                            <i class="fas fa-search"></i> Scan Control Center settings
                        </button>
                        <button type="button" id="legacy-url-cc-settings-details" class="btn btn-secondary" disabled>
                            <i class="fas fa-list"></i> Show scan details
                        </button>
                        <button type="button" id="legacy-url-cc-settings-apply" class="btn btn-danger" disabled>
                            <i class="fas fa-wrench"></i> Fix all scanned URLs
                        </button>
                    </p>
                    <div id="legacy-url-cc-settings-stats" class="row"></div>
                    <div id="legacy-url-cc-settings-diagnostics" class="mt-3 text-muted"></div>
                    <details class="mt-3 small text-muted">
                        <summary>Scanned Control Center settings</summary>
                        <div id="legacy-url-cc-settings-fields" class="mt-2"></div>
                    </details>
                </div>
            </div>

            <div id="legacy-url-cc-settings-details-result" class="card mt-3" style="display:none">
                <div class="card-header"><strong>Control Center settings scan details</strong></div>
                <div class="card-body">
                    <div id="legacy-url-cc-settings-details-note" class="small text-muted mb-2"></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Setting</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="legacy-url-cc-settings-details-rows"></tbody>
                        </table>
                    </div>
                    <p class="mb-0 mt-3">
                        <button type="button" id="legacy-url-cc-settings-details-more" class="btn btn-secondary btn-sm" style="display:none">
                            Show more
                        </button>
                    </p>
                </div>
            </div>
            <div id="legacy-url-cc-settings-apply-result" class="alert mt-2" style="display:none" role="status"></div>
        </div>
    </div>
</div>

<?=$module->initializeJavascriptModuleObject()?>
<script>
    (function ($) {
        'use strict';

        const module = <?=$module->getJavascriptModuleObjectName()?>;
        const projectPluginUrlBase = <?=json_encode($projectPluginUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
        const $refresh = $('#legacy-url-cc-refresh');
        const $progress = $('#legacy-url-cc-progress');
        const $error = $('#legacy-url-cc-error');
        const $results = $('#legacy-url-cc-results tbody');
        const $ignoreCompleted = $('#legacy-url-cc-ignore-completed');
        let settingsScan = null;
        let settingsDetailOffset = 0;
        const $settingsSummary = $('#legacy-url-cc-settings-summary');
        const $settingsScan = $('#legacy-url-cc-settings-scan');
        const $settingsDetails = $('#legacy-url-cc-settings-details');
        const $settingsApply = $('#legacy-url-cc-settings-apply');
        const $settingsStats = $('#legacy-url-cc-settings-stats');
        const $settingsDiagnostics = $('#legacy-url-cc-settings-diagnostics');
        const $settingsFields = $('#legacy-url-cc-settings-fields');
        const $settingsDetailsResult = $('#legacy-url-cc-settings-details-result');
        const $settingsDetailsNote = $('#legacy-url-cc-settings-details-note');
        const $settingsDetailsRows = $('#legacy-url-cc-settings-details-rows');
        const $settingsDetailsMore = $('#legacy-url-cc-settings-details-more');
        const $settingsApplyResult = $('#legacy-url-cc-settings-apply-result');

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

        function projectPluginUrl(projectId) {
            return projectPluginUrlBase + (projectPluginUrlBase.indexOf('?') === -1 ? '?' : '&')
                + 'pid=' + encodeURIComponent(projectId);
        }

        function projectLinks(projectIds) {
            if (!projectIds || projectIds.length === 0) return '<span class="text-muted">None</span>';
            return projectIds.map(function (projectId) {
                return '<a href="' + escapeHtml(projectPluginUrl(projectId)) + '">' + escapeHtml(projectId) + '</a>';
            }).join(', ');
        }

        function stat(label, value) {
            return '<div class="col-sm-4 col-lg-3 mb-2"><div class="border rounded p-2">'
                + '<div class="text-muted small">' + escapeHtml(label) + '</div>'
                + '<strong>' + escapeHtml(value) + '</strong></div></div>';
        }

        function renderSettings(scan) {
            settingsDetailOffset = 0;
            $settingsDetailsRows.empty();
            $settingsDetailsResult.hide();
            $settingsDetailsMore.hide();
            $settingsApplyResult.hide();
            if (!scan || scan.status !== 'complete') {
                settingsScan = null;
                $settingsStats.html('<div class="col-12 text-muted">Not scanned yet.</div>');
                $settingsDiagnostics.empty();
                $settingsFields.empty();
                $settingsSummary.show();
                return;
            }

            settingsScan = scan;
            const stats = scan.stats || {};
            $settingsStats.html(
                stat('Settings to update', stats.changed_cells || 0)
                + stat('URLs to update', stats.changed_urls || 0)
                + stat('Valid current URLs', stats.current_urls || 0)
                + stat('URLs needing review', stats.issues || 0)
            );
            const issueReasons = stats.issues_by_reason || {};
            const issueSummary = Object.keys(issueReasons).map(function (reason) {
                return escapeHtml(issueReasons[reason] + ' — ' + reason);
            });
            $settingsDiagnostics.html(issueSummary.length
                ? '<strong>Review reasons:</strong> ' + issueSummary.join('; ') + '.'
                : '');
            const fields = (stats.scanned_fields || []).map(function (field) {
                return '<li><code>' + escapeHtml(field) + '</code></li>';
            });
            $settingsFields.html(fields.length ? '<ul class="mb-0 ps-3">' + fields.join('') + '</ul>' : 'No settings are configured.');
            $settingsSummary.show();
        }

        function renderStatus(response) {
            const rows = (response.surfaces || []).map(function (surface) {
                let result;
                if (surface.status === 'not-scanned') {
                    result = '<span class="text-muted">Not scanned</span>';
                } else if (surface.status === 'unavailable') {
                    result = '<span class="text-muted">Unavailable: ' + escapeHtml(surface.message || 'Unknown schema issue') + '</span>';
                } else {
                    const scope = surface.ignore_completed ? 'completed excluded' : 'completed included';
                    result = '<div><strong>' + escapeHtml(surface.project_count) + '</strong> affected project(s)'
                        + '<span class="text-muted small"> · scanned ' + escapeHtml(surface.created_at)
                        + ' · ' + escapeHtml(scope) + '</span></div>'
                        + '<div class="small mt-1">PIDs: ' + projectLinks(surface.project_ids) + '</div>';
                }
                return '<tr><td>' + escapeHtml(surface.label) + '</td><td>' + result + '</td>'
                    + '<td class="text-end"><button type="button" class="btn btn-primaryrc btn-sm legacy-url-cc-scan"'
                    + ' data-surface="' + escapeHtml(surface.surface_id) + '"><i class="fas fa-search"></i> Scan</button></td></tr>';
            });
            $results.html(rows.length ? rows.join('') : '<tr><td colspan="3" class="text-muted">No scan surfaces are configured.</td></tr>');
            renderSettings(response.settings);
        }

        function setBusy(message) {
            $refresh.prop('disabled', true);
            $results.find('button').prop('disabled', true);
            $ignoreCompleted.prop('disabled', true);
            $settingsScan.prop('disabled', true);
            $settingsDetails.prop('disabled', true);
            $settingsDetailsMore.prop('disabled', true);
            $settingsApply.prop('disabled', true);
            $progress.text(message);
            $error.hide();
        }

        function setIdle() {
            $refresh.prop('disabled', false);
            $results.find('button').prop('disabled', false);
            $ignoreCompleted.prop('disabled', false);
            $settingsScan.prop('disabled', false);
            $settingsDetails.prop('disabled', !settingsScan
                || ((settingsScan.stats.changed_cells || 0) === 0 && (settingsScan.stats.issues || 0) === 0));
            $settingsDetailsMore.prop('disabled', false);
            $settingsApply.prop('disabled', !settingsScan || (settingsScan.stats.changed_cells || 0) === 0);
            $progress.text('');
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

        function renderSettingsDetails(result, append) {
            if (!append) $settingsDetailsRows.empty();
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
                    contents = escapeHtml(detail.reason || 'The scanned setting is no longer available.');
                }
                const location = '<strong>' + escapeHtml(detail.surface || 'Control Center setting') + '</strong><br>'
                    + '<span class="small text-muted">' + escapeHtml(detail.table || '') + ' · '
                    + escapeHtml(detail.column || '') + '<br>' + escapeHtml(formatKeys(detail.keys)) + '</span>';
                return '<tr><td>' + action + '</td><td>' + location + '</td><td>' + contents + '</td></tr>';
            });
            if (!append && rows.length === 0) {
                $settingsDetailsRows.html('<tr><td colspan="3" class="text-muted">No repair or review details are currently available.</td></tr>');
            } else {
                $settingsDetailsRows.append(rows.join(''));
            }
            const stale = result.stale_cells || 0;
            $settingsDetailsNote.text('Showing affected settings ' + ((result.offset || 0) + 1) + '–'
                + ((result.next_offset || 0)) + ' of ' + (result.total_cells || 0)
                + (stale ? '. ' + stale + ' setting(s) changed after scanning.' : '.'));
            settingsDetailOffset = result.next_offset || 0;
            $settingsDetailsMore.toggle(!!result.has_more);
            $settingsDetailsResult.show();
        }

        function loadSettingsDetails(append) {
            return module.ajax('control-center-settings-details', {
                scan_id: settingsScan.scan_id,
                offset: append ? settingsDetailOffset : 0
            }).then(function (result) {
                renderSettingsDetails(result, append);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        }

        function loadStatus() {
            setBusy('Loading cached scan results…');
            return module.ajax('control-center-status', {}).then(function (response) {
                renderStatus(response);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        }

        $refresh.on('click', loadStatus);
        $results.on('click', '.legacy-url-cc-scan', function () {
            const surfaceId = $(this).data('surface');
            const ignoreCompleted = $ignoreCompleted.prop('checked');
            setBusy('Scanning this surface…');
            module.ajax('control-center-scan', {
                surface_id: surfaceId,
                ignore_completed: ignoreCompleted
            }).then(function () {
                return module.ajax('control-center-status', {});
            }).then(function (response) {
                renderStatus(response);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        });

        $settingsScan.on('click', function () {
            setBusy('Scanning Control Center settings…');
            $settingsApplyResult.hide();
            module.ajax('control-center-settings-scan', {}).then(function (scan) {
                renderSettings(scan);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        });

        $settingsDetails.on('click', function () {
            if (!settingsScan) return;
            setBusy('Loading Control Center settings details…');
            loadSettingsDetails(false);
        });

        $settingsDetailsMore.on('click', function () {
            if (!settingsScan) return;
            setBusy('Loading more Control Center settings details…');
            loadSettingsDetails(true);
        });

        $settingsApply.on('click', function () {
            if (!settingsScan) return;
            confirmWithSimpleDialog(
                'Update every repairable legacy URL in the cached Control Center settings scan? URLs requiring review will remain unchanged.',
                'Fix scanned Control Center settings URLs',
                'Fix URLs'
            ).then(function (confirmed) {
                if (!confirmed) return null;
                setBusy('Fixing scanned Control Center settings URLs…');
                return module.ajax('control-center-settings-apply', {scan_id: settingsScan.scan_id}).then(function (result) {
                    return module.ajax('control-center-status', {}).then(function (status) {
                        renderStatus(status);
                        const counts = result.counts || {};
                        $settingsApplyResult
                            .removeClass('alert-danger')
                            .addClass('alert-success')
                            .text('Updated ' + (counts.updated_cells || 0) + ' setting(s) and '
                                + (counts.updated_urls || 0) + ' URL(s). Skipped changed: '
                                + (counts.skipped_changed || 0) + '; skipped no longer needed: '
                                + (counts.skipped_no_longer_needed || 0) + '; errors: '
                                + (counts.errors || 0) + '. The batch summary was logged by the module.')
                            .show();
                        setIdle();
                    });
                }).catch(function (error) {
                    $error.text(errorMessage(error)).show();
                    setIdle();
                });
            });
        });

        loadStatus();
    })(jQuery);
</script>
