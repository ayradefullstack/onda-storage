<?php

declare(strict_types=1);

namespace Database\Factories;

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
            'status' => RegisterType::STATUS_ACTIVE,
            'is_disabled' => false,
        ];
    }
}
