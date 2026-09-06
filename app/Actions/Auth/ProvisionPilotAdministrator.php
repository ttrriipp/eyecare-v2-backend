<?php

namespace App\Actions\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProvisionPilotAdministrator
{
    /**
     * Create or update the named administrator without a source-controlled password.
     */
    public function handle(string $email, string $firstName, string $lastName, string $password): User
    {
        $email = strtolower(trim($email));
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid administrator email address is required.');
        }

        if ($firstName === '' || $lastName === '') {
            throw new RuntimeException('Administrator first and last names are required.');
        }

        if ($password === '') {
            throw new RuntimeException('An administrator password is required.');
        }

        $adminRole = Role::query()->where('name', Role::Admin)->first();
        if ($adminRole === null) {
            throw new RuntimeException('The admin role is required before provisioning an administrator.');
        }

        return DB::transaction(function () use ($adminRole, $email, $firstName, $lastName, $password): User {
            $user = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($user !== null) {
                $isAdmin = $user->roles()->where('name', Role::Admin)->exists()
                    || (int) $user->role_id === (int) $adminRole->id;

                if (! $isAdmin) {
                    throw new RuntimeException('Refusing to promote a non-admin account.');
                }

                $user->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => $password,
                    'role_id' => $adminRole->id,
                    'is_optometrist' => false,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
                $user->updateQuietly(['must_change_password' => false]);
            } else {
                $user = User::query()->create([
                    'first_name' => $firstName,
                    'middle_name' => null,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => null,
                    'address' => null,
                    'date_of_birth' => null,
                    'password' => $password,
                    'role_id' => $adminRole->id,
                    'is_optometrist' => false,
                    'is_active' => true,
                    'must_change_password' => false,
                    'password_changed_at' => null,
                    'email_verified_at' => now(),
                    'privacy_notice_version' => null,
                    'privacy_acknowledged_at' => null,
                ]);
            }

            $user->roles()->sync([$adminRole->id]);

            return $user->fresh();
        });
    }
}
