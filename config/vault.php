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
    | Client upload concurrency
    |--------------------------------------------------------------------------
    |
    | Shared with the browser as an Inertia prop, so production can be tuned to
    | the host's PHP process limit without a rebuild. `client_max_in_flight` is
    | the chunk requests in flight across ALL files; `client_per_file_in_flight`
    | the most any one file may hold. 3 stays under both the HTTP/1.1 limit
    | of ~6 connections per origin and Herd's 4 PHP workers, leaving room for
    | navigation and polling. Keep it below the host's PHP worker count.
    |
    */

    'upload' => [
        'client_max_in_flight' => (int) env('VAULT_CLIENT_MAX_IN_FLIGHT', 3),
        'client_per_file_in_flight' => (int) env('VAULT_CLIENT_PER_FILE_IN_FLIGHT', 2),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | P5 pipeline
    |--------------------------------------------------------------------------
    |
    | Binaries are configurable by absolute path (not just PATH-resolved)
    | because a locally-installed tool (e.g. via winget on Windows) may not
    | be on the PATH the queue worker's process inherits, even though it's
    | on the interactive shell's.
    |
    */

    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),

    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),

    'clamdscan_binary' => env('CLAMDSCAN_BINARY', 'clamdscan'),

    // Consultation renderers. Absolute paths in .env on Windows, for the same
    // reason as ffmpeg above. Empty means "not installed": the family
    // degrades to an unsupported card, it never fails a deposit.
    'soffice_binary' => env('SOFFICE_BINARY', 'soffice'),

    'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),

    'probe_timeout_seconds' => (int) env('VAULT_PROBE_TIMEOUT', 30),

    'scan_timeout_seconds' => (int) env('VAULT_SCAN_TIMEOUT', 120),

    'variant_timeout_seconds' => (int) env('VAULT_VARIANT_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Deduplication lock
    |--------------------------------------------------------------------------
    |
    | Seconds DeduplicateFile holds Cache::lock($sha256) for while it checks
    | for, and links to, an existing file with the same content hash — long
    | enough to cover the lookup + row updates, short enough that a stuck
    | lock from a crashed worker clears itself quickly.
    |
    */

    'dedup_lock_seconds' => (int) env('VAULT_DEDUP_LOCK_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Variant generation
    |--------------------------------------------------------------------------
    */

    'poster_frame_percent' => (float) env('VAULT_POSTER_FRAME_PERCENT', 0.10),

    'preview_max_seconds' => (int) env('VAULT_PREVIEW_MAX_SECONDS', 30),

    'preview_max_height' => (int) env('VAULT_PREVIEW_MAX_HEIGHT', 480),

    /*
    |--------------------------------------------------------------------------
    | Doctor web endpoint
    |--------------------------------------------------------------------------
    |
    | `doctor_web_enabled` exposes /_vault-doctor outside `local`, signed URLs
    | only, and with a reduced php.ini-only payload. Keep it false except
    | during a diagnostic session.
    |
    | `doctor_resolve_ip` pins the host of `vault:doctor --fpm`'s request to
    | this IP via CURLOPT_RESOLVE, for servers behind NAT where the domain
    | resolves to a public IP from the server itself. TLS is still fully
    | verified against the domain name. Blank means no override.
    |
    */

    'doctor_web_enabled' => (bool) env('VAULT_DOCTOR_WEB_ENABLED', false),

    'doctor_resolve_ip' => env('VAULT_DOCTOR_RESOLVE_IP') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Consultation (admin review of derivatives)
    |--------------------------------------------------------------------------
    |
    | An admin never receives original bytes: every format is shown through a
    | server-generated derivative encrypted with the file's own DEK and a
    | stored random nonce. See app/Infrastructure/Render.
    |
    */

    'consult' => [
        'url_ttl_minutes' => (int) env('VAULT_CONSULT_URL_TTL', 30),
        'audit_window_minutes' => (int) env('VAULT_CONSULT_AUDIT_WINDOW', 10),
        'watermark' => (bool) env('VAULT_CONSULT_WATERMARK', true),
        'pdf_max_pages' => (int) env('VAULT_CONSULT_PDF_MAX_PAGES', 300),
        'page_max_edge' => (int) env('VAULT_CONSULT_PAGE_MAX_EDGE', 1600),
        'image_max_edge' => (int) env('VAULT_CONSULT_IMAGE_MAX_EDGE', 2000),
        'convert_timeout' => (int) env('VAULT_CONSULT_CONVERT_TIMEOUT', 180),
        // The `previews` queue connection's retry_after, and the job timeout
        // that must stay strictly below it (a test asserts it). The ffmpeg
        // render timeout must in turn stay below the job timeout.
        'retry_after' => (int) env('VAULT_CONSULT_RETRY_AFTER', 7200),
        'job_timeout' => (int) env('VAULT_CONSULT_JOB_TIMEOUT', 6600),
        'render_timeout' => (int) env('VAULT_CONSULT_RENDER_TIMEOUT', 5400),
        // libx264 speed/size trade-off for the full-length video derivative.
        'video_preset' => env('VAULT_CONSULT_VIDEO_PRESET', 'veryfast'),
        'video_crf' => (int) env('VAULT_CONSULT_VIDEO_CRF', 28),
        'video_max_height' => (int) env('VAULT_CONSULT_VIDEO_MAX_HEIGHT', 480),
        'audio_bitrate' => env('VAULT_CONSULT_AUDIO_BITRATE', '128k'),
        'sheet_max_rows' => (int) env('VAULT_CONSULT_SHEET_MAX_ROWS', 5000),
        'sheet_max_cols' => (int) env('VAULT_CONSULT_SHEET_MAX_COLS', 100),
        'sheet_max_bytes' => (int) env('VAULT_CONSULT_SHEET_MAX_BYTES', 15 * 1024 * 1024),
        'text_max_bytes' => (int) env('VAULT_CONSULT_TEXT_MAX_BYTES', 2 * 1024 * 1024),
        // Stuck-in-pending threshold for vault:doctor, seconds.
        'pending_warn_seconds' => (int) env('VAULT_CONSULT_PENDING_WARN', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Download policy
    |--------------------------------------------------------------------------
    |
    | `owner_enabled` gates the author's own original stream/link. It is
    | enforced server-side on issuance AND on the stream. There is no flag
    | that lets an admin download an original; that is a design decision.
    |
    */

    'download' => [
        'owner_enabled' => (bool) env('VAULT_OWNER_DOWNLOAD_ENABLED', true),
    ],

];
