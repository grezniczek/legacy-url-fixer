# Link Inspector & Repair

Link Inspector & Repair (LIR) finds, repairs, and relocates REDCap image and file links. It repairs outdated document hashes in authored project configuration, selected Control Center settings, and REDCap Community Platform post bodies. Cross-project reports identify links to files owned by another project and let users copy those files into the project containing the link, optionally creating direct public File Repository links.

Legacy-hash repairs require a verified e-document, owning project, and legacy hash. Cross-project relocation uses a separate, explicitly selected workflow that works regardless of hash. Links it cannot verify are listed for review.

## Getting started

Install and enable the module as a REDCap External Module. Its configuration requires External Module Framework version 16. The `_v9.9.9` directory name in this development checkout is a development convention, not a release version. Project-page scans and repairs require Design rights in that project; Control Center actions require a REDCap super user.

- **For one project:** Open **LIR • Fix legacy image/file links** from the project's External Modules menu. The page scans automatically. Review **Show scan details**, optionally **Download CSV**, then use **Fix all scanned links** for the repairable findings.
- **Across projects:** A REDCap super user can open **LIR • Scan legacy image/file links** in the Control Center. The **Project configuration** tab identifies affected project IDs; follow a project link to inspect and fix its content.
- **For cross-project links:** Open **LIR • Scan for cross-project file links** in the Control Center to identify affected projects regardless of hash. Review and repair links in each project's **LIR • Relocate cross-project file links** report.
- **For global settings or Community Sites:** Use the corresponding Control Center tab to scan, review details, and fix verified legacy links there.

After a repair, run a fresh scan to see the current state. The project page reloads and shows a result toast; the Control Center tabs provide a **Rescan** or scan button.

## Scan areas

| Area | What it scans | Where repairs happen |
| --- | --- | --- |
| Project page | Authored configuration belonging to one project | On the project page |
| Control Center: Project configuration | One physical project-configuration table at a time across non-deleted projects | On each linked project page |
| Control Center: Control Center settings | A fixed list of authored `redcap_config` values | In that tab |
| Control Center: Cross-project file links | Authored project configuration linking to files owned by another project, regardless of hash | On each linked project report |
| Control Center: Community Sites | The `body` column of discovered Community Platform posts tables | In that tab |

Project configuration includes data dictionaries, survey settings, automated survey invitations, pending survey invitations, alerts, reports, project and record dashboards, descriptive popups, e-Consent settings, and Multi-Language Management content. The module uses a fixed list of content columns and checks that each exists in the installed REDCap schema. It does not scan every text column.

The project and Control Center configuration scans exclude ordinary record data and historical or sent delivery and audit data. The pending invitation scan only considers invitations that have not been sent. Community Site post bodies are a separate, explicit scan area.

### Data dictionaries and Draft Mode

| Project state | Scanned dictionary | Repair behavior |
| --- | --- | --- |
| Development | Active `redcap_metadata` | Verified legacy links can be repaired. |
| Production, not in Draft Mode | Active `redcap_metadata` | Findings are shown for review; enter Draft Mode to repair the draft copy. |
| Production, in Draft Mode | Active `redcap_metadata` and draft `redcap_metadata_temp` | The active copy is read-only. Verified legacy links in the draft can be repaired; apply the Draft Mode changes to update the active dictionary. |

The Control Center **Data dictionary (active table)** scan always reads `redcap_metadata`. Its separate **Draft data dictionary (production projects)** scan reads `redcap_metadata_temp` only for production projects in Draft Mode.

### Control Center project scanning

The **Project configuration** tab caches affected project IDs for each surface, not links or repair previews. A project appears when a scanned surface contains a repairable link or one needing review. The linked project page provides the details and repair action.

You can scan one surface or use **Scan all** to run the project surfaces in sequence and then scan Control Center settings. **Scan all does not scan Community Sites**; open that tab to scan them. The **Ignore completed projects** checkbox excludes projects with a REDCap completion time from new scans. The activity filter can limit scans to projects active in the last 3, 6, or 12 months, or apply no limit. Neither filter limits the global settings scan. The project setting **Done with LIR legacy link Control Center scans** hides that project from new and cached Control Center project lists; its project page remains available.

These lists are snapshots. Rescan a surface after project repairs to refresh its affected project IDs.

### Control Center settings

This tab scans a fixed list of authored system settings in `redcap_config`, including custom homepage, login, help, footer, notification, and e-Consent text. Scan results, link details, CSV export, and repair are available in the tab.

A global setting's link must reference a system e-document (`redcap_edocs_metadata.project_id IS NULL`). A project-owned e-document in a global setting is listed for review and is not repaired. **Scan all** on the Project configuration tab includes this settings scan.

### Community Sites

The **Community Sites** tab looks for matching `<prefix>_posts` and `<prefix>_posts_attachments` tables. It associates a table pair with a setup project only when the project has `site_url`, `site_version`, `table_prefix`, and `tables_created` fields in `redcap_metadata`, and its `CONFIG` record contains the matching `table_prefix`. The lookup uses the project's assigned REDCap data table.

The tab scans `<prefix>_posts.body`, the Community Platform's rich-text post content. The attachments table stores document IDs rather than link text. A site without exactly one matching setup project is still scanned for review, but its links cannot be repaired automatically.

The first visit to the tab runs a scan if none is cached. Use **Rescan** to refresh it. Results show separate update, current, and review counts for each site. **Show all scan details** covers all sites; a site's **Details** button limits the view and CSV export to that site. **Fix all scanned links** applies verified repairs across the matched sites in the cached scan.

## Cross-project file link report

Open **LIR • Relocate cross-project file links** from a project's External Modules menu. This separate report scans the same authored project surfaces as the project legacy-link scan, regardless of whether a link has a current, legacy, or mismatched document hash. It recognizes supported absolute image and download links on this REDCap host with an existing document ID. It does not scan record data or Community Sites. The report includes a link only when you have Design rights in both the project containing the link and the project owning the referenced file.

The DataTables report has one row per link. Select rows across pages, then choose one of two actions (up to 100 links per batch):

- **Relocate selected** copies each referenced file into the project containing the link, registers the copy as a miscellaneous attachment, and changes only the selected link to reference the new document ID and hash. The source file stays in its original project.
- **Create public links for selected** copies each file into the project containing the link and replaces the selected link with a direct public image or download link. Choose a File Repository folder, or use **Miscellaneous File Attachments**. Regular folders require public File Repository sharing to be enabled; the miscellaneous attachment route follows REDCap's special attachment behavior. Anyone with the new public link can access the copied file.

The report rechecks permissions, the source document, and the scanned cell before each batch. Production active data-dictionary rows are read-only; enter Draft Mode to update the draft copy. Batch outcomes are logged and an audit CSV is saved in the project's File Repository. A changed cell is skipped. If a file was copied but its link could not be written, the outcome reports that the copy remains in the project.

### Control Center cross-project inventory

Super users can open **LIR • Scan for cross-project file links**, a separate Control Center page with no repair actions. **Scan all projects** scans each project content surface sequentially. The searchable, sortable results table lists affected project IDs, current project titles, link occurrence counts, and content surfaces. Follow a project ID to its report, where selected links can be relocated or replaced with public links. Enable the module in that project if needed.

This inventory includes all non-deleted projects, including completed projects and projects marked done for legacy link repairs. It uses the project report's link detection, including current, legacy, mismatched, and missing hashes. Ordinary record data and Community Sites are excluded. Active and applicable draft copies count separately.

Each surface caches only project IDs, counts, and scan status/timestamp, independently of project repair caches. **Rescan all projects** refreshes the inventory after repairs. Expand **Scan coverage and timestamps** to check unavailable surfaces or results from different runs after an interrupted scan; a partially scanned inventory is not proof that no other projects are affected.

## Understanding results

The scanner recognizes absolute `DataEntry/image_view.php` and `DataEntry/file_download.php` links, including survey passthru links. It checks the configured REDCap host, the static document ID, the referenced e-document, project ownership, and the supplied `doc_id_hash`.

| Result | Meaning | Automatic change |
| --- | --- | --- |
| **Will update** | The supplied hash exactly matches REDCap's legacy hash for the document and the ownership policy permits repair. | The link receives the current hash. |
| **Current** | The hash already matches the current algorithm. | None. |
| **Review** | A host, document, hash, ownership, or read-only rule prevents a safe repair. | None. |
| **Refresh needed** | The stored cell changed or became unavailable after the scan. | None; rescan to inspect its current value. |

A project scan also counts **Current cross-project links** when cross-project repair is enabled. Details identify the owning project for review. A current hash alone does not prove a link into a deleted project is accessible.

**Cells to update** counts stored text values; **Links to update** counts repairable links within them. One cell, such as a Community post body, can contain several links. **Fix all scanned links** repairs every eligible link in each selected cell. The legacy-hash fix has no per-link repair button; the separate cross-project report acts on selected individual links.

Links to another REDCap host, missing documents, invalid ID/hash pairs, system links to project-owned documents, and disallowed cross-project links stay unchanged. The module does not generate a replacement merely because a document ID exists. When a verified download link has companion hash parameters, the repair updates them as needed; a numeric `pid` parameter is normalized to the e-document's owning project.

## Repair safeguards and audit

Repairs use the cached scan ID, re-read each source cell, and compare it with the scan-time SHA-256 fingerprint before writing. Changed cells are skipped. The module also rechecks the current project state, schema, and applicable ownership policy. Community Site repairs recheck the table-to-project match. A repair request can therefore finish with a mix of updated and skipped cells.

- **Project repairs:** A CSV batch audit is saved to the project File Repository. The result toast reports its document ID or an audit warning. The module also writes a batch summary to its log.
- **Control Center settings and Community Site repairs:** Batch counts and outcomes are written to the External Module log.
- **Scan-details Download CSV:** This is a review export, separate from the repair audit. It fetches every detail page and writes one row per link or stale item, including locations, proposed replacements, and review reasons. Values that resemble spreadsheet formulas are exported as text. Community exports follow the selected site filter.

Cached scan data contains row locators and fingerprints rather than source text or link previews. Detail views re-read the source on demand. Project scans use project settings for their cache; Control Center and Community scans use system settings. A completed scan remains a snapshot until you rescan, and repairs invalidate the corresponding repair cache.

## Cross-project e-documents

By default, project and Community Site links must reference e-documents owned by their associated project. A super user can enable **Allow cross-project e-document link repairs** for one project, or **Force cross-project e-document link repairs for all projects** as a Control Center setting. The system setting overrides the project setting.

Use these options only for intentional links copied from another project. They relax the ownership rule; they do not relax the configured-host or exact legacy-hash checks. Rescan after changing either option so repairs use the current policy.

## Troubleshooting

- **A finding needs review:** Open scan details for the exact link and reason. The module leaves it unchanged.
- **A finding is marked “Refresh needed” or was skipped as changed:** Rescan. The stored text no longer matches the scan-time fingerprint.
- **A production data-dictionary link cannot be fixed:** Enter Draft Mode, repair the draft dictionary, then apply the Draft Mode changes.
- **A Community Site has no matching setup project:** Check the four metadata fields and the `table_prefix` value in that project's `CONFIG` record. An ambiguous or missing match disables automatic repair for that site.
- **A Control Center project list still shows a repaired project:** Rescan the relevant surface. Cached project lists do not refresh when a project repair finishes.

See [CHANGELOG.md](CHANGELOG.md) for release history.

Developed with assistance from OpenAI Codex.
