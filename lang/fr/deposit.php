<?php

// Messages côté auteur : blocages de soumission, refus de la machine d'états,
// retrait de fichiers et ses conflits, et toasts de succès de ces parcours.

return [
    // Refus de OeuvreStatusMachine (:from / :to sont des libellés de statut).
    'illegal_between' => 'Cette oeuvre ne peut pas passer de « :from » à « :to ».',
    'illegal_terminal' => 'Cette oeuvre est enregistrée. Un dépôt enregistré est définitif et ne peut pas passer à « :to ».',
    'illegal_actor' => 'Vous n’êtes pas autorisé à faire passer cette oeuvre de « :from » à « :to ».',

    // Blocages et avis de SubmissionGate.
    'submission_not_ready' => 'Cette oeuvre n’est pas prête à être soumise.',
    'no_files' => 'Cette oeuvre ne contient encore aucun fichier.',
    'file_in_pipeline' => '« :name » est encore en cours de traitement (:status). Attendez la fin du traitement.',
    'file_failed' => 'Le traitement de « :name » a échoué. Retirez-le ou téléversez-le de nouveau.',
    'file_quarantined' => '« :name » a été mis en quarantaine par l’analyse antivirus. Retirez-le.',
    'file_rejected' => '« :name » a été rejeté par l’ONDA. Retirez-le et téléversez un fichier conforme.',
    'slot_empty' => 'Le document requis « :name » n’a pas encore de fichier.',
    'slot_empty_conditional' => 'Le document « :name » est vide ; il ne s’applique peut-être pas à cette oeuvre.',

    // Refus de RemovalGate.
    'removal_mid_pipeline' => '« :name » est encore en cours de traitement (:status). Attendez la fin du traitement, puis retirez-le.',
    'removal_still_uploading' => '« :name » est encore en cours de téléversement. Annulez le téléversement au lieu de le retirer.',

    // RemovalConflict (409) : l’auteur a perdu une course contre un agent.
    'removal_no_longer_rejected' => 'Ce fichier n’est plus rejeté ; il ne peut plus être retiré.',
    'removal_not_removable' => 'Ce fichier vient d’être accepté par l’ONDA ; il ne peut plus être retiré.',
    'removal_oeuvre_decided' => 'Cette oeuvre a fait l’objet d’une décision ; ses fichiers ne peuvent plus être modifiés.',

    // Toasts de succès.
    'toast_submitted' => 'Votre oeuvre a été soumise pour examen.',
    'toast_deleted' => 'L’oeuvre a été supprimée.',
    'toast_file_removed' => 'Le fichier a été retiré de cette oeuvre.',
];
