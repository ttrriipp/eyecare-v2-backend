<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SyncUserRoles;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['must_change_password'] = true;

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): User
    {
        $roleNames = $data['roles'] ?? [];
        unset($data['roles']);

        // Set legacy role_id from first role for backward compatibility.
        $firstRole = Role::query()->whereIn('name', $roleNames)->first();
        if ($firstRole) {
            $data['role_id'] = $firstRole->id;
        }

        // Set is_optometrist for backward compatibility.
        $data['is_optometrist'] = in_array('optometrist', $roleNames, true);

        $user = parent::handleRecordCreation($data);

        // Sync the pivot using the validated action.
        app(SyncUserRoles::class)->handle(
            target: $user,
            roleNames: $roleNames,
            actor: auth()->user(),
        );

        return $user;
    }
}
