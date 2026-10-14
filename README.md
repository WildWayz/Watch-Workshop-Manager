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
