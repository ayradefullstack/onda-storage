<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageQuota>
 */
class StorageQuotaFactory extends Factory
{
    protected $model = StorageQuota::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'limit_bytes' => 50 * 1024 * 1024 * 1024,
            'used_bytes' => 0,
        ];
    }
}
