<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Consultation state for one media file. No SoftDeletes: regenerable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $media_file_id
 * @property string $family
 * @property string $status pending|ready|failed|unsupported
 * @property int|null $page_count
 * @property string|null $reason
 * @property CarbonInterface|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaConsultation extends Model
{
    use HasUuidColumn;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const UNSUPPORTED = 'unsupported';

    /**
     * The file's row, unsaved when it has none yet. (No mass assignment in
     * this codebase: columns are set explicitly.)
     */
    public static function forFile(MediaFile $mediaFile): self
    {
        $consultation = self::where('media_file_id', $mediaFile->id)->first();

        if ($consultation === null) {
            $consultation = new self;
            $consultation->media_file_id = $mediaFile->id;
        }

        return $consultation;
    }

    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'generated_at' => 'datetime',
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
     * @return HasMany<ConsultationAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ConsultationAsset::class, 'media_file_id', 'media_file_id');
    }
}
