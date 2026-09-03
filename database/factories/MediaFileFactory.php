<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaFile>
 */
class MediaFileFactory extends Factory
{
    protected $model = MediaFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid7();

        return [
            'work_id' => Work::factory(),
            'uploaded_by' => User::factory(),
            'original_name' => fake()->word().'.mp4',
            'extension' => 'mp4',
            'mime' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(1024, 5 * 1024 * 1024 * 1024),
            'disk' => 'vault',
            'path' => substr($uuid, 0, 2).'/'.substr($uuid, 2, 2).'/'.$uuid.'.bin',
            'sha256_plain' => hash('sha256', $uuid),
            'dek_wrapped' => base64_encode(random_bytes(48)),
            // Stored as the hex encoding of the 8-byte nonce (CHAR(16) column).
            'nonce' => bin2hex(random_bytes(8)),
            'mac_path' => substr($uuid, 0, 2).'/'.substr($uuid, 2, 2).'/'.$uuid.'.mac',
            'status' => 'ready',
            'duration_sec' => fake()->numberBetween(10, 3600),
            'width' => 1920,
            'height' => 1080,
            'ref_count' => 1,
            'scanned_at' => now(),
            'verified_at' => now(),
            'purged_at' => null,
        ];
    }
}
