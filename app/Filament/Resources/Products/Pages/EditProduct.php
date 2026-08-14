<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Product'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Product'),
        ];
    }
}
