<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\RegisterRoleAuteurFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A contributor role available within a college.
 *
 * @property int $id
 * @property string $uuid
 * @property int $register_type_college_id
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property int $status
 * @property bool $is_disabled
 * @property-read string $name_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class RegisterRoleAuteur extends Model
{
    /** @use HasFactory<RegisterRoleAuteurFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'register_type_college_id',
        'name',
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
     * @return BelongsTo<RegisterTypeCollege, $this>
     */
    public function registerTypeCollege(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeCollege::class);
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
