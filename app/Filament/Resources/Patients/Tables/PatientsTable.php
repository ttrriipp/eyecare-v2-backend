<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Models\Appointment;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('jobOrders')
                ->addSelect([
                    'last_visit' => Appointment::query()
                        ->select('scheduled_at')
                        ->whereColumn('patient_id', 'patients.id')
                        ->latest('scheduled_at')
                        ->limit(1),
                ])
                ->withCasts(['last_visit' => 'datetime']))
            ->columns([
                TextColumn::make('patient_number')
                    ->label('Patient #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('contact_email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('Walk-in'),
                TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_visit')
                    ->label('Last Visit')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('job_orders_count')
                    ->label('Job Orders')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('no_visits')
                    ->label('No visits yet')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('appointments')),
            ])
            ->recordActions([
                EditAction::make()->label('Edit'),
            ])
            ->defaultSort('first_name');
    }
}
