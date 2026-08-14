<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SyncUserRoles;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->getRecord()->roles()->pluck('name')->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Guard: prevent demoting the last admin
        $record = $this->getRecord();
        $newRoles = $data['roles'] ?? $record->roles->pluck('name')->all();

        if ($record->isAdmin() && ! in_array(Role::Admin, $newRoles, true)) {
            $adminCount = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', Role::Admin))
                ->where('is_active', true)
                ->count();

            if ($adminCount <= 1) {
                Notification::make()
                    ->title('Cannot demote last admin')
                    ->body('There must always be at least one active admin account.')
                    ->danger()
                    ->send();

                $data['roles'] = $record->roles->pluck('name')->all();
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        $data = $this->data;

        if (isset($data['roles'])) {
            // Update legacy columns for backward compatibility.
            $firstRole = Role::query()->whereIn('name', $data['roles'])->first();
            if ($firstRole) {
                $record->updateQuietly([
                    'role_id' => $firstRole->id,
                    'is_optometrist' => in_array(Role::Optometrist, $data['roles'], true),
                ]);
            }

            // Sync the pivot using the validated action.
            app(SyncUserRoles::class)->handle(
                target: $record,
                roleNames: $data['roles'],
                actor: auth()->user(),
            );
        }
    }
}
