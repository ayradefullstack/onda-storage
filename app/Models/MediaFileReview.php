<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Models\Concerns\SerializesEnumsAsKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in a file's review history: who decided, from what, to what,
 * why, and whether they had consulted the file.
 *
 * Append-only, like OeuvreReview, and enforced here rather than by
 * convention: saving an existing row or deleting one throws. Rows are
 * written only by App\Actions\Review\DecideMediaFile.
 *
 * @property int $id
 * @property string $uuid
 * @property int $media_file_id
 * @property int $oeuvre_id
 * @property int|null $reviewer_id
 * @property string|null $reason
 * @property ReviewStatusEnum $from_status
 * @property ReviewStatusEnum $to_status
 * @property bool $was_consulted
 * @property Carbon $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaFileReview extends Model
{
    use HasUuidColumn, SerializesEnumsAsKeys;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('media_file_reviews is append-only: a decision record cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('media_file_reviews is append-only: a decision record cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => ReviewStatusEnum::class,
            'to_status' => ReviewStatusEnum::class,
            'was_consulted' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Oeuvre, $this>
     */
    public function oeuvre(): BelongsTo
    {
        return $this->belongsTo(Oeuvre::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
