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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Level 4 of the deposit classification: the qualité within a college.
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_college_id
 * @property string $name
 * @property string|null $code_qlt
 * @property int $status
 * @property bool $is_disabled
 * @property bool $available_in_registration
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
     * Members have no translated names (ONDA dropped the columns), so this
     * is always `name` — kept so every classification level exposes the
     * same `name_global` API.
     *
     * @return Attribute<string, never>
     */
    protected function nameGlobal(): Attribute
    {
        return Attribute::get(fn (): string => $this->name);
    }
}
