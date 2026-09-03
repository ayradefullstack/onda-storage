<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\StorageQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $limit_bytes
 * @property int $used_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StorageQuota extends Model
{
    /** @use HasFactory<StorageQuotaFactory> */
    use HasFactory, HasUuidColumn;

    protected function casts(): array
    {
        return [
            'limit_bytes' => 'integer',
            'used_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
