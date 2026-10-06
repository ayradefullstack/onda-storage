<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * A name must be unique within its parent, compared TRIMMED and
 * CASE-INSENSITIVELY, against soft-deleted rows too.
 *
 * Why the comparison is done in PHP rather than with `LOWER(TRIM(...))`:
 * the seeded names carry real leading spaces (" oeuvres musicales"), and a
 * new "oeuvres musicales" must collide with that one. A parent holds at most
 * a few dozen rows, so reading its names is cheap, and `mb_strtolower`
 * folds accented letters the way a SQL `LOWER` on SQLite does not.
 *
 * No `deleted_at` filter on purpose: a retired row still owns its name, and
 * a new row under the same name would be indistinguishable from it in the
 * classification of an existing oeuvre.
 */
final class UniqueTrimmedName implements ValidationRule
{
    /**
     * @param  array<string, int|null>  $scope  parent columns the name is unique within (null = IS NULL)
     */
    public function __construct(
        private readonly string $table,
        private readonly array $scope,
        private readonly ?int $ignoreId = null,
        private readonly string $column = 'name',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $needle = mb_strtolower(trim($value));

        $query = DB::table($this->table);

        foreach ($this->scope as $column => $parent) {
            $parent === null ? $query->whereNull($column) : $query->where($column, $parent);
        }

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        $taken = $query->pluck($this->column)
            ->contains(fn (mixed $existing): bool => mb_strtolower(trim((string) $existing)) === $needle);

        if ($taken) {
            $fail('admin.referentiel.errors.nameTaken');
        }
    }
}
