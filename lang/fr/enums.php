<?php

declare(strict_types=1);

// Labels of the App\Enums\** cases: enums.<model>.<attribute>.<key>.
// Kept in step with resources/js/locales/fr.json where the UI shows the same status.

return [
    'oeuvre' => [
        'status' => [
            'draft' => 'Brouillon',
            'submitted' => 'En attente',
            'under_review' => 'En examen',
            'registered' => 'Enregistrée',
            'rejected' => 'Rejetée',
        ],
    ],
    'media_file' => [
        'status' => [
            'uploading' => 'Téléversement',
            'assembling' => 'Finalisation',
            'scanning' => 'Vérification',
            'processing' => 'Préparation',
            'ready' => 'Déposé',
            'failed' => 'Échec',
            'quarantined' => "En attente d'examen",
        ],
    ],
    'upload_session' => [
        'status' => [
            'pending' => 'En attente',
            'uploading' => 'Téléversement',
            'aborted' => 'Annulé',
            'completed' => 'Téléversé',
        ],
    ],
    'media_variant' => [
        'kind' => [
            'poster' => 'Affiche',
            'preview' => 'Extrait',
            'waveform' => "Forme d'onde",
            'thumbnail' => 'Vignette',
        ],
    ],
    'file_access_log' => [
        'action' => [
            'stream' => 'Lecture',
            'download' => 'Téléchargement',
            'preview' => 'Aperçu',
            'delete' => 'Suppression',
            'purge' => 'Purge',
            'deposit' => 'Dépôt',
            'inspect' => 'Inspection',
            'admin_consult' => 'Consultation (agent)',
            'bytes_released' => 'Octets libérés',
            'bytes_kept' => 'Octets conservés',
            'bytes_missing' => 'Octets manquants',
            'consult_original' => "Consultation de l'original",
        ],
    ],
    'media_consultation' => [
        'status' => [
            'pending' => 'Préparation',
            'ready' => 'Prêt',
            'failed' => 'Échec',
            'unsupported' => 'Indisponible',
        ],
    ],
    'language' => [
        'direction' => [
            'ltr' => 'De gauche à droite',
            'rtl' => 'De droite à gauche',
        ],
    ],
    'type_gestion' => [
        'type_gestion' => [
            'collective' => 'Gestion collective',
            'individual' => 'Gestion individuelle',
            'simple' => 'Protection simple',
        ],
    ],
    'referentiel' => [
        'status' => [
            'active' => 'Actif',
            'inactive' => 'Inactif',
        ],
    ],
];
