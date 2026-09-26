# Release checks

Run from the module checkout with PHP CLI:

```sh
php tests/release-check.php
```

The script runs the real module implementation against isolated Framework, query, hash, and file-service fixtures. No REDCap installation, credentials, database connection, or real e-documents are used. Temporary audit CSVs are created and removed by the module's usual audit code.

The checks cover:

- Project scan and repair rejection without Design rights, and project access with Design rights.
- Superuser-only Control Center inventory access.
- Source-project Design rights filtering during scan and rechecking after rights are revoked, for both relocation modes.
- Read-only production active dictionaries; writable draft dictionaries only in Draft Mode.
- Invalidating both repair caches after a project status or Draft Mode change.
- Skipping changed cells before copying or updating, and detecting competing edits in conditional writes.
- Byte-exact final comparisons and commit/rollback outcomes.
- Rejection of another scan ID or another user's scan.

Framework permission results and query outcomes are supplied by fixtures. These checks validate how the module responds to them; they do not replace browser checks with actual accounts, database transaction integration tests, or file-storage tests.

For 1.0.0, the user has confirmed both replacement modes and the Control Center inventory in the local instance. The release review additionally verified, through read-only `redcap_devctl` queries, that the content column uses a case-insensitive collation and that binary comparison distinguishes case-only changes. Ordinary-user browser sessions were not exercised by this script.
