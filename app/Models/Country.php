<?php

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $native_name
 * @property string|null $arabic_name
 * @property string|null $nationality
 * @property string|null $nationality_ar
 * @property string|null $nationality_en
 * @property string $alpha2
 * @property string|null $alpha3
 * @property string|null $numeric_code
 * @property string|null $currency_code
 * @property string|null $phone_code
 * @property string|null $flag_url
 * @property bool $is_active
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Country extends Model
{
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'id',
        'uuid',
        'name',
        'native_name',
        'arabic_name',
        'nationality',
        'nationality_ar',
        'nationality_en',
        'alpha2',
        'alpha3',
        'numeric_code',
        'currency_code',
        'phone_code',
        'flag_url',
        'is_active',
        'is_visible',
    ];

    protected $appends = [
        'code',
        'name_ar',
        'name_fr',
        'name_en',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    public function getCodeAttribute(): string
    {
        return $this->alpha2 ?? '';
    }

    public function getNameArAttribute(): string
    {
        return $this->arabic_name ?: $this->name;
    }

    public function getNameFrAttribute(): string
    {
        return $this->native_name ?: $this->name;
    }

    public function getNameEnAttribute(): string
    {
        return $this->name;
    }

    public function wilayas(): HasMany
    {
        return $this->hasMany(Wilaya::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeDz(Builder $query)
    {
        return $query->where('alpha2', 'DZ');
    }
}
