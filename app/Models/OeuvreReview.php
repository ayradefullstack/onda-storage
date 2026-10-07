<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use Database\Factories\OeuvreReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in an oeuvre's decision history: who moved it, from what, to
 * what, and why.
 *
 * Append-only — no `updated_at`, no SoftDeletes (see CLAUDE.md's
 * soft-delete classification, and FileAccessLog, which has the same
 * shape for the same reason). Rows are written only by
 * OeuvreStatusMachine; nothing else should ever construct one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $oeuvre_id
 * @property int $actor_id
 * @property string $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class OeuvreReview extends Model
{
    /** @use HasFactory<OeuvreReviewFactory> */
    use HasFactory, HasUuidColumn;

    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Oeuvre, $this>
     */
    public function oeuvre(): BelongsTo
    {
        return $this->belongsTo(Oeuvre::class);
    }

    /**
     * withTrashed on read: `users` is soft-deleted, and a decision must
     * still name its officer after that officer's account is retired.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
