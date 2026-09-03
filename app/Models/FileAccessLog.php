<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\FileAccessLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail — no `updated_at`, a row is written once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $media_file_id
 * @property int|null $user_id
 * @property string $action
 * @property string $ip
 * @property string|null $user_agent
 * @property int|null $bytes_sent
 * @property string|null $range_header
 * @property string|null $prev_hash
 * @property string $row_hash
 * @property Carbon|null $created_at
 */
class FileAccessLog extends Model
{
    /** @use HasFactory<FileAccessLogFactory> */
    use HasFactory, HasUuidColumn;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'bytes_sent' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
