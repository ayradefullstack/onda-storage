<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Referentiel\StatusEnum as ReferentielStatus;
use App\Models\RegisterRoleAuteur;
use App\Models\RegisterTypeCollege;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegisterRoleAuteur>
 */
class RegisterRoleAuteurFactory extends Factory
{
    protected $model = RegisterRoleAuteur::class;

    public function definition(): array
    {
        return [
            'register_type_college_id' => RegisterTypeCollege::factory(),
            'name' => ucfirst(fake()->word()),
            'status' => ReferentielStatus::ACTIVE,
            'is_disabled' => false,
        ];
    }
}
