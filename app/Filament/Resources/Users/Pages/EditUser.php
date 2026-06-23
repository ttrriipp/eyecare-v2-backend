<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Guard: prevent demoting the last admin
        $record = $this->getRecord();
        $newRoleId = $data['role_id'] ?? $record->role_id;
        $newRole = Role::find($newRoleId);

        if ($record->role->name === 'admin' && $newRole?->name !== 'admin') {
            $adminCount = User::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
                ->count();

            if ($adminCount <= 1) {
                Notification::make()
                    ->title('Cannot demote last admin')
                    ->body('There must always be at least one admin account.')
                    ->danger()
                    ->send();

                $data['role_id'] = $record->role_id;
            }
        }

        return $data;
    }
}
