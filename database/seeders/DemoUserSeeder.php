<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for local development.
 *
 * Credentials:
 *   Admin (admin+optometrist) — admin@eyecare.test / password
 *   Optometrist               — staff@eyecare.test / password
 *   Patient (linked)          — customer@eyecare.test / password
 *   Patient (walk-in)         — no account
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin + Optometrist (dual-role owner)
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@eyecare.test'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '09170000001',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Admin)->value('id'),
                'is_optometrist' => true,
                'email_verified_at' => now(),
            ],
        );
        $admin->update(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $admin->roles()->syncWithoutDetaching(
            Role::query()->whereIn('name', [Role::Admin, Role::Optometrist])->pluck('id'),
        );

        // Optometrist (sole)
        $staff = User::query()->firstOrCreate(
            ['email' => 'staff@eyecare.test'],
            [
                'first_name' => 'Juan',
                'last_name' => 'dela Cruz',
                'phone' => '09170000002',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Optometrist)->value('id'),
                'is_optometrist' => true,
                'email_verified_at' => now(),
            ],
        );
        $staff->update(['first_name' => 'Juan', 'last_name' => 'dela Cruz']);
        $staff->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Optometrist)->pluck('id'),
        );

        // Linked patient
        $patientUser = User::query()->firstOrCreate(
            ['email' => 'customer@eyecare.test'],
            [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'phone' => '09170000003',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Patient)->value('id'),
                'email_verified_at' => now(),
            ],
        );
        $patientUser->update(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $patientUser->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Patient)->pluck('id'),
        );

        if ($patientUser->patient === null) {
            Patient::query()->create([
                'user_id' => $patientUser->id,
                'patient_number' => 'PAT-2026-000001',
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
                'patient_number' => 'PAT-2026-000002',
                'first_name' => 'Pedro',
                'last_name' => 'Cruz',
                'phone' => '09170000004',
                'date_of_birth' => '1985-08-20',
                'gender' => 'male',
            ]);
        }
    }
}
