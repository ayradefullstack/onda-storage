<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadSession>
 */
class UploadSessionFactory extends Factory
{
    protected $model = UploadSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $chunkSize = 8_388_608;
        $sizeBytes = fake()->numberBetween($chunkSize, 50 * $chunkSize);
        $totalChunks = (int) ceil($sizeBytes / $chunkSize);

        return [
            'user_id' => User::factory(),
            'work_id' => Work::factory(),
            'filename' => fake()->word().'.mp4',
            'size_bytes' => $sizeBytes,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => 0,
            'received_bytes' => 0,
            'chunk_mask' => null,
            'dek_wrapped' => base64_encode(random_bytes(48)),
            // Stored as the hex encoding of the 8-byte nonce (CHAR(16) column).
            'nonce' => bin2hex(random_bytes(8)),
            'temp_path' => 'incoming/'.fake()->uuid().'.part',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(120),
        ];
    }
}
