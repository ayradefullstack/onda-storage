<?php

declare(strict_types=1);

// Labels of the App\Enums\** cases: enums.<model>.<attribute>.<key>.
// Kept in step with resources/js/locales/en.json where the UI shows the same status.

return [
    'oeuvre' => [
        'status' => [
            'draft' => 'Draft',
            'submitted' => 'Pending',
            'under_review' => 'Under review',
            'registered' => 'Registered',
            'rejected' => 'Rejected',
        ],
    ],
    'media_file' => [
        'status' => [
            'uploading' => 'Uploading',
            'assembling' => 'Finishing up',
            'scanning' => 'Checking',
            'processing' => 'Preparing',
            'ready' => 'Deposited',
            'failed' => 'Failed',
            'quarantined' => 'Held for review',
        ],
        'review_status' => [
            'pending' => 'To review',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
        ],
    ],
    'upload_session' => [
        'status' => [
            'pending' => 'Waiting',
            'uploading' => 'Uploading',
            'aborted' => 'Cancelled',
            'completed' => 'Uploaded',
        ],
    ],
    'media_variant' => [
        'kind' => [
            'poster' => 'Poster',
            'preview' => 'Preview clip',
            'waveform' => 'Waveform',
            'thumbnail' => 'Thumbnail',
        ],
    ],
    'file_access_log' => [
        'action' => [
            'stream' => 'Stream',
            'download' => 'Download',
            'preview' => 'Preview',
            'delete' => 'Deletion',
            'purge' => 'Purge',
            'deposit' => 'Deposit',
            'inspect' => 'Inspection',
            'admin_consult' => 'Officer consultation',
            'bytes_released' => 'Bytes released',
            'bytes_kept' => 'Bytes kept',
            'bytes_missing' => 'Bytes missing',
            'consult_original' => 'Original consulted',
        ],
    ],
    'media_consultation' => [
        'status' => [
            'pending' => 'Preparing',
            'ready' => 'Ready',
            'failed' => 'Failed',
            'unsupported' => 'Not available',
        ],
    ],
    'language' => [
        'direction' => [
            'ltr' => 'Left to right',
            'rtl' => 'Right to left',
        ],
    ],
    'type_gestion' => [
        'type_gestion' => [
            'collective' => 'Collective management',
            'individual' => 'Individual management',
            'simple' => 'Simple protection',
        ],
    ],
    'referentiel' => [
        'status' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ],
    ],
];
