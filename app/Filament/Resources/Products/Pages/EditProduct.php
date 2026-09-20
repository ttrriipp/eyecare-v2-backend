<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ProductForm::prepareFormDataBeforeFill($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ProductForm::prepareFormDataBeforeSave($data);
        $record = $this->getRecord();

        if ($record instanceof Product
            && ! $record->is_active
            && filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! $record->hasActiveVariant()) {
            throw ValidationException::withMessages([
                'data.is_active' => ['Add at least one active variant before activating the product.'],
            ]);
        }

        return $data;
    }
}
