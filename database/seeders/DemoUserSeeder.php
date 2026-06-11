<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for local development and defense demonstration.
 *
 * Credentials:
 *   Admin   — admin@eyecare.test   / password
 *   Staff   — staff@eyecare.test   / password
 *   Customer — customer@eyecare.test / password
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Demo Admin',
                'email' => 'admin@eyecare.test',
                'role' => 'admin',
            ],
            [
                'name' => 'Demo Staff',
                'email' => 'staff@eyecare.test',
                'role' => 'staff',
            ],
            [
                'name' => 'Demo Customer',
                'email' => 'customer@eyecare.test',
                'role' => 'customer',
            ],
        ];

        foreach ($accounts as $account) {
            $role = Role::query()->where('name', $account['role'])->firstOrFail();

            User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $role->id,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
