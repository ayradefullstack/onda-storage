<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Support\FileFormats;
use Database\Factories\CollegeOeuvreFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A document a declaration under a college must attach — the requirement
 * definition, not an upload (those are MediaFile).
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_college_id
 * @property string $document_key
 * @property string $title
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property list<string> $extensions
 * @property list<string> $mime_types
 * @property bool $is_required
 * @property int $display_order
 * @property int|null $max_size_kb
 * @property bool $allows_multiple
 * @property array<string, mixed>|null $conditions
 * @property bool $needs_review
 * @property-read string $title_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class CollegeOeuvreFile extends Model
{
    /** @use HasFactory<CollegeOeuvreFileFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'register_type_college_id',
        'document_key',
        'title',
        'title_ar',
        'title_en',
        'extensions',
        // Derived from `extensions` by the saving hook below, never set by a
        // form. Fillable only so the seeder and the sync command can assign
        // it explicitly; every write is overwritten by the hook anyway.
        'mime_types',
        'is_required',
        'display_order',
        'max_size_kb',
        'allows_multiple',
        'conditions',
        'needs_review',
    ];

    /**
     * `mime_types` is derived from `extensions` through the format registry,
     * on every save, with no exception.
     *
     * A `saving` hook rather than a controller call or an observer file: it
     * is the only place that runs for every write — form update, seeder,
     * artisan command, factory, or a future import — so the two columns
     * cannot drift within a single request. `referentiel:sync-mime-types`
     * exists for the other kind of drift, when the registry itself changes
     * under rows that were already correct when written.
     *
     * An unsupported extension contributes no MIMEs rather than throwing:
     * validation rejects those at the boundary, and a model hook is the
     * wrong place to fail a seeder mid-run.
     */
    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            // array_filter/array_values rather than a bare pass-through:
            // the attribute is a JSON cast, so a malformed stored value can
            // contain non-strings, and the registry expects a list.
            $document->mime_types = FileFormats::mimeTypesFor(
                array_values(array_filter($document->extensions, 'is_string')),
            );
        });
    }

    protected function casts(): array
    {
        return [
            'extensions' => 'array',
            'mime_types' => 'array',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'max_size_kb' => 'integer',
            'allows_multiple' => 'boolean',
            'conditions' => 'array',
            'needs_review' => 'boolean',
        ];
    }

    /**
     * Rows whose `extensions` list was a guess rather than a rule read off
     * ONDA's source — see CollegeOeuvreFileSeeder. An officer confirming or
     * correcting the list is the review this flag asks for, so saving
     * extensions clears it.
     *
     * @param  Builder<CollegeOeuvreFile>  $query
     * @return Builder<CollegeOeuvreFile>
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('needs_review', true);
    }

    /**
     * @return BelongsTo<RegisterTypeCollege, $this>
     */
    public function registerTypeCollege(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeCollege::class);
    }

    /**
     * @param  Builder<CollegeOeuvreFile>  $query
     * @return Builder<CollegeOeuvreFile>
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    /**
     * @param  Builder<CollegeOeuvreFile>  $query
     * @return Builder<CollegeOeuvreFile>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /**
     * See RegisterType::nameGlobal().
     *
     * @return Attribute<string, never>
     */
    protected function titleGlobal(): Attribute
    {
        return Attribute::get(function (): string {
            $translated = match (app()->getLocale()) {
                'ar' => $this->title_ar,
                'en' => $this->title_en,
                default => null,
            };

            return $translated !== null && trim($translated) !== '' ? $translated : $this->title;
        });
    }
}
