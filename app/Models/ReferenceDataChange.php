<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Domain\Deposit\Value\RowHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One field of one reference row changed by one officer.
 *
 * Append-only — no `updated_at`, no SoftDeletes, and a `prev_hash`/`row_hash`
 * chain, exactly as FileAccessLog does and for the same reason: an audit
 * row that can be edited or removed is not evidence.
 *
 * @property int $id
 * @property string $uuid
 * @property int $actor_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $subject_uuid
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string|null $prev_hash
 * @property string $row_hash
 * @property Carbon|null $created_at
 */
class ReferenceDataChange extends Model
{
    use HasUuidColumn;

    const UPDATED_AT = null;

    /**
     * Records every changed field of `$subject` as its own row.
     *
     * `$before` is the attribute snapshot taken before the model was
     * filled; anything in `$subject->getDirty()` that differs is written.
     * Called after the save, so a failed save leaves no audit row claiming
     * a change that never happened.
     *
     * Values are stored as canonical JSON rather than casts to string, so
     * `false`, `0`, `""` and `null` stay distinguishable — and so the
     * `extensions` list round-trips as a list.
     *
     * @param  array<string, mixed>  $before  attributes as they were before the change
     * @param  array<string, mixed>  $after  attributes as saved
     * @param  list<string>  $fields  the columns this form is allowed to change
     * @return int how many rows were written
     */
    public static function record(User $actor, Model $subject, array $before, array $after, array $fields): int
    {
        $written = 0;

        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if (self::encode($old) === self::encode($new)) {
                continue;
            }

            // Read inside the loop: each row chains off the one before it,
            // including rows written moments ago by this same call.
            $prevHash = self::query()->orderByDesc('id')->value('row_hash');

            $payload = [
                'actor_id' => $actor->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'subject_uuid' => (string) $subject->getAttribute('uuid'),
                'field' => $field,
                'old_value' => self::encode($old),
                'new_value' => self::encode($new),
            ];

            $change = new self;
            $change->forceFill([
                ...$payload,
                'prev_hash' => $prevHash,
                'row_hash' => RowHash::compute($prevHash, $payload),
            ])->save();

            $written++;
        }

        return $written;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    private static function encode(mixed $value): ?string
    {
        return $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
