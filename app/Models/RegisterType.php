<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\RegisterTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Level 1 of the deposit classification: the declarant type.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property int $status
 * @property bool $is_disabled
 * @property bool $is_system
 * @property-read string $name_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class RegisterType extends Model
{
    /** @use HasFactory<RegisterTypeFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    public const STATUS_ACTIVE = 1;

    protected $fillable = [
        'name',
        'slug',
        'name_ar',
        'name_en',
        'status',
        'is_disabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_disabled' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TypeGestion, $this>
     */
    public function typeGestions(): HasMany
    {
        return $this->hasMany(TypeGestion::class);
    }

    /**
     * @return HasMany<RegisterTypeCollege, $this>
     */
    public function registerTypeColleges(): HasMany
    {
        return $this->hasMany(RegisterTypeCollege::class);
    }

    /**
     * Whether this type has a gestion level — THE single predicate for it.
     *
     * A type shows the gestion select iff it has at least one active
     * `type_gestions` row. The page (through the `is_auteur` flag of the
     * classification tree), StoreOeuvreRequest and the admin forms all
     * read it from here; nothing compares a slug or an id any more. For the
     * seeded data this is true for Auteur only, exactly as before.
     */
    public function hasActiveGestions(): bool
    {
        return $this->typeGestions()->active()->exists();
    }

    /**
     * Whether a college under this type may carry a `code_dv`.
     *
     * The seeded data gives `code_dv` to the droits-voisins types only: every
     * college of Auteur and Editeur has it null. So a type whose colleges
     * all lack one is a droits-d'auteur type and refuses it; a type with no
     * colleges yet, or with at least one coded, accepts it.
     */
    public function acceptsCodeDv(): bool
    {
        $colleges = $this->registerTypeColleges();

        return ! ($colleges->exists() && ! $colleges->whereNotNull('code_dv')->exists());
    }

    /**
     * @param  Builder<RegisterType>  $query
     * @return Builder<RegisterType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * The name in the current locale when that translation is filled, else
     * `name` (French) — ONDA's `name_global`. Translations are NULL on
     * nearly every reference row, so without the fallback an Arabic select
     * would render empty options.
     *
     * @return Attribute<string, never>
     */
    protected function nameGlobal(): Attribute
    {
        return Attribute::get(function (): string {
            $translated = match (app()->getLocale()) {
                'ar' => $this->name_ar,
                'en' => $this->name_en,
                default => null,
            };

            return $translated !== null && trim($translated) !== '' ? $translated : $this->name;
        });
    }
}
