<?php

namespace App\Filament\Resources\PatientAccounts\Tables;

use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PatientAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Account Name')
                    ->searchable(['first_name', 'last_name', 'name']),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('link_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => match (true) {
                        $record->patient !== null => 'Linked',
                        $record->linkRequests()->where('status', 'pending')->exists() => 'Pending Review',
                        default => 'Unlinked',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Linked' => 'success',
                        'Pending Review' => 'warning',
                        'Unlinked' => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('link_status')
                    ->options([
                        'linked' => 'Linked',
                        'pending' => 'Pending Review',
                        'unlinked' => 'Unlinked',
                    ])
                    ->query(function ($query, $data) {
                        if ($data['value'] === 'linked') {
                            $query->whereHas('patient');
                        } elseif ($data['value'] === 'pending') {
                            $query->whereDoesntHave('patient')
                                ->whereHas('linkRequests', fn ($q) => $q->where('status', 'pending'));
                        } elseif ($data['value'] === 'unlinked') {
                            $query->whereDoesntHave('patient')
                                ->whereDoesntHave('linkRequests', fn ($q) => $q->where('status', 'pending'));
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->url(fn (User $record) => PatientAccountResource::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
