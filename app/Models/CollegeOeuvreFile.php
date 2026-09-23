<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
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
        'is_required',
        'display_order',
        'max_size_kb',
        'allows_multiple',
        'conditions',
        'needs_review',
    ];

    protected function casts(): array
    {
        return [
            'extensions' => 'array',
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
