<?php

namespace App\Filament\Resources\PatientLinkRequests\Tables;

use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Models\PatientLinkRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PatientLinkRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.first_name')
                    ->weight('bold')
                    ->label('Account Owner')
                    ->state(fn (PatientLinkRequest $record): string => $record->user?->full_name ?? '—')
                    ->searchable(['user.first_name', 'user.last_name']),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'expired' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('reviewer.first_name')
                    ->weight('bold')
                    ->label('Reviewed By')
                    ->state(fn (PatientLinkRequest $record): string => $record->reviewer?->full_name ?? '—')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                    ]),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (PatientLinkRequest $record) => PatientLinkRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
