<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $legacyCustomerRole = Role::query()
                ->where('name', 'customer')
                ->first();
            $patientRole = Role::query()
                ->where('name', 'patient')
                ->first();

            if ($legacyCustomerRole !== null && $patientRole === null) {
                $legacyCustomerRole->update(['name' => 'patient']);
            } elseif ($legacyCustomerRole !== null && $patientRole !== null) {
                User::query()
                    ->whereBelongsTo($legacyCustomerRole, 'role')
                    ->update(['role_id' => $patientRole->getKey()]);

                $legacyCustomerRole->delete();
            }

            collect([Role::Admin, Role::Optometrist, Role::Staff, Role::Patient])
                ->each(fn (string $name) => Role::query()->firstOrCreate([
                    'name' => $name,
                ]));
        });
    }
}
