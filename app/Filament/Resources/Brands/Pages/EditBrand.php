<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

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
                ->modalHeading('Archive brand')
                ->modalDescription('This will hide the brand from active lists. It can be restored later from the "Show Archived" filter.')
                ->modalSubmitActionLabel('Archive')
                ->visible(fn (): bool => (auth()->user()?->isAdmin() ?? false) && ! $this->getRecord()->trashed()),
        ];
    }
}
