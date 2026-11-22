<?php

return [
    // Shared corporate mail layout (resources/views/mail/corporate.blade.php).
    'republic' => 'République Algérienne Démocratique et Populaire',
    'office_name' => "Office National des Droits d'Auteur et Droits Voisins",
    'address' => 'Rue du Grand Séminaire, Bologhine, Alger.',
    'copyright' => '© :year ONDA Algérie. Tous droits réservés.',
    'features_title' => 'Garanties et services officiels ONDA',
    'feature1_title' => 'Protection juridique immédiate',
    'feature1_desc' => 'Horodatage souverain et enregistrement légal de vos créations intellectuelles.',
    'feature2_title' => 'Certificats et déclarations numériques',
    'feature2_desc' => 'Accès instantané à vos attestations de dépôt et suivi de vos droits financiers.',
    'feature3_title' => 'Hébergement souverain et chiffré',
    'feature3_desc' => 'Vos données et fichiers sont hébergés sur des serveurs sécurisés en Algérie.',
    'action_default' => 'Confirmer',
    'warning_title' => 'Avis de sécurité',
    'warning_text' => 'Si vous n’êtes pas à l’origine de cette demande, aucune action n’est requise. Ne transmettez jamais vos identifiants ou liens de sécurité.',

    // MediaFileFailedNotification (to the uploader).
    'file_failed' => [
        'subject' => 'Échec du traitement de votre dépôt — ONDA',
        'title' => 'Échec du traitement de votre dépôt',
        'greeting' => 'Bonjour,',
        'line_failed' => 'Le traitement de votre fichier « :file » a échoué.',
        'line_team' => 'Notre équipe technique a été notifiée. Vous n’avez pas besoin de redéposer ce fichier pour le moment.',
        'line_contact' => 'Nous vous contacterons si une action de votre part est nécessaire.',
    ],

    // MediaFileQuarantinedNotification (to admins).
    'file_quarantined' => [
        'subject' => 'Fichier mis en quarantaine — ONDA',
        'title' => 'Fichier mis en quarantaine',
        'greeting' => 'Alerte administrateur',
        'line_quarantined' => "Le fichier « :file » (uuid :uuid) a été mis en quarantaine par l'analyse antivirus.",
        'line_review' => 'Les octets ont été déplacés, jamais supprimés — une vérification manuelle est nécessaire avant toute décision.',
    ],
];
