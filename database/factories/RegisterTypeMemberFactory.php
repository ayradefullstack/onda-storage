<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Referentiel\StatusEnum as ReferentielStatus;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegisterTypeMember>
 */
class RegisterTypeMemberFactory extends Factory
{
    protected $model = RegisterTypeMember::class;

    public function definition(): array
    {
        return [
            'register_type_college_id' => RegisterTypeCollege::factory(),
            'name' => ucfirst(fake()->word()),
            'code_qlt' => strtoupper(fake()->lexify('??')),
            'status' => ReferentielStatus::ACTIVE,
            'is_disabled' => false,
            'available_in_registration' => true,
        ];
    }
}
