<?php

namespace App\Filament\Resources\LensOptions\Pages;

use App\Filament\Resources\LensOptions\LensOptionResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLensOption extends EditRecord
{
    protected static string $resource = LensOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Lens option'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Lens option'),
        ];
    }
}
