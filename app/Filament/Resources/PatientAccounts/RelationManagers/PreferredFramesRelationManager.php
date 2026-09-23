<?php

namespace App\Filament\Resources\PatientAccounts\RelationManagers;

use App\Filament\Resources\Patients\RelationManagers\PreferredFramesRelationManager as PatientPreferredFramesRelationManager;
use Filament\Tables\Table;

class PreferredFramesRelationManager extends PatientPreferredFramesRelationManager
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading('No preferred frames');
    }
}
