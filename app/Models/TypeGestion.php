<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\TypeGestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A label for the "type de gestion" select (Auteur only). Auteur colleges
 * point at it through `register_type_colleges.type_gestion_id`; see the
 * create_type_gestions_table migration.
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_id
 * @property int $type_gestion
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property int $status
 * @property bool $is_system
 * @property-read string $name_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TypeGestion extends Model
{
    /** @use HasFactory<TypeGestionFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    public const COLLECTIVE = 1;

    public const INDIVIDUAL = 2;

    public const SIMPLE = 3;

    public const VALUES = [self::COLLECTIVE, self::INDIVIDUAL, self::SIMPLE];

    public const STATUS_ACTIVE = 1;

    protected $fillable = [
        'register_type_id',
        'type_gestion',
        'name',
        'name_ar',
        'name_en',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type_gestion' => 'integer',
            'status' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Gestions a type's classification flow currently offers.
     *
     * @param  Builder<TypeGestion>  $query
     * @return Builder<TypeGestion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @return BelongsTo<RegisterType, $this>
     */
    public function registerType(): BelongsTo
    {
        return $this->belongsTo(RegisterType::class);
    }

    /**
     * @return HasMany<RegisterTypeCollege, $this>
     */
    public function registerTypeColleges(): HasMany
    {
        return $this->hasMany(RegisterTypeCollege::class);
    }

    /**
     * See RegisterType::nameGlobal().
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
