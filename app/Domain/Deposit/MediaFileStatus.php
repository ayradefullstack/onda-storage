<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

/**
 * `media_files.status` string constants — the column stays a plain string
 * (the model and its migration are frozen this phase), but eight job
 * classes writing bare `'ready'`/`'quarantined'` literals is exactly how a
 * typo becomes a silent status that never matches `MediaFile::scopeStored()`.
 * Values match the enum in `2026_09_02_100001_create_media_files_table.php`
 * exactly.
 */
final class MediaFileStatus
{
    public const UPLOADING = 'uploading';

    public const ASSEMBLING = 'assembling';

    public const SCANNING = 'scanning';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const QUARANTINED = 'quarantined';
}
