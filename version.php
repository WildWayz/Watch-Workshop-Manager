<?php
declare(strict_types=1);

/*
 * Release metadata for Custom Watch Workshop Manager.
 * Update this file for every distributed build.
 * Semantic versioning: MAJOR.MINOR.PATCH.
 */
return [
    'version' => '1.9.0',
    'build' => '20261014.20',
    'released_on' => '2026-10-14',
    'title' => 'Theme switcher and appearance themes',
    'summary' => 'Choose Light, Dark, Forest, Ocean, Sunset or Plum appearance themes. The selected theme is remembered in this browser and applies consistently across the workshop manager.',
    'notes' => [
        'Added six selectable appearance themes: Light, Dark, Forest, Ocean, Sunset and Plum.',
        'Added a quick theme selector in the application header and a matching Appearance & Theme section in Settings.',
        'Theme choice is remembered in the current browser and restored on later visits, including login/setup pages.',
        'Dark mode adjusts tables, forms, cards, navigation, notices, badges and search panels for readable contrast.',
        'Settings now lets you set or change your workshop/business name.',
        'Upload a PNG, JPG/JPEG, WEBP or GIF logo and display it in the application header and sign-in/setup screens.',
        'Replace or remove the current logo without touching your workshop records, part images or build images.',
        'Branding values are stored in SQLite, while the uploaded logo is kept in the private branding folder.',
        'The visible version badge, build ID and What’s New release history remain in place.',
    ],
    'history' => [
        [
            'version' => '1.9.0',
            'released_on' => '2026-10-14',
            'title' => 'Theme switcher and appearance themes',
            'notes' => [
                'Choose Light, Dark, Forest, Ocean, Sunset or Plum themes from the header or Settings.',
                'The selected theme is remembered by the browser and applied across tables, forms, navigation and dashboard cards.',
                'Added high-contrast surface and form-control styles for Dark mode; print output remains light.',
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
