<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Support\CatalogLifecycleActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogLifecycleActions::activate(fn (): Model => $this->getRecord(), 'Product category'),
            CatalogLifecycleActions::deactivate(fn (): Model => $this->getRecord(), 'Product category'),
        ];
    }
}
