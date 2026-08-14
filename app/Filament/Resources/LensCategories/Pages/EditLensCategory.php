<?php

namespace App\Filament\Resources\LensCategories\Pages;

use App\Filament\Resources\LensCategories\LensCategoryResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLensCategory extends EditRecord
{
    protected static string $resource = LensCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Lens package'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Lens package'),
            CatalogLifecycleActions::delete(fn (): Model => $this->getRecord(), 'Lens package'),
        ];
    }
}
