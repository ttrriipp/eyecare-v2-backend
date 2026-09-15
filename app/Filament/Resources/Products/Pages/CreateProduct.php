<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ProductForm::prepareFormDataBeforeSave($data);
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Product created')
            ->body('Now add at least one variant to make this product available in the catalog.')
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->record]);
    }
}
