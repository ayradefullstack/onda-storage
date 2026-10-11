<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One encrypted derivative file on the `variants` disk. No SoftDeletes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $media_file_id
 * @property string $kind
 * @property int|null $page_index
 * @property string $path
 * @property string $nonce
 * @property int $size_bytes
 * @property bool $is_shared_variant
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ConsultationAsset extends Model
{
    use HasUuidColumn;

    protected function casts(): array
    {
        return [
            'page_index' => 'integer',
            'size_bytes' => 'integer',
            'is_shared_variant' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }
}
