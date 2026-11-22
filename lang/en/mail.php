<?php

return [
    // Shared corporate mail layout (resources/views/mail/corporate.blade.php).
    'republic' => "People's Democratic Republic of Algeria",
    'office_name' => 'National Office of Copyright and Neighbouring Rights',
    'address' => 'Rue du Grand Séminaire, Bologhine, Algiers.',
    'copyright' => '© :year ONDA Algeria. All rights reserved.',
    'features_title' => 'Official ONDA guarantees and services',
    'feature1_title' => 'Immediate legal protection',
    'feature1_desc' => 'Sovereign timestamping and legal registration of your intellectual creations.',
    'feature2_title' => 'Digital certificates and declarations',
    'feature2_desc' => 'Instant access to your deposit certificates and follow-up of your financial rights.',
    'feature3_title' => 'Sovereign, encrypted hosting',
    'feature3_desc' => 'Your data and files are hosted on secure servers in Algeria.',
    'action_default' => 'Confirm',
    'warning_title' => 'Security notice',
    'warning_text' => 'If you did not make this request, no action is required. Never share your credentials or security links.',

    // MediaFileFailedNotification (to the uploader).
    'file_failed' => [
        'subject' => 'Processing of your deposit failed — ONDA',
        'title' => 'Processing of your deposit failed',
        'greeting' => 'Hello,',
        'line_failed' => 'The processing of your file ":file" has failed.',
        'line_team' => 'Our technical team has been notified. You do not need to upload this file again for now.',
        'line_contact' => 'We will contact you if any action is required on your part.',
    ],

    // MediaFileQuarantinedNotification (to admins).
    'file_quarantined' => [
        'subject' => 'File quarantined — ONDA',
        'title' => 'File quarantined',
        'greeting' => 'Administrator alert',
        'line_quarantined' => 'The file ":file" (uuid :uuid) was quarantined by the antivirus scan.',
        'line_review' => 'The bytes were moved, never deleted — a manual review is required before any decision.',
    ],
];
