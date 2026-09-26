# Changelog

## Unreleased

- Added a project-only report for selecting cross-project image and download links and copying their files into the link-hosting project, with an optional direct public File Repository link.

## 0.8.0

- Added Community Site discovery and post-body scanning, details, and repair in a Control Center tab.
- Added separate Community Site result counts, summary boxes, and per-site details.
- Added CSV downloads for project, Control Center settings, and Community Site scan details, plus tinted update and review summary boxes and smaller action buttons.
- Fixed Community setup-project lookup and batch-log parameter handling for Community Sites and Control Center settings.

## 0.7.0

- Added a production-project Draft Mode reminder and automatic page reload with a repair-result toast.
- Added sequential Control Center **Scan all** and a last-activity filter for project scans (no limit, 3, 6, or 12 months). Cached results record the selected filter.

## 0.6.0

- Added detection of supported image/file URLs that point to a different REDCap host. These links are reported for review in project scans, Control Center project scans, and Control Center settings scans, and are never changed automatically.

## 0.5.0

- Project scan details now show current cross-project URLs and their owning project IDs.
- Production project scans report the delivered data dictionary as read-only, alongside the draft dictionary when Draft Mode is on; repairs still target only the draft. Control Center scans the active data dictionary in all projects, including those in Draft Mode, and labels the draft scan more clearly.

## 0.4.0

- Added a Control Center option to exclude completed projects; deleted projects remain excluded. Added a project setting to mark projects done and omit them from Control Center scans and cached lists.
- Aligned Control Center cross-project URL classification with the configured repair policy and added a count of current cross-project URLs to project scans.

## 0.3.0

- Added an optional, super-user-only project setting and Control Center override for repairing verified legacy URLs that intentionally reference e-documents owned by another project.
- Normalized `INFORMATION_SCHEMA` result keys so schema detection works with MySQL installations that return uppercase column labels.

## 0.2.0

- Added Control Center surface scans.

## 0.1.0

- Initial release.
