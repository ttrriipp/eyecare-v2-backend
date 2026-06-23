<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->nullable(),
                TextInput::make('phone')
                    ->tel()
                    ->required(),
                Select::make('role_id')
                    ->options(fn () => Role::query()
                        ->whereIn('name', ['admin', 'staff'])
                        ->pluck('name', 'id')
                        ->mapWithKeys(fn ($name, $id) => [$id => ucfirst($name)])
                        ->toArray()
                    )
                    ->required()
                    ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                    ->dehydrated(fn (?User $record): bool => $record?->id !== auth()->id())
                    ->helperText(fn (?User $record): ?string => $record?->id === auth()->id()
                        ? 'You cannot change your own role.'
                        : null
                    ),
                TextInput::make('password')
                    ->password()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->label(fn (string $operation): string => $operation === 'create' ? 'Password' : 'New password (leave blank to keep)')
                    ->columnSpanFull(),
            ]);
    }
}
