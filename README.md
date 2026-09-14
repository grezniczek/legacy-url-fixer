# Legacy URL Fixer

Repairs static `DataEntry/image_view.php` and `DataEntry/file_download.php` URLs after REDCap changed
its document-hash generation in September 2026.

Use **Fix legacy image/file URLs** from a project's External Modules menu. Opening the page scans the
project's authored configuration, caches only row locators and SHA-256 fingerprints, and shows the
pending repairs. Applying a scan uses optimistic locking: any cell changed after the scan is skipped.
The batch writes directly to the relevant configuration table(s) and saves a CSV outcome log in the
project File Repository.

Use **Show scan details** to inspect the location, current URL, and proposed replacement for every
repair. Review entries show their exact location, URL, and reason instead. Details are read on demand
and only shown when the cell still matches its scan-time fingerprint, so source text is not stored in
the scan cache.

Before a URL is eligible for repair, its supplied 40-character legacy hash must match the referenced
document ID using the owning project's legacy salt. A mismatched ID/hash pair is retained unchanged
and reported for review; the module never creates a new URL merely because a document ID exists.
The referenced e-document must also belong to the project containing the URL; cross-project links are
reported for review and are never repaired.

The module scans data dictionary content (development projects, or only `redcap_metadata_temp` while
a production project is in Draft Mode), surveys, pending survey invitations, alerts, reports,
dashboards, descriptive popups, e-Consent configuration, MyCap tasks, data-quality rules, and
Multi-Language Management content. It deliberately does not alter record data or historical/sent
delivery and audit data.

REDCap super users can also use **Scan legacy image/file URLs** from the Control Center. It scans one
physical configuration surface at a time across non-deleted projects and caches only the affected PIDs
for each surface (repairable URLs and URLs requiring review). Each PID links back to the project page;
the Control Center does not expose URLs, previews, or repair actions.
