<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\MediaVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaVariant>
 */
class MediaVariantFactory extends Factory
{
    protected $model = MediaVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_file_id' => MediaFile::factory(),
            'kind' => fake()->randomElement(['poster', 'preview', 'waveform', 'thumbnail']),
            'path' => 'variants/'.fake()->uuid().'.jpg',
            'size_bytes' => fake()->numberBetween(1024, 512 * 1024),
        ];
    }
}
