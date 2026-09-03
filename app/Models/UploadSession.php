<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\UploadSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $work_id
 * @property string $filename
 * @property int $size_bytes
 * @property int $chunk_size
 * @property int $total_chunks
 * @property int $received_chunks
 * @property int $received_bytes
 * @property string|null $chunk_mask
 * @property string $dek_wrapped
 * @property string $nonce
 * @property string $temp_path
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UploadSession extends Model
{
    /** @use HasFactory<UploadSessionFactory> */
    use HasFactory, HasUuidColumn;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'received_bytes' => 'integer',
            'dek_wrapped' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Work, $this>
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }
}
