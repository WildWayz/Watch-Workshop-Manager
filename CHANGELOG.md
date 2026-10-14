## 1.7.3 — 2026-10-14 — Ensure dropdown values appear in forms

- Parts, Builds, Customers, Orders and Warranties forms now prefer a populated list assigned to the field, fall back to the standard list named in the field definition when a mapping is missing or empty, and keep existing values visible while editing.
- Added a one-time migration to restore missing default field mappings and remap only empty mappings where the standard list has values. Populated custom mappings are preserved.
- For a legacy section with a single populated enabled list and no mapping/default, the form may use that list as an unambiguous fallback rather than rendering an empty select.
- Added a help link under empty dropdown fields to open Lists and correct the assignment.
- No workshop records, existing dropdown values, images or order data are deleted.

## 1.7.2 — 2026-10-14 — Dropdown mapping repair

- Fixed mapped dropdowns so selected list values appear in the relevant Parts, Builds, Customers, Orders and Warranties forms even if a legacy section-access row was missing.
- Added a one-time SQLite migration that restores missing standard field mappings, repairs access rows for linked lists, and removes orphaned field mappings without overriding valid custom mappings.
- Enabling a standard list for its intended section (for example Materials for Parts or Movements for Builds) now links it to the matching field automatically.
- A list linked to a field remains enabled for that section; the Lists page shows current field links and explains when a list is not linked to a field.
- No workshop records or images are changed. Existing list values remain intact.

## 1.7.1 — 2026-10-14 — Preserve Lists page position after save

- Added scroll restoration to list creation, rename, deletion, section-access updates, field mapping, and list-value add/edit/delete actions.
- The active list card or field-mapping area returns to its prior viewport position after save instead of jumping to the top. When deleting a whole list, the previous page offset is restored.
- No database migration; all existing list values, mappings and workshop data are unchanged.

## 1.6.2 — 2026-10-14 — Long URL layout fix

- Part Purchase URL and Supplier Website detail fields now show concise labelled links, with the full URL available on hover.
- Supplier list rows use a short “Open website” link rather than printing the entire URL.
- Added wrapping rules for long text and URLs in detail fields and table cells to prevent overlap.
- The public certificate URL field now wraps over multiple lines; the copy button remains functional.
- No database migration.

# Changelog

## 1.6.1 — 2026-10-14

- Fixed dropdown lists being re-seeded on every request, which reverted list renames/deletions and value edits/deletions.
- Built-in dropdowns are seeded only once on new installations; existing databases are marked initialized without overwriting current list data.
- Added clearer validation for duplicate list names/values and ensure list-action errors return to the Lists page.

## 1.6.0 — 2026-10-14 — Self-hosted QR codes and A5 watch certificates
- Replaced the external QR image service with the bundled pure-PHP SVG generator in `lib/qr_svg.php`. QR images now render locally without Composer, GD or any external QR API.
- All existing orders display locally generated QR codes automatically; public tokens and certificate URLs are unchanged, so previously printed QR codes remain valid.
- Added a **Print A5 Certificate** action to admin Order details. The A5 portrait certificate includes workshop branding, public verification QR, customer name, serial, order/build details, specification, warranty, timegrapher metrics and recorded QC checks.
- The A5 certificate intentionally excludes build/watch photos, promotional photos and galleries to keep it ink-efficient and suitable for including with the watch.
- No database migration.

## 1.5.1 — 2026-10-14 — Light, printer-friendly customer certificates
- Updated the public customer certificate to a white/pale background, dark readable text and restrained green/gold accents matching the admin interface.
- Added print-specific styles to remove dark backgrounds, shadows and screen-only buttons, and to use low-ink styling on paper.
- QR codes, certificate content, service history and image galleries remain unchanged. No database migration.


## 1.5.0 — 2026-10-14 — Public watch certificates and order QR codes
- Added a unique, cryptographically random public certificate link for each order, with a QR code shown on the Order detail page and a shortcut from the Orders list.
- Added a standalone certificate page styled to match the supplied Moose-Shack Watches example, including order and serial details, watch specification, warranty status, timegrapher metrics, quality checks, public build notes, service history and categorized photos.
- Added token-protected image delivery restricted to the order and its linked build, so public certificate images do not expose the private storage paths.
- Added certificate-specific build fields, public certificate URL configuration, service history records and editable image categories with automatic schema migration.
- Regenerating a certificate link invalidates the former URL. Public pages use noindex/nofollow and intentionally omit customer contact details and internal build notes.

## 1.4.0 — 2026-10-14 — Combined first-run workshop setup
- Combined administrator account creation, company/workshop name, optional logo upload and display currency into one first-run page.
- Validates the optional logo upload (PNG, JPG/JPEG, WEBP or GIF up to 5 MB), stores it in the existing private branding directory and optimises it where image support is available.
- Saves the account and setup preferences transactionally in the existing SQLite database.
- Added a live logo preview and company-name preview, with responsive layout styles.
- Existing installations, existing branding settings and database/image structure are preserved. No schema migration is needed.

## 1.3.1 — 2026-10-14 — Simple borderless image viewer
- Replaced the previous custom lightbox with a native browser dialog styled as a borderless, viewport-sized image window.
- Clicking an image opens its original image in place, scaled to fit the screen while preserving aspect ratio.
- Close with the close button, Escape, or by clicking the blank area around the image.
- No database migration; image records and uploaded files are unchanged.

## 1.3.0 — 2026-10-14 — High-quality image optimisation

- Added an in-app Settings workflow to optimise previously uploaded images in small batches and report progress / approximate byte savings.
- New record-image uploads and workshop logos are optimised automatically.
- Converts to high-quality WebP when the host supports it and the resulting file is smaller; format-preserving fallbacks keep JPEG quality high and PNG lossless.
- Resizes images larger than 2560 px on the longest edge only when the encoded file becomes smaller.
- Updates image filenames and primary-image references when the stored format changes.
- Preserves GIF files unchanged to protect possible animation; the UI advises making a full storage-folder backup before bulk processing.
- Added the `optimized_at` column with a safe migration for existing SQLite databases.


## 1.2.2 — 2026-10-14
- Fixed remaining gallery previews that still loaded original files rather than generated thumbnails.
- Added a lightbox viewer that fits originals within the browser viewport without stretching or cropping them.
- Lightbox supports close button, clicking outside, Escape, and previous/next navigation (including arrow keys) for gallery images.
- Updated primary record images, gallery items, search/list thumbnails and branding logos so clicks use the lightbox instead of opening a raw image tab.
- No database migration; original uploads are unchanged.

## 1.2.1 — 2026-10-14
- Fixed image lists and record previews so they display generated thumbnails, not full-size originals.
- Added a viewport-fitted lightbox for primary images, gallery thumbnails, list thumbnails, search thumbnails and workshop logo images.
- Lightbox preserves aspect ratio, supports gallery previous/next navigation, closes with Escape or the backdrop, and keeps original files unchanged.


## 1.2.0 — 2026-10-14 (build 20261014.4)

- Added automatic individual watch serial numbers, created when an order is saved.
- Serial format: `K7Q2-JOSM-B00012-O47` (example).
- Prefix is a cryptographically generated four-character code; customer segment uses two characters from the first name plus two from the surname.
- Preserves the full Build Code and removes unnecessary leading zeroes from standard Order Codes (`O00047` becomes `O47`).
- Stores serial numbers against orders, not reusable build definitions, so each customer’s physical watch has a distinct serial.
- Automatically backfills serials for existing orders on upgrade.
- Shows serials in the Orders list and detail, Customer order history, Build linked orders, global search and CSV export.
- Preserves the existing SQLite database and image storage.


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

## 2026-10-14 — Version 1.1.1 — Copyright and project links
- Added a copyright notice for Moose-Shack Watches linked to https://www.moose-shack.com.
- Added a link to the Watch Workshop Manager GitHub project.
- Displayed the notice in the application footer and the login/setup screens.
- Preserved the existing database, branding settings, image galleries and storage layout.


## 1.3.0 — 2026-10-14 — High-quality image optimisation

- Added an in-app Settings workflow to optimise previously uploaded images in small batches and report progress / approximate byte savings.
- New record-image uploads and workshop logos are optimised automatically.
- Converts to high-quality WebP when the host supports it and the resulting file is smaller; format-preserving fallbacks keep JPEG quality high and PNG lossless.
- Resizes images larger than 2560 px on the longest edge only when the encoded file becomes smaller.
- Updates image filenames and primary-image references when the stored format changes.
- Preserves animated GIFs; the UI advises making a full storage-folder backup before bulk processing.
- Added the `optimized_at` column with a safe migration for existing SQLite databases.

## 2026-10-14 — Version 1.5.0 — Public watch certificates and order QR codes
- Added a cryptographically random public token for every order and an unauthenticated certificate URL that does not expose sequential order IDs.
- Added a QR code and public URL panel to each order record, plus a certificate shortcut in the Orders list and a link-regeneration action.
- Added a responsive, print-friendly certificate page styled in a dark/gold watch-certificate theme. It shows public customer name, order dates, serial, watch specification, warranty state, timegrapher fields, quality-control checks, notes, service history and photo galleries.
- Added certificate-specific build fields for case description/material/thickness, water resistance, dial details, lume, timegrapher metrics, quality-control flags and public notes.
- Added photo categories: Build Photos, Promotional Photos, Regulated Photos and Other Photos. Existing images default to Build Photos; categories can be changed in record galleries.
- Added an order-linked service-history register. Service entries are displayed on that order's public certificate.
- Added Settings > Public Certificates to configure the canonical public app URL for subdirectory/cPanel installations.
- Added automatic SQLite migrations for order tokens, certificate fields, image categories and service-history storage. Existing order records and image paths are preserved.
- Public image endpoints validate the order token and restrict access to photos attached to the linked order or its build. Contact details and internal notes are not included on the public certificate.
- QR image pixels are rendered through an external QR image service; the certificate page and workshop data are hosted locally.


## 2026-10-14 — Version 1.7.0 — Section-aware dropdown lists
- Added per-list section checkboxes for Parts, Builds, Customers, Orders, Suppliers and Warranties.
- Added field mapping controls for each dropdown form field so it can be explicitly linked to the right list.
- Dropdown values now resolve through stable list IDs rather than list names; renaming a list no longer breaks its form fields.
- Added safe one-time migration of existing built-in list-to-field assignments, preserving any field mapping already present.
- Built-in lists/default values are not re-seeded on every request; existing user edits, renames and deletions remain persistent.
- Existing saved field values remain available when a field's current dropdown is disabled or unlinked, preventing accidental data loss while editing records.
