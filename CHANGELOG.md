# Changelog

## PHP + SQLite edition
- Reimplemented core workshop manager for PHP hosting without Python or Composer.
- Kept SQLite table names/fields aligned with the previous application for easier database reuse.
- Added first-run administrator setup, password hashing, CSRF forms and session login.
- Added CRUD views for parts, builds, customers, orders, suppliers and warranties.
- Added build-to-parts Bill of Materials and running cost/profit calculations.
- Added reusable off-the-shelf builds and custom customer builds.
- Added order deposit/balance calculations and payment-completed date.
- Added private, multi-image galleries with primary-image selection.
- Added editable dropdown lists, CSV exports and downloadable database backup.
## UI wording fix
- Corrected plural labels to use explicit section names, so the Warranties list and the “Back to Warranties” link are spelled correctly.
## 2026-10-11
- Added primary-image thumbnails to Build and Order list views. Order rows use an order image first, with the linked build image as a fallback.
- Added global search across parts, builds, customers, orders, suppliers, and warranties.
- Made data-column headings sortable in ascending/descending order on every module list; sort selection is preserved during section search.

## 2026-10-11 — Physical image thumbnails
- Build and Order list views now request generated 240×240 JPEG thumbnails; clicking opens the original image.
- Added one-time backfill for existing image records on the next authenticated page load, plus on-demand thumbnail creation.
- New uploads automatically generate thumbnails; deleting images/records removes sidecar thumbnails.
- Enforced tiny list dimensions inline and versioned stylesheet links to avoid stale CSS.
- Added Settings status notice and documentation for PHP GD/Imagick support.

## 2026-10-14 — Configurable currency
- Added a Currency section to Settings with a saved currency selector for GBP, USD, EUR, CAD, AUD, NZD, CHF, JPY, CNY, INR, SGD, AED, ZAR, SEK, NOK, DKK, BRL, PLN, and TRY.
- Currency formatting now follows the selected code for monetary values throughout lists, summaries, order balances, build costing, and detail views. JPY uses zero decimal places; other listed currencies use two.
- Form labels and the Bill of Materials unit-cost label use the chosen currency.
- Currency selection changes display/formatting only; it does not convert stored numeric values or apply exchange rates.


## 1.0.0 — 2026-10-14 — Version tracking and What’s New
- Added a central `version.php` release manifest with semantic version, build ID, release date, summary and release notes.
- Added a version badge to the application header and version/build metadata to the footer.
- Added a What's New navigation page with current release notes and historical feature notes.
- Added a latest-release summary to the Dashboard and application version details to Settings.
- Added release-process guidance so future builds increment the version and document changes.


## 2026-10-14 — Version 1.1.0 — Workshop name and logo
- Added Settings controls for setting/changing the workshop or business name.
- Added logo upload, preview, replace and remove functionality for PNG, JPG/JPEG, WEBP and GIF files (5 MB limit).
- Displayed saved branding in the signed-in application header and login/setup screens.
- Stored the logo in the private `storage/branding/` folder and served it through a validated endpoint.
- Kept branding values in the existing SQLite settings table; no schema migration is required.
