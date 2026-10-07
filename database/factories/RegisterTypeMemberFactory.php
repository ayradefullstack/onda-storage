<?php

declare(strict_types=1);

namespace Database\Factories;

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
            'status' => 1,
            'is_disabled' => false,
            'available_in_registration' => true,
        ];
    }
}
