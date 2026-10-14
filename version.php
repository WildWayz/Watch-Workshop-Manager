<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.1.1',
    'build' => '20261014.3',
    'released_on' => '2026-10-14',
    'title' => 'Copyright and project links',
    'summary' => 'Added a Moose-Shack Watches copyright notice linked to the official website and a link to the Watch Workshop Manager GitHub project.',
    'notes' => [
        'Added a copyright notice for Moose-Shack Watches with a link to https://www.moose-shack.com.',
        'Added a link to the Watch Workshop Manager GitHub project.',
        'Displayed the notice in the signed-in application footer and on the login/setup screens.',
        'Kept workshop branding, currency settings, database records and image storage unchanged.',
    ],
    'history' => [
        [
            'version' => '1.1.0',
            'released_on' => '2026-10-14',
            'title' => 'Workshop name and logo settings',
            'notes' => [
                'Added Settings controls for setting/changing the workshop or business name.',
                'Added logo upload, preview, replace and remove functionality for PNG, JPG/JPEG, WEBP and GIF files.',
                'Displayed saved branding in the application header and login/setup screens.',
            ],
        ],
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
