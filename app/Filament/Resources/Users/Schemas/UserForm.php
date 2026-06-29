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
                            TextInput::make('name')
                                ->required(),
                            TextInput::make('email')
                                ->email()
                                ->unique(ignoreRecord: true)
                                ->nullable(),
                            TextInput::make('phone')
                                ->tel()
                                ->required(),
                            TextInput::make('password')
                                ->password()
                                ->revealable()
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
                        Select::make('role_id')
                            ->label('Role')
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
