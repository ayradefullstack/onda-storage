<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Chunk size
    |--------------------------------------------------------------------------
    |
    | 8 MiB, divisible by 16 so every chunk starts on an AES block boundary.
    | The browser splits uploads into chunks of this size; the server writes
    | each one at its byte offset into a pre-allocated file.
    |
    */

    'chunk_size' => (int) env('VAULT_CHUNK_SIZE', 8_388_608),

    /*
    |--------------------------------------------------------------------------
    | Maximum file size
    |--------------------------------------------------------------------------
    |
    | Hard ceiling per uploaded file. 5 GiB per CLAUDE.md.
    |
    */

    'max_file_size' => (int) env('VAULT_MAX_FILE_SIZE', 5 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | MAC segment size
    |--------------------------------------------------------------------------
    |
    | CTR is unauthenticated, so ciphertext is HMAC'd per 1 MiB segment and
    | the digests are stored in a sidecar `{uuid}.mac` file.
    |
    */

    'mac_segment_size' => (int) env('VAULT_MAC_SEGMENT_SIZE', 1_048_576),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days a soft-deleted media file's bytes are kept before the purge job
    | frees disk. Quota counts withTrashed()->whereNull('purged_at') until
    | then.
    |
    */

    'retention_days' => (int) env('VAULT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Temp file TTL
    |--------------------------------------------------------------------------
    |
    | Minutes an in-progress upload session (and its temp file on the
    | `incoming` disk) is allowed to live before it's considered expired.
    |
    */

    'temp_ttl_minutes' => (int) env('VAULT_TEMP_TTL_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | Master key path
    |--------------------------------------------------------------------------
    |
    | Path to the file holding the master KEK that wraps every per-file DEK.
    | Must live outside base_path() and outside every backup set — loss is
    | unrecoverable. Read via config() (never env() directly) so the value
    | survives config caching.
    |
    */

    'master_key_path' => env('VAULT_MASTER_KEY_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Driver bindings
    |--------------------------------------------------------------------------
    |
    | The four ports/adapters seams. Bound to concrete implementations in a
    | service provider from these config values — see CLAUDE.md's
    | local (Herd) vs. production (cPanel) table.
    |
    */

    'delivery_driver' => env('DELIVERY_DRIVER', 'stream'),

    'scan_driver' => env('SCAN_DRIVER', 'null'),

    'chunk_tracker_driver' => env('CHUNK_TRACKER_DRIVER', 'database'),

    'media_probe_driver' => env('MEDIA_PROBE_DRIVER', 'ffmpeg'),

];
