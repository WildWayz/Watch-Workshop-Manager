<?php
declare(strict_types=1);

/* Release metadata for Custom Watch Workshop Manager. */
return [
    'version' => '2.0.0',
    'build' => '20261014.21',
    'released_on' => '2026-10-14',
    'title' => 'Stability, full backup/restore and diagnostics',
    'summary' => 'Adds complete self-contained ZIP backups and verified restore with automatic pre-restore safety backup, plus a read-only stability report for SQLite integrity, relationships, images, balances, serials, storage permissions and hosting extensions. Retains dynamic dropdown fields, QR certificates, A5 certificates, image optimisation and six themes.',
    'notes' => [
        'Added a full ZIP backup containing a consistent SQLite database snapshot, all record images and thumbnails, and workshop branding/logo files.',
        'Added validated full-backup restore with archive manifest, per-file SHA-256 checksums, path-traversal/symlink protection and SQLite integrity/foreign-key checks before replacing live data.',
        'Before restore, automatically creates a full safety backup of the current database, images and branding. Rollback is attempted automatically if installation fails.',
        'Added a read-only stability check for database integrity, foreign keys, required tables, missing/empty image files, balance mismatches, duplicate serials, storage permissions and PHP extensions.',
        'Kept a database-only download as a fallback and made ZipArchive availability clearly visible in Settings.',
        'Fixed default dropdown seeding to happen only once, so renamed/deleted lists and values are not silently recreated on every page load.',
        'Combined the latest feature-complete build with the six application themes, preserving dynamic dropdown fields, QR certificate pages, A5 printing, image optimisation, search and list mappings.',
    ],
    'history' => [
        [
            'version' => '2.0.0', 'released_on' => '2026-10-14',
            'title' => 'Stability, full backup/restore and diagnostics',
            'notes' => [
                'Full self-contained ZIP backup and validated restore for database, images and branding.',
                'Automatic safety backup before restore, staged file replacement and rollback on failure.',
                'Read-only health report for SQLite integrity, foreign keys, missing images, stale balances, serial duplicates, writable paths and required PHP extensions.',
                'Restored dynamic dropdown fields and list assignments together with QR certificates, A5 certificates, service records, image optimisation and themes.',
            ],
        ],
        [
            'version' => '1.9.0', 'released_on' => '2026-10-14',
            'title' => 'Theme switcher and appearance themes',
            'notes' => ['Six selectable themes: Light, Dark, Forest, Ocean, Sunset and Plum.', 'Theme selection is remembered in the browser and applied throughout the admin interface.'],
        ],
        [
            'version' => '1.8.0', 'released_on' => '2026-10-14',
            'title' => 'Dynamic dropdown fields for all sections',
            'notes' => ['All created lists can be assigned to sections and form fields.', 'Custom field values are stored in SQLite, shown on record detail pages and exported to CSV.'],
        ],
        [
            'version' => '1.6.0', 'released_on' => '2026-10-14',
            'title' => 'Self-hosted QR codes and A5 certificates',
            'notes' => ['QR codes are generated locally.', 'Admin can print an A5 certificate without watch images.'],
        ],
        [
            'version' => '1.0.0', 'released_on' => '2026-10-14',
            'title' => 'Workshop manager foundation',
            'notes' => ['Parts, builds, bill of materials, customers, orders, suppliers and warranties.', 'SQLite data storage, image galleries, CSV export and application branding.'],
        ],
    ],
];
