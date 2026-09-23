<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Deposit\OeuvreStatus;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests that need a pre-existing decision history without replaying
 * every transition. The production path writes these rows only through
 * OeuvreStatusMachine.
 *
 * @extends Factory<OeuvreReview>
 */
class OeuvreReviewFactory extends Factory
{
    protected $model = OeuvreReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'oeuvre_id' => Oeuvre::factory(),
            'actor_id' => User::factory(),
            'from_status' => OeuvreStatus::DRAFT,
            'to_status' => OeuvreStatus::SUBMITTED,
            'reason' => null,
        ];
    }

    public function rejection(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'from_status' => OeuvreStatus::UNDER_REVIEW,
            'to_status' => OeuvreStatus::REJECTED,
            'reason' => $reason ?? fake()->sentence(12),
        ]);
    }
}
