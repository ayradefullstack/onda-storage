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
