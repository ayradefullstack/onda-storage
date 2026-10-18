<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Models\Concerns\SerializesEnumsAsKeys;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $oeuvre_id
 * @property int|null $college_oeuvre_file_id
 * @property string|null $document_key_snapshot
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
 * @property MediaFileStatus $status
 * @property ReviewStatusEnum $review_status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_reason
 * @property int|null $replaces_media_file_id
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
    use HasFactory, HasUuidColumn, SerializesEnumsAsKeys, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => MediaFileStatus::class,
            'review_status' => ReviewStatusEnum::class,
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<Oeuvre, $this>
     */
    public function oeuvre(): BelongsTo
    {
        return $this->belongsTo(Oeuvre::class);
    }

    /**
     * The required-document slot this file satisfies. Null for files deposited
     * before per-requirement uploads; `document_key_snapshot` holds the key as
     * of the upload even if this row is later re-keyed or retired.
     *
     * @return BelongsTo<CollegeOeuvreFile, $this>
     */
    public function collegeOeuvreFile(): BelongsTo
    {
        return $this->belongsTo(CollegeOeuvreFile::class);
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
     * @return HasOne<MediaConsultation, $this>
     */
    public function consultation(): HasOne
    {
        return $this->hasOne(MediaConsultation::class);
    }

    /**
     * @return HasMany<ConsultationAsset, $this>
     */
    public function consultationAssets(): HasMany
    {
        return $this->hasMany(ConsultationAsset::class);
    }

    /**
     * The officer whose decision `review_status` holds (withTrashed: a
     * retired officer still names their decision).
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    /**
     * The removed, rejected file this one replaces (set only on a
     * replacement uploaded during review). withTrashed: the replaced file
     * is always soft-deleted.
     *
     * @return BelongsTo<MediaFile, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'replaces_media_file_id')->withTrashed();
    }

    /**
     * Every replacement uploaded for this (removed, rejected) file, oldest
     * first, removed ones included: the author may withdraw a pending
     * replacement and upload another. At most one is live at a time.
     *
     * @return HasMany<MediaFile, $this>
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'replaces_media_file_id')->withTrashed()->orderBy('id');
    }

    /**
     * Removed, rejected files of an oeuvre that are still waiting for a
     * replacement — each one opens its slot for exactly one replacement
     * upload while the oeuvre is under review.
     *
     * A removed rejected file counts as replaced while a replacement row
     * points at it that is either live, or removed after being rejected in
     * turn (the chain then continues from that replacement, which is itself
     * a removed rejected file). A replacement the author withdrew while it
     * was pending does not count: the slot reopens for the same file.
     * Oldest first, which is the order
     * InitUpload reserves them in (InitUpload::unreservedReplacements also
     * drops the ones a live upload session already holds). `$slotId` null
     * means the unclassified oeuvre's single, slot-less area.
     *
     * @param  int|list<int>  $oeuvreId
     * @return Builder<MediaFile>
     */
    public static function openReplacements(int|array $oeuvreId, ?int $slotId, bool $anySlot = false): Builder
    {
        return static::onlyTrashed()
            ->whereIn('oeuvre_id', (array) $oeuvreId)
            ->when(! $anySlot, fn (Builder $query) => $slotId === null
                ? $query->whereNull('college_oeuvre_file_id')
                : $query->where('college_oeuvre_file_id', $slotId))
            ->where('review_status', ReviewStatusEnum::REJECTED)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('media_files as replacements')
                ->whereColumn('replacements.replaces_media_file_id', 'media_files.id')
                ->where(fn ($consumed) => $consumed
                    ->whereNull('replacements.deleted_at')
                    ->orWhere('replacements.review_status', ReviewStatusEnum::REJECTED)))
            ->orderBy('deleted_at')
            ->orderBy('id');
    }

    /**
     * The file's decision history, oldest first.
     *
     * @return HasMany<MediaFileReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MediaFileReview::class)->orderBy('decided_at')->orderBy('id');
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
        return $query->where('status', MediaFileStatus::READY)->whereNull('purged_at');
    }
}
