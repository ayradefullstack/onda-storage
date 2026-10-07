<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\ReferenceDataChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What every create action shares: the row and its audit trail are written
 * together or not at all.
 *
 * A create is audited as every field going from nothing to its value
 * (`old_value` null), one `reference_data_changes` row per field — the same
 * shape an update leaves, so a row's whole history reads the same way from
 * its first line.
 *
 * `is_system` is deliberately not assignable here: it is a column default
 * (false) that only a seeder ever overrides, so an admin-created row can
 * never claim to be seeder-owned.
 *
 * The step-1 classification tree is not cached (it is read per request by
 * Author\OeuvreController::classificationTree), so there is nothing to
 * invalidate: a created row is visible in the cascade on the next load.
 */
abstract class CreatesReferenceRow
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    protected function persist(User $actor, Model $model, array $attributes): Model
    {
        DB::transaction(function () use ($actor, $model, $attributes): void {
            $model->fill($attributes)->save();

            $fields = array_keys($attributes);
            $after = [];

            foreach ($fields as $field) {
                $after[$field] = $model->getAttribute($field);
            }

            ReferenceDataChange::record($actor, $model, [], $after, $fields);
        });

        return $model;
    }
}
