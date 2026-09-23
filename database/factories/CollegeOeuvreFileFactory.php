<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterTypeCollege;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollegeOeuvreFile>
 */
class CollegeOeuvreFileFactory extends Factory
{
    protected $model = CollegeOeuvreFile::class;

    public function definition(): array
    {
        return [
            'register_type_college_id' => RegisterTypeCollege::factory(),
            'document_key' => fake()->unique()->slug(2, false),
            'title' => fake()->sentence(3),
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'is_required' => true,
            'display_order' => 1,
            'allows_multiple' => true,
            'needs_review' => false,
        ];
    }
}
