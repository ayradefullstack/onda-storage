<?php

// Runtime messages of the chunked-upload endpoints (chunk, complete) and the
// late-upload guard. The init-time validation messages live in upload.php.

return [
    'missing_chunks' => 'This upload is missing chunks and cannot be completed.',
    'multipart_body' => 'Chunk bodies must be sent as raw application/octet-stream, not multipart/form-data.',
    'session_expired' => 'This upload session has expired.',
    'session_not_accepting' => 'This upload session is not accepting chunks (status: :status).',
    'chunk_out_of_range' => 'Chunk index :index is out of range for this upload (0 to :max).',
    'crc_missing' => 'Missing X-Chunk-CRC32 header for chunk :index.',
    'crc_mismatch' => 'CRC32 mismatch for chunk :index; retry this chunk.',
    'chunk_length' => '{0} Chunk :index must be exactly :expected bytes (received :received).|{1} Chunk :index must be exactly :expected byte (received :received).|[2,*] Chunk :index must be exactly :expected bytes (received :received).',
    'oeuvre_decided' => 'This work has been decided; the file was not added.',
    'oeuvre_submitted' => 'This work has been submitted; the file was not added.',
];
