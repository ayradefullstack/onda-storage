<?php

declare(strict_types=1);

namespace App\Enums\UploadSession;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `upload_sessions.status`. A session is created PENDING, becomes UPLOADING
 * once its file is pre-allocated (EncryptedLocalVault), and ends ABORTED or
 * is hard-deleted by CompleteUpload. COMPLETED is never written today; it is
 * only excluded by InitUpload's live-session query, and keeps its number.
 */
enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case PENDING = 1;
    case UPLOADING = 2;
    case ABORTED = 3;
    case COMPLETED = 4;

    public function label(): string
    {
        return __('enums.upload_session.status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'neutral',
            self::UPLOADING => 'info',
            self::ABORTED => 'destructive',
            self::COMPLETED => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::UPLOADING => 'upload',
            self::ABORTED => 'circle-x',
            self::COMPLETED => 'circle-check',
        };
    }

    /**
     * Sessions that no longer hold a live upload.
     *
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::ABORTED, self::COMPLETED];
    }
}
