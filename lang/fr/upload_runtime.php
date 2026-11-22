<?php

// Messages d'exécution des points d'entrée de téléversement par morceaux
// (chunk, complete) et du garde-fou des envois tardifs. Les messages de
// validation à l'initialisation sont dans upload.php.

return [
    'missing_chunks' => 'Il manque des morceaux à ce téléversement : il ne peut pas être finalisé.',
    'multipart_body' => 'Le corps d’un morceau doit être envoyé en application/octet-stream brut, et non en multipart/form-data.',
    'session_expired' => 'Cette session de téléversement a expiré.',
    'session_not_accepting' => 'Cette session de téléversement n’accepte plus de morceaux (statut : :status).',
    'chunk_out_of_range' => 'L’indice de morceau :index est hors limites pour ce téléversement (de 0 à :max).',
    'crc_missing' => 'L’en-tête X-Chunk-CRC32 est absent pour le morceau :index.',
    'crc_mismatch' => 'Le CRC32 du morceau :index ne correspond pas ; renvoyez ce morceau.',
    'chunk_length' => '{0} Le morceau :index doit faire exactement :expected octet (reçu : :received).|{1} Le morceau :index doit faire exactement :expected octet (reçu : :received).|[2,*] Le morceau :index doit faire exactement :expected octets (reçu : :received).',
    'oeuvre_decided' => 'Cette oeuvre a fait l’objet d’une décision ; le fichier n’a pas été ajouté.',
    'oeuvre_submitted' => 'Cette oeuvre a été soumise ; le fichier n’a pas été ajouté.',
];
