<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileAccessLog>
 */
class FileAccessLogFactory extends Factory
{
    protected $model = FileAccessLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_file_id' => MediaFile::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['stream', 'download', 'preview']),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'bytes_sent' => fake()->numberBetween(1024, 1024 * 1024),
            'range_header' => null,
            'prev_hash' => null,
            'row_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
