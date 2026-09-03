<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $work_id
 * @property int $uploaded_by
 * @property string $original_name
 * @property string $extension
 * @property string $mime
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string|null $sha256_plain
 * @property string $dek_wrapped
 * @property string $nonce
 * @property string $mac_path
 * @property string $status
 * @property int|null $duration_sec
 * @property int|null $width
 * @property int|null $height
 * @property int $ref_count
 * @property Carbon|null $scanned_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'dek_wrapped' => 'encrypted',
            'duration_sec' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'ref_count' => 'integer',
            'scanned_at' => 'datetime',
            'verified_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Work, $this>
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<MediaVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    /**
     * @return HasMany<FileAccessLog, $this>
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(FileAccessLog::class);
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }

    /**
     * @param  Builder<MediaFile>  $query
     * @return Builder<MediaFile>
     */
    public function scopeStored(Builder $query): Builder
    {
        return $query->where('status', 'ready')->whereNull('purged_at');
    }
}
