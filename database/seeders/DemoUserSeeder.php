<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts for local development.
 *
 * Credentials:
 *   Admin (optometrist) — admin@eyecare.test / password
 *   Staff (optometrist) — staff@eyecare.test / password
 *   Patient (linked)    — customer@eyecare.test / password
 *   Patient (walk-in)   — no account
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin optometrist
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@eyecare.test'],
            [
                'name' => 'Dr. Maria Santos',
                'phone' => '09170000001',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', 'admin')->value('id'),
                'is_optometrist' => true,
                'email_verified_at' => now(),
            ],
        );

        // Staff optometrist
        $staff = User::query()->firstOrCreate(
            ['email' => 'staff@eyecare.test'],
            [
                'name' => 'Dr. Juan dela Cruz',
                'phone' => '09170000002',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', 'staff')->value('id'),
                'is_optometrist' => true,
                'email_verified_at' => now(),
            ],
        );

        // Linked patient
        $patientUser = User::query()->firstOrCreate(
            ['email' => 'customer@eyecare.test'],
            [
                'name' => 'Ana Reyes',
                'phone' => '09170000003',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', 'patient')->value('id'),
                'email_verified_at' => now(),
            ],
        );

        if ($patientUser->patient === null) {
            Patient::query()->create([
                'user_id' => $patientUser->id,
                'patient_number' => 'PAT-'.Str::ulid(),
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'phone' => '09170000003',
                'contact_email' => 'customer@eyecare.test',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
            ]);
        }

        // Walk-in patient (no account)
        if (Patient::query()->where('first_name', 'Pedro')->where('last_name', 'Cruz')->doesntExist()) {
            Patient::query()->create([
                'patient_number' => 'PAT-'.Str::ulid(),
                'first_name' => 'Pedro',
                'last_name' => 'Cruz',
                'phone' => '09170000004',
                'date_of_birth' => '1985-08-20',
                'gender' => 'male',
            ]);
        }
    }
}
