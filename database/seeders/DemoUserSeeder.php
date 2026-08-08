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
 * Credentials (all passwords: password):
 *   Admin + Optometrist — admin-optometrist@eyecare.test / password
 *   Admin (plain)       — admin@eyecare.test / password
 *   Optometrist         — optometrist@eyecare.test / password
 *   Staff               — staff@eyecare.test / password
 *   Patient (linked)    — customer@eyecare.test / password
 *   Patient (walk-in)   — no account
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->createAdminOptometrist();
        $this->createAdmin();
        $this->createOptometrist();
        $this->createStaff();
        $this->createPatient();
        $this->createWalkInPatient();
    }

    private function createAdminOptometrist(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'admin-optometrist@eyecare.test'],
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
        $user->update(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $user->roles()->syncWithoutDetaching(
            Role::query()->whereIn('name', [Role::Admin, Role::Optometrist])->pluck('id'),
        );
    }

    private function createAdmin(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'admin@eyecare.test'],
            [
                'first_name' => 'Carlos',
                'last_name' => 'Reyes',
                'phone' => '09170000005',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Admin)->value('id'),
                'is_optometrist' => false,
                'email_verified_at' => now(),
            ],
        );
        $user->update(['first_name' => 'Carlos', 'last_name' => 'Reyes']);
        $user->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Admin)->pluck('id'),
        );
    }

    private function createOptometrist(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'optometrist@eyecare.test'],
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
        $user->update(['first_name' => 'Juan', 'last_name' => 'dela Cruz']);
        $user->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Optometrist)->pluck('id'),
        );
    }

    private function createStaff(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'staff@eyecare.test'],
            [
                'first_name' => 'Ana',
                'last_name' => 'Garcia',
                'phone' => '09170000006',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Staff)->value('id'),
                'is_optometrist' => false,
                'email_verified_at' => now(),
            ],
        );
        $user->update(['first_name' => 'Ana', 'last_name' => 'Garcia']);
        $user->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Staff)->pluck('id'),
        );
    }

    private function createPatient(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'customer@eyecare.test'],
            [
                'first_name' => 'Liza',
                'last_name' => 'Mendoza',
                'phone' => '09170000003',
                'password' => Hash::make('password'),
                'role_id' => Role::query()->where('name', Role::Patient)->value('id'),
                'email_verified_at' => now(),
            ],
        );
        $user->update(['first_name' => 'Liza', 'last_name' => 'Mendoza']);
        $user->roles()->syncWithoutDetaching(
            Role::query()->where('name', Role::Patient)->pluck('id'),
        );

        if ($user->patient === null) {
            Patient::query()->create([
                'user_id' => $user->id,
                'patient_number' => 'PAT-2026-000001',
                'first_name' => 'Liza',
                'last_name' => 'Mendoza',
                'phone' => '09170000003',
                'contact_email' => 'customer@eyecare.test',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
            ]);
        }
    }

    private function createWalkInPatient(): void
    {
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
