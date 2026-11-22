<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two application roles. Idempotent and role-only (no users), so a
 * production deployment can run it on its own:
 * `php artisan db:seed --class=RoleSeeder`.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'author'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
