<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Domain\Deposit\OeuvreStatus;
use Database\Factories\RegisterTypeCollegeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Level 3 of the deposit classification.
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_id
 * @property int|null $type_gestion_id
 * @property string|null $code_college
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property int $status
 * @property int $type_gestion
 * @property string|null $code_dv
 * @property bool $adhesion
 * @property bool $is_disabled
 * @property-read string $name_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class RegisterTypeCollege extends Model
{
    /** @use HasFactory<RegisterTypeCollegeFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    public const STATUS_ACTIVE = 1;

    /**
     * Technical college ONDA keeps for internal qualities (foreign works,
     * documentation). Seeded disabled; never offered when declaring.
     */
    public const CODE_REFERENTIEL_HORS_ADHESION = 'REFERENTIEL_HORS_ADHESION';

    /**
     * Documentation-only film college. Seeded disabled; never offered when
     * declaring. Not in the ONDA dump — added by step-one-select.md, as on
     * ONDA's develop branch.
     */
    public const CODE_OEUVRE_FILM = 'OEUVRE_FILM';

    /**
     * Codes kept as reference data but never offered for registration or a
     * declaration — ONDA develop's `codesHiddenFromDeclarationAndAdhesion()`.
     */
    public const CODES_HIDDEN_FROM_REGISTRATION = [
        self::CODE_REFERENTIEL_HORS_ADHESION,
        self::CODE_OEUVRE_FILM,
    ];

    protected $fillable = [
        'register_type_id',
        'type_gestion_id',
        'code_college',
        'name',
        'name_ar',
        'name_en',
        'status',
        'type_gestion',
        'code_dv',
        'adhesion',
        'is_disabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'type_gestion' => 'integer',
            'adhesion' => 'boolean',
            'is_disabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RegisterType, $this>
     */
    public function registerType(): BelongsTo
    {
        return $this->belongsTo(RegisterType::class);
    }

    /**
     * The type de gestion label row — Auteur colleges only; null for the
     * other declarant types, whose colleges still carry `type_gestion`.
     *
     * @return BelongsTo<TypeGestion, $this>
     */
    public function typeGestion(): BelongsTo
    {
        return $this->belongsTo(TypeGestion::class);
    }

    /**
     * @return HasMany<RegisterTypeMember, $this>
     */
    public function registerTypeMembers(): HasMany
    {
        return $this->hasMany(RegisterTypeMember::class);
    }

    /**
     * @return HasMany<RegisterRoleAuteur, $this>
     */
    public function registerRoleAuteurs(): HasMany
    {
        return $this->hasMany(RegisterRoleAuteur::class);
    }

    /**
     * @return HasMany<CollegeOeuvreFile, $this>
     */
    public function collegeOeuvreFiles(): HasMany
    {
        return $this->hasMany(CollegeOeuvreFile::class);
    }

    /**
     * Deposits filed under this college. Read-only from the reference side:
     * nothing here may touch an oeuvre, and retiring a college must leave
     * every one of these intact — which is precisely why the admin console
     * shows this count before letting anyone disable it.
     *
     * @return HasMany<Oeuvre, $this>
     */
    public function oeuvres(): HasMany
    {
        return $this->hasMany(Oeuvre::class);
    }

    /**
     * Deposits still in flight under this college — an author is filling
     * one in, or an officer is reading one. Disabling the college does NOT
     * break them (existing oeuvres keep their classification and stay
     * reviewable), but whoever disables it should be told the number
     * first. `registered` is excluded: those are finished and untouchable.
     *
     * @var list<string>
     */
    public const IN_FLIGHT_OEUVRE_STATUSES = [
        OeuvreStatus::DRAFT,
        OeuvreStatus::SUBMITTED,
        OeuvreStatus::UNDER_REVIEW,
    ];

    /**
     * @param  Builder<RegisterTypeCollege>  $query
     * @return Builder<RegisterTypeCollege>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Colleges that may be offered when classifying: everything except the
     * codes kept only as reference data (REFERENTIEL_HORS_ADHESION and
     * OEUVRE_FILM). Disabled colleges are NOT excluded here — the form
     * renders them unselectable rather than hiding them.
     *
     * @param  Builder<RegisterTypeCollege>  $query
     * @return Builder<RegisterTypeCollege>
     */
    public function scopeAvailableForRegistration(Builder $query): Builder
    {
        return $query->whereNotIn('code_college', self::CODES_HIDDEN_FROM_REGISTRATION);
    }

    /**
     * @param  Builder<RegisterTypeCollege>  $query
     * @return Builder<RegisterTypeCollege>
     */
    public function scopeForGestion(Builder $query, int $typeGestion): Builder
    {
        return $query->where('type_gestion', $typeGestion);
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
