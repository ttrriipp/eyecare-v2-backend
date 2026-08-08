<?php

namespace App\Actions\Users;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncUserRoles
{
    /**
     * Valid panel role sets. Each inner array represents an approved combination.
     *
     * @var array<string, array<int, string>>
     */
    private const VALID_ROLE_SETS = [
        'admin' => [Role::Admin],
        'optometrist' => [Role::Optometrist],
        'staff' => [Role::Staff],
        'admin+optometrist' => [Role::Admin, Role::Optometrist],
    ];

    /**
     * Synchronize a user's role assignments.
     *
     * @param  array<int, string>  $roleNames
     */
    public function handle(
        User $target,
        array $roleNames,
        ?User $actor = null,
    ): void {
        $actor ??= auth()->user();

        $this->guardSelfRoleMutation($target, $actor);
        $this->guardInvalidRoleSet($roleNames);
        $this->guardLastActiveAdminRemoval($target, $roleNames);

        $oldRoleNames = $target->roles->pluck('name')->sort()->values()->all();

        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id');

        DB::transaction(function () use ($target, $roleIds): void {
            $target->roles()->sync($roleIds);
        });

        $target->load('roles');

        app(CreateAuditLog::class)->handle(
            subject: $target,
            action: AuditEvent::UserRoleChanged,
            metadata: [
                'old_roles' => $oldRoleNames,
                'new_roles' => $roleNames,
            ],
        );
    }

    private function guardSelfRoleMutation(User $target, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $target->id) {
            throw ValidationException::withMessages([
                'roles' => ['You cannot change your own role assignments.'],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $roleNames
     */
    private function guardInvalidRoleSet(array $roleNames): void
    {
        sort($roleNames);

        foreach (self::VALID_ROLE_SETS as $validSet) {
            $sortedValid = $validSet;
            sort($sortedValid);

            if ($roleNames === $sortedValid) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'roles' => ['The selected role combination is not valid.'],
        ]);
    }

    /**
     * @param  array<int, string>  $newRoleNames
     */
    private function guardLastActiveAdminRemoval(User $target, array $newRoleNames): void
    {
        if (! $target->hasRole(Role::Admin)) {
            return;
        }

        if (in_array(Role::Admin, $newRoleNames, true)) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->whereHas('roles', fn ($q) => $q->where('name', Role::Admin))
            ->exists();

        if (! $otherActiveAdmins) {
            throw ValidationException::withMessages([
                'roles' => ['Cannot remove the last active administrator.'],
            ]);
        }
    }
}
