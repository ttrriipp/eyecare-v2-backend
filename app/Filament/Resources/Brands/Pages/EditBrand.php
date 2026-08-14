<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Brand'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Brand'),
            CatalogLifecycleActions::delete(fn (): Model => $this->getRecord(), 'Brand'),
        ];
    }
}
