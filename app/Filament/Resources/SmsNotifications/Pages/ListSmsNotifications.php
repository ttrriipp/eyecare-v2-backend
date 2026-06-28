<?php

namespace App\Filament\Resources\SmsNotifications\Pages;

use App\Filament\Resources\SmsNotifications\SmsNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsNotifications extends ListRecords
{
    protected static string $resource = SmsNotificationResource::class;
}
