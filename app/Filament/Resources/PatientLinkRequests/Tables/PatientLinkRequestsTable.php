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

                TextColumn::make('user.name')
                    ->label('Account Owner')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reviewedPatient.full_name')
                    ->label('Linked Patient')
                    ->placeholder('—'),

                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
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
