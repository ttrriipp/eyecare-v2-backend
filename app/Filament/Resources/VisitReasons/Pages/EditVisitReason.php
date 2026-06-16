<?php

namespace App\Filament\Resources\VisitReasons\Pages;

use App\Filament\Resources\VisitReasons\VisitReasonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVisitReason extends EditRecord
{
    protected static string $resource = VisitReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
