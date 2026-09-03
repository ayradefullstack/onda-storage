<?php

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $wilaya_id
 * @property string|null $post_code
 * @property string $name_ar
 * @property string $name_fr
 * @property bool $is_active
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Commune extends Model
{
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'uuid',
        'wilaya_id',
        'post_code',
        'name_ar',
        'name_fr',
        'is_active',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
