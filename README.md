# Legacy URL Fixer

Repairs static `DataEntry/image_view.php` and `DataEntry/file_download.php` URLs after REDCap changed
its document-hash generation in September 2026.

Developed with assistance from OpenAI Codex.

Use **Fix legacy image/file URLs** from a project's External Modules menu. Opening the page scans the
project's authored configuration, classifies both legacy and current document hashes, caches only row
locators and SHA-256 fingerprints, and shows the pending repairs. Applying a scan uses optimistic
locking: any cell changed after the scan is skipped. The batch writes directly to the relevant
configuration table(s) and saves a CSV outcome log in the project File Repository.

Use **Show scan details** to inspect the location, current URL, and proposed replacement for every
repair. Review entries show their exact location, URL, and reason instead. Current cross-project URLs
also appear with their owning project PID so they can be reviewed with the project owners. Details are
read on demand and only shown when the cell still matches its scan-time fingerprint, so source text is not stored in
the scan cache.

The scan recognizes both legacy and current document hashes. A URL is eligible for repair only when
its supplied legacy hash matches the referenced document ID using the owning project's legacy salt.
Valid current hashes are counted but not changed. The project scan separately counts current URLs whose
e-documents belong to another project when cross-project repair is enabled. A current hash does not
prove that a link into a deleted project is accessible. A mismatched ID/hash pair is retained unchanged and
reported for review; the module never creates a new URL merely because a document ID exists. By
default, the referenced e-document must also belong to the project containing the URL, so
cross-project links are reported for review. A super user may explicitly enable **Allow
cross-project e-document URL repairs** in a project's module settings for intentional links copied
from another project. The Control Center setting **Force cross-project e-document URL repairs for all
projects** enables the same policy globally and overrides the project setting. Either option still
requires an exact valid hash using the referenced e-document's owning-project salt; it only relaxes
the ownership check. When a repaired URL has a numeric `pid` parameter, it is normalized to that
owning project.

The module scans development data dictionaries, `redcap_metadata_temp` while a production project is
in Draft Mode, and the active production data dictionary for read-only review in either mode. It
also scans surveys, pending survey invitations, alerts, reports,
dashboards, descriptive popups, e-Consent configuration, and Multi-Language Management
content. It uses a fixed allow-list of HTML-capable columns and checks those names against the
installed schema; it does not generically scan every text column. The module deliberately does not alter record data or historical/sent
delivery and audit data.

REDCap super users can also use **Scan legacy image/file URLs** from the Control Center. It scans one
physical configuration surface at a time across non-deleted projects and caches only the affected PIDs
for each surface (repairable URLs and URLs requiring review). Each PID links back to the project page;
the project-surface scanner does not expose URLs, previews, or repair actions. A checkbox can exclude
completed projects from each new scan. The selected choice is shown with each cached result. The project setting **Done with Legacy URL Fixer
Control Center scans** excludes that project from new scans and hides it from cached project lists.
Project-level scans remain available. Cached Control Center results are snapshots: rescan a surface
after fixing its findings to refresh its list. The Control Center's **Data dictionary (active table)**
always scans `redcap_metadata`, which remains the delivered dictionary until Draft Mode changes are
applied. **Draft data dictionary (production projects)** scans `redcap_metadata_temp` while Draft Mode
is on. With Draft Mode on, the project page reports both tables as separate surfaces, including their
current URL counts. Active production dictionary findings remain read-only; repairs target only the
draft copy.

The Control Center page separately scans a fixed allow-list of authored system settings in
`redcap_config` and provides details plus repair for those global settings. A system setting URL must
reference a system e-document (`redcap_edocs_metadata.project_id IS NULL`); a project-owned document is
reported for review and is never repaired. System-setting repairs use the same optimistic-locking check
and log their batch summary in the External Module log.

## Changelog

Version | Description
------- | ---------------------
0.5.0   | Project scan details now show current cross-project URLs and their owning project IDs. Production project scans report the delivered data dictionary as read-only, alongside the draft dictionary when Draft Mode is on; repairs still target only the draft. Control Center scans the active data dictionary in all projects, including those in Draft Mode, and labels the draft scan more clearly.
0.4.0   | Added a Control Center option to exclude completed projects; deleted projects remain excluded. Added a project setting to mark projects done and omit them from Control Center scans and cached lists. Aligned Control Center cross-project URL classification with the configured repair policy and added a count of current cross-project URLs to project scans.
0.3.0   | Added an optional, super-user-only project setting and Control Center override for repairing verified legacy URLs that intentionally reference e-documents owned by another project.<br>Bugfix: Normalized `INFORMATION_SCHEMA` result keys so schema detection works with MySQL installations that return uppercase column labels.
0.2.0   | Added Control Center surface scans.
0.1.0   | Initial release.
