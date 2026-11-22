<?php

declare(strict_types=1);

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $scope_hash
 * @property int $failed_count
 * @property int $window_started_at epoch seconds
 * @property int|null $locked_until epoch seconds
 */
#[Fillable(['scope_hash', 'failed_count', 'window_started_at', 'locked_until'])]
class LoginLockout extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_count' => 'integer',
            'window_started_at' => 'integer',
            'locked_until' => 'integer',
        ];
    }

    /**
     * Rows nobody can still be affected by: an expired lock, or a failure
     * window that ended without reaching the threshold. A row whose lock has
     * not expired never matches, whatever its window says.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $now = now()->getTimestamp();
        $windowCutoff = $now - max(1, (int) config('auth-security.login.failure_window_seconds'));

        return static::query()
            ->where(fn (Builder $q) => $q->whereNotNull('locked_until')->where('locked_until', '<=', $now))
            ->orWhere(fn (Builder $q) => $q->whereNull('locked_until')->where('window_started_at', '<=', $windowCutoff));
    }
}
