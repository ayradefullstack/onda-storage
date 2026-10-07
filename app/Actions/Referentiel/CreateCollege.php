<?php

declare(strict_types=1);

namespace App\Actions\Referentiel;

use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\TypeGestion;
use App\Models\User;

/**
 * A collège created by an admin.
 *
 * Created DISABLED, always: today InitUpload requires a requirement slot for
 * a classified oeuvre, so a college with no required-document rows would let
 * an author classify and then be unable to upload anything. Enabling it is
 * refused until it has one (see UpdateCollegeRequest).
 *
 * `code_college` is chosen here and never changes again — it is frozen into
 * `oeuvres.code_college_snapshot` and is the join key for the documents.
 */
final class CreateCollege extends CreatesReferenceRow
{
    /**
     * @param  array<string, mixed>  $data  validated input (name, localised names, flags)
     */
    public function handle(User $actor, RegisterType $type, ?TypeGestion $gestion, array $data): RegisterTypeCollege
    {
        return $this->persist($actor, new RegisterTypeCollege, [
            'register_type_id' => $type->id,
            'type_gestion_id' => $gestion?->id,
            // The college's own 1/2/3 integer follows its gestion; a type
            // without gestions uses 1, as the seeded Editeur/Artiste/
            // Producteur colleges do.
            'type_gestion' => $gestion->type_gestion ?? TypeGestion::COLLECTIVE,
            'code_college' => $data['code_college'],
            'code_dv' => $data['code_dv'] ?? null,
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'adhesion' => $data['adhesion'] ?? false,
            'status' => RegisterTypeCollege::STATUS_ACTIVE,
            'is_disabled' => true,
        ]);
    }
}
