<?php

// Generic HTTP error texts raised by abort() at the presentation boundary
// (stream, consultation and diagnostic endpoints).

return [
    'integrity_failed' => 'This deposit failed an integrity check and could not be read.',
    'link_other_session' => 'This link was issued to a different session.',
    'downloads_disabled' => 'Downloading originals is disabled.',
    'preview_not_ready' => 'This preview is not ready.',
    'forbidden' => 'Forbidden.',
];
