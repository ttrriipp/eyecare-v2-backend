<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('action')
                    ->label('Action'),
                TextEntry::make('subject_type')
                    ->label('Subject Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextEntry::make('subject_id')
                    ->label('Subject ID'),
                TextEntry::make('actor.name')
                    ->label('Actor')
                    ->default('System'),
                TextEntry::make('created_at')
                    ->label('Occurred At')
                    ->dateTime(),
                KeyValueEntry::make('metadata')
                    ->label('Metadata')
                    ->columnSpanFull(),
            ]);
    }
}
