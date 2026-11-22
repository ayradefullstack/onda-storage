<?php

// Messages côté agent : conflits, refus, décisions par fichier et toasts de
// succès du parcours d'examen.

return [
    // ReviewConflict.
    'held_by' => ':name examine déjà cette oeuvre. Reprenez-la explicitement pour statuer.',
    'status_moved' => 'Cette oeuvre est maintenant « :status ». Rechargez la page avant de statuer.',
    'already_decided' => ':name a déjà statué sur cette oeuvre.',
    'not_under_review' => 'Cette oeuvre n’est pas en cours d’examen ; ouvrez son examen avant de statuer sur ses fichiers.',
    'reason_required' => 'Un rejet doit comporter un motif pour l’auteur.',

    // Refus d'enregistrement.
    'approval_refused' => 'Cette oeuvre ne peut pas encore être enregistrée.',
    'files_not_accepted' => 'Tous les fichiers doivent être acceptés avant l’enregistrement de cette oeuvre.',
    'file_not_accepted' => '« :name » n’a pas été accepté.',
    'file_with_decision' => ':name (:decision)',

    // Refus de décision par fichier.
    'file_accept_needs_ready' => 'Seul un fichier prêt peut être accepté ; ce fichier est « :status ».',
    'file_decide_needs_finished' => 'Ce fichier est encore « :status » ; il pourra faire l’objet d’une décision une fois son traitement terminé.',
    'file_removed' => 'Ce fichier a été retiré par l’auteur ; il ne peut plus faire l’objet d’une décision.',
    'file_foreign' => 'Ce fichier n’appartient pas à cette oeuvre.',

    // Toasts de succès.
    'toast_file_decision' => 'La décision sur le fichier a été enregistrée.',
    'toast_registered' => 'L’oeuvre a été enregistrée.',
    'toast_rejected' => 'L’auteur a été informé du motif du rejet de cette oeuvre.',
];
