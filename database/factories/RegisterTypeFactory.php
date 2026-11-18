<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Referentiel\StatusEnum as ReferentielStatus;
use App\Models\RegisterType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RegisterType>
 */
class RegisterTypeFactory extends Factory
{
    protected $model = RegisterType::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word().' '.fake()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => ReferentielStatus::ACTIVE,
            'is_disabled' => false,
        ];
    }
}
