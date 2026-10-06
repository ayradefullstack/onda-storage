<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\User;

/**
 * A qualité within a collège. `code_qlt` is optional and, once saved,
 * frozen: it is the seeder's first match key.
 */
final class CreateMember extends CreatesReferenceRow
{
    /**
     * @param  array<string, mixed>  $data  validated input (name, localised names, flags)
     */
    public function handle(User $actor, RegisterTypeCollege $college, array $data): RegisterTypeMember
    {
        return $this->persist($actor, new RegisterTypeMember, [
            'register_type_college_id' => $college->id,
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'code_qlt' => $data['code_qlt'] ?? null,
            'available_in_registration' => $data['available_in_registration'] ?? true,
            'status' => $data['status'] ?? RegisterTypeCollege::STATUS_ACTIVE,
            'is_disabled' => false,
        ]);
    }
}
