<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),
                TextColumn::make('subject_id')
                    ->label('Subject ID'),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->default('System')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Occurred At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => Cache::remember('audit_log_actions', 60, fn () => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()
                    ))
                    ->label('Action'),
                SelectFilter::make('subject_type')
                    ->options(fn (): array => Cache::remember('audit_log_subject_types', 60, fn () => AuditLog::query()
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type', 'subject_type')
                        ->mapWithKeys(fn ($v) => [$v => class_basename($v)])
                        ->all()
                    ))
                    ->label('Subject Type'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
