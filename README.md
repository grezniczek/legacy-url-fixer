# Legacy URL Fixer

Repairs static `DataEntry/image_view.php` and `DataEntry/file_download.php` URLs after REDCap changed
its document-hash generation in September 2026.

Use **Fix legacy image/file URLs** from a project's External Modules menu. Opening the page scans the
project's authored configuration, caches only row locators and SHA-256 fingerprints, and shows the
pending repairs. Applying a scan uses optimistic locking: any cell changed after the scan is skipped.
The batch writes directly to the relevant configuration table(s) and saves a CSV outcome log in the
project File Repository.

Before a URL is eligible for repair, its supplied 40-character legacy hash must match the referenced
document ID using the owning project's legacy salt. A mismatched ID/hash pair is retained unchanged
and reported for review; the module never creates a new URL merely because a document ID exists.

The module scans data dictionary content (development projects, or only `redcap_metadata_temp` while
a production project is in Draft Mode), surveys, pending survey invitations, alerts, reports,
dashboards, descriptive popups, e-Consent configuration, MyCap tasks, data-quality rules, and
Multi-Language Management content. It deliberately does not alter record data or historical/sent
delivery and audit data.
