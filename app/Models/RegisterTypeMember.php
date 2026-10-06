<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\RegisterTypeMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Level 4 of the deposit classification: the qualité within a college.
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_college_id
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $code_qlt
 * @property int $status
 * @property bool $is_disabled
 * @property bool $available_in_registration
 * @property bool $is_system
 * @property-read string $name_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class RegisterTypeMember extends Model
{
    /** @use HasFactory<RegisterTypeMemberFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'register_type_college_id',
        'name',
        'name_ar',
        'name_en',
        'code_qlt',
        'status',
        'is_disabled',
        'available_in_registration',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_disabled' => 'boolean',
            'available_in_registration' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RegisterTypeCollege, $this>
     */
    public function registerTypeCollege(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeCollege::class);
    }

    /**
     * Oeuvres classified under this qualité — what disabling it would leave
     * pointing at a hidden row (never changing them).
     *
     * @return HasMany<Oeuvre, $this>
     */
    public function oeuvres(): HasMany
    {
        return $this->hasMany(Oeuvre::class);
    }

    /**
     * Qualités offered when declaring, as opposed to ONDA's internal
     * reference qualités.
     *
     * @param  Builder<RegisterTypeMember>  $query
     * @return Builder<RegisterTypeMember>
     */
    public function scopeAvailableInRegistration(Builder $query): Builder
    {
        return $query->where('available_in_registration', true);
    }

    /**
     * Localised name with the same fallback as every other classification
     * level: the viewer's locale, then `name`. ONDA's own members have no
     * translations (the columns were added for admin-created rows), so for
     * seeded rows this is still `name`.
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
