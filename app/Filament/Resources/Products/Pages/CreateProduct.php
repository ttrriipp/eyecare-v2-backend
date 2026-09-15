<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

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
