<?php

// Plain-text validation messages of the reference-data forms (those that are
// not frontend keys).

return [
    'extension_shape' => 'Each extension must be lowercase letters or digits only — no dot, no MIME type, no spaces.',
    'extension_shape_example' => 'Each extension must be lowercase letters or digits only — no dot, no MIME type, no spaces (e.g. "pdf", not ".PDF" or "application/pdf").',
    'extension_not_accepted' => 'That format is not one this application can accept. Executable and script formats are deliberately absent from the registry.',
    'extension_duplicate' => 'The same extension is listed more than once.',
];
