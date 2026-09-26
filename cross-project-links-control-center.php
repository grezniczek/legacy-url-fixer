<?php
namespace DE\RUB\SEG\LegacyURLFixerExternalModule;

/** @var LegacyURLFixerExternalModule $module */

if (!$module->isSuperUser()) {
    ?>
    <div class="alert alert-danger" role="alert">Only a REDCap super user may use this page.</div>
    <?php
    return;
}

$projectReportUrl = $module->getUrl('cross-project-links.php');
?>
<style>
    #cross-project-cc-table > tbody > tr > td:first-child {
        background-color: var(--bs-table-bg, transparent);
    }
</style>
<div style="max-width:1100px">
    <p class="text-muted" style="font-size:.875rem;margin-bottom:5px"><em>Link Inspector &amp; Repair</em></p>
    <h4 class="mb-1"><i class="fas fa-exchange-alt"></i> Scan for cross-project file links</h4>
    <p>
        Find projects whose authored configuration contains image or download links to files owned by another project,
        regardless of the link's hash. Open a project report to review and relocate its links.
        Repairs are performed in projects only.
    </p>
    <p class="small text-muted">
        Covers all non-deleted projects, including completed projects and projects marked done for legacy link repairs.
        Record data and Community Sites are excluded. Active and applicable draft content are counted separately.
        Each surface is scanned in turn; cached results remain a snapshot until rescanned.
        Enable this module in a project if needed to open its report.
    </p>
    <p>
        <button type="button" id="cross-project-cc-scan" class="btn btn-primaryrc btn-sm" disabled>
            <i class="fas fa-search"></i> Scan all projects
        </button>
        <span id="cross-project-cc-progress" class="small text-muted ms-2" aria-live="polite"></span>
    </p>
    <div id="cross-project-cc-error" class="alert alert-danger" style="display:none" role="alert"></div>
    <div id="cross-project-cc-summary" class="row mb-3" aria-live="polite"></div>
    <div class="table-responsive">
        <table id="cross-project-cc-table" class="table table-hover table-sm" style="width:100%">
            <thead><tr><th>Project ID</th><th>Project</th><th>Cross-project links</th><th>Content surfaces</th></tr></thead>
        </table>
    </div>
    <details class="mt-3">
        <summary>Scan coverage and timestamps</summary>
        <p class="small text-muted mt-2">Results combine the latest saved scan of each surface. An interrupted scan can contain results from different runs.</p>
        <table class="table table-sm">
            <thead><tr><th>Surface</th><th>Status</th><th>Last scanned</th></tr></thead>
            <tbody id="cross-project-cc-surfaces"></tbody>
        </table>
    </details>
</div>
<?= $module->initializeJavascriptModuleObject() ?>
<script>
(function ($) {
    'use strict';
    const module = <?= $module->getJavascriptModuleObjectName() ?>;
    const projectReportUrl = <?= json_encode($projectReportUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const $scan = $('#cross-project-cc-scan');
    const $progress = $('#cross-project-cc-progress');
    const $error = $('#cross-project-cc-error');
    let surfaces = [];
    function escapeHtml(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    const table = $('#cross-project-cc-table').DataTable({
        data: [], pageLength: 25, order: [[0, 'asc']],
        language: {emptyTable: 'No affected projects in the available scan results.'},
        columns: [
            {data: 'project_id', render: function (id, type) {
                if (type !== 'display') return id;
                const url = new URL(projectReportUrl, window.location.href);
                url.searchParams.set('pid', id);
                return '<a href="' + escapeHtml(url.toString()) + '">' + escapeHtml(id) + '</a>';
            }},
            {data: 'title', render: $.fn.dataTable.render.text()},
            {data: 'link_count'},
            {data: 'surfaces', render: function (labels, type) {
                return type === 'display' ? labels.map(escapeHtml).join('<br>') : labels.join('; ');
            }}
        ]
    });
    function render(data) {
        surfaces = data.surfaces;
        table.clear().rows.add(data.projects).draw();
        const total = data.projects.reduce((sum, project) => sum + project.link_count, 0);
        const complete = surfaces.filter(surface => surface.status === 'complete').length;
        const tint = total ? '#fff0f0' : '#eef8ef';
        const boxes = [
            ['Affected projects', data.projects.length],
            ['Cross-project links', total],
            ['Surfaces scanned', complete + ' / ' + surfaces.length]
        ];
        $('#cross-project-cc-summary').html(boxes.map((box, index) =>
            '<div class="col-sm-4"><div class="border rounded p-2"' + (index < 2 ? ' style="background:' + tint + '"' : '') + '>'
            + '<div class="small text-muted">' + box[0] + '</div><strong>' + box[1] + '</strong></div></div>'
        ).join(''));
        $('#cross-project-cc-surfaces').html(surfaces.map(surface => '<tr><td>' + escapeHtml(surface.label)
            + '</td><td>' + escapeHtml(surface.status + (surface.message ? ': ' + surface.message : ''))
            + '</td><td>' + escapeHtml(surface.created_at || 'Not scanned') + '</td></tr>').join(''));
        $scan.text('Rescan all projects');
    }
    function showError(error) { $error.text(error && error.message ? error.message : String(error)).show(); }
    $scan.on('click', async function () {
        $scan.prop('disabled', true);
        $error.hide();
        try {
            // Also allows retrying if the initial status request failed.
            if (!surfaces.length) render(await module.ajax('cross-project-control-center-status', {}));
            const pending = surfaces.slice();
            for (let index = 0; index < pending.length; index++) {
                $progress.text('Scanning ' + (index + 1) + ' / ' + pending.length + ': ' + pending[index].label + '…');
                render(await module.ajax('cross-project-control-center-scan', {surface_id: pending[index].id}));
            }
            const unavailable = surfaces.filter(surface => surface.status !== 'complete').length;
            $progress.text(unavailable ? 'Scan finished; ' + unavailable + ' surface(s) unavailable. See scan coverage.' : 'Scan complete.');
        } catch (error) {
            showError(error);
            $progress.text('Scan interrupted. Available results may be incomplete; rescan to refresh.');
        } finally {
            $scan.prop('disabled', false);
        }
    });
    $progress.text('Loading cached results…');
    module.ajax('cross-project-control-center-status', {}).then(function (data) {
        render(data);
        $progress.text(surfaces.some(surface => surface.created_at) ? 'Showing cached results. See scan coverage for timestamps.' : 'No scan has been run yet.');
    }).catch(function (error) {
        showError(error);
        $progress.text('Could not load cached results.');
    }).finally(function () { $scan.prop('disabled', false); });
})(jQuery);
</script>
