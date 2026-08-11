<?php

namespace App\Filament\Resources\PatientLinkRequests\Tables;

use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Models\PatientLinkRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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
                    ->label('Account Owner')
                    ->state(fn (PatientLinkRequest $record): string => $record->user?->full_name ?? '—')
                    ->searchable(['user.first_name', 'user.last_name']),

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

                TextColumn::make('reviewer.first_name')
                    ->label('Reviewed By')
                    ->state(fn (PatientLinkRequest $record): string => $record->reviewer?->full_name ?? '—')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->tabs([
                Tab::make('All')
                    ->icon('heroicon-o-queue-list'),
                Tab::make('Pending')
                    ->icon('heroicon-o-clock')
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
                Tab::make('Approved')
                    ->icon('heroicon-o-check-circle')
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
                Tab::make('Rejected')
                    ->icon('heroicon-o-x-circle')
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
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
