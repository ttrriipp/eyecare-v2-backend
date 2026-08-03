<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\PatientInvitationStatus;
use App\Models\PatientInvitation;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvitationHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Invitation History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('channel')
                ->content(fn (?PatientInvitation $record) => $record?->channel ?? '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'email' => 'info',
                        'phone' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PatientInvitationStatus $state) => match ($state) {
                        PatientInvitationStatus::Pending => 'warning',
                        PatientInvitationStatus::Accepted => 'success',
                        PatientInvitationStatus::Expired => 'gray',
                        PatientInvitationStatus::Revoked => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('sender.first_name')
                    ->label('Sent By')
                    ->state(fn (PatientInvitation $record): string => $record->sender?->full_name ?? '—')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y g:i A'),

                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime('M j, Y g:i A'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            ->heading(null);
    }
}
