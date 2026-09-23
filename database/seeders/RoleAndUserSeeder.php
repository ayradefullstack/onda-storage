<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Seed roles and their initial users.
     */
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $author = Role::firstOrCreate(['name' => 'author']);

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'first_name' => 'Admin',
                'last_name' => 'ONDA',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $adminUser->syncRoles([$admin]);

        $author1 = User::firstOrCreate(
            ['email' => 'author1@onda.dz'],
            [
                'name' => 'Author One',
                'first_name' => 'Author',
                'last_name' => 'One',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $author1->syncRoles([$author]);

        $author2 = User::firstOrCreate(
            ['email' => 'author2@onda.dz'],
            [
                'name' => 'Author Two',
                'first_name' => 'Author',
                'last_name' => 'Two',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $author2->syncRoles([$author]);
    }
}
