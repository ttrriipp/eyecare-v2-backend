<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
                'phone' => '09170000001',
                'role' => 'admin',
            ],
            [
                'name' => 'Demo Staff',
                'email' => 'staff@eyecare.test',
                'phone' => '09170000002',
                'role' => 'staff',
            ],
            [
                'name' => 'Demo Customer',
                'email' => 'customer@eyecare.test',
                'phone' => '09170000003',
                'role' => 'patient',
            ],
        ];

        foreach ($accounts as $account) {
            $role = Role::query()->where('name', $account['role'])->firstOrFail();

            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'phone' => $account['phone'],
                    'password' => Hash::make('password'),
                    'role_id' => $role->id,
                    'email_verified_at' => now(),
                ],
            );

            if ($account['role'] === 'patient' && $user->patient === null) {
                Patient::query()->create([
                    'user_id' => $user->id,
                    'patient_number' => 'PAT-'.Str::ulid(),
                    'full_name' => $account['name'],
                    'phone' => $account['phone'],
                    'contact_email' => $account['email'],
                ]);
            }
        }
    }
}
