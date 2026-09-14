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
        Scan one configuration surface at a time across all non-deleted projects. Each completed scan is
        cached system-wide as the affected project IDs only. Affected projects have either a repairable
        legacy URL or a URL requiring review. No content, URL preview, or repair is available here; use a
        linked project ID to review and fix that project.
    </p>

    <div class="alert alert-info">
        <strong>Performance:</strong> each button scans one physical table/surface. This avoids a single
        long-running scan across all project configuration. For a production data-dictionary finding, enter
        Draft Mode before using the project page; repairs there are limited to <code>redcap_metadata_temp</code>.
    </div>

    <p>
        <button type="button" id="legacy-url-cc-refresh" class="btn btn-secondary">
            <i class="fas fa-sync"></i> Refresh cached results
        </button>
        <span id="legacy-url-cc-progress" class="ms-2 text-muted" aria-live="polite"></span>
    </p>

    <div id="legacy-url-cc-error" class="alert alert-danger" style="display:none" role="alert"></div>
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

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        function errorMessage(error) {
            if (typeof error === 'string') return error;
            if (error && typeof error.message === 'string') return error.message;
            return 'The request could not be completed. See the REDCap logs for details.';
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

        function renderStatus(response) {
            const rows = (response.surfaces || []).map(function (surface) {
                let result;
                if (surface.status === 'not-scanned') {
                    result = '<span class="text-muted">Not scanned</span>';
                } else if (surface.status === 'unavailable') {
                    result = '<span class="text-muted">Unavailable: ' + escapeHtml(surface.message || 'Unknown schema issue') + '</span>';
                } else {
                    result = '<div><strong>' + escapeHtml(surface.project_count) + '</strong> affected project(s)'
                        + '<span class="text-muted small"> · scanned ' + escapeHtml(surface.created_at) + '</span></div>'
                        + '<div class="small mt-1">PIDs: ' + projectLinks(surface.project_ids) + '</div>';
                }
                return '<tr><td>' + escapeHtml(surface.label) + '</td><td>' + result + '</td>'
                    + '<td class="text-end"><button type="button" class="btn btn-primaryrc btn-sm legacy-url-cc-scan"'
                    + ' data-surface="' + escapeHtml(surface.surface_id) + '"><i class="fas fa-search"></i> Scan</button></td></tr>';
            });
            $results.html(rows.length ? rows.join('') : '<tr><td colspan="3" class="text-muted">No scan surfaces are configured.</td></tr>');
        }

        function setBusy(message) {
            $refresh.prop('disabled', true);
            $results.find('button').prop('disabled', true);
            $progress.text(message);
            $error.hide();
        }

        function setIdle() {
            $refresh.prop('disabled', false);
            $results.find('button').prop('disabled', false);
            $progress.text('');
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
            setBusy('Scanning this surface across non-deleted projects…');
            module.ajax('control-center-scan', {surface_id: surfaceId}).then(function () {
                return module.ajax('control-center-status', {});
            }).then(function (response) {
                renderStatus(response);
                setIdle();
            }).catch(function (error) {
                $error.text(errorMessage(error)).show();
                setIdle();
            });
        });

        loadStatus();
    })(jQuery);
</script>
