<?php

namespace App\Filament\Resources\SmsNotifications\Pages;

use App\Filament\Resources\SmsNotifications\SmsNotificationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSmsNotification extends EditRecord
{
    protected static string $resource = SmsNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
