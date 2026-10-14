# Screenshots

Click [here](https://dri.me/jhc7e9buY5aBvZ1NJMJB3IryLEtnFN "View Screenshots") to view screenshots

# Update notes — version 1.7.2

Dropdown lists have been repaired so section access and field mapping work together reliably. Existing installations run a one-time mapping repair on the next request. For standard lists, saving section access automatically maps the list to its matching field (for example, Materials → Parts.Material). For custom lists, use **Lists → Link lists to form fields** and choose the exact field; the Lists page now shows which fields each list feeds. A list remains enabled for a section while it is mapped to a field, avoiding a checkbox state that leaves a field unusable.

Updating requires replacing `index.php` and `version.php` (the stylesheet URL cache key is also updated in `index.php`). Keep `config.php` and `storage/` untouched. The migration preserves existing records, list values, images, and valid custom field mappings.

---

# Update notes — version 1.7.1

The Lists page now remembers the current scroll position after adding, renaming or deleting a list; adding, editing or deleting a list value; saving section access; and changing a field-to-list mapping. It restores the active list card or mapping section where possible, instead of jumping back to the top. No database migration is required; keep `config.php` and `storage/` unchanged when updating.

# Update notes — version 1.6.2

Long URLs no longer overflow into adjacent text. Part purchase URLs and supplier websites appear as short descriptive links, with the full URL available through the tooltip. Long record values and certificate URLs wrap when needed. The certificate URL remains copyable. This is a presentation-only update; keep `config.php` and `storage/` unchanged.

# Update notes — version 1.5.1

The public customer certificate now uses a light, printer-friendly theme aligned with the admin interface. Print styles suppress screen-only controls and shadows and use white backgrounds with dark text to reduce ink consumption. No database migration is required; leave `config.php` and the entire `storage/` folder unchanged during an update.

# Custom Watch Workshop Manager — PHP + SQLite Edition

This is a dependency-light PHP version of the Custom Watch Workshop Manager for **PHP hosting such as cPanel**. It uses a single SQLite database file and stores uploaded images locally. It does not need Python, Composer, MySQL server access, or a framework.

> SQLite is a self-contained database file. This package is **PHP + SQLite**, not MySQL. Your cPanel plan must enable the `pdo_sqlite` PHP extension.

## Features

- Password-protected first-run setup and sign-in; passwords use PHP's `password_hash()`.
- Parts, categories, stock/reorder levels, supplier link, costs, URLs and notes.
- Customers, full contact/address fields, order history.
- Suppliers and supplier-to-part relationships.
- Builds: Off-the-Shelf (reusable across multiple customer orders) and Custom Customer Build (requires one customer).
- Build bill of materials: link saved parts to builds, set quantities and per-build unit costs, and calculate parts cost, labour, other costs, total cost and profit.
- Orders linked to a build and customer. Total follows the build selling price; balance is calculated from the deposit. `Payment Completed` sets balance to zero and stores a completion date while retaining the deposit amount.
- Warranties linked to builds and customers, expiry and status, notes, and images.
- Multiple images per record, private authenticated image delivery, captions, primary image, upload and remove. Build and Order list views use generated 240×240 JPEG thumbnails rather than downloading original photographs.
- Editable dropdown lists and list values.
- CSV export and SQLite database backup.
- CSRF-protected forms, session cookies, upload type/size checks and escaped record output.
- Compatible table names and fields for the previous SQLite/Python web edition, with column migrations for payment and image fields.

## Requirements

- PHP **8.1 or newer**
- PHP extension `PDO_SQLite` (`pdo_sqlite`)
- PHP extension `fileinfo` for validating uploads
- PHP GD (`gd`) or Imagick for generating physical image thumbnails (recommended; list thumbnails remain visually constrained if neither is enabled)
- Apache or another server capable of serving PHP. Standard cPanel PHP hosting is suitable if the listed extensions are enabled.

No `composer install` is required.

## Install on cPanel

1. Confirm your hosting plan offers PHP 8.1+ and `PDO_SQLite`. In cPanel, check **Select PHP Version / PHP Extensions** or ask your host to enable `pdo_sqlite` and `fileinfo`.
2. Upload `CustomWatchWorkshopPHP.zip` and extract it under a subdomain or folder, for example `public_html/workshop/`.
3. **Recommended for production:** keep the writable data folder outside `public_html`. For example create `/home/CPANEL_USER/watchworkshop_data`, then in `config.php` change the fallback data directory to that absolute path, or set `WATCHWORKSHOP_DATA_DIR` if your hosting panel supports environment variables. The app will create `database`, `images`, and `exports` subfolders automatically.
4. If you leave `storage/` inside the application folder, its `.htaccess` denies direct HTTP access to the data and image directories. Do not remove those protections.
5. Ensure the PHP process has write permission to the data directory. Use the normal hosting file owner/group permissions; **do not use `777`**.
6. Visit the application URL. The first run redirects to the setup page; create the administrator username and a strong password (12+ characters).
7. Sign in and start adding records.

If image uploads fail due to host PHP limits, set `upload_max_filesize` to at least `16M` and `post_max_size` to `64M` in cPanel's PHP INI editor. Individual images are limited to 15 MB by the application.

## Data folder

Default folder structure:

```text
CustomWatchWorkshopPHP/
├── index.php
├── config.php
├── assets/style.css
└── storage/
    ├── .htaccess
    ├── database/watch_workshop.db
    ├── images/
    │   ├── parts/<record-id>/
    │   ├── builds/<record-id>/
    │   ├── customers/<record-id>/
    │   ├── orders/<record-id>/
    │   ├── suppliers/<record-id>/
    │   └── warranties/<record-id>/
    └── exports/
```

For a backup, copy the **entire data directory**, not only the database: images live separately from the SQLite database.

## Use an existing SQLite database from the previous app

1. Stop using the old app while copying, and make a separate backup first.
2. Copy the old `watch_workshop.db` into `storage/database/watch_workshop.db` (or into the configured `APP_DB_PATH` directory).
3. Copy the old `images/` directory into `storage/images/`, preserving the subfolder structure such as `images/builds/12/...`.
4. Open the PHP edition. It applies missing-column migrations without deliberately replacing existing records.
5. The old Python edition used a custom `scrypt$...` password hash that PHP cannot directly verify. If the sign-in page offers **Reset the login while retaining workshop records**, configure a new long random `WATCHWORKSHOP_SETUP_KEY` environment value or replace `APP_SETUP_KEY` in `config.php` with the same secret, then use that flow to set a PHP-compatible administrator login. Keep this key private; change/remove it after migration.

Do not copy a live SQLite database while the old app is actively writing to it. Stop the old app first, or make a consistent database backup.

## Global search and sortable lists

The navigation area includes **Search all records**, which searches parts, builds, customers, orders, suppliers, and warranties. On each module list, click a column heading to sort ascending or descending; click the same heading again to reverse the order. Search terms are retained when sorting. The Builds and Orders lists show primary-image thumbnails; Orders use their own primary image when available and otherwise fall back to the linked Build’s primary image.

When deploying this update over an existing installation, replace `index.php` and `assets/style.css`. Keep your `storage/` directory and `config.php` unchanged so the database, images, and hosting settings are preserved.

## CSV export

Open **Settings**, then choose the export for the table you need. CSV files can be opened in Excel. The PHP edition intentionally avoids a third-party XLSX dependency; direct `.xlsx` import is not included in this no-dependency package. Existing SQLite databases from the previous app are the preferred migration method.

## Security notes

- Use HTTPS for the site and protect the cPanel account.
- Keep `storage/` outside the public web root wherever possible. If stored inside the app folder, leave its `.htaccess` protections in place.
- Use a unique, strong administrator password. Change the migration setup key before importing a legacy database.
- This is a single-workshop system using SQLite. For high write concurrency or a multi-server deployment, use a database designed for that scale.
- Take regular full-folder backups. Keep at least one backup outside the hosting account.

## Troubleshooting

### “SQLite is not enabled”
Enable PHP's `pdo_sqlite` extension for the same PHP version assigned to the domain. PHP having the PDO base module is not sufficient by itself.

### The database is read-only / cannot create database
Make sure the configured data folder exists and is writable by the PHP process. Avoid `777`; use the host's recommended ownership and permissions.

### Images show broken links
Make sure the matching `storage/images/<section>/<record-id>/` folders were copied intact and that the database's `images` table rows were migrated with it.


## Image thumbnails

Build and Order list rows load a generated 240×240 JPEG thumbnail, while clicking the thumbnail opens the original image. On the first authenticated page load after this update, the app scans existing image records and creates missing thumbnails beside their original files using the `.thumb.jpg` suffix. New uploads generate thumbnails automatically, and deleting an image or record removes its corresponding thumbnail too. The migration uses a marker file in the data directory to avoid rescanning the full image library on every visit. If images are copied into the database after that initial pass, their thumbnail will still be generated on demand when the list requests it.

For automatic physical thumbnail generation, enable PHP GD or Imagick in cPanel. The application adds a warning in Settings if neither is available. The displayed list dimensions are also enforced inline so cached or stale CSS cannot cause a full-size image to expand the row.

When deploying this correction over an existing installation, replace `index.php`, `assets/style.css`, and `README.md`. Keep `config.php` and the entire `storage/` directory unchanged. The stylesheet URL is versioned in the PHP templates to force browsers to fetch the updated thumbnail rules.

## Configure the currency

Open **Settings → Currency**, choose the display currency, then select **Save Currency**. The setting is stored in the SQLite `settings` table and is shared across sessions. Supported currencies include GBP, USD, EUR, CAD, AUD, NZD, CHF, JPY, CNY, INR, SGD, AED, ZAR, SEK, NOK, DKK, BRL, PLN, and TRY. Monetary displays and relevant input labels update to use the selected currency; Japanese Yen is displayed without decimal places.

**Important:** changing this setting changes the currency format shown by the app only. It does not perform exchange-rate conversion or change the underlying numeric values. If your existing records contain values entered in a different currency, back up the database and reconcile those amounts before switching.


## Application version and What's New

The signed-in application displays its version badge in the header and version/build ID in the footer. The navigation includes **What's New**, the Dashboard highlights the latest release, and Settings shows the installed application version.

Release metadata lives in `version.php`, separately from the SQLite database and image directory. The current manifest contains the semantic version, build ID, release date, release title, summary, bullet notes and older release history. This means normal application updates can update version information without changing workshop data.

For each future build, update `version.php` and add an entry to `CHANGELOG.md`:

- **Patch** (for example `1.0.1`) for bug fixes and small safe corrections.
- **Minor** (for example `1.1.0`) for backward-compatible features.
- **Major** (for example `2.0.0`) for incompatible changes or a major redesign.
- Update the build ID (format recommended: `YYYYMMDD.N`) and release date, and write user-facing notes for the What's New page.

When deploying this release on top of an existing installation, replace `index.php`, `assets/style.css`, `version.php`, and optionally `README.md`/`CHANGELOG.md`. Keep `config.php` and the entire `storage/` directory unchanged.


## Workshop name and logo

Open **Settings → Workshop Name & Logo** to set or change the business/workshop name shown in the app header and sign-in/setup screens. Upload a PNG, JPG/JPEG, WEBP or GIF logo (maximum 5 MB); transparent PNG logos work especially well. You can replace the logo or remove it while keeping the workshop name.

The name is stored in the SQLite `settings` table. The uploaded logo is stored in `storage/branding/`, separate from record images. The application creates this folder automatically when you upload the first logo. Back up the full `storage/` directory to preserve it. The logo upload is validated by extension, actual image decoding and detected MIME type; the application streams the selected logo through a controlled endpoint rather than exposing its private storage path.

When deploying this version over an existing installation, replace `index.php`, `assets/style.css`, `version.php`, and optionally `README.md`/`CHANGELOG.md`. Keep `config.php` and `storage/` unchanged. The updated app will create `storage/branding/` automatically and uses the existing generic settings table, so no database migration is required.

## Copyright notice and project link

The application displays a copyright notice for **Moose-Shack Watches** linking to [moose-shack.com](https://www.moose-shack.com), plus a link to the [Watch Workshop Manager GitHub project](https://github.com/WildWayz/Watch-Workshop-Manager). These appear in the signed-in application footer and the login/setup screens. This update requires no database migration.

## Automatic watch serial numbers (v1.2.0)

Each order receives a unique serial number after the order is saved. The format is:

`R4ND-JOSM-B00012-O47`

- `R4ND`: random four-character prefix (ambiguous characters such as I, O, 0 and 1 are omitted from the random alphabet).
- `JOSM`: first two characters of the customer's first name plus the first two characters of their surname.
- `B00012`: the full Build Code.
- `O47`: the Order Code with unnecessary leading zeroes removed (for example, `O00047` becomes `O47`).

Serials belong to individual orders rather than the reusable build definition, so each physical watch has its own serial even when several customers buy the same off-the-shelf model. Existing orders receive serials automatically when the updated app next opens. Serials appear in order lists/details, customer order history, linked build order lists, search and CSV exports.



### Image previews and lightbox (v1.2.2)

All existing and new record images display as contained thumbnails or constrained previews by default. Clicking an image opens the full image in an on-screen lightbox, sized to fit the current viewport while preserving the original aspect ratio. Press Escape or click the close button/backdrop to return; when opening a gallery image, use the previous/next arrows or keyboard arrow keys to browse that record's gallery. Original images and database records are not modified.


## Image optimisation (v1.3.0)

New record-image uploads and workshop logo uploads are optimised automatically when the host has a supported image encoder. When possible, the application stores a high-quality WebP image (quality 86); otherwise it tries a format-preserving fallback such as JPEG quality 88 or lossless PNG compression. Images larger than 2560 px on the longest edge are resized only when the resulting file is smaller. Existing images are not changed silently on deployment: open **Settings → Image Optimisation** and run the optimiser to process old uploads in small batches. Progress, file counts and approximate bytes saved are shown on screen.

**Back up the complete storage directory before running bulk optimisation.** Optimisation can replace stored originals with a high-quality, smaller encoding and resize very large images; it is designed to avoid visible quality loss, but lossy WebP/JPEG re-encoding is not mathematically lossless. It replaces a file only when the result is smaller. GIF files are skipped to protect possible animation (this includes static GIFs). When an image changes from one extension to `.webp`, the image record and primary-image reference are updated automatically. Thumbnails are regenerated after each processed image. If GD or Imagick is unavailable, the Settings action is disabled; ask the host to enable PHP GD or Imagick and (ideally) the WebP encoder.

The `images.optimized_at` column is added automatically to existing SQLite databases; no manual database migration is required. Newly uploaded record images are marked as processed after the automatic optimisation attempt, so the bulk process only needs to handle older images. Existing logo files are checked the first time the bulk optimizer is run.

When deploying this release, replace `index.php`, `version.php`, and optionally `README.md` / `CHANGELOG.md`. Keep `config.php` and the entire `storage/` directory unchanged. Then back up `storage/` and run the optimiser from Settings.


## Image viewer update (v1.3.1)
Images open in a simple borderless viewer within the page. The viewer fits the original image to the available viewport and preserves its aspect ratio. Use the close control, Escape, or click the empty background to close it. This update changes only `index.php`, `assets/style.css`, and `version.php`; it does not require a database migration.

## First-run setup (v1.4.0)

On a fresh installation, the setup page now collects everything in one go:

1. Administrator username and password.
2. Company/workshop name (required).
3. Optional company logo (PNG, JPG/JPEG, WEBP or GIF, up to 5 MB).
4. Display currency (defaults to GBP).

The logo is saved under `storage/branding/`; the name and currency are stored in the existing SQLite `settings` table. The account and these settings are committed together, so a failed logo upload or database write will not leave a partially created account. You can change branding and currency later in Settings. Existing installations that already have an account continue to sign in normally; this combined page appears only during first-time setup (or the existing legacy-login reset flow, which remains separate).

The host must have PHP `PDO_SQLite` enabled. PHP `fileinfo` is recommended for upload MIME checks, and GD or Imagick enables logo optimisation. The logo itself is optional.


## Public watch certificates and order QR codes (v1.5.0)

Each order has a unique public certificate link generated from a random 192-bit token. On an Order detail page, the **Public Watch Certificate & QR Code** panel shows the QR code, the full URL, an **Open Certificate** button, a copy-URL control, and an option to regenerate the link. Regenerating the link invalidates the previous token/QR destination. The Orders list also has a Certificate shortcut.

On first use after updating, the application automatically adds a `public_token` column to existing orders and assigns each order an unguessable token. It also creates the `service_history` table, new certificate-specific build fields, and an image `category` column. No manual migration is required. Back up the complete `storage/` directory before deploying the update, as always.

The public certificate page is available to anyone who has its unique link. It is a light, printer-friendly certificate using a pale background, dark readable text and restrained green/gold accents that match the admin interface and shows the customer name (not email/address/phone), order/dispatch dates, serial number, watch details, warranty state, timegrapher measurements, checked quality-control items, explicit public certificate notes, service entries and categorized images. **Do not put private information in certificate notes or service notes**; anyone with the link can read these.

Builds include extra certificate fields for watch case/model, case material, case thickness, water resistance, dial details, lume, rate, amplitude, beat error, lift angle, QC checks and public certificate notes. These are managed through the Build form. Orders have a Service History panel where admins can log the service date, description and public notes. In the record image gallery, choose a category when uploading photos or change an existing image's category. The available categories are Build Photos, Promotional Photos, Regulated Photos and Other Photos. Existing images are assigned to Build Photos by default.

Open **Settings → Public Certificates & QR Codes** to set a canonical public URL when the app is installed in a subfolder or behind a proxy. Enter the full entry URL, for example `https://www.example.com/workshop/index.php`. Leaving it blank causes the app to derive the URL from the current request. Use HTTPS for public certificates.

QR codes are generated locally by the bundled `lib/qr_svg.php` pure-PHP SVG generator. There is no QR image-service request, no Composer dependency, and no certificate URL is sent to a third party. Existing orders switch to the self-hosted generator automatically; their public tokens and certificate URLs stay the same, so QR codes already printed remain valid. The built-in encoder supports certificate URLs up to 134 UTF-8 bytes (QR Version 6-L); if a custom public base URL is longer, shorten it in Settings.

For this update, replace `index.php`, `assets/style.css`, `version.php`, `README.md` and `CHANGELOG.md`; keep `config.php` and the entire `storage/` directory unchanged.


## Self-hosted QR codes and A5 certificates

Order QR codes now use the locally bundled `lib/qr_svg.php` generator and are served by `?action=qr_code`. The public token is checked against an existing order before a QR code is generated. Existing QR image displays are switched automatically without changing order tokens. In the admin Order view, choose **Print A5 Certificate** to open a print preview sized for A5 portrait paper. It includes watch/build details, serial number, warranty state, timegrapher fields, QC items, workshop branding and the local verification QR; it deliberately excludes watch/build photograph galleries.


## Version 1.6.1 — dropdown list management fix

Built-in dropdown lists and values now seed only once on a fresh database. This prevents the application from recreating renamed/deleted default lists or restoring edited/deleted default values after each request. Existing installations are marked initialized without overwriting their current list data. Duplicate list names and values now show clear validation messages, and failed list actions return to the Lists page. No database migration is required.


## Section-aware dropdown lists (v1.7.0)

Open **Lists** to manage dropdown values and decide where each list is available. Every list has checkboxes for Parts, Builds, Customers, Orders, Suppliers and Warranties. Save section access to control which records may use the list.

The **Link lists to form fields** table assigns one specific list to each dropdown field, such as Parts → Category/Material, Builds → Movement/Case Size/Dial/Hands/Bezel/Crystal/Strap/Status/Build Type, Customers → Country/Preferred Contact, Orders → Payment Method and Warranties → Status. Pick the intended list and save the field link. Saving a mapping also enables that list for the relevant section; you can later disable a section from the list card. A dropdown field uses a list only if the list is mapped to that field and enabled for that section.

This update creates two SQLite tables, `list_modules` and `list_field_mappings`, and performs a one-time migration to associate the existing built-in lists with their current fields. List/value records are preserved. The migration does not overwrite a mapping that already exists. The default lists are not re-seeded after the initial setup, so user edits and deletions persist.

For this update, replace `index.php`, `assets/style.css`, `version.php`, `README.md` and `CHANGELOG.md`. Keep `config.php` and the full `storage/` directory untouched. On the first authenticated request after deployment, the app creates the mapping tables and migrates the existing dropdowns automatically; no manual SQLite commands are required.
