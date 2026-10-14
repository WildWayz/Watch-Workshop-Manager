<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.1.0',
    'build' => '20261014.2',
    'released_on' => '2026-10-14',
    'title' => 'Workshop branding and logo settings',
    'summary' => 'This release lets you set the workshop/business name and upload a logo, displayed in the application header and sign-in/setup screens.',
    'notes' => [
        'Settings now lets you set or change your workshop/business name.',
        'Upload a PNG, JPG/JPEG, WEBP or GIF logo and display it in the application header and sign-in/setup screens.',
        'Replace or remove the current logo without touching your workshop records, part images or build images.',
        'Branding values are stored in SQLite, while the uploaded logo is kept in the private branding folder.',
        'The visible version badge, build ID and What’s New release history remain in place.',
    ],
    'history' => [
        [
            'version' => '1.0.0',
            'released_on' => '2026-10-14',
            'title' => 'Version tracking and What’s New',
            'notes' => [
                'Added the visible version badge, build ID, What’s New page and release history.',
                'Added configurable currency, global search, sortable list headings, primary-image thumbnails and linked workshop management sections.',
            ],
        ],
        [
            'version' => 'Unversioned',
            'released_on' => 'Before 2026-10-14',
            'title' => 'Workshop manager features retained from earlier releases',
            'notes' => [
                'Parts, customers, suppliers, builds, orders, warranties, and editable dropdown lists.',
                'Reusable Off-the-Shelf builds and Custom Customer Builds, with build components linked to Parts for cost and profit calculations.',
                'Deposits, outstanding balances, payment completion, order records, and warranty details.',
                'Multiple-image galleries and generated thumbnails for Builds and Orders.',
                'Global search, sortable list headings, configurable currency, CSV exports, and SQLite database backups.',
            ],
        ],
    ],
];
