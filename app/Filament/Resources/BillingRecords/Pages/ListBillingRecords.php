<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Filament\Resources\BillingRecords\BillingRecordResource;
use Filament\Resources\Pages\ListRecords;

class ListBillingRecords extends ListRecords
{
    protected static string $resource = BillingRecordResource::class;
}
