<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->weight('bold')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->formatStateUsing(fn ($record): string => $record->full_name)
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('roles_display')
                    ->label('Roles')
                    ->getStateUsing(fn ($record): string => $record->roles->pluck('name')->map(fn ($name) => ucfirst($name))->implode(', '))
                    ->badge()
                    ->color(fn ($record): string => match (true) {
                        $record->isAdmin() && $record->isOptometrist() => 'warning',
                        $record->isAdmin() => 'danger',
                        $record->isOptometrist() => 'success',
                        default => 'info',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->since()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name', fn ($query) => $query->whereIn('name', ['admin', 'staff', 'optometrist'])),
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Active')
                    ->falseLabel('Deactivated'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->action(function (User $record): void {
                        if ($record->is_active && $record->isAdmin()) {
                            $activeAdminCount = User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                                ->where('is_active', true)
                                ->count();

                            if ($activeAdminCount <= 1) {
                                Notification::make()
                                    ->title('Cannot deactivate last admin')
                                    ->body('There must always be at least one active admin account.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->title($record->is_active ? 'Account activated' : 'Account deactivated')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
