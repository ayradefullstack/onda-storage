<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Domain\Deposit\OeuvreStatus;
use Database\Factories\OeuvreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $author_id
 * @property string|null $title
 * @property string|null $description
 * @property int|null $register_type_id
 * @property int|null $type_gestion_id
 * @property int|null $register_type_college_id
 * @property int|null $register_type_member_id
 * @property string|null $code_college_snapshot
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property Carbon|null $registered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Oeuvre extends Model
{
    /** @use HasFactory<OeuvreFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    /**
     * Only the classification — author_id and status are always set
     * explicitly by the controller, never mass-assigned from a request.
     */
    protected $fillable = [
        'register_type_id',
        'type_gestion_id',
        'register_type_college_id',
        'register_type_member_id',
        'code_college_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<RegisterType, $this>
     */
    public function registerType(): BelongsTo
    {
        return $this->belongsTo(RegisterType::class);
    }

    /**
     * @return BelongsTo<TypeGestion, $this>
     */
    public function typeGestion(): BelongsTo
    {
        return $this->belongsTo(TypeGestion::class);
    }

    /**
     * @return BelongsTo<RegisterTypeCollege, $this>
     */
    public function registerTypeCollege(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeCollege::class);
    }

    /**
     * @return BelongsTo<RegisterTypeMember, $this>
     */
    public function registerTypeMember(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeMember::class);
    }

    /**
     * @return HasMany<MediaFile, $this>
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    /**
     * The required-document definitions of this oeuvre's collège, in display
     * order. Empty for an oeuvre filed before classification existed.
     * Retired requirements are excluded by the SoftDeletes scope.
     *
     * @return HasMany<CollegeOeuvreFile, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(CollegeOeuvreFile::class, 'register_type_college_id', 'register_type_college_id')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * How many required documents are satisfied, out of how many.
     *
     * A requirement is satisfied only by a file at `ready`: a file still
     * scanning or processing has not finished the pipeline, and a failed or
     * quarantined one satisfies nothing. A soft-deleted file does not count.
     *
     * `conditional` counts the unsatisfied required documents that carry a
     * source-system condition (`conditions`), which is not evaluated yet — so
     * they may not apply to this work. It lets the page say so instead of
     * presenting `total` as a hard requirement.
     *
     * @return array{satisfied: int, total: int, conditional: int}
     */
    public function requiredDocumentsProgress(): array
    {
        if ($this->register_type_college_id === null) {
            return ['satisfied' => 0, 'total' => 0, 'conditional' => 0];
        }

        $required = $this->requirements()->required()->get(['id', 'conditions']);

        $satisfiedIds = $this->mediaFiles()
            ->where('status', 'ready')
            ->whereIn('college_oeuvre_file_id', $required->modelKeys())
            ->distinct()
            ->pluck('college_oeuvre_file_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $unsatisfied = $required->reject(fn (CollegeOeuvreFile $requirement): bool => in_array($requirement->id, $satisfiedIds, true));

        return [
            'satisfied' => $required->count() - $unsatisfied->count(),
            'total' => $required->count(),
            'conditional' => $unsatisfied->filter(fn (CollegeOeuvreFile $requirement): bool => $requirement->conditions !== null)->count(),
        ];
    }

    /**
     * The officer currently holding this deposit (set when it moves to
     * `under_review`), or who last decided on it. The concurrency guard in
     * OeuvreStatusMachine reads it; the durable record of who decided what
     * is `oeuvreReviews`, not this column.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The append-only decision history, oldest first — an officer looking
     * at a resubmission needs to know why it was rejected last time.
     *
     * @return HasMany<OeuvreReview, $this>
     */
    public function oeuvreReviews(): HasMany
    {
        return $this->hasMany(OeuvreReview::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * True once this deposit has been through at least one full round —
     * it was rejected before. The admin notification says so, because an
     * officer opening a resubmission needs that context before reading a
     * single file.
     */
    public function isResubmission(): bool
    {
        return $this->oeuvreReviews()
            ->where('to_status', OeuvreStatus::REJECTED)
            ->exists();
    }

    /**
     * The author still owns it: files may be added and the record edited.
     * Anything else is frozen — see OeuvrePolicy::update() and
     * InitUpload's status guard, which both enforce it server-side.
     */
    public function isAuthorEditable(): bool
    {
        return OeuvreStatus::isAuthorEditable($this->status);
    }

    /**
     * @return HasMany<UploadSession, $this>
     */
    public function uploadSessions(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }
}
