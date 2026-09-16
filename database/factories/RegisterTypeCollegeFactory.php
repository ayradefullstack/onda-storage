<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\TypeGestion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RegisterTypeCollege>
 */
class RegisterTypeCollegeFactory extends Factory
{
    protected $model = RegisterTypeCollege::class;

    public function definition(): array
    {
        return [
            'register_type_id' => RegisterType::factory(),
            'type_gestion_id' => null,
            'code_college' => Str::upper(fake()->unique()->lexify('COLLEGE_????????')),
            'name' => 'oeuvres '.fake()->word(),
            'status' => RegisterTypeCollege::STATUS_ACTIVE,
            'type_gestion' => TypeGestion::COLLECTIVE,
            'code_dv' => null,
            'adhesion' => true,
            'is_disabled' => false,
        ];
    }
}
