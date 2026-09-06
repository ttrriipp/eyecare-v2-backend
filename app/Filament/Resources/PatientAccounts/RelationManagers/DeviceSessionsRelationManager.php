<?php

namespace App\Filament\Resources\PatientAccounts\RelationManagers;

use Filament\Forms\Components\Placeholder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Device Sessions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('name')
                ->content(fn (?PersonalAccessToken $record) => $record?->name ?? '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->label('Device')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('last_used_at')
                    ->label('Last Active')
                    ->formatStateUsing(fn (?string $state): string => $state ? Carbon::parse($state)->diffForHumans() : 'Never')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('Never'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            ->heading(null);
    }
}
