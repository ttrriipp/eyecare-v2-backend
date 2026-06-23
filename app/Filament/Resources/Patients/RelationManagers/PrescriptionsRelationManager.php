<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrescriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'prescriptions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prescribed_at')->label('Date')->date('M j, Y')->sortable(),
                TextColumn::make('od_sphere')->label('OD Sphere'),
                TextColumn::make('os_sphere')->label('OS Sphere'),
                TextColumn::make('expires_at')->label('Expires')->date('M j, Y'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => PrescriptionResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('prescribed_at', 'desc')
            ->paginated(false);
    }
}
