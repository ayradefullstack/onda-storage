<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\OeuvreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $author_id
 * @property string|null $title
 * @property string|null $description
 * @property int|null $register_type_id
 * @property int|null $type_gestion_id
 * @property int|null $register_type_college_id
 * @property int|null $register_type_member_id
 * @property string|null $code_college_snapshot
 * @property string $status
 * @property Carbon|null $registered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Oeuvre extends Model
{
    /** @use HasFactory<OeuvreFactory> */
    use HasFactory, HasUuidColumn, SoftDeletes;

    /**
     * Only the classification — author_id and status are always set
     * explicitly by the controller, never mass-assigned from a request.
     */
    protected $fillable = [
        'register_type_id',
        'type_gestion_id',
        'register_type_college_id',
        'register_type_member_id',
        'code_college_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<RegisterType, $this>
     */
    public function registerType(): BelongsTo
    {
        return $this->belongsTo(RegisterType::class);
    }

    /**
     * @return BelongsTo<TypeGestion, $this>
     */
    public function typeGestion(): BelongsTo
    {
        return $this->belongsTo(TypeGestion::class);
    }

    /**
     * @return BelongsTo<RegisterTypeCollege, $this>
     */
    public function registerTypeCollege(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeCollege::class);
    }

    /**
     * @return BelongsTo<RegisterTypeMember, $this>
     */
    public function registerTypeMember(): BelongsTo
    {
        return $this->belongsTo(RegisterTypeMember::class);
    }

    /**
     * @return HasMany<MediaFile, $this>
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    /**
     * @return HasMany<UploadSession, $this>
     */
    public function uploadSessions(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }
}
