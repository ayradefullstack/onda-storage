<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\RegisterType;
use App\Models\TypeGestion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A type de gestion under a declarant type.
 *
 * Adding a type's FIRST active gestion switches that type's classification
 * flow from three levels to four (see RegisterType::hasActiveGestions);
 * the create form says so.
 */
final class CreateTypeGestion extends CreatesReferenceRow
{
    /**
     * @param  array<string, mixed>  $data  validated input (name, localised names, flags)
     */
    public function handle(User $actor, RegisterType $type, array $data): TypeGestion
    {
        return $this->persist($actor, new TypeGestion, [
            'register_type_id' => $type->id,
            'type_gestion' => $this->nextValue($type),
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'status' => $data['status'] ?? TypeGestion::STATUS_ACTIVE,
        ]);
    }

    /**
     * `type_gestion` is the 1/2/3 integer colleges also carry, unique per
     * type — so a type can hold at most three. The next free value, retired
     * rows included (the unique index covers them).
     */
    private function nextValue(RegisterType $type): int
    {
        $used = TypeGestion::withTrashed()->where('register_type_id', $type->id)->pluck('type_gestion')->all();

        foreach (TypeGestion::VALUES as $value) {
            if (! in_array($value, $used, true)) {
                return $value;
            }
        }

        throw ValidationException::withMessages(['register_type' => 'admin.referentiel.errors.gestionLimit']);
    }
}
