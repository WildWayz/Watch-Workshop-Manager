<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.4.0',
    'build' => '20261014.9',
    'released_on' => '2026-10-14',
    'title' => 'Combined first-run workshop setup',
    'summary' => 'Combined administrator account creation, company name, optional logo upload and display currency into one first-run setup page.',
    'notes' => [
        'First-run setup now creates the administrator account and configures company/workshop name, optional logo, and currency on the same page.',
        'The logo is validated, saved in the existing private branding folder, and optimised using the existing image-processing workflow when supported.',
        'The company name, logo and currency are saved in the existing SQLite settings table in the same transaction as account creation. Existing installations and saved settings are preserved.',
        'Added a responsive setup layout and live logo/name preview. No database migration is required.',
    ],
    'history' => [
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
