(function (root) {
    'use strict';

    function csvCell(value) {
        let text = value == null ? '' : String(value);
        // Keep spreadsheet programs from evaluating values as formulas.
        if (/^\s*[=+\-@]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function csvRow(values) {
        return values.map(csvCell).join(',') + '\r\n';
    }

    async function download(options) {
        const parts = ['\uFEFF', csvRow(options.headers)];
        let offset = 0;
        let rowCount = 0;
        let hasMore;
        do {
            const page = await options.fetchPage(offset);
            (page.details || []).forEach(function (detail) {
                parts.push(csvRow(options.mapDetail(detail)));
                rowCount++;
            });
            hasMore = !!page.has_more;
            const nextOffset = Number(page.next_offset);
            if (hasMore && (!Number.isFinite(nextOffset) || nextOffset <= offset)) {
                throw new Error('Scan detail pagination did not advance. Rescan and try again.');
            }
            offset = nextOffset;
            if (options.onPage) options.onPage(offset, page.total_cells || 0);
        } while (hasMore);

        const url = URL.createObjectURL(new Blob(parts, {type: 'text/csv;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = options.filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        return rowCount;
    }

    root.LegacyUrlScanCsv = {download: download};
})(window);
