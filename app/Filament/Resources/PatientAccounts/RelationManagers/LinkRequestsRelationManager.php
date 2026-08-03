<?php

namespace App\Filament\Resources\PatientAccounts\RelationManagers;

use App\Models\PatientLinkRequest;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinkRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'linkRequests';

    protected static ?string $title = 'Link Requests';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('request_number')
                ->content(fn (?PatientLinkRequest $record) => $record?->request_number ?? '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('reviewedPatient.full_name')
                    ->label('Matched Patient')
                    ->placeholder('—'),

                TextColumn::make('reviewer.first_name')
                    ->label('Reviewed By')
                    ->state(fn (PatientLinkRequest $record): string => $record->reviewer?->full_name ?? '—')
                    ->placeholder('—'),

                TextColumn::make('decision_note')
                    ->label('Decision Note')
                    ->limit(50)
                    ->placeholder('—'),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            ->heading(null);
    }
}
