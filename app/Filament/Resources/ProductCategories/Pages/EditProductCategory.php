<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make()
                ->label('Restore')
                ->visible(fn (): bool => (auth()->user()?->isAdmin() ?? false) && $this->getRecord()->trashed()),
            DeleteAction::make()
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->modalIcon('heroicon-o-archive-box')
                ->modalHeading('Archive category')
                ->modalDescription('This will hide the category from active lists. It can be restored later from the "Show Archived" filter.')
                ->modalSubmitActionLabel('Archive')
                ->visible(fn (): bool => (auth()->user()?->isAdmin() ?? false) && ! $this->getRecord()->trashed()),
        ];
    }
}
