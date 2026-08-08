<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Account Details')
                        ->schema([
                            TextInput::make('first_name')
                                ->label('First Name')
                                ->required(),
                            TextInput::make('last_name')
                                ->label('Last Name')
                                ->required(),
                            TextInput::make('middle_name')
                                ->label('Middle Name')
                                ->nullable(),
                            TextInput::make('email')
                                ->email()
                                ->unique(ignoreRecord: true)
                                ->required(),
                            TextInput::make('phone')
                                ->tel()
                                ->required()
                                ->prefix('+63')
                                ->formatStateUsing(fn (?string $state): ?string => $state !== null
                                    ? preg_replace('/^\+63/', '', $state)
                                    : null
                                )
                                ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null
                                    ? '+63'.preg_replace('/[^0-9]/', '', $state)
                                    : null
                                ),
                            TextInput::make('password')
                                ->password()
                                ->revealable()
                                ->rules([Password::defaults()])
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->label(fn (string $operation): string => $operation === 'create' ? 'Password' : 'New password (leave blank to keep)')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Role & Access')->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->multiple()
                            ->options([
                                Role::Admin => 'Admin',
                                Role::Optometrist => 'Optometrist',
                                Role::Staff => 'Staff',
                            ])
                            ->required()
                            ->minItems(1)
                            ->maxItems(2)
                            ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                            ->dehydrated(fn (?User $record): bool => $record?->id !== auth()->id())
                            ->validationAttribute('role combination'),
                    ]),

                    Section::make('Timeline')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Joined')
                                ->content(fn (?User $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last modified')
                                ->content(fn (?User $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
