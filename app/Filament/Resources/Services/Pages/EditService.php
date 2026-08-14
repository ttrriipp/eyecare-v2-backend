<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Service'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Service'),
        ];
    }
}
