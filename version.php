<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.8.0',
    'build' => '20261014.19',
    'released_on' => '2026-10-14',
    'title' => 'Dynamic dropdown fields for all sections',
    'summary' => 'Every created dropdown list is visible in the list-to-field overview. Lists enabled for a section automatically become custom dropdown fields when they are not already mapped to an existing form field.',
    'notes' => [
        'Every created dropdown list now appears in a list-centric overview under Lists → Link lists to form fields, including enabled sections, existing field links and dynamically added fields.',
        'Enabling an unmapped list for Parts, Builds, Customers, Orders, Suppliers or Warranties automatically adds a dropdown field named after the list to that section’s add/edit form; names matching supported categorical text fields convert those existing fields instead.',
        'Custom field values are stored in the new dynamic_field_values SQLite table, displayed on record detail pages and included in CSV export without adding database columns for user-created fields.',
        'Existing fixed-field mappings remain available and all created lists are included in their selection menus; lists already mapped to a fixed field are not duplicated within the same section.',
    ],
    'history' => [
        [
            'version' => '1.8.0',
            'released_on' => '2026-10-14',
            'title' => 'Dynamic dropdown fields for all sections',
            'notes' => [
                'Every created dropdown list is visible in the list overview and in all existing field-mapping selection menus.',
                'Enabling a list for a section adds a dropdown field named after that list to the section’s add/edit form unless it is already assigned to an existing field there; matching categorical text fields such as Case Material can be converted into dropdowns.',
                'Dynamic values are saved in SQLite, shown on record detail pages and included in CSV export.',
                'Existing records, built-in mappings, uploaded images and stored list values are preserved.',
            ],
        ],
        [
            'version' => '1.7.3',
            'released_on' => '2026-10-14',
            'title' => 'Ensure dropdown values appear in forms',
            'notes' => [
                'Parts, Builds, Customers, Orders and Warranties now resolve dropdown values using saved field mappings with a fallback to the form field’s configured default list.',
                'Added a one-time repair for existing databases when a field mapping is missing or points at an empty list while its standard list contains values.',
                'Preserves existing valid custom mappings and enables their linked sections; legacy forms show a helpful link to Dropdown Lists if no options are configured.',
            ],
        ],
        [
            'version' => '1.7.2',
            'released_on' => '2026-10-14',
            'title' => 'Repair dropdown list field links',
            'notes' => [
                'Dropdown fields read values from the saved field mapping rather than failing silently when a legacy section-access row is missing.',
                'Added a one-time migration to repair missing mappings and access rows on existing installations.',
                'Selecting a standard list for its intended section automatically maps it to the matching form field; custom lists can be linked explicitly in the field-mapping table.',
                'Lists show their linked section/field in the manager, and a mapped list cannot accidentally be disabled for that section.',
            ],
        ],
        [
            'version' => '1.7.1',
            'released_on' => '2026-10-14',
            'title' => 'Keep position when editing dropdown lists',
            'notes' => [
                'Added scroll restoration for list add, rename, delete, value add/edit/delete, section access and field mapping submissions.',
                'Preserves the viewport position of the active list card or field-mapping area after the normal post/redirect/get cycle.',
                'No database migration; all existing user data and dropdown settings remain unchanged.',
            ],
        ],
        [
            'version' => '1.7.0',
            'released_on' => '2026-10-14',
            'title' => 'Section-aware dropdown lists and field mappings',
            'notes' => [
                'Added section checkboxes to each dropdown list for Parts, Builds, Customers, Orders, Suppliers and Warranties.',
                'Added explicit field mapping controls so each dropdown field uses the correct list, based on stable list IDs rather than names.',
                'Existing built-in dropdowns are migrated automatically without overwriting an existing user mapping.',
                'Dropdown defaults are not re-seeded after initial setup, so changes remain persistent.',
            ],
        ],
        [
            'version' => '1.6.2',
            'released_on' => '2026-10-14',
            'title' => 'Long URL layout fix',
            'notes' => [
                'Part Purchase URL and Supplier Website fields show concise labelled links with the full target URL in a tooltip.',
                'Supplier list website cells no longer print the full URL, avoiding wide cells and overlapping text.',
                'Added wrapping rules for long values in detail panels and table cells.',
                'The public certificate URL now wraps across lines while retaining Copy URL functionality.',
                'No database migration; existing records and links remain unchanged.',
            ],
        ],
        [
            'version' => '1.6.1',
            'released_on' => '2026-10-14',
            'title' => 'Persistent editable dropdown lists',
            'notes' => [
                'Fixed default dropdown lists and values being re-seeded on every page request, which caused user edits and deletions to be undone.',
                'Default lists are seeded once on fresh databases. Existing installations are marked initialized without changing current list data.',
                'Added validation for duplicate list names/values and return-to-list navigation when an action fails.',
            ],
        ],
        [
            'version' => '1.6.0',
            'released_on' => '2026-10-14',
            'title' => 'Self-hosted QR codes and A5 watch certificates',
            'notes' => [
                'Bundled a pure-PHP, self-hosted SVG QR generator. Existing order QR displays now use the local endpoint instead of an external QR image service.',
                'Added a Print A5 Certificate option to Order details. The printer-friendly certificate includes watch/build specifications, serial, warranty, readings and QC information without build or promotional photographs.',
                'Kept existing order tokens and public URLs unchanged; no database migration required.',
            ],
        ],
        [
            'version' => '1.5.1',
            'released_on' => '2026-10-14',
            'title' => 'Light, printer-friendly customer certificates',
            'notes' => [
                'Updated public customer certificate pages to a white/pale background, dark text and restrained green/gold accents aligned with the admin interface.',
                'Added print-specific styling to reduce ink use, remove shadows and screen-only controls, and improve contrast on paper.',
                'No database changes; certificate data, QR links and photographs remain unchanged.',
            ],
        ],
        [
            'version' => '1.5.0',
            'released_on' => '2026-10-14',
            'title' => 'Public watch certificates and order QR codes',
            'notes' => [
                'Added unique QR-linked public watch certificate pages, specification fields, warranty state, metrics, quality checks, service history and categorized photos.',
            ],
        ],
        [
            'version' => '1.4.0',
            'released_on' => '2026-10-14',
            'title' => 'Combined first-run workshop setup',
            'notes' => [
                'Combined administrator account creation, company/workshop name, optional logo upload and display currency into one first-run setup page.',
                'Saved branding and currency in the existing SQLite settings table and kept existing installations intact.',
            ],
        ],
        [
            'version' => '1.3.1',
            'released_on' => '2026-10-14',
            'title' => 'Simple borderless image viewer',
            'notes' => [
                'Replaced the previous custom lightbox with a simpler native browser dialog to improve reliability.',
                'Clicking supported images opens a borderless, viewport-sized viewer; the original aspect ratio is preserved and the image fits within the screen.',
            ],
        ],
        [
            'version' => '1.3.0',
            'released_on' => '2026-10-14',
            'title' => 'High-quality image optimisation',
            'notes' => [
                'Added Settings controls to optimise previously uploaded images in batches.',
                'New record-image uploads and workshop logos are optimised automatically where supported.',
            ],
        ],
        [
            'version' => '1.2.2',
            'released_on' => '2026-10-14',
            'title' => 'Viewport image lightbox and scaled previews',
            'notes' => [
                'Record images display as compact thumbnails/previews and open in a viewport-fitted lightbox.',
                'Added Escape, close button, backdrop click and gallery navigation controls.',
            ],
        ],
        [
            'version' => '1.2.1',
            'released_on' => '2026-10-14',
            'title' => 'Image thumbnails',
            'notes' => [
                'Added physical thumbnail generation for existing image files and small list thumbnails.',
                'This release completes the image viewing workflow with consistent thumbnail display and a screen-fitted lightbox.',
            ],
        ],
        [
            'version' => '1.2.0',
            'released_on' => '2026-10-14',
            'title' => 'Automatic individual watch serial numbers',
            'notes' => [
                'Automatically generates a unique serial number for each order using a random four-character prefix, customer initials, full build code, and compact order reference.',
                'Existing orders receive serial numbers during database upgrade; serials appear throughout the application and exports.',
            ],
        ],
        [
            'version' => '1.1.1',
            'released_on' => '2026-10-14',
            'title' => 'Copyright and project links',
            'notes' => [
                'Added the Moose-Shack Watches copyright notice and official website link.',
                'Added the Watch Workshop Manager GitHub project link.',
            ],
        ],
        [
            'version' => '1.1.0',
            'released_on' => '2026-10-14',
            'title' => 'Workshop name and logo settings',
            'notes' => [
                'Added Settings controls for the workshop/business name and logo upload, preview, replace and remove.',
                'Displayed saved branding in the application header and login/setup screens.',
            ],
        ],
        [
            'version' => '1.0.0',
            'released_on' => '2026-10-14',
            'title' => 'Version tracking and workshop management',
            'notes' => [
                'Added the visible version badge, What’s New page and release history.',
                'Included configurable currency, global search, sortable lists, primary-image thumbnails, linked workshop records, BOM costing, payments, and warranties.',
            ],
        ],
        [
            'version' => 'Unversioned',
            'released_on' => 'Before 2026-10-14',
            'title' => 'Workshop manager features retained from earlier releases',
            'notes' => [
                'Parts, customers, suppliers, builds, orders, warranties, editable dropdown lists and multiple-image galleries.',
                'Reusable Off-the-Shelf builds and Custom Customer Builds with component-level costs.',
                'Deposits, outstanding balances, payment completion, thumbnail generation and SQLite backups.',
            ],
        ],
    ],
];
