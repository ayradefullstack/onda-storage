<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Deposit\OeuvreStatus;
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
            'status' => OeuvreStatus::DRAFT,
            'registered_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OeuvreStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);
    }

    /**
     * `$officer` is the admin holding the deposit — the concurrency guard
     * refuses a decision from anyone else, so a test exercising that path
     * must be able to say who has it.
     */
    public function underReview(?User $officer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OeuvreStatus::UNDER_REVIEW,
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now(),
            'reviewed_by' => $officer === null ? User::factory() : $officer->id,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OeuvreStatus::REJECTED,
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now(),
        ]);
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OeuvreStatus::REGISTERED,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'registered_at' => now(),
        ]);
    }
}
