<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StorageQuota;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class VaultDemoSeeder extends Seeder
{
    /**
     * Seed demo authors, an admin, and a few works with quotas.
     * No media files — nothing can be uploaded until a later phase.
     *
     * `uuid` is assigned explicitly below rather than left to
     * `HasUuidColumn`'s `creating` hook. That used to be load-bearing —
     * `DatabaseSeeder` ran every seeder under `WithoutModelEvents`, which
     * suppresses model events including that hook — but `WithoutModelEvents`
     * is no longer used there (P3-FIX commented it out), so the hook fires
     * normally now. The explicit assignment is harmless and kept for
     * clarity, but nothing here still depends on it.
     */
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $author = Role::firstOrCreate(['name' => 'author']);

        $adminUser = User::firstOrCreate(
            ['email' => 'vault-admin@onda.dz'],
            [
                'name' => 'Vault Admin',
                'first_name' => 'Vault',
                'last_name' => 'Admin',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $adminUser->syncRoles([$admin]);

        $authors = collect(['Vault Author One', 'Vault Author Two'])->map(
            fn (string $name, int $index) => tap(
                User::firstOrCreate(
                    ['email' => 'vault-author'.($index + 1).'@onda.dz'],
                    [
                        'name' => $name,
                        'first_name' => explode(' ', $name)[1],
                        'last_name' => explode(' ', $name)[2],
                        'password' => bcrypt('password'),
                        'email_verified_at' => now(),
                    ]
                ),
                fn (User $user) => $user->syncRoles([$author]),
            )
        );

        $authors->each(function (User $user) {
            StorageQuota::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'limit_bytes' => 50 * 1024 * 1024 * 1024,
                    'used_bytes' => 0,
                ],
            );

            Work::factory()
                ->count(2)
                ->state(fn (): array => ['uuid' => (string) Str::uuid7()])
                ->create(['author_id' => $user->id]);
        });
    }
}
