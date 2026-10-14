<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.6.0',
    'build' => '20261014.12',
    'released_on' => '2026-10-14',
    'title' => 'Self-hosted QR codes and A5 watch certificates',
    'summary' => 'QR codes are now generated locally with no third-party QR image service, and admin users can print a branded A5 certificate without watch photos.',
    'notes' => [
        'Replaced external QR image service URLs with a bundled pure-PHP SVG QR generator. QR symbols are generated locally; no certificate URL is sent to an external QR service.',
        'Existing orders automatically use the local QR generator when their QR is displayed. Their existing public tokens and certificate URLs remain unchanged, so previously printed QR codes continue to work.',
        'Added an authenticated Print A5 Certificate action to each order. The print page includes customer, serial, order, build specification, warranty, timegrapher and quality-control details without product/build photographs.',
        'The A5 certificate includes workshop branding and a locally generated QR code for public certificate verification. It has A5 portrait print styling and a manual print button.',
        'No database migration is required; the bundled QR generator does not require Composer, GD or an external service.',
        'Each order receives an unguessable 48-character public token and a public certificate URL; existing orders are migrated automatically.',
        'Order details include a QR code, open/copy public URL controls, and the ability to regenerate the link to invalidate the old URL.',
        'The public certificate is styled as a printable certificate of authenticity with customer name, order dates, serial number, watch specification, warranty status, timegrapher metrics and QC checks.',
        'Added public service history and grouped Build Photos, Promotional Photos, Regulated Photos and Other Photos. Image access is limited to the linked order/build and its token.',
        'Added certificate-specific build fields and a Settings option to configure the canonical public application URL. No existing workshop records or image paths are removed.',
    ],
    'history' => [
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
