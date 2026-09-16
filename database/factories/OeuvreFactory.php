<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Oeuvre>
 */
class OeuvreFactory extends Factory
{
    protected $model = Oeuvre::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => 'draft',
            'registered_at' => null,
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'registered',
            'registered_at' => now(),
        ]);
    }
}
