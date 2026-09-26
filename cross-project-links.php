<?php

use ExternalModules\ExternalModules;

ExternalModules::requireDesignRights();
$projectId = (int) PROJECT_ID;
$folders = \FileRepository::getFolderList($projectId);
$publicSharingEnabled = ($GLOBALS['file_repository_allow_public_link'] ?? null) == '1';
?>
<style>
    .legacy-cross-project-links { max-width: 960px; }
    /* Keep REDCap's first-column override from giving checkboxes a different background. */
    #cross-project-table > tbody > tr > td:first-child {
        background-color: var(--bs-table-bg, transparent);
    }
</style>
<div class="legacy-cross-project-links">
    <h4><i class="fas fa-exchange-alt"></i> Relocate cross-project file links</h4>
    <p>
        This report finds image and download links in this project's authored configuration when the referenced file belongs
        to another project. It includes current, legacy, and mismatched hashes for supported URLs on this REDCap host. Only links to projects where you also have
        Design rights appear. Record data and Community Sites are outside this report.
    </p>
    <div class="alert alert-light border py-2">
        Each selected link is handled individually. <strong>Relocate selected</strong> copies the file into this project and
        points the link to the copy. <strong>Create public links</strong> also copies the file, places it in the File Repository,
        and makes it accessible to anyone with the new link. Original files remain in their projects.
        Production data dictionaries can be changed only through Draft Mode.
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <button type="button" id="cross-project-scan" class="btn btn-primaryrc btn-sm"><i class="fas fa-search"></i> Scan project</button>
        <button type="button" id="cross-project-relocate" class="btn btn-secondary btn-sm" disabled>Relocate selected</button>
        <label class="mb-0 small" for="cross-project-folder">Public link destination</label>
        <select id="cross-project-folder" class="form-select form-select-sm" style="width:auto;max-width:320px">
            <option value="misc">Miscellaneous File Attachments</option>
            <?php if ($publicSharingEnabled): foreach ($folders as $folderId => $name): ?>
                <?php if (is_numeric($folderId) && (int) $folderId > 0): ?>
                    <option value="<?= (int) $folderId ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
                <?php endif; ?>
            <?php endforeach; endif; ?>
        </select>
        <button type="button" id="cross-project-public" class="btn btn-warning btn-sm" disabled>Create public links for selected</button>
        <span id="cross-project-progress" class="small text-muted" aria-live="polite"></span>
    </div>
    <div id="cross-project-error" class="alert alert-danger" style="display:none" role="alert"></div>
    <div id="cross-project-result" class="alert alert-info" style="display:none" role="status"></div>
    <div id="cross-project-summary" class="small text-muted mb-2"></div>
    <div class="table-responsive">
        <table id="cross-project-table" class="table table-hover table-sm" style="width:100%">
            <thead><tr>
                <th><input type="checkbox" id="cross-project-select-page" aria-label="Select visible links"></th>
                <th>Location</th><th>File owner</th><th>File</th><th>Hash</th><th>Link</th>
            </tr></thead>
        </table>
    </div>
</div>
<?=$module->initializeJavascriptModuleObject()?>
<script>
(function ($) {
    'use strict';
    const module = <?=$module->getJavascriptModuleObjectName()?>;
    let scanId = null;
    let table = null;
    const selected = new Set();
    const $scan = $('#cross-project-scan');
    const $relocate = $('#cross-project-relocate');
    const $public = $('#cross-project-public');
    const $progress = $('#cross-project-progress');
    const $error = $('#cross-project-error');
    const $result = $('#cross-project-result');
    const $summary = $('#cross-project-summary');

    function escapeHtml(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function formatKeys(keys) {
        return Object.keys(keys || {}).map(key => key + '=' + keys[key]).join(', ');
    }
    function setBusy(message) {
        $scan.prop('disabled', true);
        $relocate.prop('disabled', true);
        $public.prop('disabled', true);
        $progress.text(message);
        $error.hide();
    }
    function setIdle() {
        $scan.prop('disabled', false);
        $relocate.prop('disabled', !scanId || selected.size === 0);
        $public.prop('disabled', !scanId || selected.size === 0);
        $progress.text(selected.size ? selected.size + ' selected' : '');
    }
    function showError(error) {
        $error.text(error && error.message ? error.message : String(error)).show();
    }
    function render(data) {
        scanId = data.scan_id;
        selected.clear();
        const rows = data.rows || [];
        if (table) {
            table.clear().rows.add(rows).draw();
        } else {
        table = $('#cross-project-table').DataTable({
            data: rows,
            pageLength: 25,
            order: [[1, 'asc']],
            columns: [
                {data: null, orderable: false, searchable: false, render: function (data, type, row) {
                    if (row.read_only) return '<span title="Read-only active production dictionary">—</span>';
                    return '<input type="checkbox" class="cross-project-row" value="' + row.id + '" aria-label="Select link">';
                }},
                {data: null, render: function (data, type, row) {
                    const location = row.surface + ' · ' + row.column + ' · ' + formatKeys(row.keys);
                    return type === 'display' ? escapeHtml(location) : location;
                }},
                {data: 'owner_project_id', render: $.fn.dataTable.render.text()},
                {data: null, render: function (data, type, row) {
                    const value = row.doc_name + ' (ID ' + row.doc_id + ')';
                    return type === 'display' ? escapeHtml(value) : value;
                }},
                {data: 'hash_state', render: $.fn.dataTable.render.text()},
                {data: 'url', render: function (data, type) {
                    return type === 'display' ? '<code style="overflow-wrap:anywhere">' + escapeHtml(data) + '</code>' : data;
                }}
            ],
            drawCallback: function () {
                $('#cross-project-table .cross-project-row').each(function () {
                    this.checked = selected.has(Number(this.value));
                });
                $('#cross-project-select-page').prop('checked', false);
            }
        });
        }
        let note = rows.length + ' cross-project link(s) found on ' + data.created_at + '.';
        if (data.skipped_no_rights) note += ' ' + data.skipped_no_rights + ' link(s) hidden because you lack Design rights in the file-owning project.';
        if ((data.skipped_surfaces || []).length) note += ' Unavailable surfaces: ' + data.skipped_surfaces.join('; ') + '.';
        $summary.text(note);
        setIdle();
    }
    function runScan() {
        setBusy('Scanning project content…');
        $result.hide();
        module.ajax('cross-project-scan', {}).then(render).catch(function (error) {
            scanId = null;
            selected.clear();
            showError(error);
            setIdle();
        });
    }
    function apply(mode) {
        if (!scanId || !selected.size) return;
        const count = selected.size;
        if (count > 100) { showError('Select at most 100 links per batch.'); return; }
        const message = mode === 'public'
            ? 'Create public links for ' + count + ' selected file(s)? Anyone with those links can access the copies.'
            : 'Copy ' + count + ' selected file(s) into this project and replace their links?';
        simpleDialog(
            '<p class="mb-0">' + escapeHtml(message) + '</p>',
            mode === 'public' ? 'Create public links' : 'Relocate selected files',
            null,
            500,
            null,
            'Cancel',
            function () {
                setBusy('Processing ' + count + ' selected link(s)…');
                module.ajax('cross-project-apply', {
                    scan_id: scanId, mode: mode, selected: Array.from(selected), folder: $('#cross-project-folder').val()
                }).then(function (response) {
                    scanId = null;
                    selected.clear();
                    const failures = (response.outcomes || []).filter(item => item.result !== 'updated');
                    let message = response.updated + ' of ' + response.selected + ' link(s) updated.';
                    if (failures.length) message += ' ' + failures.length + ' skipped or failed: '
                        + failures.map(item => '#' + item.id + ' ' + item.result + (item.detail ? ' (' + item.detail + ')' : '')).join('; ');
                    if (response.audit_doc_id) message += ' Audit CSV: File Repository document ID ' + response.audit_doc_id + '.';
                    if (response.audit_error) message += ' Audit warning: ' + response.audit_error;
                    $result.text(message).show();
                    setIdle();
                    // A fresh scan reflects partial successes and any changed rows.
                    module.ajax('cross-project-scan', {}).then(render).catch(showError);
                }).catch(function (error) {
                    showError(error);
                    setIdle();
                });
            },
            mode === 'public' ? 'Create public links' : 'Relocate selected'
        );
    }
    $('#cross-project-table').on('change', '.cross-project-row', function () {
        const id = Number(this.value);
        if (this.checked) selected.add(id); else selected.delete(id);
        setIdle();
    });
    $('#cross-project-select-page').on('change', function () {
        const checked = this.checked;
        $('#cross-project-table .cross-project-row').each(function () {
            this.checked = checked;
            const id = Number(this.value);
            if (checked) selected.add(id); else selected.delete(id);
        });
        setIdle();
    });
    $scan.on('click', runScan);
    $relocate.on('click', () => apply('relocate'));
    $public.on('click', () => apply('public'));
    runScan();
})(jQuery);
</script>
